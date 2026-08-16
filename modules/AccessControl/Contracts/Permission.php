<?php

declare(strict_types=1);

namespace Modules\AccessControl\Contracts;

/**
 * The permission catalog — the complete vocabulary of things an actor may be allowed to
 * do. AccessControl is the canonical owner (docs/architecture/domain-boundary-map.md).
 *
 * Published under Contracts/ rather than Domain/ precisely because other modules must be
 * able to name a permission: it is the one part of AccessControl they are allowed to
 * depend on directly (CLAUDE.md Article 2.1).
 *
 * Permissions are fine-grained verbs, never roles. Code asks "may this actor do X?",
 * never "is this actor an admin?", so that role composition can change without touching
 * call sites.
 */
enum Permission: string
{
    /** Open the KYC review queue and rule on possible duplicate residents. */
    case KycReview = 'kyc.review';

    /**
     * Approve or refuse a KYC case. Separate from review on purpose: deciding that two
     * records are the same person and deciding that somebody becomes a verified resident
     * are different responsibilities, and an LGU may want them held by different people.
     */
    case KycApprove = 'kyc.approve';

    /** Read a canonical resident record. Every use is audited. */
    case ResidentView = 'resident.view';

    /**
     * Create a resident record and correct its fields, including approving a resident's own
     * correction request.
     *
     * Separate from `ResidentView` because reading a file and rewriting it are different
     * responsibilities: most front-line staff need the first and very few should hold the
     * second.
     */
    case ResidentManage = 'resident.manage';

    /**
     * Move a resident's verification tier, or deactivate/reactivate the record.
     *
     * Held apart from `ResidentManage` on purpose. Correcting a misspelt street is
     * routine; declaring somebody's identity established is the decision that unlocks a
     * digital ID (ADR 0011), and an LGU may well want those in different hands.
     */
    case ResidentVerify = 'resident.verify';

    /**
     * Rule on duplicate resident records and execute a merge.
     *
     * The most destructive permission in the catalog. A merge collapses two people into one
     * row, and when it is wrong it makes one resident disappear while handing their
     * assistance history to somebody else. It is deliberately not implied by
     * `ResidentManage` and belongs to no role by default.
     */
    case ResidentMerge = 'resident.merge';

    /**
     * Attach an account to a resident, or withdraw that attachment.
     *
     * Its own permission because linking is what decides whose welfare file a person can
     * open in the citizen app. Getting it wrong is a data breach performed by the system
     * itself, so it is not folded into general resident management.
     */
    case ResidentLinkReview = 'resident.link_review';

    /**
     * Create and edit households and families, move members between them, name heads and
     * record kinship.
     *
     * Reading a household is governed by `ResidentView` instead — a household is a group of
     * residents, and seeing it reveals their data, so the two must not be separable. Writing
     * gets its own permission because moving a resident between households changes who is
     * counted in every household-based distribution.
     */
    case HouseholdManage = 'household.manage';

    /*
     * ── social welfare casework (contract matrix §5) ──────────────────────────────────
     *
     * Deliberately fine-grained per lifecycle stage. The transition endpoint resolves the
     * permission from its *target* state, so the state machine and the authorization table
     * stay in one place — and separating endorse from approve is what makes separation of
     * duties expressible at all (ADR 0016 §6).
     */

    /** Open the case queue and read case files. */
    case RequestView = 'request.view';

    /**
     * See restricted cases — protective services.
     *
     * Knowing that a protection case exists for a named person is most of the disclosure, so
     * this gates list, count and detail alike (ADR 0016 §5).
     */
    case RequestViewSensitive = 'request.view-sensitive';

    /** Open a case, and submit or withdraw a draft. */
    case RequestCreate = 'request.create';

    /** Intake review: check what was brought in, or return it for missing documents. */
    case RequestIntake = 'request.intake';

    /** Social-worker assessment. */
    case RequestAssess = 'request.assess';

    /** Recommend a case for approval. Never sufficient to approve it. */
    case RequestEndorse = 'request.endorse';

    /** Approve a case, committing public money to it. */
    case RequestApprove = 'request.approve';

