<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Notification\Infrastructure\Transactional\LogTransactionalSender;
use Modules\Notification\Infrastructure\Transactional\NullTransactionalSender;
use Modules\Shared\Contracts\TransactionalDelivery;
use Modules\Shared\Contracts\TransactionalMessage;
use Modules\Shared\Contracts\TransactionalSender;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F16 — a sign-in code is issued, recorded, and reaches a person.
 *
 * ---
 *
 * **This is the test that did not exist, and its absence is why the defect survived.** Eighteen
 * authentication tests passed against a platform on which **no resident could sign in**: the code
 * was minted, hashed, stored and discarded, every step asserted, and nothing anywhere asked
 * whether it left the building. `POST auth/otp` returned 202 and 202 is what the tests checked.
 *
 * So the assertion that matters here is not "a message was sent". It is that **the text a person
 * receives contains a code that actually signs them in** — proven by reading it out of the
 * captured message and exchanging it at `auth/otp/verify`. Nothing weaker distinguishes a working
 * delivery from a message carrying the wrong six digits.
 */
final class SignInCodeDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('identity-sign-in');
    }

    /**
     * Captures what would have been sent, and persists nothing — like the real thing.
     */
    private function captureSender(): object
    {
        $sender = new class implements TransactionalSender
        {
            /** @var list<TransactionalMessage> */
            public array $sent = [];

            public function name(): string
            {
                return 'capture';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(TransactionalMessage $message): TransactionalDelivery
            {
                $this->sent[] = $message;

                return TransactionalDelivery::sent('capture-1');
            }
        };

        $this->app->instance(TransactionalSender::class, $sender);

        return $sender;
    }

    /**
     * The one message that was sent, or a failure that says so.
     *
     * A test that dies on `Undefined array key 0` when nothing was sent reports an array error
     * where it should report F16. The failure message is the part a future reader gets.
     *
     * @param  object{sent: list<TransactionalMessage>}  $sender
     */
    private function onlyMessage(object $sender): TransactionalMessage
    {
        $this->assertCount(1, $sender->sent, 'Nothing was sent. This is F16 exactly.');

        return $sender->sent[0];
    }

    /**
     * The six digits inside the message a resident would receive.
     */
    private function codeIn(TransactionalMessage $message): string
    {
        preg_match('/\b(\d{6})\b/', $message->text, $matches);
        $this->assertNotEmpty($matches, 'The message carries no six-digit code.');

        return $matches[1];
    }

    #[Test]
    public function the_code_a_resident_receives_is_the_code_that_signs_them_in(): void
    {
        $sender = $this->captureSender();
        Account::factory()->create(['mobile_number' => '+639170000001']);

        $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639170000001'], [
            'X-Client-Channel' => 'citizen-mobile',
        ])->assertStatus(202);

        $message = $this->onlyMessage($sender);
        $this->assertSame('+639170000001', $message->recipient);
        $this->assertSame('sign-in-code', $message->purpose);

        // The whole point. A message carrying the wrong six digits passes every weaker
        // assertion in this file and fails a resident standing at a counter.
        $this->postJson('/api/v1/auth/otp/verify', [
            'mobile_number' => '+639170000001',
            'code' => $this->codeIn($message),
        ], ['X-Client-Channel' => 'citizen-mobile'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);
    }

    #[Test]
    public function the_message_names_the_municipality_states_the_expiry_and_carries_no_link(): void
    {
        $sender = $this->captureSender();
        Account::factory()->create(['mobile_number' => '+639170000002']);

        $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639170000002']);

        $text = $this->onlyMessage($sender)->text;
        $this->assertStringContainsString('Taytay', $text);
        $this->assertStringContainsString('expires', $text);
        $this->assertStringContainsString('did not ask', $text);

        // A one-time code arriving with a tappable URL trains residents to tap links in texts
        // claiming to be from their LGU, which is the exact shape of the attack the code exists
        // to make harder.
        $this->assertStringNotContainsString('http', $text);
    }

    #[Test]
    public function an_unregistered_number_sends_nothing_and_is_answered_identically(): void
    {
        $sender = $this->captureSender();

        $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639179999999'])
            ->assertStatus(202)
            ->assertJsonPath('data.message', 'If that number is registered, a code has been sent to it.');

        // Nothing sent, same answer. Any difference here — a status, a delay, a wording — turns
        // sign-in into a lookup for whether somebody holds an account with the LGU.
        $this->assertSame([], $sender->sent);
    }

    #[Test]
    public function the_code_is_never_in_the_response(): void
    {
        $sender = $this->captureSender();
        Account::factory()->create(['mobile_number' => '+639170000003']);

        $response = $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639170000003']);
        $this->assertStringNotContainsString(
            $this->codeIn($this->onlyMessage($sender)),
            $response->getContent(),
        );
    }

    #[Test]
    public function delivery_does_not_persist_the_code_anywhere(): void
    {
        $sender = $this->captureSender();
        Account::factory()->create(['mobile_number' => '+639170000004']);

        $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639170000004']);
        $code = $this->codeIn($this->onlyMessage($sender));

        // The reason this does not travel as an OutboundNotification: `Notifier::notify()`
        // persists title and body, and that row is read back over an authenticated API. A
        // one-time code stored there is a credential sitting in an inbox.
        $this->assertSame(0, DB::table('notifications')->count());

        // The hash is stored, and must be — that is how the code is checked. The code itself
        // must not be anywhere.
        $this->assertSame(1, DB::table('verification_codes')->count());
        foreach (DB::table('verification_codes')->get() as $row) {
            foreach ((array) $row as $value) {
                if (is_string($value)) {
                    $this->assertStringNotContainsString($code, $value);
                }
            }
        }
    }

    #[Test]
    public function whether_the_code_left_is_recorded_where_an_operator_can_see_it(): void
    {
        $this->app->instance(TransactionalSender::class, new NullTransactionalSender);
        Account::factory()->create(['mobile_number' => '+639170000005']);

        $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639170000005'])->assertStatus(202);

        // "Issued but not delivered" is precisely the state F16 described, and it survived an
        // entire integration sequence because nothing wrote it down anywhere an operator looks.
        $actions = DB::table('audit_entries')->pluck('action')->all();
        $this->assertContains('identity.code-undelivered', $actions);
        $this->assertNotContains('identity.code-sent', $actions);
    }

    #[Test]
    public function a_deployment_with_no_sender_still_refuses_to_tell_the_client(): void
    {
        $this->app->instance(TransactionalSender::class, new NullTransactionalSender);
        Account::factory()->create(['mobile_number' => '+639170000006']);

        // Same 202, same body. An operator learns from the audit trail that nobody can sign in;
        // a client learns nothing, because the alternative is an account-existence oracle.
        $this->postJson('/api/v1/auth/otp', ['mobile_number' => '+639170000006'])
            ->assertStatus(202)
            ->assertJsonPath('data.message', 'If that number is registered, a code has been sent to it.');
    }

    // ── the message object itself ─────────────────────────────────────────────────────

    #[Test]
    public function a_message_never_renders_its_own_text_or_its_full_recipient(): void
    {
        $message = new TransactionalMessage('+639170000001', 'sign-in-code', 'Your code is 123456.');

        $this->assertStringNotContainsString('123456', (string) $message);
        $this->assertStringNotContainsString('639170000', (string) $message);
        // Enough for a support desk to confirm with somebody holding the phone, and not enough
        // for whoever reads the log to learn who holds an account.
        $this->assertSame('*********0001', $message->maskedRecipient());
    }

    #[Test]
    public function the_log_sender_refuses_to_exist_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        // It writes one-time codes to a file. A container that will not start is recoverable;
        // a log full of every resident's sign-in code is not.
        $this->expectException(LogicException::class);
        new LogTransactionalSender($this->app);
    }
}
