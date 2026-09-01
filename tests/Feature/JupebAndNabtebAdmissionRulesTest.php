<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Application;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Intake;
use App\Models\OlevelSubject;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\StudentCreationService;
use App\Support\JupebMatricColumns;
use App\Support\PermissionCatalog;
use App\Support\WorkflowCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class JupebAndNabtebAdmissionRulesTest extends TestCase
{
    use RefreshDatabase;

    private Faculty $centre;

    private Faculty $otherCollege;

    private Program $jupebCentreProgram;

    private Program $jupebOtherProgram;

    private Program $secondJupebCentreProgram;

    private Program $utmeProgram;

    private OlevelSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        WorkflowCatalog::seed();
        Role::query()->firstOrCreate(
            ['slug' => 'applicant'],
            ['name' => 'Applicant', 'is_system' => true, 'is_active' => true],
        );
        Role::query()->firstOrCreate(
            ['slug' => 'student'],
            ['name' => 'Student', 'is_system' => true, 'is_active' => true],
        );

        $campus = Campus::query()->create(['name' => 'Main', 'is_active' => true]);
        $this->centre = Faculty::query()->create([
            'campus_id' => $campus->id,
            'name' => 'College of Natural Sciences',
            'code' => 'COLNAS',
            'is_jupeb_centre' => true,
        ]);
        $this->otherCollege = Faculty::query()->create([
            'campus_id' => $campus->id,
            'name' => 'College of Law',
            'code' => 'LAW',
            'is_jupeb_centre' => false,
        ]);
        $centreDept = Department::query()->create(['faculty_id' => $this->centre->id, 'name' => 'Biological Sciences']);
        $otherDept = Department::query()->create(['faculty_id' => $this->otherCollege->id, 'name' => 'Private Law']);
        $ugDept = Department::query()->create(['faculty_id' => $this->centre->id, 'name' => 'Computer Science']);

        $this->jupebCentreProgram = $this->programme($centreDept->id, 'JUPEB Sciences', 'JUPEB-SCI', 'jupeb', ['jupeb']);
        $this->secondJupebCentreProgram = $this->programme($centreDept->id, 'JUPEB Arts', 'JUPEB-ART', 'jupeb', ['jupeb']);
        $this->jupebOtherProgram = $this->programme($otherDept->id, 'JUPEB Law', 'JUPEB-LAW', 'jupeb', ['jupeb']);
        $this->utmeProgram = $this->programme($ugDept->id, 'B.Sc Computer Science', 'BSC-CS', 'undergraduate', ['utme']);
        $this->subject = OlevelSubject::query()->create(['name' => 'English Language', 'code' => 'ENG', 'is_active' => true]);
    }

    public function test_nabteb_rejects_a_second_sitting(): void
    {
        $application = $this->formInProgress('utme');
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'academic_qualifications',
            'payload' => [
                'first_sitting' => $this->sitting('NABTEB', 'N123'),
                'second_sitting' => $this->sitting('WAEC', 'W123'),
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'NABTEB uses one sitting only. Remove the second sitting or choose a different exam type.');
    }

    public function test_nabteb_cannot_be_paired_as_the_second_sitting(): void
    {
        $application = $this->formInProgress('utme');
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'academic_qualifications',
            'payload' => [
                'first_sitting' => $this->sitting('WAEC', 'W123'),
                'second_sitting' => $this->sitting('NABTEB', 'N123'),
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'NABTEB uses one sitting only. Remove the second sitting or choose a different exam type.');
    }

    public function test_nabteb_saves_as_a_single_sitting(): void
    {
        $application = $this->formInProgress('utme');
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'academic_qualifications',
            'payload' => [
                'first_sitting' => $this->sitting('NABTEB', 'N123'),
            ],
        ])->assertOk();

        $payload = $application->fresh(['steps'])->steps->firstWhere('step_key', 'academic_qualifications')?->payload;
        $this->assertSame('NABTEB', $payload['first_sitting']['exam_type'] ?? null);
        $this->assertNull($payload['second_sitting'] ?? null);
    }

    public function test_jupeb_applicant_cannot_choose_a_second_programme(): void
    {
        $application = $this->formInProgress('jupeb');
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'programme_selection',
            'payload' => [
                'first_choice_program_id' => $this->jupebCentreProgram->id,
                'second_choice_program_id' => $this->secondJupebCentreProgram->id,
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'JUPEB applicants may select only one programme.');
    }

    public function test_jupeb_applicant_cannot_choose_a_programme_outside_a_centre(): void
    {
        $application = $this->formInProgress('jupeb');
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'programme_selection',
            'payload' => [
                'first_choice_program_id' => $this->jupebOtherProgram->id,
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'JUPEB applicants can only choose a programme offered at a JUPEB centre.');
    }

    public function test_jupeb_applicant_can_save_a_programme_at_a_centre(): void
    {
        $application = $this->formInProgress('jupeb');
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'programme_selection',
            'payload' => [
                'first_choice_program_id' => $this->jupebCentreProgram->id,
            ],
        ])->assertOk()
            ->assertJsonPath('program_id', $this->jupebCentreProgram->id);

        $payload = $application->fresh()->steps()->where('step_key', 'programme_selection')->value('payload');
        $this->assertSame($this->jupebCentreProgram->id, $payload['first_choice_program_id']);
        $this->assertNull($payload['second_choice_program_id']);
    }

    public function test_jupeb_program_list_hides_colleges_that_are_not_centres(): void
    {
        Sanctum::actingAs($this->formInProgress('jupeb')->user);

        $ids = collect($this->getJson('/api/programs?entry_mode=jupeb')->assertOk()->json())
            ->pluck('id')
            ->all();

        $this->assertContains($this->jupebCentreProgram->id, $ids);
        $this->assertContains($this->secondJupebCentreProgram->id, $ids);
        $this->assertNotContains($this->jupebOtherProgram->id, $ids);
        $this->assertNotContains($this->utmeProgram->id, $ids);
    }

    public function test_jupeb_applicant_sees_jupeb_programmes_when_no_college_is_a_centre(): void
    {
        Faculty::query()->update(['is_jupeb_centre' => false]);
        Sanctum::actingAs($this->formInProgress('jupeb')->user);

        $ids = collect($this->getJson('/api/programs?entry_mode=jupeb')->assertOk()->json())
            ->pluck('id')
            ->all();

        $this->assertContains($this->jupebCentreProgram->id, $ids);
        $this->assertContains($this->jupebOtherProgram->id, $ids);
        $this->assertContains($this->secondJupebCentreProgram->id, $ids);
        $this->assertNotContains($this->utmeProgram->id, $ids);
    }

    public function test_jupeb_applicant_sees_undergraduate_programmes_when_no_jupeb_track_exists(): void
    {
        Program::query()->whereIn('id', [
            $this->jupebCentreProgram->id,
            $this->secondJupebCentreProgram->id,
            $this->jupebOtherProgram->id,
        ])->update(['is_active' => false]);
        Faculty::query()->update(['is_jupeb_centre' => false]);

        $application = $this->formInProgress('jupeb');
        Sanctum::actingAs($application->user);

        $ids = collect($this->getJson('/api/programs?entry_mode=jupeb')->assertOk()->json())
            ->pluck('id')
            ->all();

        $this->assertContains($this->utmeProgram->id, $ids);
        $this->assertNotContains($this->jupebCentreProgram->id, $ids);

        $this->postJson("/api/applications/{$application->id}/steps", [
            'step_key' => 'programme_selection',
            'payload' => ['first_choice_program_id' => $this->utmeProgram->id],
        ])->assertOk()
            ->assertJsonPath('program_id', $this->utmeProgram->id);
    }

    public function test_jupeb_can_submit_with_passport_and_olevel_only(): void
    {
        $application = $this->readyToSubmit('jupeb', $this->jupebCentreProgram);
        $application->documents()->create([
            'doc_type' => 'olevel_first_sitting',
            'path' => 'docs/olevel.pdf',
            'original_name' => 'olevel.pdf',
        ]);
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/submit", [
            'submission_notice_accepted' => true,
        ])->assertOk()
            ->assertJsonPath('stage', 'submitted');
    }

    public function test_utme_still_requires_birth_certificate_and_jamb_to_submit(): void
    {
        $application = $this->readyToSubmit('utme', $this->utmeProgram);
        $application->documents()->create([
            'doc_type' => 'olevel_first_sitting',
            'path' => 'docs/olevel.pdf',
            'original_name' => 'olevel.pdf',
        ]);
        Sanctum::actingAs($application->user);

        $this->postJson("/api/applications/{$application->id}/submit", [
            'submission_notice_accepted' => true,
        ])->assertStatus(422)
            ->assertJsonFragment(['missing_documents' => [
                'Birth Certificate',
                'JAMB Result',
            ]]);
    }

    public function test_jupeb_student_is_created_without_an_auto_matric(): void
    {
        $application = $this->readyToSubmit('jupeb', $this->jupebCentreProgram);
        $student = app(StudentCreationService::class)->createFromApplication($application);

        $this->assertNull($student->fresh()->matric_number);
        $this->assertNotEmpty($student->student_number);
        $this->assertSame('jupeb', $student->study_level);
        $this->assertSame('matriculated', $application->fresh()->stage);
    }

    public function test_utme_student_still_receives_an_auto_matric(): void
    {
        $application = $this->readyToSubmit('utme', $this->utmeProgram);
        $student = app(StudentCreationService::class)->createFromApplication($application);

        $this->assertNotNull($student->fresh()->matric_number);
        $this->assertSame('2025/000001', $student->matric_number);
        $this->assertSame($student->matric_number, $student->student_number);
    }

    public function test_auto_matric_increments_from_the_last_issued_number(): void
    {
        $first = app(StudentCreationService::class)->createFromApplication(
            $this->readyToSubmit('utme', $this->utmeProgram),
        );
        $second = app(StudentCreationService::class)->createFromApplication(
            $this->readyToSubmit('utme', $this->utmeProgram),
        );

        $this->assertSame('2025/000001', $first->matric_number);
        $this->assertSame('2025/000002', $second->matric_number);
        $this->assertSame('2025/000002', Setting::getValue('matric_last'));
    }

    public function test_auto_matric_continues_from_matric_last_in_config(): void
    {
        config(['sis.matric_last' => '2026/000150', 'sis.matric_year' => 2026]);
        Setting::setValue('matric_last', '2026/000150');

        $student = app(StudentCreationService::class)->createFromApplication(
            $this->readyToSubmit('utme', $this->utmeProgram),
        );

        $this->assertSame('2026/000151', $student->matric_number);
        $this->assertSame('2026/000151', Setting::getValue('matric_last'));
    }

    public function test_staff_can_assign_and_import_jupeb_matric_numbers(): void
    {
        $application = $this->readyToSubmit('jupeb', $this->jupebCentreProgram);
        $student = app(StudentCreationService::class)->createFromApplication($application);
        Sanctum::actingAs($this->staffUser(['admissions.matriculate']));

        $this->getJson('/api/jupeb/matric/pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('data.0.application_number', $application->application_number);

        $template = $this->get('/api/jupeb/matric/template')->assertOk();
        $workbook = $this->loadWorkbook($template->streamedContent());
        $this->assertNotNull($workbook->getSheetByName('Matric'));
        $this->assertNotNull($workbook->getSheetByName('Pending students'));
        $this->assertNotNull($workbook->getSheetByName('Instructions'));

        $this->postJson('/api/jupeb/matric/assign', [
            'student_id' => $student->id,
            'matric_number' => 'JUPEB/2026/0001',
        ])->assertOk()
            ->assertJsonPath('data.matric_number', 'JUPEB/2026/0001');
        $this->assertSame('JUPEB/2026/0001', $student->fresh()->matric_number);

        $this->getJson('/api/jupeb/matric/pending')->assertOk()->assertJsonPath('data', []);

        $second = app(StudentCreationService::class)->createFromApplication(
            $this->readyToSubmit('jupeb', $this->secondJupebCentreProgram, 'APP/2026/JUPEB02'),
        );
        $this->assertNull($second->matric_number);

        $this->post('/api/jupeb/matric/import', [
            'file' => $this->spreadsheet('Matric', JupebMatricColumns::all(), [
                'application_number' => $second->application->application_number,
                'student_number' => '',
                'email' => $second->user->email,
                'nin' => '',
                'matric_number' => 'JUPEB/2026/0002',
            ]),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.assigned', 1)
            ->assertJsonPath('data.skipped', 0);

        $this->assertSame('JUPEB/2026/0002', $second->fresh()->matric_number);
    }

    /**
     * @param  list<string>  $entryModes
     */
    private function programme(int $departmentId, string $name, string $code, string $studyLevel, array $entryModes): Program
    {
        return Program::query()->create([
            'department_id' => $departmentId,
            'name' => $name,
            'code' => $code,
            'award_type' => $studyLevel === 'jupeb' ? 'JUPEB' : 'B.Sc',
            'study_level' => $studyLevel,
            'entry_modes' => $entryModes,
            'duration_years' => $studyLevel === 'jupeb' ? 1 : 4,
            'is_active' => true,
            'workflow_template_id' => WorkflowCatalog::idByCode(WorkflowCatalog::UG_STANDARD),
        ]);
    }

    private function formInProgress(string $entryMode): Application
    {
        $intake = $this->openIntake($entryMode);
        $role = Role::query()->where('slug', 'applicant')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        $application = Application::query()->create([
            'application_number' => 'APP/2026/'.strtoupper($entryMode).$user->id,
            'user_id' => $user->id,
            'intake_id' => $intake->id,
            'entry_mode' => $entryMode,
            'stage' => 'form_in_progress',
            'current_step' => 'academic_qualifications',
        ]);
        foreach (Application::formSteps($entryMode) as $step) {
            $application->steps()->create([
                'step_key' => $step,
                'status' => $step === 'biodata' ? 'saved' : 'pending',
                'payload' => $step === 'biodata'
                    ? ['nin_locked' => true, 'nin' => str_pad((string) (10000000000 + $user->id), 11, '0', STR_PAD_LEFT), 'photo_path' => 'passports/a.jpg']
                    : [],
            ]);
        }

        return $application->fresh(['user', 'steps']);
    }

    private function readyToSubmit(string $entryMode, Program $program, ?string $applicationNumber = null): Application
    {
        $application = $this->formInProgress($entryMode);
        if ($applicationNumber) {
            $application->update(['application_number' => $applicationNumber]);
        }
        $application->update(['program_id' => $program->id]);
        foreach ($application->steps as $step) {
            $payload = $step->payload ?? [];
            if ($step->step_key === 'programme_selection') {
                $payload = [
                    'first_choice_program_id' => $program->id,
                    'first_choice_college_id' => $program->department?->faculty_id,
                    'first_choice_department_id' => $program->department_id,
                    'second_choice_program_id' => null,
                ];
            }
            $step->update(['status' => 'saved', 'payload' => $payload]);
        }

        return $application->fresh(['user', 'steps', 'program.department']);
    }

    private function openIntake(string $entryMode): Intake
    {
        $session = AcademicSession::query()->firstOrCreate(['label' => '2025/2026']);
        $term = AcademicTerm::query()->firstOrCreate(
            ['session_label' => '2025/2026', 'name' => 'First'],
            [
                'academic_session_id' => $session->id,
                'is_current' => true,
            ],
        );

        return Intake::query()->firstOrCreate(
            ['entry_mode' => $entryMode],
            [
                'academic_term_id' => $term->id,
                'name' => strtoupper($entryMode).' 2025',
                'is_open' => true,
                'application_fee_amount' => 5000,
                'opens_on' => now()->subDay()->toDateString(),
                'closes_on' => now()->addMonth()->toDateString(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sitting(string $examType, string $examNumber): array
    {
        return [
            'exam_type' => $examType,
            'exam_center' => 'Abeokuta',
            'exam_year' => '2019',
            'exam_number' => $examNumber,
            'results' => [
                ['subject_id' => $this->subject->id, 'subject_name' => 'English Language', 'grade' => 'C6'],
            ],
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(array $permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Admissions tester',
            'slug' => 'admissions-tester-'.substr(sha1(uniqid()), 0, 8),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
        );
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $row
     */
    private function spreadsheet(string $title, array $columns, array $row): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($columns, null, 'A1');
        $values = [];
        foreach ($columns as $column) {
            $values[] = $row[$column] ?? '';
        }
        $sheet->fromArray([$values], null, 'A2');
        $path = sys_get_temp_dir().'/jupeb-matric-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'jupeb-matric.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function loadWorkbook(string $binary): Spreadsheet
    {
        $path = sys_get_temp_dir().'/jupeb-template-'.uniqid().'.xlsx';
        file_put_contents($path, $binary);

        return IOFactory::load($path);
    }
}
