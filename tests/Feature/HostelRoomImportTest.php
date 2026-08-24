<?php

namespace Tests\Feature;

use App\Models\Hostel;
use App\Models\HostelBed;
use App\Models\HostelBlock;
use App\Models\HostelRoom;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\HostelRoomImportColumns;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class HostelRoomImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCatalog::all() as $perm) {
            Permission::query()->updateOrCreate(['key' => $perm['key']], $perm);
        }
    }

    public function test_hostel_room_import_creates_skips_duplicates_and_fails_unknown_parents(): void
    {
        Sanctum::actingAs($this->staffUser());
        $hostel = Hostel::query()->create([
            'name' => 'Queen Hall',
            'gender' => 'female',
            'category' => 'undergraduate',
            'is_active' => true,
        ]);
        $block = HostelBlock::query()->create(['hostel_id' => $hostel->id, 'name' => 'Block A']);

        $response = $this->get('/api/hostel-rooms/import-template')->assertOk();
        $workbook = $this->loadWorkbook($response->streamedContent());
        foreach (['Instructions', 'Rooms', 'Hostels', 'Blocks'] as $title) {
            $this->assertNotNull($workbook->getSheetByName($title), "Missing sheet {$title}");
        }

        $this->post('/api/hostel-rooms/import', [
            'file' => $this->spreadsheetRows([
                [
                    'hostel_id' => (string) $hostel->id,
                    'block_id' => (string) $block->id,
                    'number' => 'A101',
                    'capacity' => '4',
                    'gender' => 'female',
                    'is_active' => 'yes',
                ],
                [
                    'hostel_id' => (string) $hostel->id,
                    'block_id' => (string) $block->id,
                    'number' => 'A101',
                    'capacity' => '2',
                    'gender' => 'female',
                    'is_active' => 'yes',
                ],
                [
                    'hostel_id' => '99999',
                    'block_id' => (string) $block->id,
                    'number' => 'A102',
                    'capacity' => '4',
                    'gender' => '',
                    'is_active' => '',
                ],
                [
                    'hostel_id' => (string) $hostel->id,
                    'block_id' => '99999',
                    'number' => 'A103',
                    'capacity' => '4',
                    'gender' => '',
                    'is_active' => '',
                ],
            ]),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('failed', 2);

        $room = HostelRoom::query()->where('number', 'A101')->first();
        $this->assertNotNull($room);
        $this->assertSame(4, (int) $room->capacity);
        $this->assertSame('female', $room->gender);
        $this->assertSame(4, HostelBed::query()->where('hostel_room_id', $room->id)->count());
        $this->assertSame(1, HostelRoom::query()->count());
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function spreadsheetRows(array $rows): UploadedFile
    {
        $columns = HostelRoomImportColumns::all();
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(HostelRoomImportColumns::SHEET);
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
        $path = sys_get_temp_dir().'/hostel-room-import-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'rooms.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function loadWorkbook(string $binary): Spreadsheet
    {
        $path = sys_get_temp_dir().'/hostel-room-template-'.uniqid().'.xlsx';
        file_put_contents($path, $binary);

        return IOFactory::load($path);
    }

    private function staffUser(): User
    {
        $role = Role::query()->create([
            'name' => 'Hostel importer',
            'slug' => 'hostel-importer-'.substr(sha1(uniqid('', true)), 0, 8),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->where('key', 'hostel.manage')->pluck('id')
        );
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }
}
