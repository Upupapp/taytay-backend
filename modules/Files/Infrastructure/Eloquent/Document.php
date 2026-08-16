<?php

declare(strict_types=1);

namespace Modules\Files\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One document slot on one owning record, holding every version ever presented against it.
 */
final class Document extends Model
{
    protected $table = 'documents';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /**
     * @return HasMany<DocumentVersion, self>
     */
    public function versions(): HasMany
    {
        // Oldest first, matching the console's `versions` array, whose last entry is current.
        // Within this module only — a cross-module relation would be an Article 2.2 violation,
        // and both tables are owned here.
        return $this->hasMany(DocumentVersion::class)->orderBy('version');
    }

    /**
     * The version in force, or null for a slot nothing has been presented against.
     */
    public function currentVersion(): ?DocumentVersion
    {
        /** @var DocumentVersion|null $version */
        $version = $this->versions()->whereNull('superseded_at')->orderByDesc('version')->first();

        return $version;
    }
}
