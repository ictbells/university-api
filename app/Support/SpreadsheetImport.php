<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpreadsheetImport
{
    public const QUEUE_ROW_THRESHOLD = 40;

    public static function shouldQueue(int $rowCount, bool $force = false): bool
    {
        if (config('queue.default') === 'sync') {
            return false;
        }

        return $force || $rowCount >= self::QUEUE_ROW_THRESHOLD;
    }

    public static function storeUpload(UploadedFile $file, string $directory): string
    {
        return $file->store($directory);
    }

    public static function cacheResult(string $key, array $result): void
    {
        Cache::put($key, $result, now()->addHours(6));
    }

    public static function cachedResult(string $key): ?array
    {
        $result = Cache::get($key);

        return is_array($result) ? $result : null;
    }

    /**
     * @return list<list<mixed>>
     */
    public static function readRows(string $path, ?string $sheetName = null): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $sheetName
            ? ($spreadsheet->getSheetByName($sheetName) ?: $spreadsheet->getActiveSheet())
            : $spreadsheet->getActiveSheet();

        return $sheet->toArray(null, true, true, false);
    }

    public static function countDataRows(string $path, ?string $sheetName = null): int
    {
        $rows = self::readRows($path, $sheetName);
        $count = 0;
        for ($i = 1; $i < count($rows); $i++) {
            if (is_array($rows[$i]) && ! self::rowEmpty($rows[$i])) {
                $count++;
            }
        }

        return $count;
    }

    public static function normalizeHeader(string $value): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($value)));
    }

    /**
     * @param  list<mixed>  $headers
     * @return array<string, int>
     */
    public static function indexHeaders(array $headers): array
    {
        $indexes = [];
        foreach ($headers as $index => $header) {
            $normalized = self::normalizeHeader((string) $header);
            if ($normalized !== '') {
                $indexes[$normalized] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $indexes
     * @return array<string, string>
     */
    public static function mapRow(array $row, array $indexes): array
    {
        $mapped = [];
        foreach ($indexes as $column => $index) {
            $mapped[$column] = trim((string) ($row[$index] ?? ''));
        }

        return $mapped;
    }

    /**
     * @param  list<mixed>  $row
     */
    public static function rowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    public static function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        $time = strtotime($value);

        return $time ? date('Y-m-d', $time) : $value;
    }

    public static function parseDateTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $time = strtotime($value);

        return $time ? date('Y-m-d H:i:s', $time) : null;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $instructions
     * @param  array<string, string>  $sample
     */
    public static function templateDownload(
        string $sheetTitle,
        array $columns,
        array $instructions,
        array $sample,
        string $filename,
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet;
        $help = $spreadsheet->getActiveSheet();
        $help->setTitle('Instructions');
        $help->fromArray(array_map(fn (string $line) => [$line], $instructions), null, 'A1');
        $help->getColumnDimension('A')->setWidth(120);

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($sheetTitle);
        $sheet->fromArray($columns, null, 'A1');
        $values = [];
        foreach ($columns as $column) {
            $values[] = $sample[$column] ?? '';
        }
        $sheet->fromArray([$values], null, 'A2');
        foreach ($columns as $index => $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setAutoSize(true);
        }
        $spreadsheet->setActiveSheetIndex(1);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @param  list<string>  $headers
     */
    public static function errorSpreadsheet(array $errors, array $headers, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $line = 2;
        foreach ($errors as $error) {
            $row = [];
            foreach ($headers as $header) {
                $row[] = $error[$header] ?? '';
            }
            $sheet->fromArray([$row], null, 'A'.$line);
            $line++;
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
