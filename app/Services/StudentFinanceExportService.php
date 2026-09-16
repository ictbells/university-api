<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Setting;
use App\Support\ExportNumbers;
use App\Support\InstitutionLogo;
use App\Support\PdfBinaryResponse;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentFinanceExportService
{
    public const MAX_ROWS = 5000;

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $filterSummary
     */
    public function export(string $format, Collection $rows, string $title, array $filterSummary = []): Response
    {
        $institution = $this->institution();
        $generatedAt = now()->format('d M Y H:i:s');
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $title) ?: 'student_finance';
        $filename = $safeTitle.'_'.now()->format('Ymd_His');
        $totals = [
            'wallet' => round($rows->sum(fn ($row) => (float) $row['wallet']), 2),
            'billed' => round($rows->sum(fn ($row) => (float) $row['billed']), 2),
            'paid' => round($rows->sum(fn ($row) => (float) $row['paid']), 2),
            'outstanding' => round($rows->sum(fn ($row) => (float) $row['outstanding']), 2),
        ];

        return match ($format) {
            'pdf' => $this->pdf($institution, $title, $filterSummary, $rows, $totals, $generatedAt, $filename),
            'excel' => $this->excel($institution, $title, $filterSummary, $rows, $totals, $generatedAt, $filename),
            'word' => $this->word($institution, $title, $filterSummary, $rows, $totals, $generatedAt, $filename),
            default => throw new \InvalidArgumentException('Unsupported export format.'),
        };
    }

    /**
     * @return array{name: string, motto: string}
     */
    private function institution(): array
    {
        $campus = Campus::query()->where('is_active', true)->orderBy('id')->first()
            ?? Campus::query()->orderBy('id')->first();

        return [
            'name' => (string) Setting::getValue('university_name', 'Bells University of Technology'),
            'motto' => (string) Setting::getValue('university_motto', 'Chords of Knowledge'),
            'address' => trim(collect([$campus?->address, $campus?->city])->filter()->implode(', ')),
        ];
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return ['S/N', 'Student', 'Matric', 'Programme', 'College', 'Level', 'Wallet', 'Billed', 'Paid', 'Outstanding', 'Clearance'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function values(array $row, int $sn, bool $display = false): array
    {
        return [
            $sn,
            $row['name'],
            $row['matric'],
            $row['programme'],
            $row['college'],
            $row['level'],
            $display ? ExportNumbers::display($row['wallet']) : $row['wallet'],
            $display ? ExportNumbers::display($row['billed']) : $row['billed'],
            $display ? ExportNumbers::display($row['paid']) : $row['paid'],
            $display ? ExportNumbers::display($row['outstanding']) : $row['outstanding'],
            $row['clearance'],
        ];
    }

    /**
     * @return list<int>
     */
    private function moneyIndexes(): array
    {
        return [6, 7, 8, 9];
    }

    /**
     * @param  array{name: string, motto: string}  $institution
     * @param  list<string>  $filterSummary
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{wallet: float, billed: float, paid: float, outstanding: float}  $totals
     */
    private function pdf(
        array $institution,
        string $title,
        array $filterSummary,
        Collection $rows,
        array $totals,
        string $generatedAt,
        string $filename,
    ): Response {
        $html = view('exports.student-finance-pdf', [
            'institution' => $institution,
            'title' => $title,
            'filterSummary' => $filterSummary,
            'rows' => $rows,
            'totals' => $totals,
            'generatedAt' => $generatedAt,
            'count' => $rows->count(),
            'logo_data_uri' => InstitutionLogo::dataUri(),
        ])->render();

        return PdfBinaryResponse::fromHtml($html, $filename);
    }

    /**
     * @param  array{name: string, motto: string}  $institution
     * @param  list<string>  $filterSummary
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{wallet: float, billed: float, paid: float, outstanding: float}  $totals
     */
    private function excel(
        array $institution,
        string $title,
        array $filterSummary,
        Collection $rows,
        array $totals,
        string $generatedAt,
        string $filename,
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Financial status');
        $headers = $this->headers();
        $lastCol = chr(64 + count($headers));

        $row = 1;
        $sheet->setCellValue('A'.$row, $institution['name']);
        $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0C4A6E');
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
        $sheet->setCellValue('A'.$row, $institution['motto'] ?? '');
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
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(chr(65 + $index).$headerRow, $header);
        }
        $headerRange = 'A'.$headerRow.':'.$lastCol.$headerRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0C4A6E');

        $rowIndex = $headerRow + 1;
        $moneyIndexes = $this->moneyIndexes();
        foreach ($rows as $i => $data) {
            foreach ($this->values($data, $i + 1) as $index => $value) {
                $cell = chr(65 + $index).$rowIndex;
                if ($index === 0 || in_array($index, $moneyIndexes, true)) {
                    ExportNumbers::writeCell($sheet, $cell, $value, true, $index !== 0);
                } else {
                    $sheet->setCellValue($cell, $value);
                }
            }
            $rowIndex++;
        }

        $totalRow = $rowIndex;
        $sheet->setCellValue('A'.$totalRow, 'Total');
        ExportNumbers::writeCell($sheet, 'G'.$totalRow, $totals['wallet'], true, true);
        ExportNumbers::writeCell($sheet, 'H'.$totalRow, $totals['billed'], true, true);
        ExportNumbers::writeCell($sheet, 'I'.$totalRow, $totals['paid'], true, true);
        ExportNumbers::writeCell($sheet, 'J'.$totalRow, $totals['outstanding'], true, true);
        $sheet->getStyle('A'.$totalRow.':'.$lastCol.$totalRow)->getFont()->setBold(true);
        $sheet->getStyle('A'.$totalRow.':'.$lastCol.$totalRow)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0F2FE');
        $rowIndex++;

        $sheet->getStyle('A'.$headerRow.':'.$lastCol.max($headerRow, $rowIndex - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        foreach (range('A', $lastCol) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
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
     * @param  array{name: string, motto: string}  $institution
     * @param  list<string>  $filterSummary
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{wallet: float, billed: float, paid: float, outstanding: float}  $totals
     */
    private function word(
        array $institution,
        string $title,
        array $filterSummary,
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

        $section->addText($institution['name'], ['bold' => true, 'size' => 16, 'color' => '0C4A6E'], ['alignment' => Jc::CENTER]);
        $section->addText((string) ($institution['motto'] ?? ''), ['italic' => true, 'size' => 10, 'color' => '64748B'], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);
        $section->addText($title, ['bold' => true, 'size' => 13], ['alignment' => Jc::CENTER]);
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
        $table->addRow();
        foreach ($this->headers() as $header) {
            $cell = $table->addCell(1400, ['bgColor' => '0C4A6E']);
            $cell->addText($header, ['bold' => true, 'color' => 'FFFFFF', 'size' => 8]);
        }
        foreach ($rows as $i => $data) {
            $table->addRow();
            foreach ($this->values($data, $i + 1, true) as $value) {
                $cell = $table->addCell(1400);
                $cell->addText((string) $value, ['size' => 8, 'color' => '1E293B']);
            }
        }
        $table->addRow();
        foreach ([
            'Total', '', '', '', '', '',
            ExportNumbers::display($totals['wallet']),
            ExportNumbers::display($totals['billed']),
            ExportNumbers::display($totals['paid']),
            ExportNumbers::display($totals['outstanding']),
            '',
        ] as $value) {
            $cell = $table->addCell(1400, ['bgColor' => 'E0F2FE']);
            $cell->addText((string) $value, ['bold' => true, 'size' => 8, 'color' => '0C4A6E']);
        }

        return response()->streamDownload(function () use ($phpWord) {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save('php://output');
        }, $filename.'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }
}
