<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Credential\Application\CredentialService;
use Modules\Credential\Contracts\CredentialStatus;
use Modules\Credential\Infrastructure\Eloquent\Credential;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\ResidentProfile\Contracts\VerificationTier;
use PHPUnit\Framework\Attributes\Test;

/**
 * Digital ID: the feature flag, issuance rules, and the QR payload.
 *
 * The QR tests are the ones that matter. A QR code is photographed, screenshotted,
 * forwarded and left on counters — so the payload must be worthless to anyone who captures
 * it, and must not describe the person holding it.
 */
final class DigitalIdTest extends KycTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The feature ships off; these tests turn it on deliberately.
        config(['credential.digital_id.enabled' => true]);
    }

    // ── the feature flag ──────────────────────────────────────────────────────────────

    #[Test]
    public function every_route_reports_not_found_while_the_feature_is_off(): void
    {
        config(['credential.digital_id.enabled' => false]);

        [$account] = $this->activeCitizenWithResident();
        Sanctum::actingAs($account);

        // 404, not 403: a feature that is not live should look absent rather than
        // forbidden — "forbidden" tells a caller it exists and is worth pursuing.
        $this->getJson('/api/v1/me/credential')->assertNotFound();
        $this->postJson('/api/v1/me/credential/qr')->assertNotFound();
        $this->postJson('/api/v1/credential-verifications', ['payload' => 'x.y'])->assertNotFound();

        Sanctum::actingAs($this->reviewer());
        $this->postJson('/api/v1/admin/credentials', ['resident_id' => 'anything'])->assertNotFound();
    }

    // ── issuance ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_credential_is_only_issued_to_a_fully_verified_resident(): void
    {
        $resident = $this->existingResident([
            'verification_tier' => VerificationTier::PartiallyVerified,
            'verified_at' => null,
        ]);

        Sanctum::actingAs($this->reviewer());

        // Partial verification is deliberately enough to receive assistance, and not
        // enough to be handed an identity document.
        $this->postJson('/api/v1/admin/credentials', ['resident_id' => $resident->uuid])
            ->assertStatus(409);

        $this->assertSame(0, Credential::query()->count());
    }

    #[Test]
    public function issuing_twice_returns_the_same_credential(): void
    {
        $resident = $this->existingResident();
        Sanctum::actingAs($this->reviewer());

        $first = $this->postJson('/api/v1/admin/credentials', ['resident_id' => $resident->uuid])
            ->assertCreated()->json('data.serial');
        $second = $this->postJson('/api/v1/admin/credentials', ['resident_id' => $resident->uuid])
            ->assertCreated()->json('data.serial');

        // A retried request must not leave two valid cards in circulation.
        $this->assertSame($first, $second);
        $this->assertSame(1, Credential::query()->count());
    }

    #[Test]
    public function a_citizen_cannot_issue_a_credential(): void
    {
        $resident = $this->existingResident();
        [$account] = $this->activeCitizenWithResident();

        Sanctum::actingAs($account);

        $this->postJson('/api/v1/admin/credentials', ['resident_id' => $resident->uuid])
            ->assertForbidden();
    }

    #[Test]
    public function serials_are_not_sequential(): void
    {
        Sanctum::actingAs($this->reviewer());

        $serials = [];

        foreach (['Ana', 'Ben', 'Carl'] as $name) {
            $resident = $this->existingResident(['first_name' => $name, 'last_name' => 'Reyes']);
            $serials[] = $this->postJson('/api/v1/admin/credentials', ['resident_id' => $resident->uuid])
                ->assertCreated()->json('data.serial');
        }

        // A sequential serial tells any holder how many IDs the LGU has issued and lets
        // them guess their neighbour's.
        $this->assertCount(3, array_unique($serials));
        $this->assertNotSame(1, (int) preg_replace('/\D/', '', (string) $serials[0]));
    }

    // ── the QR payload ────────────────────────────────────────────────────────────────

    #[Test]
    public function the_qr_payload_contains_no_personal_information(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $this->issueFor($resident);

        Sanctum::actingAs($account);
        $payload = (string) $this->postJson('/api/v1/me/credential/qr')->assertOk()->json('data.payload');

        $decoded = base64_decode(strtr(explode('.', $payload)[0], '-_', '+/'), true);

        // THE ACCEPTANCE CRITERION. The payload is a handle, not a copy of the record.
        foreach (['Maria', 'Dela Cruz', 'Santos', '1990-01-15', 'Rizal Street', 'female'] as $personal) {
            $this->assertStringNotContainsString($personal, (string) $decoded);
            $this->assertStringNotContainsString($personal, $payload);
        }

        // What it does carry: a version, a serial, an expiry, a nonce and a key id.
        $body = json_decode((string) $decoded, true);
        $this->assertSame(['v', 's', 'e', 'n', 'k'], array_keys($body));
    }

    #[Test]
    public function a_tampered_payload_is_rejected(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $this->issueFor($resident);

        Sanctum::actingAs($account);
        $payload = (string) $this->postJson('/api/v1/me/credential/qr')->assertOk()->json('data.payload');

        [$body, $signature] = explode('.', $payload);

        // Flip the signature: the body is readable, but it cannot be re-sealed without the
        // server's key.
        $this->postJson('/api/v1/credential-verifications', ['payload' => $body.'.'.strrev($signature)])
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.outcome', 'signature-invalid');
    }

    #[Test]
    public function a_forged_payload_for_a_real_serial_is_rejected(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $credential = $this->issueFor($resident);

        // An attacker who knows a serial still cannot mint a payload for it.
        $forged = rtrim(strtr(base64_encode((string) json_encode([
            'v' => 1, 's' => $credential->serial, 'e' => now()->addHour()->getTimestamp(), 'n' => 'forged', 'k' => 'local',
        ])), '+/', '-_'), '=');

        Sanctum::actingAs($account);
        $this->postJson('/api/v1/credential-verifications', ['payload' => $forged.'.notavalidsignature'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'signature-invalid');
    }

    #[Test]
    public function a_payload_cannot_be_replayed(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $this->issueFor($resident);

        Sanctum::actingAs($account);
        $payload = (string) $this->postJson('/api/v1/me/credential/qr')->assertOk()->json('data.payload');

        $this->postJson('/api/v1/credential-verifications', ['payload' => $payload])
            ->assertOk()->assertJsonPath('data.valid', true);

        // A code photographed over someone's shoulder is useless: its nonce is spent.
        $this->postJson('/api/v1/credential-verifications', ['payload' => $payload])
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.outcome', 'replayed');
    }

    #[Test]
    public function an_expired_payload_is_rejected(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $this->issueFor($resident);

        Sanctum::actingAs($account);
        $payload = (string) $this->postJson('/api/v1/me/credential/qr')->assertOk()->json('data.payload');

        $this->travel((int) config('credential.qr.ttl_seconds') + 5)->seconds();

        $this->postJson('/api/v1/credential-verifications', ['payload' => $payload])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'expired');
    }

    #[Test]
    public function a_revoked_credential_stops_verifying(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $credential = $this->issueFor($resident);

        Sanctum::actingAs($this->reviewer());
        $this->postJson("/api/v1/admin/credentials/{$credential->uuid}/revoke", ['reason' => 'Card reported lost'])
            ->assertOk();

        Sanctum::actingAs($account);

        // Minting is refused, and even a payload minted before revocation must not pass.
        $this->postJson('/api/v1/me/credential/qr')->assertStatus(409);
        $this->assertSame(CredentialStatus::Revoked, $credential->refresh()->status);
    }

    #[Test]
    public function a_payload_minted_before_revocation_is_refused_afterwards(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $credential = $this->issueFor($resident);

        Sanctum::actingAs($account);
        $payload = (string) $this->postJson('/api/v1/me/credential/qr')->assertOk()->json('data.payload');

        app(CredentialService::class)->revoke($credential, 'lost', null);

        // Validity is decided at scan time against current state, never by the payload.
        $this->postJson('/api/v1/credential-verifications', ['payload' => $payload])
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.outcome', 'revoked');
    }

    // ── the verification response ─────────────────────────────────────────────────────

    #[Test]
    public function the_verification_response_discloses_only_a_status_and_a_display_name(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $this->issueFor($resident);

        Sanctum::actingAs($account);
        $payload = (string) $this->postJson('/api/v1/me/credential/qr')->assertOk()->json('data.payload');

        $data = $this->postJson('/api/v1/credential-verifications', ['payload' => $payload])
            ->assertOk()->json('data');

        // Exactly enough for a human to match a face to a card.
        $this->assertSame(['outcome', 'valid', 'serial', 'expires_at', 'holder_name'], array_keys($data));
        $this->assertSame('Maria Dela Cruz', $data['holder_name']);

        // And nothing that describes the person's circumstances. A kiosk operator is not
        // an LGU case worker.
        $body = (string) json_encode($data);
        foreach (['1990-01-15', 'Rizal Street', 'Santos', 'barangay', 'income', 'sector'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    #[Test]
    public function every_scan_is_recorded_including_the_failures(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $this->issueFor($resident);

        Sanctum::actingAs($account);
        $payload = (string) $this->postJson('/api/v1/me/credential/qr')->assertOk()->json('data.payload');

        $this->postJson('/api/v1/credential-verifications', ['payload' => $payload])->assertOk();
        $this->postJson('/api/v1/credential-verifications', ['payload' => 'garbage.payload'])->assertOk();

        $outcomes = DB::table('credential_verifications')->pluck('outcome')->all();

        // A forged scan is exactly the event an investigation later asks about.
        $this->assertContains('valid', $outcomes);
        $this->assertContains('signature-invalid', $outcomes);
    }

    #[Test]
    public function the_stored_nonce_is_hashed(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $this->issueFor($resident);

        Sanctum::actingAs($account);
        $payload = (string) $this->postJson('/api/v1/me/credential/qr')->assertOk()->json('data.payload');
        $nonce = json_decode((string) base64_decode(strtr(explode('.', $payload)[0], '-_', '+/'), true), true)['n'];

        $this->postJson('/api/v1/credential-verifications', ['payload' => $payload])->assertOk();

        $stored = (string) DB::table('credential_verifications')->where('outcome', 'valid')->value('nonce_hash');

        $this->assertNotSame($nonce, $stored);
        $this->assertSame(hash('sha256', $nonce), $stored);
    }

    #[Test]
    public function a_holder_cannot_reach_another_persons_credential(): void
    {
        [, $residentA] = $this->activeCitizenWithResident();
        $this->issueFor($residentA);

        // A second citizen with no resident link at all.
        $other = $this->citizen(['mobile_number' => '+639170008888']);
        Sanctum::actingAs($other);

        // Resolved from the token, so there is no identifier to tamper with.
        $this->getJson('/api/v1/me/credential')->assertNotFound();
        $this->postJson('/api/v1/me/credential/qr')->assertNotFound();
    }

    #[Test]
    public function signing_keys_are_never_returned_by_any_endpoint(): void
    {
        [$account, $resident] = $this->activeCitizenWithResident();
        $this->issueFor($resident);

        Sanctum::actingAs($account);

        $key = (string) config('credential.qr.keys.local');
        $bodies = [
            (string) $this->getJson('/api/v1/me/credential')->getContent(),
            (string) $this->postJson('/api/v1/me/credential/qr')->getContent(),
        ];

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString($key, $body);
        }
    }

    private function issueFor($resident): Credential
    {
        return app(CredentialService::class)->issue(
            app(ResidentDirectory::class)->summaryFor($resident->uuid),
            null,
        );
    }
}
