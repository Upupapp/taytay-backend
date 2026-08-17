<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Content\Jobs\PublishScheduledPosts;
use Modules\Notification\Application\Notifier;
use Modules\Notification\Jobs\DeliverNotification;
use Modules\Reporting\Infrastructure\Eloquent\ReportExport;
use Modules\Reporting\Jobs\PurgeExpiredExports;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\CacheKey;
use Modules\Shared\Application\ClientChannel;
use Modules\Shared\Application\DataScope;
use PHPUnit\Framework\Attributes\Test;

/**
 * The acceptance criteria of TAB 31, as tests.
 *
 *  1. **Retried jobs are safe or explicitly idempotent.**
 *  2. **No notification dependent on an uncommitted record is sent.**
 *  3. **Cache keys cannot leak one user's sensitive data to another.**
 */
final class ConsistencyTest extends KycTestCase
{
    use RefreshDatabase;

    // ── criterion 3: a cache key cannot cross a user or a scope ──────────────────────

    #[Test]
    public function two_actors_never_share_a_cache_key(): void
    {
        $first = $this->actor('11111111-1111-1111-1111-111111111111', ['request.view']);
        $second = $this->actor('22222222-2222-2222-2222-222222222222', ['request.view']);

        $this->assertNotSame(
            CacheKey::forActor($first, 'cases.list', ['page' => 1]),
            CacheKey::forActor($second, 'cases.list', ['page' => 1]),
        );
    }

    #[Test]
    public function the_same_actor_with_different_authority_gets_a_different_key(): void
    {
        $subject = '11111111-1111-1111-1111-111111111111';

        $narrow = $this->actor($subject, ['request.view']);
        $wide = $this->actor($subject, ['request.view', 'request.view-sensitive']);

        /*
         * THE CASE KEYING-BY-SUBJECT-ALONE MISSES. A caseworker granted `request.view-sensitive`
         * at 10am must not read 9am's cached answer, which was built without protection cases in
         * it — and, far worse, one whose grant was WITHDRAWN at 10am must not keep reading the
         * wider answer until the entry expires.
         *
         * The authority fingerprint means the old entry is simply never looked up again:
         * invalidation by construction rather than by finding and forgetting every key.
         */
        $this->assertNotSame(
            CacheKey::forActor($narrow, 'cases.list', []),
            CacheKey::forActor($wide, 'cases.list', []),
        );
    }

    #[Test]
    public function a_narrowed_scope_gets_a_different_key(): void
    {
        $subject = '11111111-1111-1111-1111-111111111111';

        $municipality = $this->actor($subject, ['resident.view'], DataScope::municipality());
        $oneBarangay = $this->actor($subject, ['resident.view'], DataScope::barangays([1]));

        // Same person, same permissions, different reach. A shared key would serve the whole
        // municipality's rows to somebody scoped to one barangay (ADR 0012).
        $this->assertNotSame(
            CacheKey::forActor($municipality, 'residents.list', []),
            CacheKey::forActor($oneBarangay, 'residents.list', []),
        );
    }

    #[Test]
    public function a_guest_does_not_share_the_public_key(): void
    {
        $guest = ActorContext::guest();

        /*
         * An endpoint that returns *something* to anonymous callers and *more* to authenticated
         * ones would otherwise serve the second answer to the first — the catalogue endpoints do
         * exactly that, narrowing to published for a caller without `services.view_unpublished`.
         */
        $this->assertNotSame(
            CacheKey::public('services.list'),
            CacheKey::forActor($guest, 'services.list'),
        );
    }

    #[Test]
    public function a_key_part_cannot_forge_another_key_by_containing_a_separator(): void
    {
        $actor = $this->actor('11111111-1111-1111-1111-111111111111', []);

        /*
         * A caller-supplied filter value reaching a key is the realistic path here. Without
         * normalisation, `?status=x:lguids:public:services` would let a search key masquerade as
         * a public one — cache poisoning through a query string.
         */
        $forged = CacheKey::forActor($actor, 'search', ['x:lguids:public:services.list']);

        $this->assertStringNotContainsString('lguids:public:services.list', $forged);
    }

