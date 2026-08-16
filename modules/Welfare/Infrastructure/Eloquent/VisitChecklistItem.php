<?php

declare(strict_types=1);

namespace Modules\Welfare\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing the worker set out to check.
 *
 * A prompt, never a score. Nothing totals these and nothing derives an eligibility or a
 * vulnerability rating from them — a checklist that totals is a checklist that decides.
 */
final class VisitChecklistItem extends Model
{
    protected $table = 'field_visit_checklist_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['checked' => 'boolean'];
    }
}
