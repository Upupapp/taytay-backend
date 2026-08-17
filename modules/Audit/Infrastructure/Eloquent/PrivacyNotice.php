<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A published version of the privacy notice.
 *
 * IMMUTABLE ONCE PUBLISHED. An acknowledgement points at a version, so editing that version in
 * place would silently rewrite what somebody was shown — and "she accepted the privacy notice"
 * means nothing if the notice can change underneath the acceptance.
 */
final class PrivacyNotice extends Model
{
    protected $table = 'privacy_notices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null
            && $this->effective_from !== null
            && $this->effective_from->lte(now());
    }
}
