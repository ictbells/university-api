<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadCandidateDataRequest;
use App\Models\AcademicTerm;
use App\Models\CandidateData;
use App\Services\AuditWriter;
use App\Services\CandidateDataImportService;
use App\Support\CandidateEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CandidateDataController extends Controller
{
    public function __construct(
        private CandidateDataImportService $importer,
        private AuditWriter $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('admissions.import'), 403);

        $query = CandidateData::query();

        if ($request->filled('registration_number')) {
            $query->where('rg_num', 'like', '%'.strtoupper(trim((string) $request->registration_number)).'%');
        }
        if ($request->filled('candidate_name')) {
            $query->where('rg_candname', 'like', '%'.$request->candidate_name.'%');
        }
        if ($request->filled('state_name')) {
            $query->where('state_name', 'like', '%'.$request->state_name.'%');
        }
        if ($request->filled('academic_year')) {
            $query->where('academic_year', $request->academic_year);
        }

        $sortBy = in_array($request->input('sort_by'), ['id', 'rg_num', 'rg_candname', 'rg_aggr', 'created_at'], true)
            ? $request->input('sort_by')
            : 'id';
        $sortOrder = strtolower((string) $request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return response()->json($query->paginate(min(max((int) $request->input('per_page', 25), 1), 100)));
    }

    public function sessions(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('admissions.import'), 403);

        $terms = AcademicTerm::query()
            ->orderByDesc('starts_on')
            ->get(['id', 'name', 'session_label', 'is_current']);

        $years = CandidateData::query()
            ->distinct()
            ->orderByDesc('academic_year')
            ->pluck('academic_year');

        return response()->json([
            'terms' => $terms,
            'uploaded_years' => $years,
            'open_intake_sessions' => CandidateEligibility::openIntakeSessionLabels(),
        ]);
    }

    public function upload(UploadCandidateDataRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('admissions.import'), 403);

        try {
            $result = $this->importer->import(
                $request->file('file'),
                (string) $request->input('academic_year'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to import candidate data: '.$e->getMessage()], 500);
        }

        $this->audit->record(
            'candidate_data.imported',
            'Candidate data imported from spreadsheet',
            'admissions',
            'candidate_data',
            null,
            null,
            array_merge($result, [
                'academic_year' => $request->input('academic_year'),
                'file_name' => $request->file('file')->getClientOriginalName(),
            ]),
        );

        return response()->json([
            'message' => "Successfully imported {$result['imported']} candidate record(s). {$result['skipped']} row(s) skipped.",
            'data' => $result,
        ]);
    }

    public function lookup(Request $request, string $jambRegistration): JsonResponse
    {
        $candidate = CandidateEligibility::findByJamb(
            $jambRegistration,
            $request->query('academic_year'),
        );

        if (! $candidate) {
            return response()->json([
                'message' => 'Candidate data not found for the provided registration number.',
            ], 404);
        }

        $names = CandidateEligibility::splitCandidateName($candidate->rg_candname);

        return response()->json([
            'data' => $candidate,
            'suggested' => [
                'first_name' => $names['first_name'],
                'last_name' => $names['last_name'],
                'utme' => CandidateEligibility::utmeQualificationPayload($candidate),
            ],
        ]);
    }
}
