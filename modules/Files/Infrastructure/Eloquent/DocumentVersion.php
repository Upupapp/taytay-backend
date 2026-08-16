<?php

declare(strict_types=1);

namespace Modules\Files\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Files\Contracts\DocumentSource;
use Modules\Files\Contracts\DocumentValidity;
use Modules\Files\Contracts\VerificationStatus;

/**
 * One document as it was presented, at one moment, by one person.
 *
 * APPEND-ONLY. `$timestamps` is off and there is no `updated_at` column: a row is written once,
 * and afterwards only two stamps are ever set on it — supersession and a verification decision.
 * Both are additive facts about a version that itself never changes. Enforced by
 * `DocumentHistoryIsAppendOnlyTest`.
 *
 * @property DocumentSource $source
 * @property VerificationStatus $verification_status
 */
final class DocumentVersion extends Model
{
    protected $table = 'document_versions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'source' => DocumentSource::class,
            'verification_status' => VerificationStatus::class,
            'issued_on' => 'date',
            'expires_on' => 'date',
            'expiry_unknown' => 'boolean',
            'received_at' => 'datetime',
            'verified_at' => 'datetime',
            'superseded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->created_at ??= now();
            $model->received_at ??= now();
        });
    }

    /**
     * @return BelongsTo<Document, self>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<StoredFile, self>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    /**
     * How close this document is to lapsing.
     *
     * Computed rather than stored, because a stored answer is wrong the day after it is written
     * and nothing would recompute it. See {@see DocumentValidity} for the warning window and
     * whose convention it is.
     */
    public function validity(?Carbon $on = null): DocumentValidity
    {
        return DocumentValidity::of($this->expires_on, $this->expiry_unknown, $on);
    }
}
