<?php

namespace App\Services;

use App\Models\Hostel;
use App\Models\HostelBlock;
use App\Models\HostelRoom;
use App\Support\CatalogImportSkipped;
use App\Support\HostelRoomImportColumns;
use App\Support\ImportLookupSheets;
use App\Support\SpreadsheetImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class HostelRoomImportService
{
    public function __construct(private HostelRoomService $rooms) {}

    /**
     * @return array<string, mixed>
     */
    public function import(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! $path || ! is_readable($path)) {
            throw new \InvalidArgumentException('Unable to read the uploaded file.');
        }

        $rows = SpreadsheetImport::readRows($path, HostelRoomImportColumns::SHEET);
        if (count($rows) < 2) {
            throw new \InvalidArgumentException('File must include a header row and at least one data row.');
        }

        $headers = array_map(fn ($value) => SpreadsheetImport::normalizeHeader((string) $value), $rows[0]);
        $indexes = SpreadsheetImport::indexHeaders($headers);
        foreach (HostelRoomImportColumns::required() as $field) {
            if (! array_key_exists($field, $indexes)) {
                throw new \InvalidArgumentException("The spreadsheet must include a {$field} column.");
            }
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $line = $i + 1;
            if (! is_array($row) || SpreadsheetImport::rowEmpty($row)) {
                continue;
            }
            $data = SpreadsheetImport::mapRow($row, $indexes);
            try {
                DB::transaction(fn () => $this->importRow($data));
                $created++;
            } catch (CatalogImportSkipped $e) {
                $skipped++;
                $errors[] = ['row' => $line, 'message' => $e->getMessage()];
            } catch (Throwable $e) {
                $failed++;
                $errors[] = ['row' => $line, 'message' => $e->getMessage()];
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    public function template(): StreamedResponse
    {
        return SpreadsheetImport::templateDownload(
            HostelRoomImportColumns::SHEET,
            HostelRoomImportColumns::all(),
            HostelRoomImportColumns::instructions(),
            HostelRoomImportColumns::sample(),
            HostelRoomImportColumns::FILENAME,
            [
                ImportLookupSheets::hostels(),
                ImportLookupSheets::hostelBlocks(),
            ],
        );
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importRow(array $data): void
    {
        foreach (HostelRoomImportColumns::required() as $field) {
            if (blank($data[$field] ?? null)) {
                throw new RuntimeException("Missing required field: {$field}.");
            }
        }

        $hostel = $this->findHostel($data['hostel_id']);
        $block = $this->findBlock($hostel, $data['block_id']);
        $number = trim($data['number']);
        if ($this->roomExists($block, $number)) {
            throw new CatalogImportSkipped('A room with this number already exists in this block.');
        }

        $capacity = (int) $data['capacity'];
        if ($capacity < 1 || $capacity > 20) {
            throw new RuntimeException('capacity must be between 1 and 20.');
        }

        $gender = strtolower(trim((string) ($data['gender'] ?? '')));
        if ($gender !== '' && ! in_array($gender, ['male', 'female'], true)) {
            throw new RuntimeException('gender must be male or female.');
        }

        $this->rooms->storeRoom($block, [
            'number' => $number,
            'capacity' => $capacity,
            'gender' => $gender !== '' ? $gender : null,
            'is_active' => array_key_exists('is_active', $data) && $data['is_active'] !== ''
                ? $this->boolish($data['is_active'])
                : true,
        ]);
    }

    private function findHostel(string $value): Hostel
    {
        $id = SpreadsheetImport::parseId($value, 'hostel_id');
        $hostel = Hostel::query()->find($id);
        if (! $hostel) {
            throw new RuntimeException('Unknown hostel_id.');
        }

        return $hostel;
    }

    private function findBlock(Hostel $hostel, string $value): HostelBlock
    {
        $id = SpreadsheetImport::parseId($value, 'block_id');
        $block = HostelBlock::query()->find($id);
        if (! $block) {
            throw new RuntimeException('Unknown block_id.');
        }
        if ((int) $block->hostel_id !== (int) $hostel->id) {
            throw new RuntimeException('block_id does not belong to hostel_id.');
        }

        return $block;
    }

    private function roomExists(HostelBlock $block, string $number): bool
    {
        return HostelRoom::query()
            ->where('hostel_block_id', $block->id)
            ->whereRaw('UPPER(number) = ?', [strtoupper($number)])
            ->exists();
    }

    private function boolish(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'y'], true);
    }
}
