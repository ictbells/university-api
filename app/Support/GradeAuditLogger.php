<?php

namespace App\Support;

use App\Models\Grade;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Writes result-processing events into the platform audit trail (audit_logs).
 */
final class GradeAuditLogger
{
    public const ACTION_CREATED = 'grade.created';

    public const ACTION_UPDATED = 'grade.updated';

    public const ACTION_DELETED = 'grade.deleted';

    public const ACTION_IMPORTED = 'grade.imported';

    public const ACTION_STATUS_CHANGE = 'grade.status_change';

    public const ACTION_GRADING_SCALE_UPDATED = 'grading_scale.updated';

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function record(
        ?Grade $grade,
        string $action,
        ?Authenticatable $actor,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $note = null,
        ?array $meta = null,
    ): void {
        try {
            $enrollment = $grade?->relationLoaded('enrollment')
                ? $grade->enrollment
                : $grade?->enrollment()->with('offering.course')->first();

            $course = $enrollment?->offering?->course;
            $summary = match ($action) {
                self::ACTION_CREATED => 'Created grade draft'.($course ? " for {$course->code}" : ''),
                self::ACTION_UPDATED => 'Updated grade'.($course ? " for {$course->code}" : ''),
                self::ACTION_DELETED => 'Deleted grade'.($course ? " for {$course->code}" : ''),
                self::ACTION_IMPORTED => 'Imported grade'.($course ? " for {$course->code}" : ''),
                self::ACTION_STATUS_CHANGE => sprintf(
                    'Grade status %s → %s%s',
                    $fromStatus ?: '—',
                    $toStatus ?: '—',
                    $course ? " ({$course->code})" : '',
                ),
                self::ACTION_GRADING_SCALE_UPDATED => 'Updated grading scale',
                default => $action,
            };

            $user = $actor instanceof User ? $actor : null;

            app(AuditWriter::class)->record(
                $action,
                $summary,
                'results',
                $grade ? Grade::class : null,
                $grade?->id,
                $fromStatus ? ['status' => $fromStatus] : ($meta['before'] ?? null),
                $toStatus ? ['status' => $toStatus, ...(isset($meta['after']) && is_array($meta['after']) ? $meta['after'] : [])] : ($meta['after'] ?? $meta),
                $note,
                $user,
            );
        } catch (\Throwable) {
            // Never break primary flows because audit write failed
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function systemAction(
        string $action,
        ?Authenticatable $actor,
        array $meta,
        ?string $note = null,
    ): void {
        self::record(null, $action, $actor, null, null, $note, $meta);
    }

    public static function created(Grade $grade, Authenticatable $actor): void
    {
        self::record($grade, self::ACTION_CREATED, $actor, null, $grade->status);
    }

    public static function imported(Grade $grade, Authenticatable $actor, ?array $meta = null): void
    {
        self::record($grade, self::ACTION_IMPORTED, $actor, null, $grade->status, null, $meta);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function updated(Grade $grade, Authenticatable $actor, array $before, array $after): void
    {
        self::record($grade, self::ACTION_UPDATED, $actor, null, $grade->status, null, [
            'before' => $before,
            'after' => $after,
        ]);
    }

    public static function deleted(Grade $grade, Authenticatable $actor): void
    {
        self::record($grade, self::ACTION_DELETED, $actor, $grade->status, null);
    }

    public static function statusChange(
        Grade $grade,
        ?string $from,
        string $to,
        Authenticatable $actor,
        ?string $note = null,
    ): void {
        self::record($grade, self::ACTION_STATUS_CHANGE, $actor, $from, $to, $note);
    }

    public static function gradingScaleUpdated(Authenticatable $actor, array $meta): void
    {
        self::systemAction(self::ACTION_GRADING_SCALE_UPDATED, $actor, $meta, 'Grading scale updated');
    }
}
