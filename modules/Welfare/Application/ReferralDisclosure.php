<?php

declare(strict_types=1);

namespace Modules\Welfare\Application;

use Modules\Files\Application\DocumentLibrary;
use Modules\Files\Contracts\FileClassification;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Welfare\Domain\DisclosureBasis;
use Modules\Welfare\Domain\SharedField;
use Modules\Welfare\Infrastructure\Eloquent\Referral;
use Modules\Welfare\Infrastructure\Eloquent\ReferralAttachment;
use Modules\Welfare\Infrastructure\Eloquent\ReferralSharedField;

/**
 * Deciding what actually leaves the building (ADR 0021 §3).
 *
 * A REFERRAL SUMMARY IS UNLIKE EVERY OTHER PAYLOAD IN THIS SYSTEM: it is handed to another
 * organisation. Once it is printed or transmitted, this office no longer controls who reads it
 * and nothing can be taken back. Every other endpoint here can be tightened later; this one
 * cannot.
 *
 * So the summary is **composed, not laid out**. The minimum — name, reference number, reason —
 * goes because the receiving office cannot act without it. Everything else is opt-in, one field
 * at a time, each with a stated need, because "include everything, they can ignore what they
 * don't need" is how a survivor's address reaches a desk that had no reason to hold it.
 *
 * This is RA 10173's minimisation and purpose-limitation duties written as code rather than as a
 * paragraph in a manual.
 */
final class ReferralDisclosure
{
    public function __construct(
        private readonly ResidentDirectory $residents,
        private readonly DocumentLibrary $library,
        private readonly WelfareAudit $audit,
    ) {}

    /**
     * Records the lawful basis. Required before a referral may be sent.
     */
    public function recordAuthority(
        Referral $referral,
        DisclosureBasis $basis,
        string $note,
        ActorContext $actor,
    ): Referral {
        $this->assertStillEditable($referral);

        /*
         * A basis with no note is a checkbox, not a record.
         *
         * Each basis needs a *different* fact written down — see DisclosureBasis::notePrompt().
         * A vital-interest referral whose note reads "client agreed" is a record that
         * contradicts its own basis, and that is worth being able to spot.
         */
        if (trim($note) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, $basis->notePrompt());
        }

        $referral->forceFill([
            'disclosure_basis' => $basis,
            'disclosure_note' => $note,
            'disclosure_recorded_by' => $actor->subjectId,
            'disclosure_recorded_at' => now(),
        ])->save();

