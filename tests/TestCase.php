<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccessControl\Domain\Role;
use Modules\Identity\Infrastructure\Eloquent\Account;

abstract class TestCase extends BaseTestCase
{
    /**
     * Records which response fields are ever non-null, so a field that is ALWAYS null can be
     * found. OFF unless `FIELD_PROBE=1`, and it changes nothing about how a test behaves.
     *
     * ── THE DEFECT CLASS THIS EXISTS FOR ─────────────────────────────────────────────
     *
     * `report_reasons` on the moderation queue was null for every row for as long as the feature
     * had existed: `reportedComments()` counted the relation without loading it, and the
     * projection renders the reasons only when it IS loaded. No error, no slow page, green suite
     * — a moderator simply never saw why anything was reported.
     *
     * **A query budget cannot see this.** A silently-null field costs no queries; that is exactly
     * what makes it silent. Nor can a static scan: the field is declared, spelled correctly, and
     * present in the payload. The only signal is that across every response the whole suite
     * produces, it is never once populated.
     *
     * Run it as:
     *
     *     FIELD_PROBE=1 php artisan test
     *     php artisan lguids:field-probe
     *
     * A field reported here is a QUESTION, not a defect. Many are legitimately absent in every
     * scenario the suite happens to build — an optional note nobody set, a cancellation reason on
     * a case nobody cancelled. The output is a list to read, and the ones worth chasing are
     * fields whose whole purpose is to carry information a screen shows.
     */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $response = parent::json($method, $uri, $data, $headers, $options);

        if (getenv('FIELD_PROBE') === '1') {
            $this->recordFieldObservations((string) $uri, $response->getContent());
        }

        return $response;
    }

    private function recordFieldObservations(string $uri, string|false $body): void
    {
        if ($body === false || $body === '' || $body[0] !== '{') {
            return;
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! isset($decoded['data'])) {
            return;
        }

        // The path without ids, so every call to one endpoint aggregates together.
        $endpoint = preg_replace('~/[0-9a-f]{8}-[0-9a-f-]{27,}~i', '/{id}', parse_url($uri, PHP_URL_PATH) ?? $uri);

        $rows = $decoded['data'];
        $rows = array_is_list($rows) ? $rows : [$rows];

        $lines = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($row as $field => $value) {
                // `1` means seen non-null at least once here; `0` means seen and null.
                // Double quotes: PHP does not interpret an escape inside single quotes, and a
                // literal backslash-t made the first collection unparseable.
                $lines[] = $endpoint."\t".$field."\t".($value === null || $value === [] ? '0' : '1');
            }
        }

        if ($lines !== []) {
            file_put_contents(
                storage_path('framework/testing/field-probe.tsv'),
                implode(PHP_EOL, $lines).PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        }
    }

    /**
     * Grants a role to an account through the canonical `role_assignments` table.
     *
     * Deliberately writes the row rather than stubbing the repository: the point of most
     * of these tests is that authority is resolved server-side from persisted state, and
     * a stub would prove only that the stub works.
     */
    protected function grantRole(Account $account, string $role, ?int $barangayId = null): void
    {
        /*
         * AN UNKNOWN ROLE FAILS LOUDLY, because it used to fail silently and in the worst
         * direction.
         *
         * `role_assignments.role` is a plain string, so granting `social_worker` — a job title
         * this system has no role for — wrote a row that resolved to **no permissions at all**.
         * Every test using it asserted a refusal, so every one of them passed: not because the
         * permission boundary held, but because the actor had nothing. Six tests written across
         * TABs 07, 08 and 17 were weaker than they read.
         *
         * A test asserting a 403 is exactly where this hides, because the wrong answer and the
         * right answer look identical.
         */
        if (! in_array($role, array_map(static fn (Role $r): string => $r->value, Role::cases()), true)) {
            throw new \InvalidArgumentException(
                "There is no role '{$role}'. A test granting one asserts nothing: the account gets "
                .'no permissions, and every refusal it then checks passes for the wrong reason. '
                .'Roles: '.implode(', ', array_map(static fn (Role $r): string => $r->value, Role::cases())),
            );
        }

        DB::table('role_assignments')->insert([
            'uuid' => (string) Str::uuid7(),
            'subject_id' => $account->uuid,
            'role' => $role,
            'scope_type' => $barangayId === null ? 'all-barangays' : 'own-barangay',
            'barangay_id' => $barangayId,
            'valid_from' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
