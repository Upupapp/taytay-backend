<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\ServiceCatalog\Contracts\ProviderSummary;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ServiceProvider;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ServiceProviderChannel;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ServiceProviderService;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * The offices, hospitals and partners this LGU refers people to (ADR 0021 §1).
 *
 * A DIRECTORY RATHER THAN A FREE-TEXT FIELD, for a reason that shows up at a counter:
 * "PhilHealth Rizal", "Philhealth - Rizal" and "PHIC Rizal" are three spellings of one office.
 * Once those exist, an applicant cannot be told whether anybody has heard back, and a report on
 * referral outcomes counts one destination three ways.
 *
 * It also carries **what each provider actually accepts**, so a referral is not sent to an office
 * that does not do this work — which costs the family a trip they cannot afford and loses days
 * nobody gets back.
 */
final class ProviderDirectory
{
    public function __construct(private readonly ServiceCatalogAudit $audit) {}

    /**
     * @return Builder<ServiceProvider>
     */
    public function query(): Builder
    {
        return ServiceProvider::query()->orderBy('name');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ActorContext $actor): ServiceProvider
    {
        return DB::transaction(function () use ($attributes, $actor): ServiceProvider {
            $provider = ServiceProvider::query()->create($this->writable($attributes) + [
                'status' => 'active',
            ]);

            $this->syncChildren($provider, $attributes);

            $this->audit->record($actor->subjectId, 'provider.created', 'Service provider added', (string) $provider->uuid);

            return $provider->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(ServiceProvider $provider, array $changes, ActorContext $actor): ServiceProvider
    {
        return DB::transaction(function () use ($provider, $changes, $actor): ServiceProvider {
            $provider->forceFill($this->writable($changes))->save();

            $this->syncChildren($provider, $changes);

            $this->audit->record($actor->subjectId, 'provider.updated', 'Service provider updated', (string) $provider->uuid);

            return $provider->refresh();
        });
    }

    /**
     * Replaces the channel and service rows, when the caller supplied them.
     *
     * Replace rather than merge: a partial update that *omits* channels means "leave them alone",
     * and one that *sends* them means "these are the channels now". Merging would make removing a
     * channel impossible through this API, and a provider that stopped taking email would keep
     * receiving referrals by it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function syncChildren(ServiceProvider $provider, array $attributes): void
    {
        if (array_key_exists('channels', $attributes)) {
            $provider->channelRows()->delete();

            foreach (array_unique((array) $attributes['channels']) as $channel) {
                ServiceProviderChannel::query()->create([
                    'service_provider_id' => $provider->id,
                    'channel' => (string) $channel,
                ]);
            }
        }

        if (array_key_exists('services_offered', $attributes)) {
            $provider->serviceRows()->delete();

            foreach (array_unique((array) $attributes['services_offered']) as $service) {
                ServiceProviderService::query()->create([
                    'service_provider_id' => $provider->id,
                    'service' => (string) $service,
                ]);
            }
        }
    }

    /**
     * Suspends or retires an entry.
     *
     * Never deletes. Referrals already sent name this provider, and a directory row that vanishes
     * turns those into referrals to nowhere — the destination snapshot on the referral survives,
     * but the ability to look up who to chase does not.
     */
    public function changeStatus(ServiceProvider $provider, string $status, ActorContext $actor): ServiceProvider
    {
        if (! in_array($status, ['active', 'suspended', 'retired'], true)) {
            throw new ApiException(ErrorCode::BadRequest, 'That is not a provider status.');
        }

        /*
         * An entry cannot be activated while it is unusable.
         *
         * "Accepting referrals" with no channel and no contact is the worst state in this table:
         * it invites a worker to route a family somewhere, and produces a referral nobody can
         * follow up.
         */
        if ($status === 'active' && $provider->problems() !== []) {
            throw new ApiException(
                ErrorCode::Conflict,
                'That entry is missing a contact, a channel or a service, so it cannot accept referrals yet.',
            );
        }

        $provider->forceFill(['status' => $status])->save();

        $this->audit->record(
            $actor->subjectId,
            'provider.status-changed',
            'Service provider '.$status,
            (string) $provider->uuid,
        );

        return $provider->refresh();
    }

    /**
     * Records that somebody has re-checked this entry against reality.
     *
     * A directory nobody re-checks is a list of disconnected numbers within two years, and the
     * failure is silent: the referral goes out, nobody answers, and the family is the last to
     * find out.
     */
    public function markVerified(ServiceProvider $provider, ActorContext $actor): ServiceProvider
    {
        $provider->forceFill([
            'verified_by' => $actor->subjectId,
            'verified_at' => now(),
        ])->save();

        return $provider->refresh();
    }

    public function summaryFor(string $uuid): ?ProviderSummary
    {
        /** @var ServiceProvider|null $provider */
        $provider = ServiceProvider::query()->where('uuid', $uuid)->first();

        return $provider === null ? null : $this->summarise($provider);
    }

    public function summarise(ServiceProvider $provider): ProviderSummary
    {
        return new ProviderSummary(
            id: (string) $provider->uuid,
            name: (string) $provider->name,
            destinationType: (string) $provider->destination_type,
            status: (string) $provider->status,
            servicesOffered: $provider->servicesOffered(),
            channels: $provider->channels(),
            contactPerson: $provider->contact_person,
            contactPhone: $provider->contact_phone,
            contactEmail: $provider->contact_email,
            address: $provider->address,
            usualResponseDays: $provider->usual_response_days === null ? null : (int) $provider->usual_response_days,
            problems: $provider->problems(),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function writable(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip([
            'name', 'destination_type', 'address', 'barangay_id',
            'contact_person', 'contact_position', 'contact_phone', 'contact_email',
            'usual_response_days', 'notes',
        ]));
    }
}
