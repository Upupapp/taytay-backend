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
                Permission::KycReview,
                Permission::KycApprove,
                Permission::CredentialManage,
                // Sees who holds what. Provisioning is deliberately absent — see below.
                Permission::StaffView,
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
