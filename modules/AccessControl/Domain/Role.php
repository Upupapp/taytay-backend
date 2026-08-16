<?php

declare(strict_types=1);

namespace Modules\AccessControl\Domain;

use Modules\AccessControl\Contracts\Permission;

/**
 * Roles are bundles of permissions assigned to an account. They are an authoring
 * convenience: authorization is always evaluated against permissions, never role names.
 *
 * Deny by default — a role grants exactly what is listed here and nothing more, and an
 * account with no assignment (the default for every new account) has no permissions.
 */
enum Role: string
{
    /** A citizen acting for themselves. Holds no administrative permission. */
    case Resident = 'resident';

    /** A device/kiosk that may check credential validity. Sees no personal data beyond
     *  the minimum needed to display a verification result. */
    case Verifier = 'verifier';

    /** LGU front-line staff. */
    case LguStaff = 'lgu_staff';

    /** LGU administrator for the service catalog and staff-facing configuration. */
    case LguAdmin = 'lgu_admin';

    /**
     * Hands assistance over and records that it was received.
     *
     * THE OTHER HALF OF SEGREGATION OF DUTIES. Until TAB 18 nobody held `request.release` at all,
     * deliberately — the contract matrix recorded that no single non-administrator role may both
     * approve a case and release its money, and `lgu_admin` therefore holds approval and not
     * release.
     *
     * That left the permission unheld, which was correct while there was nothing to release. This
     * role is what makes it operable without collapsing the split: it releases and it approves
     * nothing (ADR 0023 §3).
     */
    case DisbursingOfficer = 'disbursing_officer';

    /** Provisions staff and their scopes. Holds no operational permission over residents. */
    case SecurityOfficer = 'security_officer';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Resident => [],

            // A verifier device checks credentials at a counter. It reads no resident
            // record and reviews nothing — the verification endpoint returns the minimum
            // it needs without any permission at all.
            self::Verifier => [],

            self::LguStaff => [
                Permission::ServicesViewUnpublished,
                Permission::ResidentView,
                // Front-line staff take walk-ins and fix bad addresses — that is most of
                // the job. They do not decide who is verified, and they do not merge.
                Permission::ResidentManage,
                // Household composition changes constantly and is recorded at the counter
                // and in the field; withholding it would push the work to an admin who was
                // not there.
                Permission::HouseholdManage,
                // Field work is where vulnerability is observed, so front-line staff both
                // read and record it. Safeguarding factors are deliberately NOT included:
                // recording that somebody is a VAWC survivor is a protection decision, not
                // an intake one.
                Permission::VulnerabilityView,
                Permission::VulnerabilityManage,
                /*
                 * Casework up to and including endorsement.
                 *
                 * Front-line staff take the intake, assess, and recommend. They do NOT hold
                 * `RequestApprove`, `RequestRelease` or `RequestReject` — approving one's own
                 * recommendation is the single-signature path every audit of a benefits
                 * programme looks for first (ADR 0016 §6).
                 */
                Permission::RequestView,
                Permission::RequestCreate,
                Permission::RequestIntake,
                Permission::RequestAssess,
                Permission::RequestEndorse,
                Permission::RequestAssign,
                // Front-line staff read the programme catalogue to advise applicants. Authoring
                // programmes and their guidance is not theirs.
                Permission::ProgramView,
                // Front-line staff read rolls to answer "am I enrolled?" at the counter. Putting
                // a name on one is money-adjacent and is not theirs.
                Permission::EnrollmentView,
                /*
                 * Receiving papers at the counter is the job, so recording a document is theirs.
                 * `DocumentVerify` is NOT — the clerk who took the paper is not thereby the
                 * person who judged it sufficient, and a verified requirement is what advances a
                 * case toward money (ADR 0020 §7).
                 *
                 * `DocumentViewSensitive` and `DocumentShare` are also absent: safeguarding
                 * images and outward copies are separate decisions with separate trails.
                 */
                Permission::DocumentManage,
                /*
                 * Front-line staff prepare referrals — routing a family to a hospital's medical
                 * social worker is ordinary casework and often urgent.
                 *
                 * `ReferralSend` is NOT theirs. Sending is the one irreversible step: once the
                 * sheet is out, this office no longer controls who reads it. Nor is
                 * `ReferralDiscloseProtected` — releasing a home address or a sector membership
                 * is a protection decision, not an intake one (ADR 0021 §5).
                 */
                Permission::ReferralView,
                Permission::ReferralManage,
                /*
                 * Field work IS front-line staff's job — they are the ones who go.
                 *
                 * `CaseNoteViewProtected` and the safeguarding pair are NOT theirs. A worker
                 * covering a colleague's caseload can read the running record and see that
                 * protected entries exist without reading them, which is what lets them ask the
                 * right person rather than act on a file they think is complete (ADR 0022 §3).
                 */
                Permission::VisitView,
                Permission::VisitManage,
                // Work queues are how front-line staff know what they owe today.
                Permission::TaskView,
                Permission::TaskManage,
                // Front-line staff read the dashboard for their own barangay. Person-level export
                // is deliberately not theirs.
                Permission::ReportView,
                // Staff review possible duplicates but do not decide who becomes verified.
                Permission::KycReview,
            ],

