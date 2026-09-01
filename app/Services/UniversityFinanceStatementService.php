<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Invoice;
use App\Models\InvoiceRebate;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Wallet;
use App\Support\FeeSchedule;
use App\Support\InstitutionLogo;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UniversityFinanceStatementService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $from = $from?->copy()->startOfDay();
        $to = $to?->copy()->endOfDay();
        $now = now();

        $feeCollected = $this->money($this->feePayments($from, $to)->sum('amount'));
        $walletInflows = $this->money($this->walletFundingPayments($from, $to)->sum('amount'));
        $walletApplied = $this->money(
            $this->feePayments($from, $to)->where('method', 'wallet')->sum('amount')
        );
        $cashReceived = $this->money(
            $this->successfulPayments($from, $to)->where('method', '!=', 'wallet')->sum('amount')
        );
        $receipts = $this->money($this->successfulPayments($from, $to)->sum('amount'));
        $invoiced = $this->money($this->activeInvoices($from, $to)->sum('amount'));
        $rebates = $this->money($this->activeRebates($from, $to)->sum('invoice_rebates.amount'));
        $outstanding = $this->money(
            Invoice::query()
                ->whereNotIn('status', ['cancelled', 'disabled'])
                ->whereIn('status', ['unpaid', 'partial'])
                ->sum('balance')
        );
        $walletLiability = $this->money((float) Wallet::query()->sum('balance'));

        $todayFrom = $now->copy()->startOfDay();
        $todayTo = $now->copy()->endOfDay();
        $monthFrom = $now->copy()->startOfMonth();

        return [
            'institution' => $this->institution(),
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'label' => $this->periodLabel($from, $to),
            ],
            'generated_at' => $now->toIso8601String(),
            'totals' => [
                'invoiced' => $invoiced,
                'collected' => $feeCollected,
                'receipts' => $receipts,
                'cash_received' => $cashReceived,
                'wallet_inflows' => $walletInflows,
                'wallet_applied' => $walletApplied,
                'rebates' => $rebates,
                'outstanding' => $outstanding,
                'wallet_liability' => $walletLiability,
            ],
            'today' => [
                'collected' => $this->money($this->feePayments($todayFrom, $todayTo)->sum('amount')),
                'receipts' => $this->money($this->successfulPayments($todayFrom, $todayTo)->sum('amount')),
                'payments' => $this->successfulPayments($todayFrom, $todayTo)->count(),
            ],
            'this_month' => [
                'collected' => $this->money($this->feePayments($monthFrom, $todayTo)->sum('amount')),
                'receipts' => $this->money($this->successfulPayments($monthFrom, $todayTo)->sum('amount')),
                'payments' => $this->successfulPayments($monthFrom, $todayTo)->count(),
            ],
            'invoice_counts' => [
                'issued' => $this->activeInvoices($from, $to)->count(),
                'unpaid' => Invoice::query()->where('status', 'unpaid')->count(),
                'partial' => Invoice::query()->where('status', 'partial')->count(),
                'paid' => Invoice::query()->where('status', 'paid')->count(),
            ],
            'payment_counts' => [
                'successful' => $this->successfulPayments($from, $to)->count(),
                'pending' => $this->applyPeriod(Payment::query()->where('status', 'pending'), $from, $to)->count(),
                'failed' => $this->applyPeriod(Payment::query()->whereIn('status', ['failed', 'cancelled']), $from, $to)->count(),
            ],
            'by_category' => $this->byCategory($from, $to),
            'by_method' => $this->byMethod($from, $to),
            'monthly' => $this->monthly($from, $to),
            'recent_payments' => $this->recentPayments($from, $to),
        ];
    }

    public function export(string $format, array $statement): StreamedResponse
    {
        $generatedAt = now()->format('d M Y H:i:s');
        $title = 'University financial statement';
        $filename = 'university_financial_statement_'.now()->format('Ymd_His');

        return match ($format) {
            'pdf' => $this->pdf($statement, $title, $generatedAt, $filename),
            'excel' => $this->excel($statement, $title, $generatedAt, $filename),
            'word' => $this->word($statement, $title, $generatedAt, $filename),
            default => throw new \InvalidArgumentException('Unsupported export format.'),
        };
    }

    /**
     * @return list<string>
     */
    public function filterSummary(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        return ['Period: '.$this->periodLabel($from, $to)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byCategory(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $billed = $this->activeInvoices($from, $to)
            ->selectRaw('category, COALESCE(SUM(amount), 0) as billed, COUNT(*) as invoices')
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        $collected = $this->feePayments($from, $to)
            ->leftJoin('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->selectRaw("COALESCE(invoices.category, payments.purpose, 'other') as category, COALESCE(SUM(payments.amount), 0) as collected")
            ->groupByRaw("COALESCE(invoices.category, payments.purpose, 'other')")
            ->get()
            ->keyBy('category');

        $outstanding = Invoice::query()
            ->whereNotIn('status', ['cancelled', 'disabled'])
            ->whereIn('status', ['unpaid', 'partial'])
            ->selectRaw('category, COALESCE(SUM(balance), 0) as outstanding')
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        $keys = $billed->keys()
            ->merge($collected->keys())
            ->merge($outstanding->keys())
            ->unique()
            ->values();

        return $keys
            ->map(function ($category) use ($billed, $collected, $outstanding) {
                $code = (string) $category;

                return [
                    'category' => $code,
                    'label' => FeeSchedule::label($code),
                    'invoiced' => $this->money((float) ($billed[$code]->billed ?? 0)),
                    'collected' => $this->money((float) ($collected[$code]->collected ?? 0)),
                    'outstanding' => $this->money((float) ($outstanding[$code]->outstanding ?? 0)),
                    'invoices' => (int) ($billed[$code]->invoices ?? 0),
                ];
            })
            ->sortByDesc('collected')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byMethod(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        return $this->successfulPayments($from, $to)
            ->selectRaw("COALESCE(NULLIF(method, ''), 'other') as method, COALESCE(SUM(amount), 0) as amount, COUNT(*) as payments")
            ->groupByRaw("COALESCE(NULLIF(method, ''), 'other')")
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'method' => (string) $row->method,
                'label' => $this->methodLabel((string) $row->method),
                'amount' => $this->money((float) $row->amount),
                'payments' => (int) $row->payments,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function monthly(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $monthSql = $this->monthExpression('created_at');
        $collectedMonthSql = $this->monthExpression('payments.created_at');
        $invoiceMonthSql = $this->monthExpression('invoices.created_at');

        $collected = $this->feePayments($from, $to)
            ->selectRaw($collectedMonthSql.' as month, COALESCE(SUM(payments.amount), 0) as collected')
            ->groupByRaw($collectedMonthSql)
            ->pluck('collected', 'month');

        $invoiced = $this->activeInvoices($from, $to)
            ->selectRaw($invoiceMonthSql.' as month, COALESCE(SUM(invoices.amount), 0) as invoiced')
            ->groupByRaw($invoiceMonthSql)
            ->pluck('invoiced', 'month');

        $keys = $collected->keys()->merge($invoiced->keys())->unique()->sort()->values();

        return $keys->map(function ($month) use ($collected, $invoiced) {
            $key = (string) $month;
            $label = Carbon::createFromFormat('Y-m', $key)?->format('M Y') ?: $key;

            return [
                'month' => $key,
                'label' => $label,
                'invoiced' => $this->money((float) ($invoiced[$key] ?? 0)),
                'collected' => $this->money((float) ($collected[$key] ?? 0)),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentPayments(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        return $this->successfulPayments($from, $to)
            ->with([
                'invoice:id,number,category,student_id,user_id',
                'invoice.student:id,first_name,last_name,matric_number,student_number',
                'user:id,name',
            ])
            ->latest('payments.id')
            ->limit(20)
            ->get()
            ->map(function (Payment $payment) {
                $student = $payment->invoice?->student;
                $name = trim(collect([$student?->first_name, $student?->last_name])->filter()->implode(' '));
                $category = (string) ($payment->invoice?->category ?: $payment->purpose ?: 'other');

                return [
                    'id' => $payment->id,
                    'receipt_no' => $payment->receipt_no,
                    'reference' => $payment->reference,
                    'payer' => $name !== '' ? $name : ($payment->user?->name ?: '—'),
                    'matric' => $student?->matric_number ?: $student?->student_number,
                    'category' => $category,
                    'category_label' => FeeSchedule::label($category),
                    'method' => (string) $payment->method,
                    'method_label' => $this->methodLabel((string) $payment->method),
                    'amount' => $this->money((float) $payment->amount),
                    'invoice_number' => $payment->invoice?->number,
                    'created_at' => optional($payment->created_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    private function feePayments(?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $this->successfulPayments($from, $to)
            ->where(function (Builder $query) {
                $query->whereNull('payments.purpose')
                    ->orWhereNotIn('payments.purpose', ['wallet_topup', 'wallet_funding']);
            });
    }

    private function walletFundingPayments(?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $this->successfulPayments($from, $to)
            ->whereIn('payments.purpose', ['wallet_topup', 'wallet_funding']);
    }

    private function successfulPayments(?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $this->applyPeriod(
            Payment::query()->whereIn('payments.status', ['successful', 'paid']),
            $from,
            $to,
            'payments.created_at',
        );
    }

    private function activeInvoices(?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $this->applyPeriod(
            Invoice::query()->whereNotIn('invoices.status', ['cancelled', 'disabled']),
            $from,
            $to,
            'invoices.created_at',
        );
    }

    private function activeRebates(?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $this->applyPeriod(
            InvoiceRebate::query()
                ->join('invoices', 'invoices.id', '=', 'invoice_rebates.invoice_id')
                ->whereNull('invoice_rebates.reversed_at')
                ->whereNotIn('invoices.status', ['cancelled', 'disabled']),
            $from,
            $to,
            'invoice_rebates.created_at',
        );
    }

    private function applyPeriod(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to, string $column = 'created_at'): Builder
    {
        if ($from) {
            $query->where($column, '>=', $from);
        }
        if ($to) {
            $query->where($column, '<=', $to);
        }

        return $query;
    }

    private function monthExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    private function periodLabel(?CarbonInterface $from, ?CarbonInterface $to): string
    {
        if ($from && $to) {
            return $from->format('d M Y').' – '.$to->format('d M Y');
        }
        if ($from) {
            return 'From '.$from->format('d M Y');
        }
        if ($to) {
            return 'Until '.$to->format('d M Y');
        }

        return 'All time';
    }

    private function methodLabel(string $method): string
    {
        return match ($method) {
            'paystack' => 'Paystack',
            'wema' => 'Wema Bank',
            'wallet' => 'Wallet',
            'legacy_import' => 'Import',
            'bank' => 'Bank',
            default => $method !== '' ? ucfirst(str_replace('_', ' ', $method)) : 'Other',
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

    private function money(float $value): float
    {
        return round($value, 2);
    }

    private function naira(float $value): string
    {
        return 'NGN '.number_format($value, 2);
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    private function pdf(array $statement, string $title, string $generatedAt, string $filename): StreamedResponse
    {
        $html = view('exports.university-finance-statement-pdf', [
            'statement' => $statement,
            'title' => $title,
            'generatedAt' => $generatedAt,
            'logo_data_uri' => InstitutionLogo::dataUri(),
            'naira' => fn (float $value) => $this->naira($value),
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $output = $dompdf->output();

        return response()->streamDownload(function () use ($output) {
            echo $output;
        }, $filename.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    private function excel(array $statement, string $title, string $generatedAt, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Statement');
        $institution = $statement['institution'];
        $totals = $statement['totals'];

        $row = 1;
        $sheet->setCellValue('A'.$row, $institution['name']);
        $sheet->mergeCells('A'.$row.':D'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0C4A6E');
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
        $sheet->setCellValue('A'.$row, $institution['motto'] ?? '');
        $sheet->mergeCells('A'.$row.':D'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('64748B');
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;
        $sheet->setCellValue('A'.$row, $title);
        $sheet->mergeCells('A'.$row.':D'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
        $row++;
        $sheet->setCellValue('A'.$row, 'Period: '.$statement['period']['label'].' · Generated '.$generatedAt);
        $sheet->mergeCells('A'.$row.':D'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setSize(9)->getColor()->setRGB('64748B');
        $row += 2;

        $row = $this->writeExcelSection($sheet, $row, 'Statement of receipts', [
            ['Fee collections', $totals['collected']],
            ['Wallet top-ups', $totals['wallet_inflows']],
            ['Cash received (Paystack / Wema / import / bank)', $totals['cash_received']],
            ['Wallet applied to invoices', $totals['wallet_applied']],
            ['Total receipts', $totals['receipts']],
        ]);
        $row += 1;
        $row = $this->writeExcelSection($sheet, $row, 'Financial position', [
            ['Invoices issued (period)', $totals['invoiced']],
            ['Rebates granted (period)', $totals['rebates']],
            ['Outstanding receivables (now)', $totals['outstanding']],
            ['Student wallet liability (now)', $totals['wallet_liability']],
        ]);
        $row += 1;
        $row = $this->writeExcelTable(
            $sheet,
            $row,
            'By fee category',
            ['Category', 'Invoiced', 'Collected', 'Outstanding'],
            collect($statement['by_category'])->map(fn ($line) => [
                $line['label'], $line['invoiced'], $line['collected'], $line['outstanding'],
            ])->all(),
        );
        $row += 1;
        $this->writeExcelTable(
            $sheet,
            $row,
            'By payment method',
            ['Method', 'Amount', 'Payments'],
            collect($statement['by_method'])->map(fn ($line) => [
                $line['label'], $line['amount'], $line['payments'],
            ])->all(),
        );

        foreach (range('A', 'D') as $column) {
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
     * @param  list<array{0: string, 1: float}>  $lines
     */
    private function writeExcelSection($sheet, int $row, string $heading, array $lines): int
    {
        $sheet->setCellValue('A'.$row, $heading);
        $sheet->mergeCells('A'.$row.':B'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('0C4A6E');
        $row++;
        foreach ($lines as $line) {
            $sheet->setCellValue('A'.$row, $line[0]);
            $sheet->setCellValue('B'.$row, $this->naira((float) $line[1]));
            $sheet->getStyle('B'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $row++;
        }

        return $row;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function writeExcelTable($sheet, int $row, string $heading, array $headers, array $rows): int
    {
        $lastCol = chr(64 + count($headers));
        $sheet->setCellValue('A'.$row, $heading);
        $sheet->mergeCells('A'.$row.':'.$lastCol.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('0C4A6E');
        $row++;
        $headerRow = $row;
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(chr(65 + $index).$headerRow, $header);
        }
        $sheet->getStyle('A'.$headerRow.':'.$lastCol.$headerRow)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A'.$headerRow.':'.$lastCol.$headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0C4A6E');
        $row++;
        foreach ($rows as $data) {
            foreach ($data as $index => $value) {
                $cell = chr(65 + $index).$row;
                $sheet->setCellValue($cell, is_float($value) || is_int($value) ? $this->naira((float) $value) : $value);
                if (is_float($value) || is_int($value)) {
                    $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
            }
            $row++;
        }
        $sheet->getStyle('A'.$headerRow.':'.$lastCol.max($headerRow, $row - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        return $row;
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    private function word(array $statement, string $title, string $generatedAt, string $filename): StreamedResponse
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(10);
        $section = $phpWord->addSection([
            'orientation' => 'portrait',
            'marginTop' => 600,
            'marginBottom' => 600,
            'marginLeft' => 700,
            'marginRight' => 700,
        ]);
        $institution = $statement['institution'];
        $totals = $statement['totals'];

        $section->addText($institution['name'], ['bold' => true, 'size' => 16, 'color' => '0C4A6E'], ['alignment' => Jc::CENTER]);
        $section->addText((string) ($institution['motto'] ?? ''), ['italic' => true, 'size' => 10, 'color' => '64748B'], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);
        $section->addText($title, ['bold' => true, 'size' => 13], ['alignment' => Jc::CENTER]);
        $section->addText('Period: '.$statement['period']['label'].' · Generated '.$generatedAt, ['size' => 9, 'color' => '64748B'], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);

        $section->addText('Statement of receipts', ['bold' => true, 'size' => 12, 'color' => '0C4A6E']);
        $this->wordKeyValues($section, [
            ['Fee collections', $totals['collected']],
            ['Wallet top-ups', $totals['wallet_inflows']],
            ['Cash received (Paystack / Wema / import / bank)', $totals['cash_received']],
            ['Wallet applied to invoices', $totals['wallet_applied']],
            ['Total receipts', $totals['receipts']],
        ]);
        $section->addTextBreak(1);
        $section->addText('Financial position', ['bold' => true, 'size' => 12, 'color' => '0C4A6E']);
        $this->wordKeyValues($section, [
            ['Invoices issued (period)', $totals['invoiced']],
            ['Rebates granted (period)', $totals['rebates']],
            ['Outstanding receivables (now)', $totals['outstanding']],
            ['Student wallet liability (now)', $totals['wallet_liability']],
        ]);
        $section->addTextBreak(1);
        $section->addText('By fee category', ['bold' => true, 'size' => 12, 'color' => '0C4A6E']);
        $this->wordTable($section, ['Category', 'Invoiced', 'Collected', 'Outstanding'], collect($statement['by_category'])->map(fn ($line) => [
            $line['label'], $this->naira((float) $line['invoiced']), $this->naira((float) $line['collected']), $this->naira((float) $line['outstanding']),
        ])->all());
        $section->addTextBreak(1);
        $section->addText('By payment method', ['bold' => true, 'size' => 12, 'color' => '0C4A6E']);
        $this->wordTable($section, ['Method', 'Amount', 'Payments'], collect($statement['by_method'])->map(fn ($line) => [
            $line['label'], $this->naira((float) $line['amount']), (string) $line['payments'],
        ])->all());

        return response()->streamDownload(function () use ($phpWord) {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        }, $filename.'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * @param  list<array{0: string, 1: float}>  $lines
     */
    private function wordKeyValues($section, array $lines): void
    {
        $table = $section->addTable(['borderSize' => 4, 'borderColor' => 'CBD5E1', 'cellMargin' => 60, 'width' => 9000, 'unit' => 'dxa']);
        foreach ($lines as $line) {
            $table->addRow();
            $table->addCell(6200)->addText($line[0]);
            $table->addCell(2800)->addText($this->naira((float) $line[1]), [], ['alignment' => Jc::END]);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function wordTable($section, array $headers, array $rows): void
    {
        $table = $section->addTable(['borderSize' => 4, 'borderColor' => 'CBD5E1', 'cellMargin' => 60, 'width' => 9000, 'unit' => 'dxa']);
        $table->addRow();
        foreach ($headers as $header) {
            $table->addCell(2250, ['bgColor' => '0C4A6E'])->addText($header, ['bold' => true, 'color' => 'FFFFFF']);
        }
        foreach ($rows as $data) {
            $table->addRow();
            foreach ($data as $value) {
                $table->addCell(2250)->addText((string) $value);
            }
        }
    }
}
