<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Setting;
use App\Models\Student;
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

class RegistrationExportService
{
    public const MAX_ROWS = 5000;

    /**
     * @param  Collection<int, Student>  $students
     * @param  list<string>  $filterSummary
     */
    public function export(
        string $format,
        Collection $students,
        string $title,
        array $filterSummary = [],
        bool $showEntryMode = true,
    ): StreamedResponse {
        $institution = $this->institution();
        $rows = $students->map(fn (Student $student) => $this->rowData($student))->values();
        $generatedAt = now()->format('d M Y H:i');
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $title) ?: 'registrations';
        $filename = $safeTitle.'_'.now()->format('Ymd_His');

        return match ($format) {
            'pdf' => $this->pdf($institution, $title, $filterSummary, $rows, $showEntryMode, $generatedAt, $filename),
            'excel' => $this->excel($institution, $title, $filterSummary, $rows, $showEntryMode, $generatedAt, $filename),
            'word' => $this->word($institution, $title, $filterSummary, $rows, $showEntryMode, $generatedAt, $filename),
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
    private function rowData(Student $student): array
    {
        $name = trim(collect([
            $student->first_name,
            $student->middle_name,
            $student->last_name,
        ])->filter()->implode(' '));

        return [
            'student' => $name !== '' ? $name : ($student->user?->name ?: '—'),
            'email' => $student->user?->email ?: '—',
            'matric' => $student->matric_number ?: ($student->student_number ?: '—'),
            'entry_mode' => strtoupper((string) ($student->application?->entry_mode ?: '—')),
            'programme' => $student->program?->name ?: ($student->program?->code ?: '—'),
            'session' => $student->application?->intake?->term?->session_label ?: '—',
            'tuition' => number_format((float) ($student->getAttribute('tuition_percent') ?? 0), 0).'%',
            'course_reg' => match ((string) ($student->getAttribute('course_reg_status') ?? 'not_started')) {
                'registered' => 'Registered',
                'in_progress' => 'In progress',
                default => 'Not started',
            },
            'units' => (string) ($student->getAttribute('enrolled_units') ?? 0),
            'extension' => (string) ($student->getAttribute('extension_status') ?: '—'),
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
        bool $showEntryMode,
        string $generatedAt,
        string $filename,
    ): StreamedResponse {
        $html = view('exports.registrations-pdf', [
            'institution' => $institution,
            'title' => $title,
            'filterSummary' => $filterSummary,
            'rows' => $rows,
            'showEntryMode' => $showEntryMode,
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
        bool $showEntryMode,
        string $generatedAt,
        string $filename,
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Registrations');

        $headers = $this->headers($showEntryMode);
        $columns = [];
        for ($i = 0; $i < count($headers); $i++) {
            $columns[] = chr(ord('A') + $i);
        }
        $lastCol = $columns[count($columns) - 1];

        $row = 1;
        $logoPath = InstitutionLogo::path();
        if ($logoPath !== null) {
            $logoRow = $row;
            $logoHeight = 44;

            $sheet->mergeCells('A'.$logoRow.':'.$lastCol.$logoRow);
            $sheet->getRowDimension($logoRow)->setRowHeight(48);
            $sheet->getStyle('A'.$logoRow.':'.$lastCol.$logoRow)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);

            $drawing = new Drawing;
            $drawing->setPath($logoPath);
            $drawing->setHeight($logoHeight);
            $drawing->setCoordinates('A'.$logoRow);

            $font = $spreadsheet->getDefaultStyle()->getFont();
            $totalWidthPx = 0;
            foreach ($columns as $column) {
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
        $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0C4A6E');
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;

        $sheet->setCellValue('A'.$row, $institution['motto']);
        $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
        $sheet->getStyle('A'.$row)->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('64748B');
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;

        $addressLine = collect([$institution['address'], $institution['contact']])->filter()->implode(' · ');
        if ($addressLine !== '') {
            $sheet->setCellValue('A'.$row, $addressLine);
            $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
            $sheet->getStyle('A'.$row)->getFont()->setSize(9)->getColor()->setRGB('64748B');
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        $row++;

        $sheet->setCellValue('A'.$row, $title);
        $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
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
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $rowIndex = $headerRow + 1;
        foreach ($rows as $i => $data) {
            $values = $this->rowValues($data, $showEntryMode, $i + 1);
            foreach ($values as $index => $value) {
                $sheet->setCellValue($columns[$index].$rowIndex, $value);
            }
            $rowIndex++;
        }

        $lastDataRow = max($headerRow, $rowIndex - 1);
        $dataRange = 'A'.$headerRow.':'.$lastCol.$lastDataRow;
        $sheet->getStyle($dataRange)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        foreach ($columns as $column) {
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
        bool $showEntryMode,
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

        $headers = $this->headers($showEntryMode);
        $table->addRow();
        foreach ($headers as $header) {
            $cell = $table->addCell(1400, ['bgColor' => '0C4A6E', 'valign' => 'center']);
            $cell->addText($header, ['bold' => true, 'color' => 'FFFFFF', 'size' => 8]);
        }

        foreach ($rows as $i => $data) {
            $table->addRow();
            foreach ($this->rowValues($data, $showEntryMode, $i + 1) as $value) {
                $cell = $table->addCell(1400);
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
     * @return list<string>
     */
    private function headers(bool $showEntryMode): array
    {
        $headers = ['S/N', 'Student', 'Email', 'Matric no.', 'Programme', 'Session', 'Tuition %', 'Course reg.', 'Units', 'Extension'];
        if ($showEntryMode) {
            array_splice($headers, 4, 0, ['Entry mode']);
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function rowValues(array $row, bool $showEntryMode, int $sn): array
    {
        $values = [
            (string) $sn,
            $row['student'],
            $row['email'],
            $row['matric'],
            $row['programme'],
            $row['session'],
            $row['tuition'],
            $row['course_reg'],
            $row['units'],
            $row['extension'],
        ];
        if ($showEntryMode) {
            array_splice($values, 4, 0, [$row['entry_mode']]);
        }

        return $values;
    }
}
