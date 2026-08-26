<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Campus;
use App\Models\Setting;
use App\Support\InstitutionLogo;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Drawing as SharedDrawing;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationExportService
{
    public const MAX_ROWS = 5000;

    /**
     * @param  Collection<int, Application>  $applications
     * @param  list<string>  $filterSummary
     */
    public function export(
        string $format,
        Collection $applications,
        string $title,
        array $filterSummary = [],
        string $referenceKind = 'application_number',
    ): StreamedResponse {
        $institution = $this->institution();
        $rows = $applications->map(fn (Application $app) => $this->rowData($app, $referenceKind))->values();
        $generatedAt = now()->format('d M Y H:i');
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $title) ?: 'applications';
        $filename = $safeTitle.'_'.now()->format('Ymd_His');

        return match ($format) {
            'pdf' => $this->pdf($institution, $title, $filterSummary, $rows, $referenceKind, $generatedAt, $filename),
            'excel' => $this->excel($institution, $title, $filterSummary, $rows, $referenceKind, $generatedAt, $filename),
            'word' => $this->word($institution, $title, $filterSummary, $rows, $referenceKind, $generatedAt, $filename),
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
            'address' => trim(collect([
                $campus?->address,
                $campus?->city,
            ])->filter()->implode(', ')),
            'contact' => (string) Setting::getValue('university_contact', ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function rowData(Application $app, string $referenceKind): array
    {
        $reference = $referenceKind === 'jamb'
            ? ($app->jamb_registration ?: $app->user?->jamb_registration ?: '—')
            : ($app->application_number ?: '—');

        return [
            'sn' => '',
            'applicant' => $app->user?->name ?: '—',
            'email' => $app->user?->email ?: '—',
            'reference' => (string) $reference,
            'programme' => $app->program?->name ?: ($app->program?->code ?: '—'),
            'category' => strtoupper((string) $app->entry_mode),
            'session' => $app->intake?->term?->session_label ?: '—',
            'submitted' => $app->submitted_at?->format('d M Y H:i:s') ?: '—',
            'fee' => $app->applicationFeeInvoice?->status ?: '—',
            'stage' => str_replace('_', ' ', (string) $app->stage),
        ];
    }

    private function referenceLabel(string $referenceKind): string
    {
        return $referenceKind === 'jamb' ? 'JAMB Number' : 'Application Number';
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
        string $referenceKind,
        string $generatedAt,
        string $filename,
    ): StreamedResponse {
        $html = view('exports.applications-pdf', [
            'institution' => $institution,
            'title' => $title,
            'filterSummary' => $filterSummary,
            'rows' => $rows,
            'referenceLabel' => $this->referenceLabel($referenceKind),
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
        string $referenceKind,
        string $generatedAt,
        string $filename,
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Applications');

        $row = 1;
        $logoPath = InstitutionLogo::path();
        if ($logoPath !== null) {
            $logoRow = $row;
            $logoHeight = 44;

            $sheet->mergeCells('A'.$logoRow.':J'.$logoRow);
            $sheet->getRowDimension($logoRow)->setRowHeight(48);
            $sheet->getStyle('A'.$logoRow.':J'.$logoRow)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);

            $drawing = new Drawing;
            $drawing->setPath($logoPath);
            $drawing->setHeight($logoHeight);
            $drawing->setCoordinates('A'.$logoRow);

            $font = $spreadsheet->getDefaultStyle()->getFont();
            $totalWidthPx = 0;
            foreach (range('A', 'J') as $column) {
                $columnWidth = $sheet->getColumnDimension($column)->getWidth();
                if ($columnWidth <= 0) {
                    $columnWidth = 8.43;
                }
                $totalWidthPx += SharedDrawing::cellDimensionToPixels((float) $columnWidth, $font);
            }

            $drawing->setOffsetX(max(0, (int) (($totalWidthPx - $drawing->getWidth()) / 2)));

            $rowHeightPx = SharedDrawing::pointsToPixels((float) $sheet->getRowDimension($logoRow)->getRowHeight());
            $drawing->setOffsetY(max(0, (int) (($rowHeightPx - $logoHeight) / 2)));

            $drawing->setWorksheet($sheet);
            $row++;
        }

        $sheet->setCellValue('A'.$row, $institution['name']);
        $sheet->mergeCells('A'.$row.':J'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0C4A6E');
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;

        $sheet->setCellValue('A'.$row, $institution['motto']);
        $sheet->mergeCells('A'.$row.':J'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('64748B');
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;

        $addressLine = collect([$institution['address'], $institution['contact']])->filter()->implode(' · ');
        if ($addressLine !== '') {
            $sheet->setCellValue('A'.$row, $addressLine);
            $sheet->mergeCells('A'.$row.':J'.$row);
            $sheet->getStyle('A'.$row)->getFont()->setSize(9)->getColor()->setRGB('64748B');
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        $row++;

        $sheet->setCellValue('A'.$row, $title);
        $sheet->mergeCells('A'.$row.':J'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
        $row++;

        $meta = 'Generated '.$generatedAt.' · '.$rows->count().' record(s)';
        if ($filterSummary !== []) {
            $meta .= ' · Filters: '.implode('; ', $filterSummary);
        }
        $sheet->setCellValue('A'.$row, $meta);
        $sheet->mergeCells('A'.$row.':J'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setSize(9)->getColor()->setRGB('64748B');
        $row += 2;

        $headers = [
            'S/N', 'Applicant', 'Email', $this->referenceLabel($referenceKind),
            'Programme', 'Category', 'Session', 'Submitted', 'App. Fee', 'Stage',
        ];
        $headerRow = $row;
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue($columns[$index].$headerRow, $header);
        }
        $sheet->getStyle('A'.$headerRow.':J'.$headerRow)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A'.$headerRow.':J'.$headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0C4A6E');
        $sheet->getStyle('A'.$headerRow.':J'.$headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $rowIndex = $headerRow + 1;
        foreach ($rows as $i => $row) {
            $sheet->setCellValue("A{$rowIndex}", $i + 1);
            $sheet->setCellValue("B{$rowIndex}", $row['applicant']);
            $sheet->setCellValue("C{$rowIndex}", $row['email']);
            $sheet->setCellValue("D{$rowIndex}", $row['reference']);
            $sheet->setCellValue("E{$rowIndex}", $row['programme']);
            $sheet->setCellValue("F{$rowIndex}", $row['category']);
            $sheet->setCellValue("G{$rowIndex}", $row['session']);
            $sheet->setCellValue("H{$rowIndex}", $row['submitted']);
            $sheet->setCellValue("I{$rowIndex}", $row['fee']);
            $sheet->setCellValue("J{$rowIndex}", $row['stage']);
            $rowIndex++;
        }

        $lastDataRow = max($headerRow, $rowIndex - 1);
        $sheet->getStyle("A{$headerRow}:J{$lastDataRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        foreach (range('A', 'J') as $column) {
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
     * @param  array{name: string, motto: string, address: string, contact: string}  $institution
     * @param  list<string>  $filterSummary
     * @param  Collection<int, array<string, string>>  $rows
     */
    private function word(
        array $institution,
        string $title,
        array $filterSummary,
        Collection $rows,
        string $referenceKind,
        string $generatedAt,
        string $filename,
    ): StreamedResponse {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(10);
        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'marginTop' => 600,
            'marginBottom' => 600,
            'marginLeft' => 600,
            'marginRight' => 600,
        ]);

        $logoPath = InstitutionLogo::path();
        if ($logoPath !== null) {
            $section->addImage($logoPath, [
                'width' => 52,
                'height' => 52,
                'alignment' => Jc::CENTER,
            ]);
        }

        $section->addText($institution['name'], ['bold' => true, 'size' => 16, 'color' => '0C4A6E'], ['alignment' => Jc::CENTER]);
        $section->addText($institution['motto'], ['italic' => true, 'size' => 10, 'color' => '64748B'], ['alignment' => Jc::CENTER]);
        $addressLine = collect([$institution['address'], $institution['contact']])->filter()->implode(' · ');
        if ($addressLine !== '') {
            $section->addText($addressLine, ['size' => 9, 'color' => '64748B'], ['alignment' => Jc::CENTER]);
        }
        $section->addTextBreak(1);
        $section->addText($title, ['bold' => true, 'size' => 13, 'color' => '0F172A'], ['alignment' => Jc::CENTER]);

        $meta = 'Generated '.$generatedAt.' · '.$rows->count().' record(s)';
        if ($filterSummary !== []) {
            $meta .= ' · Filters: '.implode('; ', $filterSummary);
        }
        $section->addText($meta, ['size' => 9, 'color' => '64748B'], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);

        $table = $section->addTable([
            'borderSize' => 6,
            'borderColor' => 'CBD5E1',
            'cellMargin' => 40,
            'width' => 100 * 50,
            'unit' => 'pct',
        ]);

        $headers = [
            'S/N', 'Applicant', 'Email', $this->referenceLabel($referenceKind),
            'Programme', 'Category', 'Session', 'Submitted', 'Fee', 'Stage',
        ];
        $table->addRow();
        foreach ($headers as $header) {
            $cell = $table->addCell(1200, ['bgColor' => '0C4A6E', 'valign' => 'center']);
            $cell->addText($header, ['bold' => true, 'color' => 'FFFFFF', 'size' => 8]);
        }

        foreach ($rows as $i => $row) {
            $table->addRow();
            $values = [
                (string) ($i + 1),
                $row['applicant'],
                $row['email'],
                $row['reference'],
                $row['programme'],
                $row['category'],
                $row['session'],
                $row['submitted'],
                $row['fee'],
                $row['stage'],
            ];
            foreach ($values as $value) {
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
}