    /** Refuse a case. */
    case RequestReject = 'request.reject';

    /** Book an approved case for a release date. */
    case RequestSchedule = 'request.schedule';

    /** Confirm that assistance was handed over. Money-adjacent; TAB 18 builds the ledger. */
    case RequestRelease = 'request.release';

    /** Close or expire a case. */
    case RequestClose = 'request.close';

    /** Assign a case to a caseworker or team. */
    case RequestAssign = 'request.assign';

    /** Read vulnerability factors and the decision-support snapshot built from them. */
    case VulnerabilityView = 'vulnerability.view';

    /** Record, review and end vulnerability observations. */
    case VulnerabilityManage = 'vulnerability.manage';

    /**
     * See safeguarding factors — VAWC, CICL, child-at-risk, trafficking.
     *
     * The narrowest permission in the catalog. Membership of these categories can endanger
     * someone, so a caller without this sees a response identical to that of a person with no
     * such factors: no count, no placeholder, nothing to infer from (ADR 0015 §4).
     *
     * Held by `lgu_admin` today only because that is the most senior role that exists. It
     * belongs to a dedicated protection officer, and the LGU should move it to one before
     * production.
     */
    case VulnerabilityViewProtected = 'vulnerability.view_protected';

    /** Issue or revoke a digital ID credential. */
    case CredentialManage = 'credential.manage';

    /** Read programme rolls and a beneficiary's assistance history. */
    case EnrollmentView = 'enrollment.view';

    /**
     * Enrol a beneficiary, suspend them, or take them off a roll.
     *
     * Money-adjacent: being on a roll is what makes somebody a recipient in TAB 18's release
     * run. Deliberately separate from `request.approve` — approving a case and putting a name on
     * a payment roll are two decisions, and an office may want them held by different people.
     */
    case EnrollmentManage = 'enrollment.manage';

    /**
     * Record a document against a case requirement, and decide a conditional requirement.
     *
     * Held by front-line staff: receiving papers at the counter is the job. Note that recording
     * a document is not accepting one — see `document.verify`.
     */
    case DocumentManage = 'document.manage';

    /**
     * Accept or refuse a presented document.
     *
     * Separate from recording it, because "we received this" and "this satisfies the requirement"
     * are two different claims, and only the second one advances a case toward money. The clerk
     * who took the paper is not thereby the person who judged it sufficient.
     */
    case DocumentVerify = 'document.verify';

    /**
     * Open a document classified as sensitive.
     *
     * RA 9262 / RA 9344 material, health records, biometrics. A case worker holding
     * `request.view.sensitive` can read that such a document exists; opening the image is a
     * further step and a further audit entry.
     */
    case DocumentViewSensitive = 'document.view.sensitive';

    /**
     * Issue a copy of a document for use outside the office.
     *
     * The narrowest permission here, and deliberately not implied by any other. Every internal
     * read leaves a trail this system controls; a copy that leaves does not, so the act of
     * creating one is separately authorised and separately recorded.
     */
    case DocumentShare = 'document.share';

    /** Read the referral queue and the service provider directory. */
    case ReferralView = 'referral.view';

    /**
     * Draft a referral, record what the receiving office reports, close it out.
     *
     * Everything except the moment it leaves the building.
     */
    case ReferralManage = 'referral.manage';

    /**
     * **Send** a referral — the act that discloses a person's information to another
     * organisation.
     *
     * Separate from `referral.manage` because it is the only irreversible step: once the sheet
     * is out, the MSWDO no longer controls who reads it and nothing can be taken back. Drafting
     * is casework; sending is a disclosure decision, and an office may want the second held by
     * fewer people than the first.
     */
    case ReferralSend = 'referral.send';

    /**
     * Release a field that can endanger the client — home address, sector membership,
     * assistance history.
     *
     * A home address is the field an abuser needs; sector membership can disclose that somebody
     * is a VAWC survivor or a child in conflict with the law. Held separately so releasing one is
     * a second decision rather than one more checkbox on a form somebody is working through
     * quickly.
     */
    case ReferralDiscloseProtected = 'referral.disclose.protected';

