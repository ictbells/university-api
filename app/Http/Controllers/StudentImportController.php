<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportStudentsRequest;
use App\Jobs\ImportStudentsJob;
use App\Models\Intake;
use App\Services\StudentImportService;
use App\Support\AdmissionEntryRules;
use App\Support\ApplicantImportColumns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentImportController extends Controller
{
    public function __construct(private StudentImportService $importer) {}

    public function options(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        $intakes = Intake::query()
            ->with('term:id,name,session_label')
            ->orderByDesc('id')
            ->get(['id', 'name', 'entry_mode', 'academic_term_id', 'is_open']);

        return response()->json([
            'intakes' => $intakes,
            'entry_modes' => collect(AdmissionEntryRules::ENTRY_MODE_ORDER)
                ->map(fn (string $mode) => [
                    'value' => $mode,
                    'label' => strtoupper($mode),
                ])
                ->values(),
        ]);
    }

    public function template(Request $request): StreamedResponse
    {
        $this->authorizeImport($request);
        $entryMode = strtolower((string) $request->query('entry_mode', 'utme'));
        if (! in_array($entryMode, ApplicantImportColumns::MODES, true)) {
            abort(422, 'Unknown student category.');
        }

        return $this->importer->template();
    }

    public function import(ImportStudentsRequest $request): JsonResponse
    {
        $this->authorizeImport($request);

        $intake = Intake::query()->findOrFail((int) $request->input('intake_id'));
        $entryMode = strtolower((string) $request->input('entry_mode'));
        if ($intake->entry_mode !== $entryMode) {
            return response()->json([
                'message' => 'The selected application window does not match this student category.',
            ], 422);
        }

        $verifyNin = $request->boolean('verify_nin');
        $sendCredentials = $request->has('send_credentials')
            ? $request->boolean('send_credentials')
            : true;
        $file = $request->file('file');
        $rowCount = $this->importer->countDataRows($file);
        $importId = (string) Str::uuid();
        $options = [
            'verify_nin' => $verifyNin,
            'send_credentials' => $sendCredentials,
        ];

        if ($this->importer->shouldQueue($verifyNin, $rowCount)) {
            $path = $this->importer->storeUpload($file);
            $this->importer->cacheResult($importId, [
                'status' => 'queued',
                'queued' => true,
                'import_id' => $importId,
            ]);
            ImportStudentsJob::dispatch(
                $importId,
                $path,
                $intake->id,
                $entryMode,
                $verifyNin,
                $sendCredentials,
                (int) $request->user()->id,
            );

            return response()->json([
                'message' => 'Import queued. NIN checks and mail can take a few minutes.',
                'queued' => true,
                'status' => 'queued',
                'import_id' => $importId,
            ], 202);
        }

        try {
            $result = $this->importer->import($file, $intake, $entryMode, $options);
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
        $created = (int) ($result['created'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);
        $emailed = (int) ($result['emailed'] ?? 0);

        return "Imported {$created} student(s). {$skipped} row(s) skipped. {$emailed} credential email(s) sent.";
    }

    private function authorizeImport(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission('students.import')
            || $user->hasPermission('students.manage'),
            403,
        );
    }
}
