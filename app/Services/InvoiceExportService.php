<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Invoice;
use App\Models\Setting;
use App\Support\FeeSchedule;
use App\Support\InstitutionLogo;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Drawing as SharedDrawing;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceExportService
{
    public const MAX_ROWS = 5000;

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  list<string>  $filterSummary
     */
    public function export(string $format, Collection $invoices, string $title, array $filterSummary = []): StreamedResponse
    {
        $institution = $this->institution();
        $rows = $invoices->map(fn (Invoice $invoice) => $this->rowData($invoice))->values();
        $generatedAt = now()->format('d M Y H:i:s');
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $title) ?: 'invoices';
        $filename = $safeTitle.'_'.now()->format('Ymd_His');

        return match ($format) {
            'pdf' => $this->pdf($institution, $title, $filterSummary, $rows, $generatedAt, $filename),
            'excel' => $this->excel($institution, $title, $filterSummary, $rows, $generatedAt, $filename),
            'word' => $this->word($institution, $title, $filterSummary, $rows, $generatedAt, $filename),
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
     * @return array<string, string>
     */
    private function rowData(Invoice $invoice): array
    {
        $student = $invoice->student;
        $name = trim(collect([$student?->first_name, $student?->last_name])->filter()->implode(' '));
        $status = $invoice->status === 'cancelled' ? 'Disabled' : (string) $invoice->status;

        return [
            'number' => (string) ($invoice->number ?: '—'),
            'payer' => $name !== '' ? $name : ($invoice->user?->name ?: '—'),
            'matric' => $this->payerIdentifier($invoice),
            'category' => FeeSchedule::label((string) $invoice->category),
            'programme' => $student?->program?->name ?: '—',
            'college' => $student?->program?->department?->faculty?->name ?: '—',
            'department' => $student?->program?->department?->name ?: '—',
            'amount' => number_format((float) $invoice->amount, 2),
            'balance' => number_format((float) $invoice->balance, 2),
            'status' => ucfirst($status),
            'date' => optional($invoice->created_at)->format('d M Y H:i:s') ?: '—',
        ];
    }

    private function payerIdentifier(Invoice $invoice): string
    {
        $student = $invoice->student;
        $matric = $student?->matric_number ?: $student?->student_number;
        if ($matric) {
            return (string) $matric;
        }

        $application = $invoice->application ?: $invoice->user?->latestApplication;

        return (string) (
            $application?->jamb_registration
            ?: $invoice->user?->jamb_registration
            ?: $application?->application_number
            ?: '—'
        );
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return ['S/N', 'Number', 'Payer', 'Matric', 'Category', 'Programme', 'College', 'Amount', 'Balance', 'Status', 'Timestamp'];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function rowValues(array $row, int $sn): array
    {
        return [
            (string) $sn,
            $row['number'],
            $row['payer'],
            $row['matric'],
            $row['category'],
            $row['programme'],
            $row['college'],
            $row['amount'],
            $row['balance'],
            $row['status'],
            $row['date'],
        ];
    }

    /**
     * @param  array{name: string, motto: string, address: string, contact: string}  $institution
     * @param  list<string>  $filterSummary
     * @param  Collection<int, array<string, string>>  $rows
     */
    private function pdf(
        array $institution,
        string $title,
        array $filterSummary,
        Collection $rows,
        string $generatedAt,
        string $filename,
    ): StreamedResponse {
        $html = view('exports.invoices-pdf', [
            'institution' => $institution,
            'title' => $title,
            'filterSummary' => $filterSummary,
            'rows' => $rows,
            'generatedAt' => $generatedAt,
            'count' => $rows->count(),
            'logo_data_uri' => InstitutionLogo::dataUri(),
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $output = $dompdf->output();

        return response()->streamDownload(function () use ($output) {
            echo $output;
        }, $filename.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * @param  array{name: string, motto: string, address: string, contact: string}  $institution
     * @param  list<string>  $filterSummary
     * @param  Collection<int, array<string, string>>  $rows
     */
    private function excel(
        array $institution,
        string $title,
        array $filterSummary,
        Collection $rows,
        string $generatedAt,
        string $filename,
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Invoices');
        $headers = $this->headers();
        $columns = array_slice(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'], 0, count($headers));
        $lastCol = $columns[count($columns) - 1];

        $row = 1;
        $logoPath = InstitutionLogo::path();
        if ($logoPath !== null) {
            $logoRow = $row;
            $logoHeight = 44;
            $widths = [8, 18, 20, 16, 14, 22, 18, 12, 12, 12, 20];
            foreach ($columns as $index => $column) {
                $sheet->getColumnDimension($column)->setWidth($widths[$index] ?? 14);
            }

            $sheet->mergeCells('A'.$logoRow.':'.$lastCol.$logoRow);
            $sheet->getRowDimension($logoRow)->setRowHeight(52);
            $sheet->getStyle('A'.$logoRow.':'.$lastCol.$logoRow)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);

            $drawing = new Drawing;
            $drawing->setPath($logoPath);
            $drawing->setHeight($logoHeight);
            $this->centreExcelDrawing($drawing, $sheet, $columns, $logoRow, $spreadsheet->getDefaultStyle()->getFont());
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
        foreach ($headers as $index => $header) {
            $sheet->setCellValue($columns[$index].$headerRow, $header);
        }
        $headerRange = 'A'.$headerRow.':'.$lastCol.$headerRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0C4A6E');

        $rowIndex = $headerRow + 1;
        foreach ($rows as $i => $data) {
            foreach ($this->rowValues($data, $i + 1) as $index => $value) {
                $sheet->setCellValue($columns[$index].$rowIndex, $value);
            }
            $rowIndex++;
        }

        $sheet->getStyle('A'.$headerRow.':'.$lastCol.max($headerRow, $rowIndex - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        if ($logoPath === null) {
            foreach ($columns as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
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
     * @param  Collection<int, array<string, string>>  $rows
     */
    private function word(
        array $institution,
        string $title,
        array $filterSummary,
        Collection $rows,
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
            $section->addImage($logoPath, ['width' => 48, 'height' => 48, 'alignment' => Jc::CENTER]);
        }
        $section->addText($institution['name'], ['bold' => true, 'size' => 16, 'color' => '0C4A6E'], ['alignment' => Jc::CENTER]);
        $section->addText($institution['motto'], ['italic' => true, 'size' => 10, 'color' => '64748B'], ['alignment' => Jc::CENTER]);
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
            'cellMargin' => 30,
            'width' => 100 * 50,
            'unit' => 'pct',
        ]);
        $table->addRow();
        foreach ($this->headers() as $header) {
            $cell = $table->addCell(1200, ['bgColor' => '0C4A6E', 'valign' => 'center']);
            $cell->addText($header, ['bold' => true, 'color' => 'FFFFFF', 'size' => 8]);
        }
        foreach ($rows as $i => $data) {
            $table->addRow();
            foreach ($this->rowValues($data, $i + 1) as $value) {
                $cell = $table->addCell(1200);
                $cell->addText((string) $value, ['size' => 8, 'color' => '1E293B']);
            }
        }

        return response()->streamDownload(function () use ($phpWord) {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save('php://output');
        }, $filename.'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * Excel clamps a large offset on A1 to that cell, so the drawing is
     * anchored in the column where the centred image actually starts.
     *
     * @param  list<string>  $columns
     */
    private function centreExcelDrawing(
        Drawing $drawing,
        Worksheet $sheet,
        array $columns,
        int $row,
        Font $font,
    ): void {
        $widthsPx = [];
        $totalWidthPx = 0;
        foreach ($columns as $column) {
            $columnWidth = (float) $sheet->getColumnDimension($column)->getWidth();
            if ($columnWidth <= 0) {
                $columnWidth = 8.43;
            }
            $px = SharedDrawing::cellDimensionToPixels($columnWidth, $font);
            $widthsPx[] = $px;
            $totalWidthPx += $px;
        }

        $startX = max(0, (int) (($totalWidthPx - $drawing->getWidth()) / 2));
        $cursor = 0;
        $anchor = $columns[0];
        $offsetX = $startX;
        foreach ($columns as $index => $column) {
            $width = $widthsPx[$index];
            if ($cursor + $width > $startX) {
                $anchor = $column;
                $offsetX = $startX - $cursor;
                break;
            }
            $cursor += $width;
        }

        $drawing->setCoordinates($anchor.$row);
        $drawing->setOffsetX(max(0, $offsetX));
        $rowHeightPx = SharedDrawing::pointsToPixels((float) $sheet->getRowDimension($row)->getRowHeight());
        $drawing->setOffsetY(max(0, (int) (($rowHeightPx - $drawing->getHeight()) / 2)));
    }
}
