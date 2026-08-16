<?php

declare(strict_types=1);

namespace Modules\Events\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Events\Domain\AttendanceState;
use Modules\Events\Domain\RegistrationStatus;

/**
 * One person's place at one event.
 *
 * @property RegistrationStatus $status
 * @property AttendanceState $attendance
 */
final class EventRegistration extends Model
{
    protected $table = 'event_registrations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'attendance' => AttendanceState::class,
            'registered_at' => 'datetime',
            'promoted_at' => 'datetime',
            'attendance_marked_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }
}
