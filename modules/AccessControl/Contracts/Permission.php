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

    /** Issue or revoke a digital ID credential. */
    case CredentialManage = 'credential.manage';

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
