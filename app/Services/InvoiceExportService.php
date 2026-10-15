<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Support\ExportNumbers;
use App\Support\FeeSchedule;
use App\Support\InstitutionLogo;
use App\Support\PaymentGatewaySettings;
use App\Support\PdfBinaryResponse;
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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceExportService
{
    public const MAX_ROWS = 25000;

    public const MAX_ROWS_CSV = 100000;

    public const MAX_ROWS_PDF = 1000;

    public const MAX_ROWS_WORD = 2000;

    public const VARIANT_INVOICES = 'invoices';

    public const VARIANT_PAYMENTS = 'payments';

    public static function maxRowsFor(string $format): int
    {
        return match ($format) {
            'pdf' => self::MAX_ROWS_PDF,
            'word' => self::MAX_ROWS_WORD,
            'csv' => self::MAX_ROWS_CSV,
            default => self::MAX_ROWS,
        };
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  list<string>  $filterSummary
     */
    public function export(string $format, Collection $invoices, string $title, array $filterSummary = []): Response
    {
        @ini_set('memory_limit', '2048M');
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $institution = $this->institution();
        $rows = $invoices->map(fn (Invoice $invoice) => $this->rowData($invoice))->values();
        $totals = [
            'amount' => round($rows->sum(fn ($row) => (float) $row['amount']), 2),
            'balance' => round($rows->sum(fn ($row) => (float) $row['balance']), 2),
        ];
        $generatedAt = now()->format('d M Y H:i:s');
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $title) ?: 'invoices';
        $filename = $safeTitle.'_'.now()->format('Ymd_His');

        return match ($format) {
            'pdf' => $this->pdf($institution, $title, $filterSummary, $rows, $totals, $generatedAt, $filename, self::VARIANT_INVOICES),
            'excel' => $this->excel($institution, $title, $filterSummary, $rows, $totals, $generatedAt, $filename, self::VARIANT_INVOICES),
            'word' => $this->word($institution, $title, $filterSummary, $rows, $totals, $generatedAt, $filename, self::VARIANT_INVOICES),
            'csv' => $this->streamCsv($title, $filterSummary, function (callable $emit) use ($invoices) {
                foreach ($invoices as $invoice) {
                    $emit($this->rowData($invoice));
                }
            }, self::VARIANT_INVOICES),
            default => throw new \InvalidArgumentException('Unsupported export format.'),
        };
    }

    /**
     * Successful gateway payments only (one row per payment).
     *
     * @param  Collection<int, Payment>  $payments
     * @param  list<string>  $filterSummary
     */
    public function exportPayments(string $format, Collection $payments, string $title, array $filterSummary = []): Response
    {
        @ini_set('memory_limit', '2048M');
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $institution = $this->institution();
        $rows = $payments->map(fn (Payment $payment) => $this->paymentRowData($payment))->values();
        $totals = [
            'amount' => round($rows->sum(fn ($row) => (float) $row['amount']), 2),
            'balance' => 0.0,
        ];
        $generatedAt = now()->format('d M Y H:i:s');
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $title) ?: 'gateway_payments';
        $filename = $safeTitle.'_'.now()->format('Ymd_His');

        return match ($format) {
            'pdf' => $this->pdf($institution, $title, $filterSummary, $rows, $totals, $generatedAt, $filename, self::VARIANT_PAYMENTS),
            'excel' => $this->excel($institution, $title, $filterSummary, $rows, $totals, $generatedAt, $filename, self::VARIANT_PAYMENTS),
            'word' => $this->word($institution, $title, $filterSummary, $rows, $totals, $generatedAt, $filename, self::VARIANT_PAYMENTS),
            'csv' => $this->streamCsv($title, $filterSummary, function (callable $emit) use ($payments) {
                foreach ($payments as $payment) {
                    $emit($this->paymentRowData($payment));
                }
            }, self::VARIANT_PAYMENTS),
            default => throw new \InvalidArgumentException('Unsupported export format.'),
        };
    }

    /**
     * Stream CSV while rows are produced in chunks.
     *
     * @param  list<string>  $filterSummary
     * @param  callable(callable(array<string, mixed>): void): void  $producer
     */
    public function streamCsv(
        string $title,
        array $filterSummary,
        callable $producer,
        string $variant = self::VARIANT_INVOICES,
    ): StreamedResponse {
        @ini_set('memory_limit', '512M');
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $institution = $this->institution();
        $generatedAt = now()->format('d M Y H:i:s');
        $safeTitle = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $title) ?: ($variant === self::VARIANT_PAYMENTS ? 'gateway_payments' : 'invoices');
        $filename = $safeTitle.'_'.now()->format('Ymd_His');

        return response()->streamDownload(function () use ($institution, $title, $filterSummary, $producer, $generatedAt, $variant) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [$institution['name']]);
            fputcsv($handle, [(string) ($institution['motto'] ?? '')]);
            fputcsv($handle, [$title]);
            $meta = 'Generated '.$generatedAt;
            if ($filterSummary !== []) {
                $meta .= ' · Filters: '.implode('; ', $filterSummary);
            }
            fputcsv($handle, [$meta]);
            fputcsv($handle, []);
            fputcsv($handle, $this->headers($variant));

            $sn = 0;
            $totals = ['amount' => 0.0, 'balance' => 0.0];
            $emit = function (array $row) use ($handle, &$sn, &$totals, $variant) {
                $sn++;
                $totals['amount'] = round($totals['amount'] + (float) $row['amount'], 2);
                $totals['balance'] = round($totals['balance'] + (float) ($row['balance'] ?? 0), 2);
                fputcsv($handle, $this->rowValues($row, $sn, false, $variant));
            };
            $producer($emit);

            fputcsv($handle, $this->totalRowValues($totals, $variant));
            fputcsv($handle, [$sn.' record(s)']);
            fclose($handle);
        }, $filename.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
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
     * @return array<string, mixed>
     */
    public function mapRow(Invoice $invoice): array
    {
        return $this->rowData($invoice);
    }

    /**
     * @return array<string, mixed>
     */
    public function mapPaymentRow(Payment $payment): array
    {
        return $this->paymentRowData($payment);
    }

    /**
     * @return array<string, mixed>
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
            'amount' => round((float) $invoice->amount, 2),
            'balance' => round((float) $invoice->balance, 2),
            'gateway' => $this->invoiceGatewayLabel($invoice),
            'status' => ucfirst($status),
            'date' => optional($invoice->created_at)->format('d M Y H:i:s') ?: '—',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRowData(Payment $payment): array
    {
        $invoice = $payment->invoice;
        $student = $invoice?->student;
        $name = trim(collect([$student?->first_name, $student?->last_name])->filter()->implode(' '));

        return [
            'receipt' => (string) ($payment->receipt_no ?: '—'),
            'reference' => (string) ($payment->reference ?: $payment->paystack_reference ?: '—'),
            'number' => (string) ($invoice?->number ?: '—'),
            'payer' => $name !== '' ? $name : ($payment->user?->name ?: $invoice?->user?->name ?: '—'),
            'matric' => $invoice ? $this->payerIdentifier($invoice) : '—',
            'category' => FeeSchedule::label((string) ($invoice?->category ?: $payment->purpose ?: 'other')),
            'programme' => $student?->program?->name ?: '—',
            'college' => $student?->program?->department?->faculty?->name ?: '—',
            'gateway' => $this->gatewayLabel((string) $payment->method),
            'amount' => round((float) $payment->amount, 2),
            'balance' => 0.0,
            'status' => 'Successful',
            'date' => optional($payment->created_at)->format('d M Y H:i:s') ?: '—',
        ];
    }

    private function invoiceGatewayLabel(Invoice $invoice): string
    {
        $payment = $invoice->relationLoaded('payments')
            ? $invoice->payments
                ->filter(fn (Payment $payment) => in_array((string) $payment->status, ['successful', 'paid'], true))
                ->sortByDesc('id')
                ->first()
            : null;

        if (! $payment) {
            return '—';
        }

        return $this->gatewayLabel((string) $payment->method);
    }

    private function gatewayLabel(string $method): string
    {
        $method = strtolower(trim($method));

        return match ($method) {
            PaymentGatewaySettings::PAYSTACK => 'Paystack',
            PaymentGatewaySettings::WEMA => 'Wema Bank',
            PaymentGatewaySettings::PAYGATE => 'PayGate',
            'wallet' => 'Wallet',
            'bank', 'transfer', 'bank_transfer' => 'Bank',
            'legacy_import', 'import' => 'Import',
            default => $method !== '' ? ucfirst(str_replace('_', ' ', $method)) : '—',
        };
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
    private function headers(string $variant = self::VARIANT_INVOICES): array
    {
        if ($variant === self::VARIANT_PAYMENTS) {
            return ['S/N', 'Receipt', 'Reference', 'Invoice', 'Payer', 'Matric', 'Category', 'Programme', 'College', 'Gateway', 'Amount', 'Timestamp'];
        }

        return ['S/N', 'Number', 'Payer', 'Matric', 'Category', 'Programme', 'College', 'Amount', 'Balance', 'Gateway', 'Status', 'Timestamp'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function rowValues(array $row, int $sn, bool $display = false, string $variant = self::VARIANT_INVOICES): array
    {
        if ($variant === self::VARIANT_PAYMENTS) {
            return [
                $sn,
                $row['receipt'],
                $row['reference'],
                $row['number'],
                $row['payer'],
                $row['matric'],
                $row['category'],
                $row['programme'],
                $row['college'],
                $row['gateway'],
                $display ? ExportNumbers::display($row['amount']) : $row['amount'],
                $row['date'],
            ];
        }

        return [
            $sn,
            $row['number'],
            $row['payer'],
            $row['matric'],
            $row['category'],
            $row['programme'],
            $row['college'],
            $display ? ExportNumbers::display($row['amount']) : $row['amount'],
            $display ? ExportNumbers::display($row['balance']) : $row['balance'],
            $row['gateway'] ?? '—',
            $row['status'],
            $row['date'],
        ];
    }

    /**
     * @param  array{amount: float, balance: float}  $totals
     * @return list<mixed>
     */
    private function totalRowValues(array $totals, string $variant = self::VARIANT_INVOICES, bool $display = false): array
    {
        $amount = $display ? ExportNumbers::display($totals['amount']) : $totals['amount'];
        $balance = $display ? ExportNumbers::display($totals['balance']) : $totals['balance'];

        if ($variant === self::VARIANT_PAYMENTS) {
            return ['Total', '', '', '', '', '', '', '', '', '', $amount, ''];
        }

        return ['Total', '', '', '', '', '', '', $amount, $balance, '', '', ''];
    }

    /**
     * @return list<int>
     */
    private function moneyIndexes(string $variant = self::VARIANT_INVOICES): array
    {
        return $variant === self::VARIANT_PAYMENTS ? [10] : [7, 8];
    }

    /**
     * @return list<string>
     */
    private function columnLetters(int $count): array
    {
        $columns = [];
        for ($i = 0; $i < $count; $i++) {
            $columns[] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        }

        return $columns;
    }

    /**
     * @param  array{name: string, motto: string, address: string, contact: string}  $institution
     * @param  list<string>  $filterSummary
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{amount: float, balance: float}  $totals
     */
    private function pdf(
        array $institution,
        string $title,
        array $filterSummary,
        Collection $rows,
        array $totals,
        string $generatedAt,
        string $filename,
        string $variant = self::VARIANT_INVOICES,
    ): Response {
        $html = view('exports.invoices-pdf', [
            'institution' => $institution,
            'title' => $title,
            'filterSummary' => $filterSummary,
            'rows' => $rows,
            'totals' => $totals,
            'generatedAt' => $generatedAt,
            'count' => $rows->count(),
            'variant' => $variant,
            'logo_data_uri' => InstitutionLogo::dataUri(),
        ])->render();

        return PdfBinaryResponse::fromHtml($html, $filename);
    }

    /**
     * @param  array{name: string, motto: string, address: string, contact: string}  $institution
     * @param  list<string>  $filterSummary
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{amount: float, balance: float}  $totals
     */
    private function excel(
        array $institution,
        string $title,
        array $filterSummary,
        Collection $rows,
        array $totals,
        string $generatedAt,
        string $filename,
        string $variant = self::VARIANT_INVOICES,
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($variant === self::VARIANT_PAYMENTS ? 'Payments' : 'Invoices');
        $headers = $this->headers($variant);
        $columns = $this->columnLetters(count($headers));
        $lastCol = $columns[count($columns) - 1];
        $defaultWidths = $variant === self::VARIANT_PAYMENTS
            ? [8, 14, 18, 16, 18, 14, 14, 18, 16, 12, 12, 18]
            : [8, 18, 20, 16, 14, 22, 18, 12, 12, 12, 12, 20];

        $row = 1;
        $logoPath = InstitutionLogo::path();
        if ($logoPath !== null) {
            $logoRow = $row;
            $logoHeight = 44;
            foreach ($columns as $index => $column) {
                $sheet->getColumnDimension($column)->setWidth($defaultWidths[$index] ?? 14);
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
        $moneyIndexes = $this->moneyIndexes($variant);
        foreach ($rows as $i => $data) {
            foreach ($this->rowValues($data, $i + 1, false, $variant) as $index => $value) {
                $cell = $columns[$index].$rowIndex;
                if ($index === 0 || in_array($index, $moneyIndexes, true)) {
                    ExportNumbers::writeCell($sheet, $cell, $value, true, $index !== 0);
                } else {
                    $sheet->setCellValue($cell, $value);
                }
            }
            $rowIndex++;
        }

        $totalRow = $rowIndex;
        foreach ($this->totalRowValues($totals, $variant) as $index => $value) {
            $cell = $columns[$index].$totalRow;
            if (in_array($index, $moneyIndexes, true)) {
                ExportNumbers::writeCell($sheet, $cell, $value, true, true);
            } else {
                $sheet->setCellValue($cell, $value);
            }
        }
        $sheet->getStyle('A'.$totalRow.':'.$lastCol.$totalRow)->getFont()->setBold(true);
        $sheet->getStyle('A'.$totalRow.':'.$lastCol.$totalRow)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0F2FE');
        $rowIndex++;

        // AutoSize + full-sheet borders choke on multi-thousand invoice exports.
        if ($rows->count() <= 250) {
            $sheet->getStyle('A'.$headerRow.':'.$lastCol.max($headerRow, $rowIndex - 1))->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
            if ($logoPath === null) {
                foreach ($columns as $column) {
                    $sheet->getColumnDimension($column)->setAutoSize(true);
                }
            }
        } else {
            $sheet->getStyle($headerRange)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
            $sheet->getStyle('A'.$totalRow.':'.$lastCol.$totalRow)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
            if ($logoPath === null) {
                foreach ($columns as $index => $column) {
                    $sheet->getColumnDimension($column)->setWidth($defaultWidths[$index] ?? 14);
                }
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
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{amount: float, balance: float}  $totals
     */
    private function word(
        array $institution,
        string $title,
        array $filterSummary,
        Collection $rows,
        array $totals,
        string $generatedAt,
        string $filename,
        string $variant = self::VARIANT_INVOICES,
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
        foreach ($this->headers($variant) as $header) {
            $cell = $table->addCell(1200, ['bgColor' => '0C4A6E', 'valign' => 'center']);
            $cell->addText($header, ['bold' => true, 'color' => 'FFFFFF', 'size' => 8]);
        }
        foreach ($rows as $i => $data) {
            $table->addRow();
            foreach ($this->rowValues($data, $i + 1, true, $variant) as $value) {
                $cell = $table->addCell(1200);
                $cell->addText((string) $value, ['size' => 8, 'color' => '1E293B']);
            }
        }
        $table->addRow();
        foreach ($this->totalRowValues($totals, $variant, true) as $value) {
            $cell = $table->addCell(1200, ['bgColor' => 'E0F2FE']);
            $cell->addText((string) $value, ['bold' => true, 'size' => 8, 'color' => '0C4A6E']);
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
