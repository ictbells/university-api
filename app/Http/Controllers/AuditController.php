<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\ReportExportService;
use App\Services\ReportQueryService;
use App\Support\AuditListQuery;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(private ReportExportService $exports) {}

    public function index(Request $request)
    {
        $this->validateFilters($request);
        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));
        $paginator = AuditListQuery::fromRequest($request)->paginate($perPage);
        $payload = $paginator->toArray();
        $payload['summary'] = AuditListQuery::summary($request);
        $payload['facets'] = AuditListQuery::facets();

        return response()->json($payload);
    }

    public function export(Request $request)
    {
        $data = $request->validate([
            'format' => 'required|in:pdf,excel,word',
            'title' => 'nullable|string|max:120',
        ]);
        $this->validateFilters($request);

        $query = AuditListQuery::fromRequest($request);
        $total = (clone $query)->reorder()->count();
        $logs = $query->limit(ReportQueryService::MAX_ROWS)->get();
        $filters = AuditListQuery::filterSummary($request);
        if ($total > ReportQueryService::MAX_ROWS) {
            $filters[] = 'Limited to first '.ReportQueryService::MAX_ROWS.' of '.$total.' matching entries';
        }

        $headers = [
            ['key' => 'occurred_at', 'label' => 'When'],
            ['key' => 'actor', 'label' => 'Who'],
            ['key' => 'action', 'label' => 'Action'],
            ['key' => 'summary', 'label' => 'Summary'],
            ['key' => 'module', 'label' => 'Module'],
            ['key' => 'entity', 'label' => 'Entity'],
            ['key' => 'request_id', 'label' => 'Request'],
            ['key' => 'ip', 'label' => 'IP'],
            ['key' => 'reason', 'label' => 'Reason'],
        ];

        $rows = $logs->map(function (AuditLog $log) {
            $when = $log->occurred_at?->format('d M Y H:i:s') ?: '—';
            $entity = trim(($log->entity_type ?: '').($log->entity_id ? ':'.$log->entity_id : ''));

            return [
                'occurred_at' => $when,
                'actor' => (string) ($log->actor_email ?: $log->actor_name ?: '—'),
                'action' => (string) ($log->action ?: '—'),
                'summary' => (string) ($log->summary ?: '—'),
                'module' => (string) ($log->module ?: '—'),
                'entity' => $entity !== '' ? $entity : '—',
                'request_id' => (string) ($log->request_id ?: '—'),
                'ip' => (string) ($log->ip ?: '—'),
                'reason' => (string) ($log->reason ?: '—'),
            ];
        })->all();

        return $this->exports->export(
            $data['format'],
            $headers,
            $rows,
            $data['title'] ?? 'Audit trail',
            $filters,
        );
    }

    public function show(AuditLog $auditLog)
    {
        return $auditLog->toArray();
    }

    private function validateFilters(Request $request): void
    {
        $request->validate([
            'search' => 'nullable|string|max:200',
            'module' => 'nullable|string|max:100',
            'action' => 'nullable|string|max:120',
            'actor_email' => 'nullable|string|max:190',
            'request_id' => 'nullable|string|max:80',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:10|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
    }
}
