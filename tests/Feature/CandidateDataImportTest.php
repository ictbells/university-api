<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Intake;
use App\Models\OfficeDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\CandidateDataImportColumns;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CandidateDataImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
    }

    public function test_sessions_endpoint_returns_application_sessions(): void
    {
        $intake = $this->openIntake();
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/candidate-data/sessions')
            ->assertOk()
            ->assertJsonPath('intakes.0.id', $intake->id)
            ->assertJsonPath('intakes.0.name', 'UTME 2025')
            ->assertJsonPath('intakes.0.session_label', '2025/2026')
            ->assertJsonMissingPath('terms');
    }

    public function test_template_download_includes_candidate_headers(): void
    {
        Sanctum::actingAs($this->staffUser());

        $this->get('/api/candidate-data/import-template')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_upload_stores_candidates_against_the_application_session_year(): void
    {
        $intake = $this->openIntake();
        Sanctum::actingAs($this->staffUser());

        $this->post('/api/candidate-data/upload', [
            'file' => $this->spreadsheet([
                'registration_number' => '20261234AB',
                'candidate_name' => 'Ada Okoye',
            ]),
            'intake_id' => $intake->id,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.imported', 1);

        $this->assertDatabaseHas('candidate_data', [
            'rg_num' => '20261234AB',
            'academic_year' => '2025/2026',
            'rg_candname' => 'Ada Okoye',
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function spreadsheet(array $row): UploadedFile
    {
        $columns = CandidateDataImportColumns::all();
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($columns, null, 'A1');
        $values = [];
        foreach ($columns as $column) {
            $values[] = $row[$column] ?? '';
        }
        $sheet->fromArray([$values], null, 'A2');
        $path = sys_get_temp_dir().'/candidate-data-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'candidates.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function openIntake(): Intake
    {
        $session = AcademicSession::query()->create(['label' => '2025/2026']);
        $term = AcademicTerm::query()->create([
            'academic_session_id' => $session->id,
            'name' => 'First',
            'session_label' => '2025/2026',
            'is_current' => true,
        ]);

        return Intake::query()->create([
            'academic_term_id' => $term->id,
            'name' => 'UTME 2025',
            'entry_mode' => 'utme',
            'is_open' => true,
            'application_fee_amount' => 5000,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
        ]);
    }

    private function staffUser(): User
    {
        $role = Role::query()->create([
            'name' => 'Candidate importer',
            'slug' => 'cand-import-'.substr(sha1(uniqid('', true)), 0, 8),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['admissions.import'])->pluck('id')
        );
        $office = OfficeDepartment::query()->create([
            'name' => 'Admissions '.$role->slug,
            'code' => substr($role->slug, 0, 20),
            'is_active' => true,
        ]);
        $office->syncNavKeys(['candidate-data']);
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        Staff::query()->create([
            'user_id' => $user->id,
            'staff_number' => 'ST-'.strtoupper(substr($role->slug, -8)),
            'office_department_id' => $office->id,
        ]);

        return $user->fresh(['roles.permissions', 'staff']);
    }
}
