<?php

namespace App\Support;

class ApplicantImportColumns
{
    public const MODES = ['utme', 'de', 'jupeb', 'transfer', 'pg'];

    /**
     * @return list<string>
     */
    public static function forMode(string $entryMode): array
    {
        $columns = self::shared();
        $columns = array_merge($columns, match ($entryMode) {
            'utme', 'jupeb' => self::utme(),
            'de' => self::directEntry(),
            'transfer' => self::transfer(),
            'pg' => self::postgraduate(),
            default => [],
        });

        return $columns;
    }

    /**
     * @return list<string>
     */
    public static function shared(): array
    {
        $olevel = [];
        foreach ([1, 2] as $sitting) {
            $olevel[] = "sitting{$sitting}_exam_type";
            $olevel[] = "sitting{$sitting}_exam_year";
            $olevel[] = "sitting{$sitting}_exam_number";
            $olevel[] = "sitting{$sitting}_exam_centre";
            for ($i = 1; $i <= 9; $i++) {
                $olevel[] = "sitting{$sitting}_subject_{$i}_id";
                $olevel[] = "sitting{$sitting}_grade_{$i}";
            }
        }

        return array_merge([
            'email',
            'phone',
            'password',
            'nin',
            'first_name',
            'middle_name',
            'last_name',
            'date_of_birth',
            'gender',
            'marital_status',
            'religion',
            'country',
            'state_id',
            'lga_id',
            'address',
            'blood_group',
            'genotype',
            'has_medical_condition',
            'medical_condition_details',
            'next_of_kin_name',
            'next_of_kin_relationship',
            'next_of_kin_phone',
            'next_of_kin_email',
            'next_of_kin_address',
            'sponsor_name',
            'sponsor_relationship',
            'sponsor_phone',
            'sponsor_email',
            'sponsor_address',
            'first_choice_programme_id',
            'second_choice_programme_id',
            'old_application_number',
        ], $olevel);
    }

    /**
     * @return list<string>
     */
    public static function utme(): array
    {
        $cols = [
            'jamb_registration',
            'utme_aggregate',
            'utme_year',
            'utme_course_choice',
            'utme_english_score',
        ];
        for ($i = 1; $i <= 4; $i++) {
            $cols[] = "utme_subject_{$i}";
            $cols[] = "utme_score_{$i}";
        }
        for ($i = 1; $i <= 2; $i++) {
            $cols[] = "utme_institution_{$i}";
            $cols[] = "utme_programme_{$i}";
        }

        return $cols;
    }

    /**
     * @return list<string>
     */
    public static function directEntry(): array
    {
        return [
            'jamb_registration',
            'jamb_de_number',
            'previous_institution',
            'qualification_type',
            'qualification_title',
            'qualification_class',
            'qualification_year',
            'previous_programme',
            'requested_entry_level',
        ];
    }

    /**
     * @return list<string>
     */
    public static function transfer(): array
    {
        return [
            'previous_university',
            'previous_programme',
            'previous_student_id',
            'credits_earned',
            'cgpa',
            'reason_for_transfer',
            'requested_entry_level',
            'has_transfer_approval',
            'approval_reference',
        ];
    }

    /**
     * @return list<string>
     */
    public static function postgraduate(): array
    {
        $cols = [
            'prior_degree_title',
            'prior_institution',
            'prior_field_of_study',
            'prior_class',
            'prior_award_level',
            'prior_year_awarded',
            'prior_country',
            'nysc_status',
            'nysc_number',
            'nysc_year',
            'nysc_exemption_reason',
            'research_interest',
            'proposed_area',
            'statement_of_purpose',
        ];
        for ($i = 1; $i <= 3; $i++) {
            $cols[] = "referee_{$i}_name";
            $cols[] = "referee_{$i}_email";
            $cols[] = "referee_{$i}_institution";
            $cols[] = "referee_{$i}_position";
            $cols[] = "referee_{$i}_phone";
        }

        return $cols;
    }

    /**
     * @return list<string>
     */
    public static function required(string $entryMode): array
    {
        $required = ['email', 'phone', 'nin', 'first_name', 'last_name', 'first_choice_programme_id'];
        if (in_array($entryMode, AdmissionEntryRules::JAMB_ENTRY_MODES, true)) {
            $required[] = 'jamb_registration';
        }

        return $required;
    }

