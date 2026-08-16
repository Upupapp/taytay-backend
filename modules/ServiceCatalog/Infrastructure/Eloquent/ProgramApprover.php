<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A role permitted to approve under a programme.
 *
 * Records policy, not authority. Authorization still comes from AccessControl on every request
 * — this table says who the programme *expects* to sign, and TAB 18 will read it as one input
 * to segregation of duties. A row here grants nothing on its own (ADR 0002).
 */
final class ProgramApprover extends Model
{
    protected $table = 'program_approvers';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
