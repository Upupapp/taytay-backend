<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\ServiceCatalog\Domain\PublicationStatus;

/**
 * An LGU assistance programme.
 *
 * @property PublicationStatus $status
 */
final class Program extends Model
{
    use SoftDeletes;

    protected $table = 'programs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PublicationStatus::class,
            'is_citizen_visible' => 'boolean',
            'active_from' => 'date',
            'active_to' => 'date',
            'applications_open_at' => 'datetime',
            'applications_close_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /** @return HasMany<ProgramRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(ProgramRequirement::class);
    }

    /** @return HasMany<ProgramEligibilityCriterion, $this> */
    public function criteria(): HasMany
    {
        return $this->hasMany(ProgramEligibilityCriterion::class);
    }

    /** @return HasMany<ProgramIntakeChannel, $this> */
    public function intakeChannels(): HasMany
    {
        return $this->hasMany(ProgramIntakeChannel::class);
    }

    /** @return HasMany<ProgramApprover, $this> */
    public function approvers(): HasMany
    {
        return $this->hasMany(ProgramApprover::class);
    }

    /**
     * Whether a citizen with no special permission may see this.
     *
     * BOTH conditions, and they are separate facts. An internal referral programme can be fully
     * published and operational while remaining invisible to the public catalogue; collapsing
     * the two would force staff to leave a live programme in `draft` to hide it, and a draft
     * programme accepts no applications.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->status->isPubliclyVisible() && (bool) $this->is_citizen_visible;
    }

    /**
     * Whether the programme is within its operating dates today.
     *
     * Open-ended at either end is normal: a standing programme has no end date, and one
     * announced before it starts has a future `active_from`.
     */
    public function isActiveOn(?\DateTimeInterface $moment = null): bool
    {
        $date = $moment === null ? now()->startOfDay() : Carbon::instance($moment)->startOfDay();

        if ($this->active_from !== null && $date->lt($this->active_from)) {
            return false;
        }

        return ! ($this->active_to !== null && $date->gt($this->active_to));
    }

    /**
     * Whether applications are being accepted right now.
     *
     * A separate window from `active_*`: a relief operation announces early and opens later, and
     * an applicant needs to be told which of those two states it is in.
     */
    public function acceptsApplicationsNow(): bool
    {
        if (! $this->isActiveOn()) {
            return false;
        }

        if ($this->applications_open_at !== null && now()->lt($this->applications_open_at)) {
            return false;
        }

        return ! ($this->applications_close_at !== null && now()->gt($this->applications_close_at));
    }

    /**
     * Whether Taytay decides who receives this.
     *
     * False for 4Ps and similar: the LGU tracks and refers, but DSWD sets eligibility. Guidance
     * against a national programme is reported as indicative only (ADR 0018 §4).
     */
    public function isLocallyDetermined(): bool
    {
        return $this->authority === 'local';
    }
}
