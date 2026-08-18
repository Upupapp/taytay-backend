<?php

declare(strict_types=1);

namespace Modules\Notification\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Events\Contracts\EventRegistrationPromoted;
use Modules\Notification\Application\ChannelRegistry;
use Modules\Notification\Application\NotifyApplicantOnCaseTransition;
use Modules\Notification\Application\NotifyRegistrantOnWaitlistPromotion;
use Modules\Notification\Infrastructure\Channels\DatabaseChannel;
use Modules\Notification\Infrastructure\Channels\FcmChannel;
use Modules\Notification\Infrastructure\Channels\NullChannel;
use Modules\Notification\Infrastructure\Transactional\LogTransactionalSender;
use Modules\Notification\Infrastructure\Transactional\NullTransactionalSender;
use Modules\Shared\Contracts\TransactionalSender;
use Modules\Welfare\Contracts\CaseStatusChanged;

/**
 * Notification owns outbound dispatch, delivery receipts and channel preferences.
 *
 * IT DOES NOT OWN WHY A NOTIFICATION WAS TRIGGERED. Welfare decides that a case was approved;
 * this module decides how to tell somebody. That split is why nothing here imports a case, and
 * why a provider outage cannot reach welfare work.
 *
 * The registry is bound here so an environment swaps a real provider for a null one by binding
 * rather than by editing a service — which is what makes the whole path exercisable in a test
 * suite with no SMS bill and no Firebase project.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRegistry::class, static fn (): ChannelRegistry => new ChannelRegistry([
            new DatabaseChannel,
            new FcmChannel,
            // Email and SMS have no provider yet. `skipped`, never `sent` — a dashboard showing
            // "delivered" for a channel that does not exist tells an operator the family was told.
            new NullChannel('email'),
            new NullChannel('sms'),
        ]));

        /*
         * TRANSACTIONAL DELIVERY — the seam a sign-in code travels through (L-18, F16).
         *
         * Separate from the channel registry on purpose. A channel is chosen by preference and
         * may be switched off by the recipient; this may not, because switching it off means
         * nobody can sign in. It also persists nothing, where `database` is a channel whose
         * entire job is to persist.
         *
         * `null` is the default because it is the truth: this platform has no SMS provider, and
         * choosing one is a procurement decision the LGU has not made. `log` exists so sign-in
         * is exercisable end to end on a developer machine, and refuses to construct anywhere
         * else — see LogTransactionalSender.
         */
        $this->app->singleton(TransactionalSender::class, function (): TransactionalSender {
            return match ((string) config('notification.transactional.sender', 'null')) {
                'log' => new LogTransactionalSender($this->app),
                default => new NullTransactionalSender,
            };
        });
    }

    public function boot(): void
    {
        /*
         * RESOLVED AT BOOT, ON PURPOSE, AND THE RESULT IS DISCARDED.
         *
         * A singleton that throws in its constructor is lazy: nothing notices until the first
         * request that needs it, and for the transactional sender that request is somebody trying
         * to sign in. A misconfigured deployment would then answer `500` on `POST auth/otp` and
         * look like an application bug rather than a configuration one — which is exactly how it
         * presented the first time, as thirty-one failing authentication tests.
         *
         * Touching it here turns that into a container that will not start, which is the failure
         * this was always documented as having.
         */
        $this->app->make(TransactionalSender::class);

        /*
         * Registered HERE, in the module that owns the notification, so Welfare stays ignorant of
         * who cares that a case moved. Removing this one line turns notifications off entirely
         * and changes nothing else in the system.
         */
        Event::listen(CaseStatusChanged::class, NotifyApplicantOnCaseTransition::class);

        /*
         * Same inversion, same reason: Events announces that a seat opened and knows nothing
         * about whether anybody is told. A push provider outage cannot roll back a promotion.
         */
        Event::listen(EventRegistrationPromoted::class, NotifyRegistrantOnWaitlistPromotion::class);
    }
}