    #[Test]
    public function the_mfa_challenge_is_never_stored_under_its_own_value(): void
    {
        Cache::flush();

        /*
         * Written directly rather than through a sign-in, because the property under test is WHERE
         * the value is stored, not how it got there — and a fixture that had to configure TOTP
         * first would fail for reasons unrelated to the key.
         */
        $challenge = (string) Str::uuid7();

        Cache::put(
            CacheKey::forOpaqueToken('identity.mfa-challenge', $challenge),
            ['account_id' => 1],
            now()->addMinutes(5),
        );

        // Anyone who could read the cache — an operator debugging, a memory dump, a misconfigured
        // Redis — must still be unable to complete somebody else's second factor.
        $this->assertNull(Cache::get('identity:mfa-challenge:'.$challenge));
        $this->assertNotNull(Cache::get(CacheKey::forOpaqueToken('identity.mfa-challenge', $challenge)));
    }

    // ── criterion 2: nothing is sent for an uncommitted record ───────────────────────

    #[Test]
    public function a_rolled_back_decision_leaves_no_notification_behind(): void
    {
        [$citizen] = $this->activeCitizenWithResident();

        try {
            DB::transaction(function () use ($citizen): void {
                app(Notifier::class)->notify(
                    (string) $citizen->uuid,
                    'case.status-changed',
                    ['title' => 'Update on your request', 'body' => 'Your request was approved.'],
                );

                throw new \RuntimeException('The decision was undone.');
            });
        } catch (\RuntimeException) {
            // Expected — the caller's transaction failed after the notification was recorded.
        }

        /*
         * THE ROW IS GONE, so there is nothing for a worker to deliver even if one ran.
         *
         * `DeliverNotification` loads the notification by UUID and returns silently when it is not
         * there, which is the second half of the guarantee: the dispatch and the row are written in
         * the same transaction, so a rollback removes both, and a job that somehow survived would
         * find nothing to send.
         */
        $this->assertDatabaseCount('notifications', 0);
    }

    #[Test]
    public function the_dispatch_timing_itself_is_asserted_structurally(): void
    {
        /*
         * AN HONEST NOTE ABOUT WHAT THIS SUITE CANNOT PROVE.
         *
         * The acceptance criterion is that no notification dependent on an uncommitted record is
         * sent. The row-level half is tested above. The *dispatch* half cannot be observed here:
         * `RefreshDatabase` wraps every test in its own transaction, so an inner `DB::transaction`
         * is a savepoint and the outermost commit never arrives — `Queue::fake()` therefore sees a
         * dispatch it can never resolve as "after commit", and asserting on it would be asserting
         * on the harness rather than on the code.
         *
         * So the mechanism is asserted directly instead: `Notifier` must queue with
         * `afterCommit()`. If somebody removes it, this fails and they read why — which is the
         * same trade `EventRegistrationTest` makes for the row lock it cannot exercise.
         */
        $source = (string) file_get_contents(
            base_path('modules/Notification/Application/Notifier.php'),
        );

        $this->assertMatchesRegularExpression(
            '/DeliverNotification::dispatch\([^;]*\)->afterCommit\(\)/s',
            $source,
            'Notifier must queue delivery with afterCommit(): a worker that picks the job up before '.
            'the transaction commits can tell a family their assistance was approved before it was '.
            '— or after a rollback, when it never was.',
        );
    }