    public static function sample(string $entryMode): array
    {
        $row = array_fill_keys(self::forMode($entryMode), '');
        $row['email'] = 'ada.okoye@example.com';
        $row['phone'] = '08030000000';
        $row['password'] = '';
        $row['nin'] = '12345678901';
        $row['first_name'] = 'Adaeze';
        $row['middle_name'] = 'Chioma';
        $row['last_name'] = 'Okoye';
        $row['date_of_birth'] = '2004-03-18';
        $row['gender'] = 'Female';
        $row['country'] = 'Nigeria';
        $row['state_id'] = '28';
        $row['lga_id'] = '560';
        $row['first_choice_programme_id'] = '1';
        $row['sitting1_exam_type'] = 'WAEC';
        $row['sitting1_exam_year'] = '2021';
        $row['sitting1_exam_number'] = '1234567890';
        $row['sitting1_exam_centre'] = 'Lagos';
        $row['sitting1_subject_1_id'] = '1';
        $row['sitting1_grade_1'] = 'C6';
        $row['sitting1_subject_2_id'] = '2';
        $row['sitting1_grade_2'] = 'B3';

        if (in_array($entryMode, ['utme', 'jupeb', 'de'], true)) {
            $row['jamb_registration'] = '12345678AB';
        }
        if ($entryMode === 'utme' || $entryMode === 'jupeb') {
            $row['utme_aggregate'] = '250';
            $row['utme_year'] = '2025';
            $row['utme_course_choice'] = 'Computer Science';
            $row['utme_english_score'] = '65';
            $row['utme_subject_1'] = 'English Language';
            $row['utme_score_1'] = '65';
            $row['utme_subject_2'] = 'Mathematics';
            $row['utme_score_2'] = '70';
            $row['utme_subject_3'] = 'Physics';
            $row['utme_score_3'] = '58';
            $row['utme_subject_4'] = 'Chemistry';
            $row['utme_score_4'] = '57';
            $row['utme_institution_1'] = 'Bells University of Technology';
            $row['utme_programme_1'] = 'Computer Science';
            $row['utme_institution_2'] = 'University of Lagos';
            $row['utme_programme_2'] = 'Computer Science';
        }
        if ($entryMode === 'de') {
            $row['jamb_de_number'] = '12345678DE';
            $row['previous_institution'] = 'Yaba College of Technology';
            $row['qualification_type'] = 'nd';
            $row['qualification_title'] = 'ND Computer Science';
            $row['qualification_class'] = 'upper_credit';
            $row['qualification_year'] = '2023';
            $row['previous_programme'] = 'Computer Science';
            $row['requested_entry_level'] = '200';
        }
        if ($entryMode === 'transfer') {
            $row['previous_university'] = 'University of Lagos';
            $row['previous_programme'] = 'Computer Science';
            $row['previous_student_id'] = 'UNILAG/2019/001';
            $row['credits_earned'] = '60';
            $row['cgpa'] = '3.40';
            $row['reason_for_transfer'] = 'Relocation';
            $row['requested_entry_level'] = '200';
            $row['has_transfer_approval'] = 'yes';
            $row['approval_reference'] = 'TR-001';
        }
        if ($entryMode === 'pg') {
            $row['first_choice_programme_id'] = '1';
            $row['prior_degree_title'] = 'B.Sc Computer Science';
            $row['prior_institution'] = 'Bells University of Technology';
            $row['prior_field_of_study'] = 'Computer Science';
            $row['prior_class'] = 'second_lower';
            $row['prior_award_level'] = 'bachelor';
            $row['prior_year_awarded'] = '2020';
            $row['prior_country'] = 'Nigeria';
            $row['nysc_status'] = 'completed';
            $row['nysc_number'] = 'NYSC-2021-001';
            $row['research_interest'] = 'Machine learning';
            $row['proposed_area'] = 'Applied AI';
            $row['statement_of_purpose'] = 'I want to advance computing research.';
            $row['referee_1_name'] = 'Prof. Ada';
            $row['referee_1_email'] = 'ada.ref@example.com';
            $row['referee_1_institution'] = 'Bells University';
            $row['referee_1_position'] = 'Professor';
            $row['referee_2_name'] = 'Dr. Chidi';
            $row['referee_2_email'] = 'chidi.ref@example.com';
            $row['referee_2_institution'] = 'University of Ibadan';
            $row['referee_2_position'] = 'Senior Lecturer';
        }

        return $row;
    }
}
