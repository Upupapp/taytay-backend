<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A document or condition a programme asks an applicant to satisfy.
 *
 * Versioned per programme: a case returned for a missing document must remain explicable
 * against the requirements that applied when it was returned, not against the ones added since.
 */
final class ProgramRequirement extends Model
{
    protected $table = 'program_requirements';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /** @return HasMany<ProgramRequirementDocument, $this> */
    public function acceptedDocuments(): HasMany
    {
        return $this->hasMany(ProgramRequirementDocument::class);
    }

    public function isMandatory(): bool
    {
        return $this->obligation === 'required';
    }
}