    #[Test]
    public function every_job_dispatched_from_a_write_path_is_queued_after_commit(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(base_path('modules')) as $path) {
            $source = $this->withoutComments((string) file_get_contents($path));

            /*
             * Matched on the dispatch expression, not on the job class: `Job::dispatch(...)`
             * without `->afterCommit()` inside a transaction lets a worker pick the job up before
             * the row exists — or after a rollback, acting on a decision that never happened.
             *
             * The sweeps are exempt because they are dispatched by the SCHEDULER, which is not
             * inside a transaction and has no row to wait for.
             */
            if (preg_match_all('/(\w+)::dispatch\(/', $source, $matches) < 1) {
                continue;
            }

            foreach ($matches[1] as $job) {
                /*
                 * QUEUED JOBS ONLY. `Event::dispatch()` and `EventBus::dispatch()` are domain
                 * events, which run SYNCHRONOUSLY inside the caller's transaction on purpose —
                 * `ResidentMerged` repoints six modules' rows and must roll back with the merge
                 * (ADR 0019 §4). Requiring `afterCommit()` on those would be requiring the
                 * opposite of what they are for, and the first version of this scan did exactly
                 * that.
                 */
                if (! in_array($job, $this->queuedJobClasses(), true)) {
                    continue;
                }

                if (in_array($job, ['PublishScheduledPosts', 'SweepOverdueReferrals', 'PurgeExpiredExports'], true)) {
                    continue;
                }

                if (! preg_match('/'.preg_quote($job, '/').'::dispatch\([^;]*\)->afterCommit\(\)/s', $source)) {
                    $offenders[] = str_replace('\\', '/', substr($path, strlen(base_path()) + 1)).": {$job}";
                }
            }
        }

        // A scan that recognised no jobs at all would report a spotless codebase.
        $this->assertGreaterThanOrEqual(5, count($this->queuedJobClasses()), 'The job scan found nothing.');