    /** Maintain the service provider directory. */
    case ProviderManage = 'provider.manage';

    /** Read the field visit calendar and visit records. */
    case VisitView = 'visit.view';

    /** Schedule a visit, record what was found, cancel one. */
    case VisitManage = 'visit.manage';

    /**
     * Read the **body** of a protected case note.
     *
     * Safety planning for a VAWC survivor, anything identifying a child in conflict with the law,
     * a disclosure given in confidence, clinical detail. Without it a reader still sees that the
     * note exists, who wrote it and when — see ADR 0022 §3 for why concealing its existence would
     * be worse than concealing its content.
     */
    case CaseNoteViewProtected = 'case-note.view-protected';

    /**
     * Read safeguarding detail.
     *
     * The narrowest read permission in this system. A safeguarding concern names why a family is
     * being watched, and the category alone — "child-protection" against a named household — is
     * itself the disclosure.
     */
    case SafeguardingView = 'safeguarding.view';

    /**
     * Raise, review and close a safeguarding concern.
     *
     * Deciding that a family no longer needs watching is as consequential as deciding they do,
     * and both belong to a protection officer rather than to whoever is on the case.
     */
    case SafeguardingManage = 'safeguarding.manage';

    /**
     * Read work queues.
     *
     * Broad on purpose. A task carries a type, an opaque identifier and a short instruction, and
     * nothing about the record behind it — so seeing a queue discloses nothing, and the subject is
     * opened through its own module's endpoint, which does its own check (ADR 0024 §2).
     */
    case TaskView = 'task.view';

    /** Raise, reassign and close a task. */
    case TaskManage = 'task.manage';

    /** Read dashboard metrics and request an aggregate export. */
    case ReportView = 'report.view';

    /**
     * Export a report that NAMES INDIVIDUALS.
     *
     * Separate from `report.view` because the two are different acts. An aggregate leaves the
     * building as a statistic; a person-level export leaves as a copy of a caseload, and once it
     * is on a laptop none of this system's authorization applies to it any more — no scope, no
     * audit, no revocation (ADR 0026 §3).
     */
    case ReportExportPersonLevel = 'report.export.person-level';

    /** Read the programme catalogue, including drafts and retired programmes. */
    case ProgramView = 'program.view';

    /**
     * Author programmes: create, edit, publish, and open a new eligibility guidance version.
     *
     * Editing guidance changes who the office will be *advised* to help. It never decides
     * anything on its own (ADR 0018 §3), but it shapes what every caseworker sees, so it is
     * held apart from reading the catalogue.
     */
    case ProgramManage = 'program.manage';

    /** See catalog entries that are not published to citizens (drafts, retired). */
    case ServicesViewUnpublished = 'services.view_unpublished';

    /** Create, edit and publish catalog entries. */
    case ServicesManage = 'services.manage';

    /**
     * Provision staff: create accounts, assign and revoke roles, grant and withdraw
     * barangay access.
     *
     * The most dangerous permission in the catalog, because it is the one that hands out
     * the others. It is deliberately NOT sufficient on its own — StaffProvisioningService
     * additionally refuses to grant authority the actor does not already hold, and refuses
     * to act on the actor's own account (ADR 0012).
     */
    case StaffManage = 'staff.manage';

    /** Read the staff directory and each member's effective authority. */
    case StaffView = 'staff.view';

    public static function tryFromName(string $permission): ?self
    {
        return self::tryFrom($permission);
    }

    /**
     * Whether this permission is about *staffing the office* rather than about serving a
     * resident.
     *
     * The distinction is what makes delegated provisioning safe. A security officer may
     * appoint a KYC reviewer without being able to approve a KYC case themselves — that is
     * separation of duties working, not a hole. What they may never do is hand out
     * administrative permissions they do not hold, because that is the step that turns one
     * compromised provisioner into two (StaffProvisioningService).
     */
    public function isAdministrative(): bool
    {
        return match ($this) {
            self::StaffManage, self::StaffView => true,
            default => false,
        };
    }
}
