<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class WemaReconcileReportService
{
    /**
     * @return list<string>
     */
    public function pendingHeaders(): array
    {
        return [
            'Payment ID',
            'Invoice ID',
            'Invoice number',
            'Payer email',
            'Purpose',
            'Amount we sent (NGN)',
            'Our orderId (WEMA-*)',
            'Stored gateway id',
            'Portal status (before)',
            'Payment created at',
            'Wema transaction id',
            'Wema status',
            'Wema amount',
            'Wema fee',
            'Wema top-level orderId',
            'Wema metadata.orderId',
            'Wema metadata.invoice_id',
            'Wema metadata.purpose',
            'Wema customer email',
            'Wema transaction time',
            'Matched by',
            'OrderId match',
            'Invoice ID match',
            'Amount match',
            'Reconcile result',
            'Portal status (after)',
            'Notes',
        ];
    }

    /**
     * @return list<string>
     */
    public function unmatchedHeaders(): array
    {
        return [
            'Wema transaction id',
            'Wema status',
            'Wema amount',
            'Wema fee',
            'Wema top-level orderId',
            'Wema metadata.orderId',
            'Wema metadata.invoice_id',
            'Wema metadata.purpose',
            'Wema customer email',
            'Wema transaction time',
            'Wema metadata JSON',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pendingRows
     * @param  list<array<string, mixed>>  $unmatchedRows
     * @param  array<string, mixed>  $summary
     */
    public function write(array $pendingRows, array $unmatchedRows, array $summary, string $path): string
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $spreadsheet = new Spreadsheet;
        $this->writeSummarySheet($spreadsheet->getActiveSheet(), $summary, count($pendingRows), count($unmatchedRows));
        $this->writePendingSheet($spreadsheet->createSheet(), $pendingRows);
        $this->writeUnmatchedSheet($spreadsheet->createSheet(), $unmatchedRows);
        $spreadsheet->setActiveSheetIndex(0);

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function writeSummarySheet(Worksheet $sheet, array $summary, int $pendingCount, int $unmatchedCount): void
    {
        $sheet->setTitle('Summary');
        $sheet->fromArray([
            ['Wema / AlatPay reconciliation report'],
            ['Generated at', (string) ($summary['generated_at'] ?? now()->toDateTimeString())],
            ['Lookback (days)', (string) ($summary['days'] ?? '')],
            ['Dry run', ! empty($summary['dry_run']) ? 'Yes' : 'No'],
            [],
            ['Pending payments reviewed', $pendingCount],
            ['Fulfilled', (int) ($summary['fulfilled'] ?? 0)],
            ['Skipped / unconfirmed', (int) ($summary['skipped'] ?? 0)],
            ['No AlatPay transaction id', (int) ($summary['no_tx_id'] ?? 0)],
            ['Errors', (int) ($summary['failed'] ?? 0)],
            ['Wema completed charges with no matching pending payment', $unmatchedCount],
            ['AlatPay transactions prefetched', (int) ($summary['prefetched'] ?? 0)],
        ], null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(62);
        $sheet->getColumnDimension('B')->setWidth(28);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writePendingSheet(Worksheet $sheet, array $rows): void
    {
        $sheet->setTitle('Pending payments');
        $headers = $this->pendingHeaders();
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));

        $sheet->setCellValue('A1', 'What we sent to Wema');
        $sheet->mergeCells('A1:J1');
        $sheet->setCellValue('K1', 'What Wema returned');
        $sheet->mergeCells('K1:T1');
        $sheet->setCellValue('U1', 'Comparison');
        $sheet->mergeCells('U1:'.$lastCol.'1');

        $sheet->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0C4A6E');
        $sheet->getStyle('K1:T1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F766E');
        $sheet->getStyle('U1:'.$lastCol.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('7C2D12');
        $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:'.$lastCol.'1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->fromArray($headers, null, 'A2');
        $sheet->getStyle('A2:'.$lastCol.'2')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A2:J2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('155E75');
        $sheet->getStyle('K2:T2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F766E');
        $sheet->getStyle('U2:'.$lastCol.'2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('9A3412');

        $line = 3;
        foreach ($rows as $row) {
            $sheet->fromArray([$this->pendingValues($row)], null, 'A'.$line);
            $line++;
        }

        $end = max(2, $line - 1);
        $sheet->getStyle('A1:'.$lastCol.$end)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sheet->freezePane('A3');
        $this->autosize($sheet, count($headers));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeUnmatchedSheet(Worksheet $sheet, array $rows): void
    {
        $sheet->setTitle('Unmatched Wema charges');
        $headers = $this->unmatchedHeaders();
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:'.$lastCol.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F766E');

        $line = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([$this->unmatchedValues($row)], null, 'A'.$line);
            $line++;
        }

        $end = max(1, $line - 1);
        $sheet->getStyle('A1:'.$lastCol.$end)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sheet->freezePane('A2');
        $this->autosize($sheet, count($headers));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function pendingValues(array $row): array
    {
        return [
            $row['payment_id'] ?? '',
            $row['invoice_id'] ?? '',
            $row['invoice_number'] ?? '',
            $row['payer_email'] ?? '',
            $row['purpose'] ?? '',
            $row['amount_sent'] ?? '',
            $row['our_order_id'] ?? '',
            $row['stored_gateway_id'] ?? '',
            $row['portal_status_before'] ?? '',
            $row['payment_created_at'] ?? '',
            $row['wema_transaction_id'] ?? '',
            $row['wema_status'] ?? '',
            $row['wema_amount'] ?? '',
            $row['wema_fee'] ?? '',
            $row['wema_order_id'] ?? '',
            $row['wema_metadata_order_id'] ?? '',
            $row['wema_metadata_invoice_id'] ?? '',
            $row['wema_metadata_purpose'] ?? '',
            $row['wema_customer_email'] ?? '',
            $row['wema_transaction_time'] ?? '',
            $row['match_by'] ?? '',
            $row['order_id_match'] ?? '',
            $row['invoice_id_match'] ?? '',
            $row['amount_match'] ?? '',
            $row['reconcile_result'] ?? '',
            $row['portal_status_after'] ?? '',
            $row['notes'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function unmatchedValues(array $row): array
    {
        return [
            $row['wema_transaction_id'] ?? '',
            $row['wema_status'] ?? '',
            $row['wema_amount'] ?? '',
            $row['wema_fee'] ?? '',
            $row['wema_order_id'] ?? '',
            $row['wema_metadata_order_id'] ?? '',
            $row['wema_metadata_invoice_id'] ?? '',
            $row['wema_metadata_purpose'] ?? '',
            $row['wema_customer_email'] ?? '',
            $row['wema_transaction_time'] ?? '',
            $row['wema_metadata_json'] ?? '',
        ];
    }

    private function autosize(Worksheet $sheet, int $columnCount): void
    {
        for ($index = 1; $index <= max(1, $columnCount); $index++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }
    }
}
