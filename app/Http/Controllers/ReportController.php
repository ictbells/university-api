<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Enrollment;
use App\Models\HostelBed;
use App\Models\Invoice;
use App\Models\MedicalBill;
use App\Models\SavedReport;
use App\Models\Student;
use App\Models\Wallet;
use App\Services\AuditWriter;
use App\Services\ReportExportService;
use App\Services\ReportQueryService;
use App\Support\InvoiceSettlement;
use App\Support\Reports\ReportDatasetCatalog;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ReportController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(
        private ReportQueryService $queries,
        private ReportExportService $exports,
        private AuditWriter $audit,
    ) {}

    public function summary()
    {
        $funnel = Application::query()->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');

        return [
            'admissions_funnel' => $funnel,
            'students' => Student::query()->count(),
            'enrollments' => Enrollment::query()->count(),
            'invoices_outstanding' => Invoice::query()->whereIn('status', ['unpaid', 'partial'])->sum('balance'),
            'payments_collected' => InvoiceSettlement::collectedRevenue(),
            'wallet_total' => Wallet::query()->sum('balance'),
            'hostel_occupancy' => [
                'total_beds' => HostelBed::query()->count(),
                'occupied' => HostelBed::query()->where('status', 'occupied')->count(),
            ],
            'medical_bills' => MedicalBill::query()->sum('amount'),
        ];
    }

    public function datasets(Request $request)
    {
        $user = $request->user();

        return [
            'data' => collect(ReportDatasetCatalog::all())
                ->filter(fn ($dataset) => $dataset->userCanAccess($user))
                ->map(fn ($dataset) => $dataset->toSchema())
                ->values()
                ->all(),
        ];
    }

    public function run(Request $request)
    {
        $data = $request->validate([
            'dataset' => 'required|string',
            'columns' => 'nullable|array',
            'columns.*' => 'string',
            'filters' => 'nullable|array',
            'group_by' => 'nullable|array',
            'group_by.*' => 'string',
            'aggregations' => 'nullable|array',
            'sorts' => 'nullable|array',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:100',
            'saved_report_id' => 'nullable|integer',
        ]);

        try {
            $result = $this->queries->run(
                $request->user(),
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['per_page'] ?? 25),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->record(
            'report.run',
            'Ran report '.$result['dataset'],
            'reports',
            'saved_report',
            isset($data['saved_report_id']) ? (int) $data['saved_report_id'] : null,
            after: ['dataset' => $result['dataset'], 'total' => $result['meta']['total']],
        );

        return $result;
    }

    public function export(Request $request)
    {
        $data = $request->validate([
            'dataset' => 'required|string',
            'columns' => 'nullable|array',
            'columns.*' => 'string',
            'filters' => 'nullable|array',
            'group_by' => 'nullable|array',
            'group_by.*' => 'string',
            'aggregations' => 'nullable|array',
            'sorts' => 'nullable|array',
            'format' => 'required|in:pdf,excel,word',
            'title' => 'nullable|string|max:120',
            'saved_report_id' => 'nullable|integer',
        ]);

        try {
            $exported = $this->queries->exportRows($request->user(), $data, (string) ($data['title'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->record(
            'report.export',
            'Exported report '.$exported['title'],
            'reports',
            'saved_report',
            isset($data['saved_report_id']) ? (int) $data['saved_report_id'] : null,
            after: [
                'dataset' => $data['dataset'],
                'format' => $data['format'],
                'rows' => $exported['total'],
            ],
        );

        return $this->exports->export(
            $data['format'],
            $exported['headers'],
            $exported['rows'],
            $exported['title'],
            $exported['filter_summary'],
        );
    }

    public function indexSaved(Request $request)
    {
        $user = $request->user();
        $accessible = collect(ReportDatasetCatalog::all())
            ->filter(fn ($dataset) => $dataset->userCanAccess($user))
            ->map(fn ($dataset) => $dataset->key)
            ->all();

        $rows = SavedReport::query()
            ->with('creator:id,name,email')
            ->where(function ($query) use ($user) {
                $query->where('created_by', $user->id)
                    ->orWhere('visibility', 'shared');
            })
            ->whereIn('dataset_key', $accessible !== [] ? $accessible : ['__none__'])
            ->latest()
            ->get();

        return ['data' => $rows];
    }

    public function showSaved(Request $request, SavedReport $savedReport)
    {
        $this->authorizeView($request, $savedReport);

        return $savedReport->load('creator:id,name,email');
    }

    public function storeSaved(Request $request)
    {
        $data = $this->validatedSaved($request);
        $this->assertDataset($request, $data['dataset_key']);

        try {
            $definition = $this->queries->validate(
                ReportDatasetCatalog::get($data['dataset_key']),
                [...$data['definition'], 'dataset' => $data['dataset_key']],
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->officeGate('reports.store', null, $data, 'Save report '.$data['name'], function () use ($request, $data, $definition) {
            $report = SavedReport::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'dataset_key' => $data['dataset_key'],
                'definition' => $definition,
                'visibility' => $data['visibility'],
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $this->audit->record('report.save', 'Saved report '.$report->name, 'reports', 'saved_report', $report->id, after: $report);

            return response()->json($report, 201);
        });
    }

    public function updateSaved(Request $request, SavedReport $savedReport)
    {
        if (! $savedReport->writableBy($request->user())) {
            abort(403, 'This action is not authorized.');
        }

        $data = $this->validatedSaved($request, false);
        if (isset($data['dataset_key'])) {
            $this->assertDataset($request, $data['dataset_key']);
        }

        $datasetKey = $data['dataset_key'] ?? $savedReport->dataset_key;
        $definition = $data['definition'] ?? $savedReport->definition;
        try {
            $definition = $this->queries->validate(
                ReportDatasetCatalog::get($datasetKey),
                [...$definition, 'dataset' => $datasetKey],
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->officeGate(
            'reports.update',
            $savedReport,
            ['saved_report_id' => $savedReport->id, ...$data, 'dataset_key' => $datasetKey, 'definition' => $definition],
            'Update report '.$savedReport->name,
            function () use ($request, $savedReport, $data, $datasetKey, $definition) {
                $before = $savedReport->toArray();
                $savedReport->update([
                    'name' => $data['name'] ?? $savedReport->name,
                    'description' => array_key_exists('description', $data) ? $data['description'] : $savedReport->description,
                    'dataset_key' => $datasetKey,
                    'definition' => $definition,
                    'visibility' => $data['visibility'] ?? $savedReport->visibility,
                    'updated_by' => $request->user()->id,
                ]);

                $this->audit->record('report.update', 'Updated report '.$savedReport->name, 'reports', 'saved_report', $savedReport->id, $before, $savedReport);

                return $savedReport->fresh();
            },
        );
    }

    public function destroySaved(Request $request, SavedReport $savedReport)
    {
        if (! $savedReport->writableBy($request->user())) {
            abort(403, 'This action is not authorized.');
        }

        return $this->officeGate(
            'reports.destroy',
            $savedReport,
            ['saved_report_id' => $savedReport->id],
            'Delete report '.$savedReport->name,
            function () use ($savedReport) {
                $before = $savedReport->toArray();
                $savedReport->delete();
                $this->audit->record('report.delete', 'Deleted report '.$before['name'], 'reports', 'saved_report', $savedReport->id, $before);

                return response()->json(['ok' => true]);
            },
        );
    }

    private function authorizeView(Request $request, SavedReport $savedReport): void
    {
        if (! $savedReport->visibleTo($request->user())) {
            abort(404);
        }
        $this->assertDataset($request, $savedReport->dataset_key);
    }

    private function assertDataset(Request $request, string $datasetKey): void
    {
        $dataset = ReportDatasetCatalog::get($datasetKey);
        if (! $dataset || ! $dataset->userCanAccess($request->user())) {
            abort(response()->json([
                'message' => 'You do not have permission for this report dataset.',
                'access_reason' => 'missing_permission',
            ], 403));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedSaved(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:120',
            'description' => 'nullable|string|max:500',
            'dataset_key' => ($creating ? 'required' : 'sometimes').'|string',
            'definition' => ($creating ? 'required' : 'sometimes').'|array',
            'visibility' => ($creating ? 'required' : 'sometimes').'|in:private,shared',
        ]);
    }
}
