<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\ResidentProfile\Contracts\RelationshipType;

/**
 * One directed kinship tie: "<resident> is the <type> of <related_resident>".
 *
 * The inverse view is derived on read, never stored — see {@see RelationshipType}.
 *
 * @property RelationshipType $type
 */
final class ResidentRelationship extends Model
{
    protected $table = 'resident_relationships';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => RelationshipType::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
            $model->effective_from ??= now()->toDateString();
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('effective_to');
    }

    public function isOpen(): bool
    {
        return $this->effective_to === null;
    }
}
