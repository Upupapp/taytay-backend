<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

use Modules\Audit\Domain\AuditRisk;

/**
 * Which audited acts are high-risk (ADR 0034 §2).
 *
 * THE LIST IS THE MASTER COMMAND'S, TRANSCRIBED. It names them explicitly — auth and security
 * events, resident merge, verification, sensitive document download, case status, assessment,
 * approval and release, PII export, role and permission change, newsfeed moderation and
 * publication, event export and attendance — and each line below traces to one of those.
 *
 * WHY A TABLE RATHER THAN A JUDGEMENT AT EACH CALL SITE. "Is this one important?" asked eighty
 * times gets eighty answers, and the answers drift with whoever is writing that module that week.
 * Asked once, in a file somebody can read end to end, it is reviewable — and a compliance reviewer
 * can check the LGU's list against this one without reading eighty call sites.
 *
 * THE DEFAULT IS `routine`, NOT `high`, AND THAT IS DELIBERATE. Marking everything high would mean
 * network identifiers on every routine read — a movement log of the office's own staff, thousands
 * of rows a day — and a "high-risk" digest nobody reads because it contains everything. Under-
 * classifying a genuinely sensitive act is the worse error, which is why the list is explicit
 * rather than heuristic and why `AuditRiskCoverageTest` exercises each named act end to end.
 */
final class AuditActionCatalog
{
    /**
     * Exact action strings that are always high-risk.
     *
     * @var list<string>
     */
    private const HIGH_RISK = [
        // ── auth and security (master command: "auth/security events") ──
        'identity.account-locked',
        'identity.sign-in-blocked',
        'identity.sign-in-failed',
        'identity.password-reset-completed',
        'identity.password-reset-replayed',
        'identity.mfa-disabled',
        'identity.mfa-recovery-used',
        'identity.tokens-revoked-all',
        'identity.staff-provisioned',
        'identity.staff-deactivated',

        // ── role and permission change ──
        'access.role-assigned',
        'access.role-revoked',
        'access.barangay-granted',
        'access.barangay-grant-revoked',

        // ── resident merge and verification ──
        'resident.merged',
        'resident.verified',
        'resident.deactivated',
        'kyc.case-approved',
        'kyc.case-rejected',

        // ── credentials ──
        'credential.issued',
        'credential.revoked',

        // ── the protection tier ──
        'safeguarding.opened',
        'safeguarding.closed',
        'referral.sent',
        'referral.protected-disclosed',

        // ── privacy governance (this TAB) ──
        'privacy.legal-hold-placed',
        'privacy.legal-hold-lifted',
        'privacy.consent-withdrawn',
        'privacy.retention-purged',
    ];

    /**
     * Prefixes whose every action is high-risk.
     *
     * Needed because several actions are composed at the call site — `'task.'.$status->value`,
     * `'newsfeed.'.$target->value` — so an exact list could not name them all, and a list that
     * named some would classify the rest as routine WITHOUT ANYBODY NOTICING. A prefix covers a
     * family including the members added later, which is the failure mode that matters.
     *
     * @var list<string>
     */
    private const HIGH_RISK_PREFIXES = [
        // Every document read of somebody's file, and every outward share.
        'document.',
        // Every export. A copy of the database leaving this application's control (ADR 0026 §3).
        'report.',
        // Case status, assessment, approval, release — the whole welfare lifecycle, because each
        // step commits or refuses public money to a named household.
        'case.',
        'release.',
        'assessment.',
        // Newsfeed moderation and publication: what the municipality said, and what it removed.
        'newsfeed.',
        // Event export and attendance. Also publication, for the same reason as the newsfeed.
        'event.attendance',
        'event.registrant-export',
        'event.published',
        'event.cancelled',
        // Anything the media pipeline makes reachable by the whole internet.
        'media.',
    ];

    public static function riskFor(string $action): AuditRisk
    {
        if (in_array($action, self::HIGH_RISK, true)) {
            return AuditRisk::High;
        }

        foreach (self::HIGH_RISK_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return AuditRisk::High;
            }
        }

        return AuditRisk::Routine;
    }

    /**
     * The declared high-risk vocabulary, for the coverage test and for a compliance reviewer.
     *
     * @return array{exact: list<string>, prefixes: list<string>}
     */
    public static function declared(): array
    {
        return ['exact' => self::HIGH_RISK, 'prefixes' => self::HIGH_RISK_PREFIXES];
    }
}
