<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A form somebody is still filling in.
 *
 * Deliberately not a case in `draft` status: an abandoned half-filled form is not a request the
 * office has been asked to act on, and putting it in the case queue would fill the backlog with
 * things nobody submitted.
 */
final class AssistanceDraft extends Model
{
    protected $table = 'assistance_drafts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->expires_at ??= now()->addDays((int) config('welfare.drafts.retention_days', 30));
        });
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether this draft may still be edited or submitted.
     *
     * Both conditions. An expired draft is refused rather than silently resurrected: the
     * retention clock is a privacy commitment, and quietly extending it whenever somebody
     * returns would make the commitment meaningless.
     */
    public function isEditable(): bool
    {
        return ! $this->isSubmitted() && ! $this->isExpired();
    }
}
