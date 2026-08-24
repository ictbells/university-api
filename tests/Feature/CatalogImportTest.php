<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\OfficeDepartment;
use App\Models\OlevelSubject;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\CatalogImportColumns;
use App\Support\PermissionCatalog;
use App\Support\WorkflowCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
        WorkflowCatalog::seed();
    }

    public function test_college_import_creates_unknown_campus_fails_and_duplicate_code_is_skipped(): void
    {
        Sanctum::actingAs($this->staffUser());
        Campus::query()->create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);

        $response = $this->get('/api/academic/faculties/import-template')
            ->assertOk();
        $workbook = $this->loadWorkbook($response->streamedContent());
        foreach (['Instructions', 'Colleges', 'Campuses'] as $title) {
            $this->assertNotNull($workbook->getSheetByName($title), "Missing sheet {$title}");
        }

        $this->post('/api/academic/faculties/import', [
            'file' => $this->spreadsheetRows('Colleges', CatalogImportColumns::all('colleges'), [
                ['name' => 'College of Science', 'code' => 'COLNAS', 'campus_code' => 'MAIN'],
                ['name' => 'College of Arts', 'code' => 'COLNAS', 'campus_code' => 'MAIN'],
                ['name' => 'Ghost College', 'code' => 'GHOST', 'campus_code' => 'NOPE'],
            ]),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('failed', 1);

        $this->assertSame(1, Faculty::query()->count());
        $this->assertDatabaseHas('faculties', ['name' => 'College of Science', 'code' => 'COLNAS']);
        $this->assertDatabaseMissing('faculties', ['code' => 'GHOST']);
    }

    public function test_department_import_requires_an_existing_college_code(): void
    {
        Sanctum::actingAs($this->staffUser());
        $campus = Campus::query()->create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
        Faculty::query()->create(['campus_id' => $campus->id, 'name' => 'College of Science', 'code' => 'COLNAS']);

        $this->post('/api/academic/departments/import', [
            'file' => $this->spreadsheetRows('Departments', CatalogImportColumns::all('departments'), [
                ['name' => 'Computer Science', 'code' => 'CSC', 'college_code' => 'COLNAS'],
                ['name' => 'Physics', 'code' => 'PHY', 'college_code' => 'MISSING'],
            ]),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('failed', 1);

        $this->assertSame(1, Department::query()->count());
        $this->assertDatabaseHas('departments', ['name' => 'Computer Science', 'code' => 'CSC']);
    }

    public function test_programme_import_creates_with_entry_modes_and_unknown_department_fails(): void
    {
        Sanctum::actingAs($this->staffUser());
        $this->seedAcademicTree();

        $this->post('/api/academic/programs/import', [
            'file' => $this->spreadsheetRows('Programmes', CatalogImportColumns::all('programmes'), [
                [
                    'name' => 'B.Sc Computer Science',
                    'code' => 'BSC-CS',
                    'department_code' => 'CSC',
                    'award_type' => 'B.Sc',
                    'study_level' => 'undergraduate',
                    'duration_years' => '4',
                    'entry_modes' => 'utme,de',
                    'is_research_degree' => 'no',
                ],
                [
                    'name' => 'B.Sc Physics',
                    'code' => 'BSC-PHY',
                    'department_code' => 'MISSING',
                    'award_type' => 'B.Sc',
                    'study_level' => 'undergraduate',
                    'duration_years' => '4',
                    'entry_modes' => 'utme',
                    'is_research_degree' => '',
                ],
            ]),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('failed', 1);

        $program = Program::query()->where('code', 'BSC-CS')->first();
        $this->assertNotNull($program);
        $this->assertSame(['utme', 'de'], $program->entry_modes);
        $this->assertNotNull($program->workflow_template_id);
        $this->assertSame(1, Program::query()->count());
    }

    public function test_olevel_import_creates_and_skips_duplicate_name(): void
    {
        Sanctum::actingAs($this->staffUser());

        $this->post('/api/academic/olevel-subjects/import', [
            'file' => $this->spreadsheetRows('Olevel', CatalogImportColumns::all('olevel'), [
                ['name' => 'English Language', 'code' => '', 'is_active' => 'yes'],
                ['name' => 'English Language', 'code' => '', 'is_active' => 'yes'],
            ]),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('skipped', 1);

        $this->assertSame(1, OlevelSubject::query()->count());
        $this->assertDatabaseHas('olevel_subjects', ['name' => 'English Language']);
    }

    public function test_course_import_creates_and_skips_existing_code_without_update(): void
    {
        Sanctum::actingAs($this->staffUser());
        $tree = $this->seedAcademicTree();
        $program = Program::query()->create([
            'department_id' => $tree['department']->id,
            'name' => 'B.Sc Computer Science',
            'code' => 'BSC-CS',
            'award_type' => 'B.Sc',
            'study_level' => 'undergraduate',
            'entry_modes' => ['utme'],
            'duration_years' => 4,
            'is_active' => true,
            'workflow_template_id' => WorkflowCatalog::idByCode(WorkflowCatalog::UG_STANDARD),
        ]);
        $existing = Course::query()->create([
            'department_id' => $tree['department']->id,
            'code' => 'CSC 101',
            'title' => 'Introduction to Computing',
            'units' => 3,
            'course_type' => 'departmental',
        ]);

        $this->post('/api/academic/courses/import', [
            'file' => $this->spreadsheetRows('Courses', CatalogImportColumns::all('courses'), [
                [
                    'code' => 'CSC 101',
                    'title' => 'Changed title',
                    'units' => '4',
                    'course_type' => 'general',
                    'department_code' => 'CSC',
                    'programme_code' => 'BSC-CS',
                    'level_code' => '',
                ],
                [
                    'code' => 'CSC 102',
                    'title' => 'Programming I',
                    'units' => '3',
                    'course_type' => 'departmental',
                    'department_code' => 'CSC',
                    'programme_code' => 'BSC-CS',
                    'level_code' => '',
                ],
            ]),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('skipped', 1);

        $existing->refresh();
        $this->assertSame('Introduction to Computing', $existing->title);
        $this->assertSame(3, (int) $existing->units);
        $this->assertSame('departmental', $existing->course_type);
        $this->assertSame(0, $existing->programs()->count());

        $created = Course::query()->where('code', 'CSC 102')->first();
        $this->assertNotNull($created);
        $this->assertSame('Programming I', $created->title);
        $this->assertTrue($created->programs()->where('programs.id', $program->id)->exists());
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, string>>  $rows
     */
    private function spreadsheetRows(string $title, array $columns, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($columns, null, 'A1');
        $line = 2;
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? '';
            }
            $sheet->fromArray([$values], null, 'A'.$line);
            $line++;
        }
        $path = sys_get_temp_dir().'/catalog-import-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, strtolower($title).'.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function loadWorkbook(string $binary): Spreadsheet
    {
        $path = sys_get_temp_dir().'/catalog-template-'.uniqid().'.xlsx';
        file_put_contents($path, $binary);

        return IOFactory::load($path);
    }

    /**
     * @return array{campus: Campus, faculty: Faculty, department: Department}
     */
    private function seedAcademicTree(): array
    {
        $campus = Campus::query()->create(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
        $faculty = Faculty::query()->create([
            'campus_id' => $campus->id,
            'name' => 'College of Science',
            'code' => 'COLNAS',
        ]);
        $department = Department::query()->create([
            'faculty_id' => $faculty->id,
            'name' => 'Computer Science',
            'code' => 'CSC',
        ]);

        return compact('campus', 'faculty', 'department');
    }

    private function staffUser(): User
    {
        $role = Role::query()->create([
            'name' => 'Catalogue importer',
            'slug' => 'catalog-importer-'.substr(sha1(uniqid('', true)), 0, 8),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', [
                'academic.colleges.manage',
                'academic.departments.manage',
                'academic.programmes.manage',
                'academic.courses.manage',
                'academic.olevel.manage',
            ])->pluck('id')
        );
        $office = OfficeDepartment::query()->create([
            'name' => 'Academic Affairs '.$role->slug,
            'code' => substr($role->slug, 0, 20),
            'is_active' => true,
        ]);
        $office->syncNavKeys(['colleges', 'departments', 'programmes', 'courses', 'olevel']);
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
