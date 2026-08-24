<?php

namespace App\Support;

use App\Models\Grade;
use App\Models\GradeStatusEvent;
use Illuminate\Contracts\Auth\Authenticatable;

final class GradeAuditLogger
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_IMPORTED = 'imported';

    public const ACTION_STATUS_CHANGE = 'status_change';

    public const ACTION_GRADING_SCALE_UPDATED = 'grading_scale_updated';

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
    ): ?GradeStatusEvent {
        try {
            $enrollment = $grade?->relationLoaded('enrollment')
                ? $grade->enrollment
                : $grade?->enrollment()->with('offering')->first();

            $attrs = [
                'grade_id' => $grade?->id,
                'action' => $action,
                'student_id' => $enrollment?->student_id,
                'course_id' => $enrollment?->offering?->course_id,
                'academic_term_id' => $enrollment?->offering?->academic_term_id,
                'sitting' => $grade?->sitting,
                'from_status' => $fromStatus,
                'to_status' => $toStatus ?? $grade?->status,
                'note' => $note,
                'meta' => $meta,
                'actor_user_id' => $actor?->getAuthIdentifier(),
            ];

            return GradeStatusEvent::query()->create($attrs);
        } catch (\Throwable) {
            return null;
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
    ): ?GradeStatusEvent {
        return self::record(null, $action, $actor, null, null, $note, $meta);
    }

    public static function created(Grade $grade, Authenticatable $actor): ?GradeStatusEvent
    {
        return self::record($grade, self::ACTION_CREATED, $actor, null, $grade->status);
    }

    public static function imported(Grade $grade, Authenticatable $actor, ?array $meta = null): ?GradeStatusEvent
    {
        return self::record($grade, self::ACTION_IMPORTED, $actor, null, $grade->status, null, $meta);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function updated(Grade $grade, Authenticatable $actor, array $before, array $after): ?GradeStatusEvent
    {
        return self::record($grade, self::ACTION_UPDATED, $actor, null, $grade->status, null, [
            'before' => $before,
            'after' => $after,
        ]);
    }

    public static function deleted(Grade $grade, Authenticatable $actor): ?GradeStatusEvent
    {
        return self::record($grade, self::ACTION_DELETED, $actor, $grade->status, null);
    }

    public static function statusChange(
        Grade $grade,
        ?string $from,
        string $to,
        Authenticatable $actor,
        ?string $note = null,
    ): ?GradeStatusEvent {
        return self::record($grade, self::ACTION_STATUS_CHANGE, $actor, $from, $to, $note);
    }

    public static function gradingScaleUpdated(Authenticatable $actor, array $meta): ?GradeStatusEvent
    {
        return self::systemAction(self::ACTION_GRADING_SCALE_UPDATED, $actor, $meta, 'Grading scale updated');
    }
}
