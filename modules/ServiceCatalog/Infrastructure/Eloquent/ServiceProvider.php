<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An external office, hospital or partner this LGU refers people to.
 *
 * Lives in ServiceCatalog rather than Welfare because it is a catalogue of who provides what —
 * the same kind of fact as a programme, and one that outlives any particular referral. Welfare
 * asks for it by identifier and never joins.
 */
final class ServiceProvider extends Model
{
    protected $table = 'service_providers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return HasMany<ServiceProviderChannel, self>
     */
    public function channelRows(): HasMany
    {
        return $this->hasMany(ServiceProviderChannel::class);
    }

    /**
     * @return HasMany<ServiceProviderService, self>
     */
    public function serviceRows(): HasMany
    {
        return $this->hasMany(ServiceProviderService::class);
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        /*
         * THE RELATION PROPERTY, NEVER THE METHOD.
         *
         * `channelRows()` returns the relation and every `->pluck()` on it is a fresh query.
         * `channelRows` reads the loaded collection when the caller eager-loaded it, and
         * otherwise loads once and caches on this instance — which matters even without eager
         * loading, because `problems()` asks for channels and services a SECOND time.
         *
         * Measured on `GET /admin/service-providers`: 8 queries for one provider and 36 for
         * eight, four per row — one each for channels and services, then both again inside
         * `problems()`.
         */
        return $this->channelRows->pluck('channel')
            ->map(static fn (mixed $value): string => (string) $value)->values()->all();
    }

    /**
     * @return list<string>
     */
    public function servicesOffered(): array
    {
        // The relation property, for the reason given on channels() above.
        return $this->serviceRows->pluck('service')
            ->map(static fn (mixed $value): string => (string) $value)->values()->all();
    }

    public function isAcceptingReferrals(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Whether this entry is complete enough to actually send something to.
     *
     * A directory entry with no channel and no contact is a name. Sending to it produces a
     * referral nobody can follow up, and a family who was told help was arranged.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->channels() === []) {
            $problems[] = 'provider-needs-a-channel';
        }

        if ($this->servicesOffered() === []) {
            $problems[] = 'provider-needs-a-service';
        }

        if ($this->contact_phone === null && $this->contact_email === null && $this->address === null) {
            $problems[] = 'provider-needs-a-way-to-reach-it';
        }

        return $problems;
    }
}
