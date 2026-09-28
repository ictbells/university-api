<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Setting;
use App\Support\InstitutionLogo;
use App\Support\PdfBinaryResponse;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseOfferingExportService
{
    private const SUMMARY_HEADERS = ['S/N', 'Course code', 'Course title', 'Units', 'Type', 'Programmes', 'Section', 'Session', 'Semester', 'Lecturer', 'Capacity', 'Registered'];

    private const ROSTER_HEADERS = ['S/N', 'Matric no.', 'Surname', 'Other names', 'Gender', 'Programme', 'Level', 'Email', 'Carry-over', 'Registered on'];

    /**
     * @param  Collection<int, array<string, string|int>>  $rows
     * @param  list<string>  $filterSummary
     */
    public function export(string $format, Collection $rows, string $title, array $filterSummary = []): Response
    {
        $totalRegistered = (int) $rows->sum('registered');
        $values = $rows->values()->map(fn (array $row, int $i) => [
            $i + 1,
            $row['code'],
            $row['title'],
            $row['units'],
            $row['type'],
            $row['programmes'],
            $row['section'],
            $row['session'],
            $row['semester'],
            $row['lecturer'],
            $row['capacity'],
            $row['registered'],
        ])->all();
        $meta = $rows->count().' offering(s) · '.$totalRegistered.' registration(s)';

        return $this->render($format, [
            'title' => $title,
            'sheet' => 'Course offerings',
            'meta' => $meta,
            'filterSummary' => $filterSummary,
            'headers' => self::SUMMARY_HEADERS,
            'numeric' => [3, 11],
            'rows' => $values,
            'total' => ['label' => 'Total', 'value' => $totalRegistered],
            'empty' => 'No course offerings match the selected filters.',
            'footer' => 'Course Offerings',
            'landscape' => true,
        ]);
    }

    /**
     * @param  Collection<int, array<string, string>>  $students
     */
    public function roster(string $format, CourseOffering $offering, Collection $students): Response
    {
        $course = $offering->course;
        $term = $offering->term;
        $termLabel = trim(($term?->session_label ?: $term?->session?->label ?: '').' '.($term?->name ?: ''));
        $title = trim(($course?->code ?: 'Course').' — '.($course?->title ?: '').' (Section '.($offering->section ?: 'A').')');
        $values = $students->values()->map(fn (array $s, int $i) => [
            $i + 1,
            $s['matric'],
            $s['surname'],
            $s['other_names'],
            $s['gender'],
            $s['programme'],
            $s['level'],
            $s['email'],
            $s['carry_over'],
            $s['registered_at'],
        ])->all();

        return $this->render($format, [
            'title' => $title,
            'sheet' => 'Class list',
            'meta' => $students->count().' registered student(s)',
            'filterSummary' => array_values(array_filter([
                $termLabel !== '' ? 'Semester: '.$termLabel : null,
                $course?->units ? 'Units: '.$course->units : null,
                $offering->lecturer_display_name ? 'Lecturer: '.$offering->lecturer_display_name : null,
            ])),
            'headers' => self::ROSTER_HEADERS,
            'numeric' => [],
            'rows' => $values,
            'total' => null,
            'empty' => 'No students have registered this course.',
            'footer' => 'Course Class List',
            'landscape' => true,
            'filename' => ($course?->code ?: 'course').'_'.($offering->section ?: 'A').'_'.($term?->name ?: 'term').'_class_list',
        ]);
    }

    /**
     * @param  array{title: string, sheet: string, meta: string, filterSummary: list<string>, headers: list<string>, numeric: list<int>, rows: list<list<string|int>>, total: array{label: string, value: int}|null, empty: string, footer: string, landscape: bool, filename?: string}  $report
     */
    private function render(string $format, array $report): Response
    {
        $institution = $this->institution();
        $report['generatedAt'] = now()->format('d M Y H:i:s');
        $base = $report['filename'] ?? $report['title'];
        $filename = (preg_replace('/[^A-Za-z0-9_\-]+/', '_', $base) ?: 'export').'_'.now()->format('Ymd_His');

        return match ($format) {
            'pdf' => $this->pdf($institution, $report, $filename),
            'excel' => $this->excel($institution, $report, $filename),
            default => throw new \InvalidArgumentException('Unsupported export format.'),
        };
    }

    /**
     * @return array{name: string, motto: string, address: string}
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

    private function pdf(array $institution, array $report, string $filename): Response
    {
        $html = view('exports.course-offerings-pdf', [
            'institution' => $institution,
            'report' => $report,
            'logo_data_uri' => InstitutionLogo::dataUri(),
        ])->render();

        return PdfBinaryResponse::fromHtml($html, $filename);
    }

    private function excel(array $institution, array $report, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($report['sheet'], 0, 31));
        $headers = $report['headers'];
        $colCount = count($headers);
        $lastCol = Coordinate::stringFromColumnIndex($colCount);
        $col = fn (int $index) => Coordinate::stringFromColumnIndex($index + 1);

        $row = 1;
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
        $sheet->setCellValue('A'.$row, $report['title']);
        $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
        $meta = 'Generated '.$report['generatedAt'].' · '.$report['meta'];
        if ($report['filterSummary'] !== []) {
            $meta .= ' · '.implode('; ', $report['filterSummary']);
        }
        $sheet->setCellValue('A'.$row, $meta);
        $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
        $sheet->getStyle('A'.$row)->getFont()->setSize(9)->getColor()->setRGB('64748B');
        $row += 2;

        $headerRow = $row;
        foreach ($headers as $index => $header) {
            $sheet->setCellValue($col($index).$headerRow, $header);
        }
        $headerRange = 'A'.$headerRow.':'.$lastCol.$headerRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0C4A6E');

        $rowIndex = $headerRow + 1;
        foreach ($report['rows'] as $values) {
            foreach ($values as $index => $value) {
                $sheet->setCellValueExplicit(
                    $col($index).$rowIndex,
                    $value,
                    is_int($value) ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                );
            }
            $rowIndex++;
        }

        $lastRow = max($headerRow, $rowIndex - 1);
        if ($report['total'] !== null) {
            $sheet->setCellValue('A'.$rowIndex, $report['total']['label']);
            $sheet->mergeCells('A'.$rowIndex.':'.$col($colCount - 2).$rowIndex);
            $sheet->setCellValue($lastCol.$rowIndex, $report['total']['value']);
            $sheet->getStyle('A'.$rowIndex.':'.$lastCol.$rowIndex)->getFont()->setBold(true);
            $sheet->getStyle('A'.$rowIndex.':'.$lastCol.$rowIndex)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
            $lastRow = $rowIndex;
        }

        $sheet->getStyle('A'.$headerRow.':'.$lastCol.$lastRow)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        for ($i = 0; $i < $colCount; $i++) {
            $sheet->getColumnDimension($col($i))->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
