<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportInvoicesRequest;
use App\Jobs\ImportInvoicesJob;
use App\Services\InvoiceImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceImportController extends Controller
{
    public function __construct(private InvoiceImportService $importer) {}

    public function template(Request $request): StreamedResponse
    {
        $this->authorizeImport($request);

        return $this->importer->template();
    }

    public function pending(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        return response()->json([
            'pending_by_matric' => $this->importer->pendingByMatric(),
        ]);
    }

    public function import(ImportInvoicesRequest $request): JsonResponse
    {
        $this->authorizeImport($request);

        $file = $request->file('file');
        $rowCount = $this->importer->countDataRows($file);
        $importId = (string) Str::uuid();

        if ($this->importer->shouldQueue($rowCount)) {
            $path = $this->importer->storeUpload($file);
            $this->importer->cacheResult($importId, [
                'status' => 'queued',
                'queued' => true,
                'import_id' => $importId,
            ]);
            ImportInvoicesJob::dispatch($importId, $path, (int) $request->user()->id);

            return response()->json([
                'message' => 'Import queued.',
                'queued' => true,
                'status' => 'queued',
                'import_id' => $importId,
            ], 202);
        }

        try {
            $result = $this->importer->import($file);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $result = array_merge($result, [
            'status' => 'done',
            'queued' => false,
            'import_id' => $importId,
        ]);
        $this->importer->cacheResult($importId, $result);

        return response()->json([
            'message' => $this->summaryMessage($result),
            'data' => $result,
        ]);
    }

    public function status(Request $request, string $importId): JsonResponse
    {
        $this->authorizeImport($request);
        $result = $this->importer->cachedResult($importId);
        if (! $result) {
            return response()->json(['message' => 'Import result not found or has expired.'], 404);
        }

        return response()->json($result);
    }

    public function errors(Request $request, string $importId): StreamedResponse|JsonResponse
    {
        $this->authorizeImport($request);
        $result = $this->importer->cachedResult($importId);
        if (! $result) {
            return response()->json(['message' => 'Import result not found or has expired.'], 404);
        }

        return $this->importer->errorSpreadsheet($result['errors'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function summaryMessage(array $result): string
    {
        $posted = (int) ($result['posted'] ?? 0);
        $pending = (int) ($result['pending'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);

        return "Posted {$posted} invoice(s). {$pending} held until the student exists. {$skipped} row(s) skipped.";
    }

    private function authorizeImport(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission('finance.invoices.import')
            || $user->hasPermission('finance.invoices.manage'),
            403,
        );
    }
}
