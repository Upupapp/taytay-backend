<?php

declare(strict_types=1);

namespace Modules\Shared\Support;

/**
 * The declared citizen API surface (ADR 0032 §1).
 *
 * WHY A DECLARED LIST RATHER THAN A RULE. The obvious way to answer "is this a citizen endpoint?"
 * is a rule — everything not under `admin/`. That rule is already wrong in this codebase:
 * `staff/*` and `tasks/*` are staff endpoints with no `admin` prefix, and would be classified as
 * citizen-facing by any prefix test. A rule that is already wrong on day one is a rule that will
 * be wrong silently on day four hundred.
 *
 * So every route in the API is classified, by hand, into exactly one of two lists — and
 * `CitizenSurfaceTest` fails the build for a route in neither. **That is the "by construction" in
 * "internal admin-only fields are absent by construction":** a new endpoint cannot join the API
 * without somebody stating which audience it serves, and stating "citizen" subjects it
 * immediately to the leak scan below.
 *
 * The alternative — a scan that only checks routes it happens to know about — is the failure mode
 * this project has already been bitten by twice: a test that could only find what it rendered.
 */
final class CitizenSurface
{
    /**
     * Route names a resident may legitimately reach.
     *
     * Grouped by the master command's own list of core citizen endpoints, so the two can be read
     * against each other.
     *
     * @return list<string>
     */
    public static function citizenRouteNames(): array
    {
        return [
            // ── platform ──
            'v1.health',
            'v1.app.bootstrap',
            /*
             * The barangay directory. A resident reaches it before they have an
             * account, because `me/kyc` asks for a barangay and the first thing
             * onboarding asks for is an address.
             *
             * Declared here so the leak scan covers it: the projection is name,
             * code, UUID and PSGC code, and nothing about anybody — and the way
             * it stays that way is being scanned rather than being remembered.
             */
            'v1.barangays.index',

            // ── account, session, device ──
            'v1.auth.otp.request',
            'v1.auth.otp.verify',
            'v1.auth.password.forgot',
            'v1.auth.password.reset',
            'v1.auth.tokens.store',
            'v1.auth.tokens.mfa',
            'v1.auth.tokens.destroy',
            'v1.me.show',
            'v1.me.sessions.index',
            'v1.me.sessions.destroy',
            'v1.me.sessions.revoke-all',
            'v1.me.devices.index',
            'v1.me.devices.store',
            'v1.me.devices.destroy',
            'v1.me.mfa.begin',
            'v1.me.mfa.confirm',
            'v1.me.mfa.destroy',
            'v1.me.mfa.recovery-codes',
            'v1.me.contact.request',
            'v1.me.contact.confirm',

            // ── profile and corrections ──
            'v1.me.profile.show',
            'v1.me.profile.corrections.index',
            'v1.me.profile.corrections.store',
            'v1.me.profile.corrections.withdraw',
            'v1.me.household.show',

            // ── verification / KYC ──
            'v1.me.kyc.show',
            'v1.me.kyc.register',
            'v1.me.kyc.submit',

            // ── digital ID ──
            'v1.me.credential.show',
            'v1.me.credential.qr',
            'v1.credential.verify',

            // ── catalogue ──
            'v1.services.index',
            'v1.programs.index',
            'v1.programs.show',

            // ── assistance ──
            'v1.me.assistance.drafts.index',
            'v1.me.assistance.drafts.store',
            'v1.me.assistance.drafts.update',
            'v1.me.assistance.drafts.destroy',
            'v1.me.assistance.drafts.submit',
            'v1.me.cases.index',
            'v1.me.cases.show',
            'v1.me.cases.cancel',
            'v1.me.assistance-history',
            'v1.me.referrals.index',

            // ── requirements and documents ──
            'v1.me.cases.requirements.index',
            'v1.me.cases.requirements.documents.store',
            'v1.me.cases.requirements.documents.access',
            'v1.documents.download',

            // ── newsfeed ──
            'v1.newsfeed.index',
            'v1.newsfeed.show',
            'v1.newsfeed.comments.index',
            'v1.newsfeed.comments.store',
            'v1.newsfeed.comments.update',
            'v1.newsfeed.comments.destroy',
            'v1.newsfeed.comments.report',
            'v1.newsfeed.react',
            'v1.newsfeed.unreact',
            'v1.newsfeed.share',

            // ── events ──
            'v1.events.index',
            'v1.events.show',
            'v1.events.register',
            'v1.events.withdraw',
            'v1.me.event-registrations.index',
            'v1.me.event-registrations.show',

            /*
             * ── privacy ──
             *
             * The notice is PUBLIC: one that required an account to read would be one a person
             * could not consult before deciding whether to create an account (ADR 0034 §4).
             *
             * The consent routes are scoped at the query to the token's subject and take no
             * identifier at all, so a resident manages only their own.
             */
            'v1.privacy.notice',
            'v1.me.privacy.acknowledge',
            'v1.me.privacy.consents.index',
            'v1.me.privacy.consents.store',
            'v1.me.privacy.consents.withdraw',

            // ── notifications ──
            'v1.me.notifications.index',
            'v1.me.notifications.read',
            'v1.me.notifications.read-all',
            'v1.me.notification-preferences.index',
            'v1.me.notification-preferences.update',
        ];
    }