            self::LguAdmin => [
                Permission::ServicesViewUnpublished,
                Permission::ServicesManage,
                Permission::ResidentView,
                Permission::ResidentManage,
                Permission::ResidentVerify,
                Permission::ResidentMerge,
                Permission::ResidentLinkReview,
                Permission::HouseholdManage,
                Permission::VulnerabilityView,
                Permission::VulnerabilityManage,
                // See the note on the permission: this belongs to a dedicated protection
                // officer, and sits here only because that role does not exist yet.
                Permission::VulnerabilityViewProtected,
                /*
                 * The approving authority. Holds decision rights over casework, and
                 * deliberately NOT `RequestEndorse` — the MSWDO head approves what the social
                 * workers recommend; they do not write the recommendation and then sign it.
                 *
                 * `RequestRelease` is also absent: no single non-administrator role may both
                 * approve a case and release its money (contract matrix §5). TAB 18 builds
                 * the release workflow against a role that holds release and not approve.
                 */
                Permission::RequestView,
                Permission::RequestViewSensitive,
                Permission::RequestCreate,
                Permission::RequestIntake,
                Permission::RequestAssess,
                Permission::RequestApprove,
                Permission::RequestReject,
                Permission::RequestSchedule,
                Permission::RequestClose,
                Permission::RequestAssign,
                Permission::ProgramView,
                Permission::ProgramManage,
                Permission::EnrollmentView,
                Permission::EnrollmentManage,
                Permission::DocumentManage,
                Permission::DocumentVerify,
                // Sits here for the same reason as VulnerabilityViewProtected: it belongs to a
                // dedicated protection officer, and that role does not exist yet.
                Permission::DocumentViewSensitive,
                Permission::ReferralView,
                Permission::ReferralManage,
                // The disclosure decisions. Held here because sending a referral releases a
                // person's information outside this office, and that is the MSWDO head's call.
                Permission::ReferralSend,
                Permission::ReferralDiscloseProtected,
                Permission::ProviderManage,
                Permission::VisitView,
                Permission::VisitManage,
                /*
                 * The protection tier. Sits here for the same reason as
                 * `VulnerabilityViewProtected` and `DocumentViewSensitive`: it belongs to a
                 * dedicated protection officer, and that role does not exist yet (gap G-30).
                 *
                 * When it does, these three move there and the MSWDO head keeps none of them —
                 * reading a survivor's safety plan is not an administrative convenience.
                 */
                Permission::CaseNoteViewProtected,
                Permission::SafeguardingView,
                Permission::SafeguardingManage,
                Permission::TaskView,
                Permission::TaskManage,
                Permission::ReportView,
                Permission::ReportExportPersonLevel,
                Permission::SavedViewShare,
                /*
                 * Deliberately ABSENT: DocumentShare. Nobody holds it yet.
                 *
                 * The outward-sharing path is built and refused rather than built and quietly
                 * granted, because the first holder of this permission should be a decision the
                 * LGU makes on the record — not a line that arrived with a feature (gap G-26).
                 */
                Permission::KycReview,
                Permission::KycApprove,
                Permission::CredentialManage,
                // Sees who holds what. Provisioning is deliberately absent — see below.
                Permission::StaffView,
            ],

            /*
             * Releases assistance, and decides nothing about who gets it.
             *
             * Deliberately absent: `RequestApprove`, `RequestReject`, `RequestEndorse`,
             * `RequestAssess`, `ResidentManage`, `EnrollmentManage`. A disbursing officer who
             * could also approve, or put a name on a roll, would be a single signature between an
             * empty case file and money leaving the building.
             *
             * `RequestView` and `EnrollmentView` are present because they must be: somebody
             * handing over a payment has to be able to see what was approved and confirm the
             * person in front of them is on the roll.
             */
            self::DisbursingOfficer => [
                Permission::RequestView,
                Permission::RequestRelease,
                Permission::RequestSchedule,
                Permission::EnrollmentView,
                Permission::ProgramView,
                Permission::ResidentView,
                // A disbursing officer works from a queue like everybody else.
                Permission::TaskView,
                Permission::TaskManage,
            ],

            // Separation of duties: the person who hands out authority is not the person
            // who exercises it over residents. A security officer provisions staff and can
            // read the directory, but holds no KYC, resident or credential permission —
            // and because a granter may only pass on authority they hold themselves
            // (StaffProvisioningService), they cannot mint it for a confederate either.
            self::SecurityOfficer => [
                Permission::StaffView,
                Permission::StaffManage,
            ],
        };
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    public static function permissionsFor(array $roles): array
    {
        $permissions = [];

        foreach ($roles as $role) {
            foreach ((self::tryFrom($role)?->permissions() ?? []) as $permission) {
                $permissions[] = $permission->value;
            }
        }

        return array_values(array_unique($permissions));
    }
}
