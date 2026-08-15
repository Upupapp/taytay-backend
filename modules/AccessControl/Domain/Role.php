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
                // Staff review possible duplicates but do not decide who becomes verified.
                Permission::KycReview,
            ],

            self::LguAdmin => [
                Permission::ServicesViewUnpublished,
                Permission::ServicesManage,
                Permission::ResidentView,
                Permission::KycReview,
                Permission::KycApprove,
                Permission::CredentialManage,
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