        $this->assertSame([], array_unique($offenders), implode("\n", [
            'These jobs are dispatched without afterCommit():',
            '',
            ...array_unique($offenders),
            '',
            'A job queued inside a transaction can be picked up before the row exists — or after a',
            'rollback, acting on a decision that never happened. A family told their assistance was',
            'approved cannot be un-told (ADR 0036 §5).',
        ]));
    }

    // ── criterion 1: a retried job is safe ───────────────────────────────────────────

    #[Test]
    public function running_the_export_purge_twice_is_harmless(): void
    {
        Storage::fake('object-storage');
        config()->set('files.disk', 'object-storage');

        Storage::disk('object-storage')->put('exports/2026/08/old.csv', 'reference,name');

        $export = ReportExport::query()->create([
            'uuid' => (string) Str::uuid7(),
            'report' => 'case-summary',
            'format' => 'csv',
            'filters' => [],
            'permission_context' => [],
            'requested_by' => '11111111-1111-1111-1111-111111111111',
            'requested_at' => now()->subDays(9),
            'status' => 'ready',
            'is_person_level' => false,
            'stored_file_id' => 'exports/2026/08/old.csv',
            'expires_at' => now()->subDay(),
        ]);

        (new PurgeExpiredExports)->handle();

        $this->assertFalse(Storage::disk('object-storage')->exists('exports/2026/08/old.csv'));

        /*
         * THE SECOND RUN IS THE TEST. A retried sweep must not throw on a file it already deleted,
         * because a throw marks the job failed and the next scheduled run inherits a failure that
         * was actually a success.
         */
        (new PurgeExpiredExports)->handle();

        $export->refresh();

        // The FILE is gone; the ROW survives with everything explaining why the export existed.
        $this->assertSame('expired', (string) $export->status);
        $this->assertNull($export->stored_file_id);
        $this->assertSame('11111111-1111-1111-1111-111111111111', (string) $export->requested_by);
        $this->assertNotNull($export->requested_at);
    }

    #[Test]
    public function the_scheduled_publish_sweep_publishes_a_post_at_most_once(): void
    {
        Bus::fake();

        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $post = $this->postJson('/api/v1/admin/newsfeed', [
            'body' => 'The office will be closed on Monday.',
            'category' => 'advisory',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/newsfeed/{$post}/status", [
            'status' => 'scheduled',
            'publish_at' => now()->addMinute()->toIso8601ZuluString(),
        ])->assertOk();

        $this->travelTo(now()->addMinutes(2));

        $job = new PublishScheduledPosts;
        $job->handle();

        $publishedAt = DB::table('newsfeed_posts')->where('uuid', $post)->value('published_at');
        $this->assertNotNull($publishedAt);

        /*
         * Run again, as a retry or an overlapping sweep would. The conditional UPDATE matches
         * nothing the second time, so `published_at` does not move — a republished post would
         * reappear at the top of the feed with a new date, reading as the office announcing
         * something old as if it were new (ADR 0028 §4).
         */
        $job->handle();

        $this->assertSame($publishedAt, DB::table('newsfeed_posts')->where('uuid', $post)->value('published_at'));

        $this->travelBack();
    }

    #[Test]
    public function a_retried_registration_never_creates_a_second_seat(): void
    {
        Sanctum::actingAs($this->reviewer('lgu_admin'));

        $event = $this->postJson('/api/v1/admin/events', [
            'title' => 'Barangay feeding programme',
            'description' => 'Supplementary feeding.',
            'category' => 'health',
            'starts_at' => now()->addWeek()->toIso8601ZuluString(),
            'ends_at' => now()->addWeek()->addHours(3)->toIso8601ZuluString(),
            'venue_name' => 'Dolores covered court',
            'venue_address' => 'Dolores, Taytay, Rizal',
            'registration_required' => true,
            'capacity' => 10,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/events/{$event}/status", ['status' => 'published'])->assertOk();

        [$citizen] = $this->activeCitizenWithResident();
        Sanctum::actingAs($citizen);

        $headers = ['Idempotency-Key' => 'consistency-retry-1'];

        $first = $this->postJson("/api/v1/events/{$event}/registration", [], $headers)->assertCreated();
        $second = $this->postJson("/api/v1/events/{$event}/registration", [], $headers)->assertCreated();

        // Replayed verbatim — the caller cannot tell it from the original (ADR 0031 §3).
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('event_registrations', 1);
    }

    #[Test]
    public function the_idempotency_service_covers_every_operation_the_master_command_names(): void
    {
        $named = [
            'assistance submit' => 'POST /api/v1/me/assistance/drafts/{draft}/submit',
            'event register' => 'POST /api/v1/events/{event}/registration',
            'release confirmation' => 'POST /api/v1/admin/releases/{release}/confirmation',
        ];

        $sources = '';

        foreach ($this->phpFilesUnder(base_path('modules')) as $path) {
            $sources .= (string) file_get_contents($path);
        }

        foreach ($named as $operation => $endpoint) {
            /*
             * The endpoint string is what scopes the idempotency key, so it appearing in the code
             * is what proves the operation is covered — a key scoped to the wrong endpoint would
             * let one operation replay another's response.
             */
            $this->assertStringContainsString(
                $endpoint,
                $sources,
                "The master command names «{$operation}» as needing idempotency; no key is scoped to it.",
            );
        }
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $permissions
     */
    private function actor(string $subjectId, array $permissions, ?DataScope $scope = null): ActorContext
    {
        return ActorContext::authenticated(
            $subjectId,
            ['lgu_staff'],
            $permissions,
            ClientChannel::AdminConsole,
            $scope,
        );
    }

    /**
     * The short class name of every queued job in the system.
     *
     * Derived from the filesystem rather than listed, so a job added next year is covered without
     * anybody remembering to add it here.
     *
     * @return list<string>
     */
    private function queuedJobClasses(): array
    {
        $names = [];

        foreach ($this->phpFilesUnder(base_path('modules')) as $path) {
            if (str_contains(str_replace('\\', '/', $path), '/Jobs/')) {
                $names[] = basename($path, '.php');
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