        return $referral->refresh();
    }

    /**
     * Adds one field to what will be released.
     *
     * @param  bool  $mayDiscloseProtected  Whether the caller holds `referral.disclose.protected`.
     *                                      Passed in rather than resolved here so authorization
     *                                      stays in one place, at the controller.
     */
    public function shareField(
        Referral $referral,
        SharedField $field,
        string $because,
        bool $mayDiscloseProtected,
        ActorContext $actor,
    ): ReferralSharedField {
        $this->assertStillEditable($referral);

        // An unexplained field is not shared. The reason is what a data-privacy officer reads
        // when asked why a receiving office holds somebody's income.
        if (trim($because) === '') {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Say why the receiving office needs '.$field->label().'.',
            );
        }

        /*
         * A second decision for the fields that can endanger somebody.
         *
         * A home address is the field an abuser needs; sector membership can disclose that
         * somebody is a VAWC survivor or a child in conflict with the law. Requiring a separate
         * permission means releasing one is not just another checkbox on a form a worker is
         * moving through quickly.
         */
        if ($field->needsExtraCare() && ! $mayDiscloseProtected) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'Releasing '.$field->label().' needs a protection-level authorisation.',
            );
        }

        /** @var ReferralSharedField $row */
        $row = ReferralSharedField::query()->updateOrCreate(
            ['referral_id' => $referral->id, 'field' => $field->value],
            ['because' => $because, 'chosen_by' => $actor->subjectId],
        );

        return $row;
    }

    public function withholdField(Referral $referral, SharedField $field): void
    {
        $this->assertStillEditable($referral);

        ReferralSharedField::query()
            ->where('referral_id', $referral->id)
            ->where('field', $field->value)
            ->delete();
    }

    /**
     * Attaches one document.
     *
     * THREE GATES, AND THEY ARE NOT REDUNDANT:
     *
     *  1. **A reason**, like every field.
     *  2. **`document.share`**, checked by the controller — attaching a document to a referral IS
     *     an outward disclosure of a file, which is exactly the act TAB 15 put behind that
     *     permission. Treating it as a different act because it happens on a different screen
     *     would be the whole point of the permission lost.
     *  3. **Classification.** A file classified `sensitive` may not leave by any route this
     *     system offers, whatever anybody holds. That is checked here rather than only at the
     *     grant, because the referral sheet lists attachment labels — so a sensitive document
     *     would be *named* to the receiving office even if the bytes never followed.
     */
    public function attachDocument(
        Referral $referral,
        string $documentUuid,
        string $label,
        string $because,
        ActorContext $actor,
    ): ReferralAttachment {
        $this->assertStillEditable($referral);

        if (trim($because) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'Say why the receiving office needs this document.');
        }

        $version = $this->library->currentVersion($documentUuid);

        if ($version === null) {
            throw new ApiException(ErrorCode::NotFound, 'That document was not found.');
        }

        if ($version->file === null) {
            throw new ApiException(
                ErrorCode::Conflict,
                'That record holds no file, so there is nothing to attach.',
            );
        }

        if ($this->library->classificationOf($version->id) === FileClassification::Sensitive) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'Documents of this kind may not be shared outside the office.',
            );
        }

        // An unscanned file must not be handed to another organisation: a caseworker opening it
        // on a managed workstation is a risk this office already took, and passing it on is a
        // risk it would be giving to somebody else.
        if (! $version->file->scanStatus->mayLeaveTheOffice()) {
            throw new ApiException(
                ErrorCode::Conflict,
                'That file has not been scanned yet and cannot be shared.',
            );
        }

        /** @var ReferralAttachment $row */
        $row = ReferralAttachment::query()->updateOrCreate(
            ['referral_id' => $referral->id, 'document_id' => $documentUuid],
            ['label' => $label, 'because' => $because, 'chosen_by' => $actor->subjectId],
        );

        $this->audit->record(
            $actor->subjectId,
            'referral.document-attached',
            'Document attached to a referral',
            (string) $referral->uuid,
        );

        return $row;
    }

    public function detachDocument(Referral $referral, string $documentUuid): void
    {
        $this->assertStillEditable($referral);

        ReferralAttachment::query()
            ->where('referral_id', $referral->id)
            ->where('document_id', $documentUuid)
            ->delete();
    }

    /**
     * Builds the sheet that leaves the building.
     *
     * A FLAT LIST OF LINES, deliberately. A structure with optional nested sections is a
     * structure somebody eventually renders whole.
     *
     * A field chosen but not held is **skipped, not printed empty**: an empty line invites the
     * receiving office to ask for it. And a withheld field is **absent, not marked withheld** —
     * "Address: withheld" tells the reader there is an address worth hiding, which for a
     * protection case is itself the disclosure.
     *
     * @return array<string, mixed>
     */
    public function compose(Referral $referral, ActorContext $actor): array
    {
        if ($referral->disclosure_basis === null) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Record the lawful basis before producing a referral sheet.',
            );
        }

        $resident = $this->residents->summaryFor((string) $referral->resident_id);

        if ($resident === null) {
            throw new ApiException(ErrorCode::NotFound, 'That client record was not found.');
        }

        $lines = [
            ['label' => 'Client', 'value' => $resident->displayName, 'is_extra' => false],
            ['label' => 'Referred by', 'value' => 'MSWDO Taytay, Rizal', 'is_extra' => false],
        ];

        foreach ($referral->sharedFields()->get() as $choice) {
            $value = $this->valueFor($choice->field, (string) $referral->resident_id);

            if ($value === null) {
                continue;
            }

            $lines[] = ['label' => $choice->field->label(), 'value' => $value, 'is_extra' => true];
        }

        /*
         * Composing the sheet is itself a disclosure event, audited separately from sending.
         *
         * Somebody who prints a sheet and never sends it has still produced a document holding a
         * person's information, and that piece of paper exists.
         */
        $this->audit->record(
            $actor->subjectId,
            'referral.summary-composed',
            'Referral summary produced',
            (string) $referral->uuid,
        );

        return [
            'reference_number' => $referral->reference_number,
            'destination_name' => $referral->destination_name,
            'service_requested' => $referral->service_requested,
            'reason' => $referral->reason,
            'lines' => $lines,
            'attachments' => $referral->attachments()->get()
                ->map(fn (ReferralAttachment $a): string => (string) $a->label)->all(),
            // Printed so the receiving office knows the basis it holds the information on — it
            // changes what they may lawfully do with it.
            'authority_statement' => $referral->disclosure_basis->statement(),
            'handling_notice' => self::HANDLING_NOTICE,
        ];
    }

    public const HANDLING_NOTICE =
        'Shared by the Municipal Social Welfare and Development Office of Taytay, Rizal for the '.
        'purpose stated above only. It contains personal information protected under RA 10173. '.
        'Do not forward it or use it for another purpose.';

    /**
     * What must be true before this referral may be sent.
     *
     * @return list<string>
     */
    public function blockersFor(Referral $referral): array
    {
        $blockers = [];

        if ($referral->disclosure_basis === null) {
            $blockers[] = 'disclosure-basis-required';
        }

        if (trim((string) $referral->service_requested) === '') {
            $blockers[] = 'service-required';
        }

        if (trim((string) $referral->reason) === '') {
            $blockers[] = 'reason-required';
        }

        if (trim((string) $referral->destination_name) === '') {
            $blockers[] = 'destination-required';
        }

        return $blockers;
    }

    /**
     * Reads one field for the sheet.
     *
     * ResidentProfile answers, because it owns the data and therefore owns what each field means.
     * Assistance history is not yet wired — it is assembled across cases and enrolments, and
     * returns nothing rather than an invented value, so a chosen field that cannot be filled is
     * simply absent from the sheet (gap G-29).
     */
    private function valueFor(SharedField $field, string $residentUuid): ?string
    {
        $facts = $this->residents->disclosureFactsFor($residentUuid);

        return $facts[$field->value] ?? null;
    }

    /**
     * A sent referral's disclosure is frozen.
     *
     * The other office already has what it has. Editing the record afterwards would make it
     * describe a disclosure that never happened — and the version that did happen would be the
     * one nobody could reconstruct.
     */
    private function assertStillEditable(Referral $referral): void
    {
        if ($referral->status->hasLeftTheOffice()) {
            throw new ApiException(
                ErrorCode::Conflict,
                'That referral has already been sent. Its disclosure record cannot be changed.',
            );
        }
    }

    /**
     * Every field released, with its reason. The disclosure record itself.
     *
     * @return list<array<string, mixed>>
     */
    public function plan(Referral $referral): array
    {
        return $referral->sharedFields()->get()->map(fn (ReferralSharedField $row): array => [
            'field' => $row->field->value,
            'label' => $row->field->label(),
            'because' => $row->because,
            'needs_extra_care' => $row->field->needsExtraCare(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attachmentPlan(Referral $referral): array
    {
        return $referral->attachments()->get()->map(fn (ReferralAttachment $row): array => [
            'document_id' => $row->document_id,
            'label' => $row->label,
            'because' => $row->because,
        ])->all();
    }
}