    /**
     * Staff endpoints that do NOT sit behind the `admin/` prefix.
     *
     * They are not defects — `Article 3` is explicit that a URL prefix grants nothing and that
     * authorization is a server-side decision — but they are exactly why the citizen surface
     * cannot be derived from a path rule. Listed so the classification is complete.
     *
     * @return list<string>
     */
    public static function staffRouteNamesOutsideAdminPrefix(): array
    {
        return [
            'v1.staff.index',
            'v1.staff.show',
            'v1.staff.store',
            'v1.staff.deactivate',
            'v1.staff.roles.store',
            'v1.staff.roles.destroy',
            'v1.staff.barangays.store',
            'v1.staff.barangays.destroy',
            'v1.staff.catalog',
            'v1.tasks.index',
            'v1.tasks.show',
            'v1.tasks.store',
            'v1.tasks.assign',
            'v1.tasks.close',
        ];
    }

    /**
     * The narrow, stated exceptions to the list below.
     *
     * KEYED BY URL PATH, NOT GLOBAL. An exemption that turned a field off everywhere would defeat
     * the list; this one says "on this endpoint, and only this one, that field is the answer to
     * the question being asked".
     *
     * **`GET /api/v1/me` returns the caller's own `permissions` and `roles`, and that is correct.**
     * It was flagged by the scan when the list was first written, and the flag was worth thinking
     * about rather than suppressing: Article 3.4 forbids the server *trusting* an authority list
     * that arrives from a client, which is a different thing from telling a client what it holds.
     * The admin console cannot render a menu without knowing its own authority, and a resident's
     * own list tells them nothing they did not already know by being themselves.
     *
     * What the entry in the list still catches — and what it is for — is an authority list turning
     * up inside a *record*: a case, a comment, a registration. That is where it would be a
     * disclosure about the office rather than a statement about the caller.
     *
     * @return array<string, list<string>>
     */
    public static function fieldExemptions(): array
    {
        return [
            'api/v1/me' => ['permissions', 'roles'],
        ];
    }

    /**
     * Field names that must never appear in a response a resident can read, at any depth.
     *
     * EVERY ENTRY IS HERE BECAUSE OF A REAL CONSEQUENCE, not because it sounds internal:
     *
     *  * `staff_notes`, `internal_notes`, `moderation_reason`, `moderated_by` — written in the
     *    office's voice *about* the person, and never for them to read;
     *  * `assigned_to`, `caseworker_id`, `endorsed_by`, `approved_by` — naming the individual
     *    officer who decided somebody's case turns a decision into a person to confront;
     *  * `safeguarding`, `vulnerability_score`, `risk_score` — a derived judgement about a
     *    household, which ADR 0015 §3 already says gates nothing and must not be shown as if it
     *    did;
     *  * `permission_context`, `permissions`, `roles` — a client that receives an authority list
     *    is a client somebody will eventually trust to send one back (Article 3.4);
     *  * `deleted_at` — the existence of a soft-deleted record is itself a disclosure;
     *  * `password`, `secret`, `token`, `signing_key`, `private_key` — Article 5.5 and 5.6.
     *
     * The list is deliberately about *names*, so a projection that starts returning a new field
     * with one of these names fails even if the value happens to be null that day.
     *
     * @return list<string>
     */
    public static function fieldsForbiddenToCitizens(): array
    {
        return [
            'staff_notes',
            'internal_notes',
            'internal_reason',
            'moderation_reason',
            'moderation_state',
            'moderated_by',
            'moderated_at',
            'assigned_to',
            'caseworker_id',
            'endorsed_by',
            'approved_by',
            'rejected_by',
            'cancelled_by',
            'safeguarding',
            'safeguarding_factors',
            'vulnerability_score',
            'risk_score',
            'permission_context',
            'permissions',
            'roles',
            'deleted_at',
            'password',
            'password_hash',
            'secret',
            'mfa_secret',
            'signing_key',
            'private_key',
            'api_token',
            'access_token',
        ];
    }
}
