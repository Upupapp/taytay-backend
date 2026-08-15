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
