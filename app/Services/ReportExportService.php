<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Setting;
use App\Support\ExportNumbers;
use App\Support\InstitutionLogo;
use App\Support\PdfBinaryResponse;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
    /**
     * @param  list<array{key: string, label: string, type?: string, aggregatable?: bool}>  $headers
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $filterSummary
     * @param  array<string, float|int>  $totals
     */
    public function export(
        string $format,
        array $headers,
        array $rows,
        string $title,
        array $filterSummary = [],
        array $totals = [],
    ): Response {
        $institution = $this->institution();
        $generatedAt = now()->format('d M Y H:i');
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $title) ?: 'report';
        $filename = $safeTitle.'_'.now()->format('Ymd_His');
        $rowCollection = Collection::make($rows);
        if ($totals === []) {
            $totals = $this->totalsFromRows($headers, $rows);
        }

        return match ($format) {
            'pdf' => $this->pdf($institution, $title, $filterSummary, $headers, $rowCollection, $totals, $generatedAt, $filename),
            'excel' => $this->excel($institution, $title, $filterSummary, $headers, $rowCollection, $totals, $generatedAt, $filename),
            'word' => $this->word($institution, $title, $filterSummary, $headers, $rowCollection, $totals, $generatedAt, $filename),
            default => throw new \InvalidArgumentException('Unsupported export format.'),
        };
    }

    /**
     * @return array{name: string, motto: string, address: string, contact: string}
     */
    private function institution(): array
    {
        $campus = Campus::query()->where('is_active', true)->orderBy('id')->first()
            ?? Campus::query()->orderBy('id')->first();

        return [
            'name' => (string) Setting::getValue('university_name', 'Bells University of Technology'),
            'motto' => (string) Setting::getValue('university_motto', 'Chords of Knowledge'),
            'address' => trim(collect([$campus?->address, $campus?->city])->filter()->implode(', ')),
            'contact' => (string) Setting::getValue('university_contact', ''),
        ];
    }

    /**
     * @param  list<array{key: string, label: string, type?: string, aggregatable?: bool}>  $headers
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function totalsFromRows(array $headers, array $rows): array
    {
        $totals = [];
        foreach ($headers as $header) {
            if (! $this->isNumericHeader($header)) {
                continue;
            }
            $sum = 0.0;
            foreach ($rows as $row) {
                $sum += ExportNumbers::toNumber($row[$header['key']] ?? null) ?? 0.0;
            }
            $totals[$header['key']] = round($sum, 2);
        }

        return $totals;
    }

    /**
     * @param  array{key: string, label: string, type?: string, aggregatable?: bool}  $header
     */
    private function isNumericHeader(array $header): bool
    {
        if (($header['type'] ?? '') === 'number' && ($header['aggregatable'] ?? false)) {
            return true;
        }

        return ($header['type'] ?? '') === 'number' && ExportNumbers::isMoneyField($header['key'], $header['label'] ?? '');
    }

    /**
     * @param  array{key: string, label: string, type?: string, aggregatable?: bool}  $header
     */
    private function displayValue(array $header, mixed $value): string
    {
        if (($header['type'] ?? '') === 'number') {
            $decimals = ExportNumbers::isMoneyField($header['key'], $header['label'] ?? '') ? 2 : 0;

            return ExportNumbers::display($value, $decimals);
        }

        return $value === null || $value === '' ? '—' : (string) $value;
    }

    /**
     * @param  array{name: string, motto: string, address: string, contact: string}  $institution
     * @param  list<string>  $filterSummary
     * @param  list<array{key: string, label: string, type?: string, aggregatable?: bool}>  $headers
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, float|int>  $totals
     */
    private function pdf(
        array $institution,
        string $title,
        array $filterSummary,
        array $headers,
        Collection $rows,
        array $totals,
        string $generatedAt,
        string $filename,
    ): Response {
        $html = view('exports.custom-report-pdf', [
            'institution' => $institution,
            'title' => $title,
            'filterSummary' => $filterSummary,
            'headers' => $headers,
            'rows' => $rows,
            'totals' => $totals,
            'generatedAt' => $generatedAt,
            'count' => $rows->count(),
            'logo_data_uri' => InstitutionLogo::dataUri(),
            'displayValue' => fn (array $header, mixed $value) => $this->displayValue($header, $value),
        ])->render();

        return PdfBinaryResponse::fromHtml($html, $filename);
    }

    /**
     * @param  array{name: string, motto: string, address: string, contact: string}  $institution
     * @param  list<string>  $filterSummary
     * @param  list<array{key: string, label: string, type?: string, aggregatable?: bool}>  $headers
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, float|int>  $totals
     */
    private function excel(
        array $institution,
        string $title,
        array $filterSummary,
        array $headers,
        Collection $rows,
        array $totals,
        string $generatedAt,
        string $filename,
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($title, 0, 31) ?: 'Report');
        $labels = ['S/N', ...array_column($headers, 'label')];
        $columnCount = count($labels);
        $lastCol = Coordinate::stringFromColumnIndex($columnCount);

        $row = 1;
        $logoPath = InstitutionLogo::path();
        if ($logoPath !== null) {
            $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
            $sheet->getRowDimension($row)->setRowHeight(52);
            $drawing = new Drawing;
            $drawing->setPath($logoPath);
            $drawing->setHeight(44);
            $drawing->setCoordinates('A'.$row);
            $drawing->setWorksheet($sheet);
            $row++;
        }

        $sheet->setCellValue('A'.$row, $institution['name']);
        $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0C4A6E');
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
        $sheet->setCellValue('A'.$row, $institution['motto']);
        $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
        $sheet->getStyle('A'.$row)->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('64748B');
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;
        $sheet->setCellValue('A'.$row, $title);
        $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
        $meta = 'Generated '.$generatedAt.' · '.$rows->count().' record(s)';
        if ($filterSummary !== []) {
            $meta .= ' · Filters: '.implode('; ', $filterSummary);
        }
        $sheet->setCellValue('A'.$row, $meta);
        $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
        $sheet->getStyle('A'.$row)->getFont()->setSize(9)->getColor()->setRGB('64748B');
        $row += 2;

        $headerRow = $row;
        foreach ($labels as $index => $label) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$headerRow, $label);
        }
        $headerRange = 'A'.$headerRow.':'.$lastCol.$headerRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0C4A6E');

        $rowIndex = $headerRow + 1;
        foreach ($rows as $i => $data) {
            $sheet->setCellValueExplicit('A'.$rowIndex, $i + 1, DataType::TYPE_NUMERIC);
            foreach ($headers as $colIndex => $header) {
                $cell = Coordinate::stringFromColumnIndex($colIndex + 2).$rowIndex;
                $numeric = ($header['type'] ?? '') === 'number';
                $money = ExportNumbers::isMoneyField($header['key'], $header['label'] ?? '');
                ExportNumbers::writeCell($sheet, $cell, $data[$header['key']] ?? '—', $numeric, $money);
            }
            $rowIndex++;
        }

        if ($rows->isNotEmpty() || $totals !== []) {
            $totalRow = $rowIndex;
            $sheet->setCellValue('A'.$totalRow, 'Total');
            foreach ($headers as $colIndex => $header) {
                $cell = Coordinate::stringFromColumnIndex($colIndex + 2).$totalRow;
                if ($this->isNumericHeader($header) && array_key_exists($header['key'], $totals)) {
                    $money = ExportNumbers::isMoneyField($header['key'], $header['label'] ?? '');
                    ExportNumbers::writeCell($sheet, $cell, $totals[$header['key']], true, $money);
                } else {
                    $sheet->setCellValue($cell, '');
                }
            }
            $sheet->getStyle('A'.$totalRow.':'.$lastCol.$totalRow)->getFont()->setBold(true);
            $sheet->getStyle('A'.$totalRow.':'.$lastCol.$totalRow)->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0F2FE');
            $rowIndex++;
        }

        $endRow = max($headerRow, $rowIndex - 1);
        $sheet->getStyle('A'.$headerRow.':'.$lastCol.$endRow)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        for ($i = 1; $i <= $columnCount; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array{name: string, motto: string, address: string, contact: string}  $institution
     * @param  list<string>  $filterSummary
     * @param  list<array{key: string, label: string, type?: string, aggregatable?: bool}>  $headers
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, float|int>  $totals
     */
    private function word(
        array $institution,
        string $title,
        array $filterSummary,
        array $headers,
        Collection $rows,
        array $totals,
        string $generatedAt,
        string $filename,
    ): StreamedResponse {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(9);
        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'marginTop' => 500,
            'marginBottom' => 500,
            'marginLeft' => 500,
            'marginRight' => 500,
        ]);

        $logoPath = InstitutionLogo::path();
        if ($logoPath !== null) {
            $section->addImage($logoPath, ['height' => 48, 'alignment' => Jc::CENTER]);
        }
        $section->addText($institution['name'], ['bold' => true, 'size' => 16, 'color' => '0C4A6E'], ['alignment' => Jc::CENTER]);
        if ($institution['motto'] !== '') {
            $section->addText($institution['motto'], ['italic' => true, 'size' => 10, 'color' => '64748B'], ['alignment' => Jc::CENTER]);
        }
        $section->addTextBreak(1);
        $section->addText($title, ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
        $meta = 'Generated '.$generatedAt.' · '.$rows->count().' record(s)';
        if ($filterSummary !== []) {
            $meta .= ' · Filters: '.implode('; ', $filterSummary);
        }
        $section->addText($meta, ['size' => 9, 'color' => '64748B'], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);

        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => 'CBD5E1',
            'cellMargin' => 40,
        ]);
        $headerStyle = ['bold' => true, 'color' => 'FFFFFF'];
        $headerCell = ['bgColor' => '0C4A6E', 'valign' => 'center'];
        $totalStyle = ['bold' => true, 'color' => '0C4A6E'];
        $totalCell = ['bgColor' => 'E0F2FE', 'valign' => 'center'];

        $table->addRow();
        $table->addCell(600, $headerCell)->addText('S/N', $headerStyle);
        foreach ($headers as $header) {
            $table->addCell(1400, $headerCell)->addText(htmlspecialchars($header['label']), $headerStyle);
        }

        foreach ($rows as $index => $data) {
            $table->addRow();
            $table->addCell(600)->addText((string) ($index + 1));
            foreach ($headers as $header) {
                $table->addCell(1400)->addText(htmlspecialchars($this->displayValue($header, $data[$header['key']] ?? '—')));
            }
        }

        $table->addRow();
        $table->addCell(600, $totalCell)->addText('Total', $totalStyle);
        foreach ($headers as $header) {
            $value = $this->isNumericHeader($header) && array_key_exists($header['key'], $totals)
                ? $this->displayValue($header, $totals[$header['key']])
                : '';
            $table->addCell(1400, $totalCell)->addText(htmlspecialchars($value), $totalStyle);
        }

        return response()->streamDownload(function () use ($phpWord) {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        }, $filename.'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }
}
