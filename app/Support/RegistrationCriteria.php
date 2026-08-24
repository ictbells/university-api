<?php

namespace App\Support;

use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;

class RegistrationCriteria
{
    public static function completedApplication(Builder $query): Builder
    {
        return $query
            ->where('stage', 'matriculated')
            ->whereNotNull('student_id');
    }

    public static function studentsQuery(): Builder
    {
        return Student::query()
            ->whereHas('application', fn (Builder $application) => self::completedApplication($application))
            ->whereHas('invoices', TuitionProgress::tuitionConstraint());
    }

    public static function excludeRegisteredApplications(Builder $query): Builder
    {
        return $query->whereNot(function (Builder $registered) {
            self::completedApplication($registered)
                ->whereHas('user.invoices', TuitionProgress::tuitionConstraint());
        });
    }
}
