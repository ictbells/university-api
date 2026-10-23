<?php

use App\Models\Setting;
use App\Models\Student;
use App\Services\MatricSequence;
use App\Support\DotenvWriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private const STUDENT_ID = 1898;

    private const APPLICATION_ID = 2547;

    private const FROM = '2026/00001';

    private const TO = '2026/0001';

    public function up(): void
    {
        DB::transaction(function () {
            $student = Student::query()->find(self::STUDENT_ID);
            if (! $student) {
                return;
            }

            $applicationOk = ! $student->application_id
                || (int) $student->application_id === self::APPLICATION_ID;
            $matchesWrong = $student->matric_number === self::FROM
                || $student->student_number === self::FROM;
            if (! $applicationOk || ! $matchesWrong) {
                Log::info('pg_matric_fix.skipped', [
                    'student_id' => self::STUDENT_ID,
                    'matric_number' => $student->matric_number,
                    'student_number' => $student->student_number,
                    'application_id' => $student->application_id,
                ]);

                return;
            }

            $taken = Student::query()
                ->whereKeyNot(self::STUDENT_ID)
                ->where(function ($query) {
                    $query->where('matric_number', self::TO)
                        ->orWhere('student_number', self::TO);
                })
                ->exists();
            if ($taken) {
                throw new \RuntimeException(
                    'Cannot correct student '.self::STUDENT_ID.': '.self::TO.' is already assigned.'
                );
            }

            $student->update([
                'matric_number' => self::TO,
                'student_number' => self::TO,
            ]);

            $last = (string) Setting::getValue(MatricSequence::PG_SETTING_KEY, '');
            if ($last === self::FROM || $last === '') {
                Setting::setValue(MatricSequence::PG_SETTING_KEY, self::TO);
                config(['sis.pg_matric_last' => self::TO]);
            }

            if (! app()->environment('testing') && ! app()->runningUnitTests()) {
                $envLast = (string) ($_ENV['PG_MATRIC_LAST'] ?? getenv('PG_MATRIC_LAST') ?: '');
                if ($envLast === self::FROM || $envLast === '') {
                    DotenvWriter::set('PG_MATRIC_LAST', self::TO);
                }
                $envDigits = (string) ($_ENV['PG_MATRIC_DIGITS'] ?? getenv('PG_MATRIC_DIGITS') ?: '');
                if ($envDigits === '' || $envDigits === '0') {
                    DotenvWriter::set('PG_MATRIC_DIGITS', '4');
                }
            }

            config(['sis.pg_matric_digits' => 4]);

            Log::info('pg_matric_fix.applied', [
                'student_id' => self::STUDENT_ID,
                'application_id' => $student->application_id,
                'from' => self::FROM,
                'to' => self::TO,
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $student = Student::query()->find(self::STUDENT_ID);
            if (! $student) {
                return;
            }
            if ($student->matric_number !== self::TO && $student->student_number !== self::TO) {
                return;
            }

            $taken = Student::query()
                ->whereKeyNot(self::STUDENT_ID)
                ->where(function ($query) {
                    $query->where('matric_number', self::FROM)
                        ->orWhere('student_number', self::FROM);
                })
                ->exists();
            if ($taken) {
                return;
            }

            $student->update([
                'matric_number' => self::FROM,
                'student_number' => self::FROM,
            ]);

            if ((string) Setting::getValue(MatricSequence::PG_SETTING_KEY, '') === self::TO) {
                Setting::setValue(MatricSequence::PG_SETTING_KEY, self::FROM);
                config(['sis.pg_matric_last' => self::FROM]);
            }
        });
    }
};
