<?php

namespace App\Support;

final class BroadsheetStanding
{
    public const GS = 'GS';

    public const NGS = 'NGS';

    public const ABS_P = 'ABS_P';

    public const ABS_NP = 'ABS_NP';

    public const AR = 'AR';

    public const SICK = 'SICK';

    public const RUS = 'RUS';

    public const SUS = 'SUS';

    public const EXP = 'EXP';

    public const WD = 'WD';

    /**
     * @param  list<string|null>  $examRemarks
     */
    public static function classify(
        ?float $cgpa,
        int $outstandingUnits,
        ?string $yearOfEntry,
        array $examRemarks,
        bool $hasScoredPaper,
        bool $hasAwaitingOrMissing,
        ?string $sanctionType = null,
        ?string $adminRemark = null,
    ): string {
        $sanction = self::fromSanction($sanctionType);
        if ($sanction !== null) {
            return $sanction;
        }

        $admin = GradeExamRemark::normalize($adminRemark);
        if ($admin === GradeExamRemark::SICK) {
            return self::SICK;
        }
        if ($admin === GradeExamRemark::ABS_NP) {
            return self::ABS_NP;
        }
        if ($admin === GradeExamRemark::ABS_P) {
            return self::ABS_P;
        }

        $remarks = [];
        foreach ($examRemarks as $remark) {
            $normalized = GradeExamRemark::normalize(is_string($remark) ? $remark : null);
            if ($normalized !== null) {
                $remarks[] = $normalized;
            }
        }

        if (! $hasScoredPaper) {
            if (in_array(GradeExamRemark::SICK, $remarks, true)) {
                return self::SICK;
            }
            if (in_array(GradeExamRemark::ABS_NP, $remarks, true)) {
                return self::ABS_NP;
            }
            if (in_array(GradeExamRemark::ABS_P, $remarks, true)) {
                return self::ABS_P;
            }
            if ($hasAwaitingOrMissing) {
                return self::AR;
            }
        } elseif ($hasAwaitingOrMissing) {
            return self::AR;
        }

        return StudentProgressMetrics::standing($cgpa, $outstandingUnits, $yearOfEntry);
    }

    public static function fromSanction(?string $type): ?string
    {
        return match (strtolower(trim((string) $type))) {
            StudentTermSanctionType::RUSTICATED, 'rus' => self::RUS,
            StudentTermSanctionType::EXPELLED, 'exp' => self::EXP,
            StudentTermSanctionType::SUSPENDED, 'sus' => self::SUS,
            StudentTermSanctionType::WITHDRAWN, 'wd' => self::WD,
            default => null,
        };
    }

    public static function summaryBucket(string $status): string
    {
        return match ($status) {
            self::GS => 'good_standing',
            self::NGS => 'not_good_standing',
            self::ABS_P => 'absent_with_permission',
            self::ABS_NP => 'absent_without_permission',
            self::AR => 'incomplete',
            self::SICK => 'sick',
            self::RUS, self::SUS, self::EXP, self::WD => 'rusticated',
            default => 'not_good_standing',
        };
    }
}
