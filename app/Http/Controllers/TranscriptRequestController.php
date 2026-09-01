<?php

namespace App\Http\Controllers;

use App\Models\TranscriptRequest;
use App\Services\TranscriptRequestService;
use App\Support\OfficeApprovalCatalog;
use App\Support\TranscriptChannel;
use App\Support\TranscriptRequestSettings;
use App\Support\TranscriptType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class TranscriptRequestController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(private TranscriptRequestService $service) {}

    public function meta()
    {
        return response()->json($this->service->meta());
    }

    public function lookup(Request $request)
    {
        $data = $request->validate([
            'matric_number' => 'required|string|max:64',
            'email' => 'required|email|max:255',
            'channel' => ['nullable', 'string', Rule::in(TranscriptChannel::KEYS)],
        ]);

        try {
            return response()->json($this->service->lookup(
                $data['matric_number'],
                $data['email'],
                $data['channel'] ?? null,
            ));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function quote(Request $request)
    {
        $data = $request->validate([
            'program_id' => 'required|integer|exists:programs,id',
            'transcript_type' => ['required', 'string', Rule::in(TranscriptType::ALL)],
        ]);

        try {
            return response()->json($this->service->quote((int) $data['program_id'], $data['transcript_type']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'matric_number' => 'required|string|max:64',
            'email' => 'required|email|max:255',
            'program_id' => 'required|integer|exists:programs,id',
            'transcript_type' => ['required', 'string', Rule::in(TranscriptType::ALL)],
            'copies' => 'nullable|integer|min:1|max:10',
            'purpose' => 'nullable|string|max:255',
            'delivery_email' => 'nullable|email|max:255',
            'delivery_address' => 'nullable|string|max:1000',
            'collection_method' => ['nullable', 'string', Rule::in(TranscriptType::COLLECTION_METHODS)],
            'channel' => ['nullable', 'string', Rule::in(TranscriptChannel::KEYS)],
        ]);

        try {
            $result = $this->service->create(
                $data['matric_number'],
                $data['email'],
                (int) $data['program_id'],
                $data['transcript_type'],
                (int) ($data['copies'] ?? 1),
                $data['purpose'] ?? null,
                $data['delivery_email'] ?? null,
                $data['delivery_address'] ?? null,
                $data['collection_method'] ?? null,
                $data['channel'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, 201);
    }

    public function show(string $token)
    {
        return response()->json($this->service->showPublic($token));
    }

    public function pay(string $token)
    {
        $request = $this->service->findByToken($token);

        try {
            $payment = $this->service->initializePayment($request);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'request' => $this->service->publicPayload($request->fresh()),
            'payment' => $payment,
        ]);
    }

    public function verify(Request $request, string $token, string $reference)
    {
        $transactionId = $request->query('transactionId') ?: $request->query('transaction_id');
        try {
            return response()->json($this->service->verifyPayment(
                $token,
                $reference,
                $transactionId ? (string) $transactionId : null,
            ));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function download(string $token)
    {
        $request = $this->service->findByToken($token);

        return $this->service->downloadResponse($request);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('transcripts.view'), 403);

        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'program_id' => 'nullable|integer|exists:programs,id',
        ]);

        $perPage = min(50, max(10, (int) $request->input('per_page', 25)));
        $query = TranscriptRequest::query()
            ->with(['student.program', 'invoice', 'processor'])
            ->latest('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($channel = $request->input('channel')) {
            abort_unless(TranscriptChannel::isValid($channel), 422, 'Invalid transcript channel.');
            $query->where(function ($q) use ($channel) {
                $q->whereHas('program', fn ($pq) => TranscriptChannel::applyToProgramsQuery($pq, $channel))
                    ->orWhere(function ($q2) use ($channel) {
                        $q2->whereNull('program_id')
                            ->whereHas('student.program', fn ($pq) => TranscriptChannel::applyToProgramsQuery($pq, $channel));
                    });
            });
        }
        if ($type = $request->input('transcript_type')) {
            abort_unless(in_array($type, TranscriptType::ALL, true), 422, 'Invalid transcript type.');
            $query->where('transcript_type', $type);
        }
        if ($programId = (int) $request->input('program_id')) {
            $query->where('program_id', $programId);
        }
        if ($collection = $request->input('collection_method')) {
            abort_unless(in_array($collection, TranscriptType::COLLECTION_METHODS, true), 422, 'Invalid collection method.');
            $query->where('collection_method', $collection);
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }
        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('public_token', 'like', '%'.$search.'%')
                    ->orWhere('contact_email', 'like', '%'.$search.'%')
                    ->orWhere('delivery_email', 'like', '%'.$search.'%')
                    ->orWhereHas('student', function ($sq) use ($search) {
                        $sq->where('matric_number', 'like', '%'.$search.'%')
                            ->orWhere('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('program', function ($pq) use ($search) {
                        $pq->where('name', 'like', '%'.$search.'%')
                            ->orWhere('code', 'like', '%'.$search.'%');
                    });
            });
        }

        $page = $query->paginate($perPage);

        return [
            'data' => collect($page->items())->map(fn (TranscriptRequest $row) => $this->service->staffPayload($row))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'settings' => TranscriptRequestSettings::all(),
        ];
    }

    public function staffShow(Request $request, TranscriptRequest $transcriptRequest)
    {
        abort_unless($request->user()->hasPermission('transcripts.view'), 403);

        return $this->service->staffPayload($transcriptRequest);
    }

    public function start(Request $request, TranscriptRequest $transcriptRequest)
    {
        abort_unless($request->user()->hasPermission('transcripts.process'), 403);

        return $this->officeGate(
            'transcripts.start',
            $transcriptRequest,
            ['transcript_request_id' => $transcriptRequest->id],
            'Start transcript processing',
            fn () => $this->service->startProcessing($transcriptRequest, $request->user()),
            $this->transcriptNavKey($transcriptRequest),
        );
    }

    public function ready(Request $request, TranscriptRequest $transcriptRequest)
    {
        abort_unless($request->user()->hasPermission('transcripts.process'), 403);

        $data = $request->validate([
            'delivery_mode' => ['required', Rule::in(TranscriptRequest::DELIVERY_MODES)],
            'file' => 'nullable|file|mimes:pdf|max:10240',
        ]);

        $payload = [
            'transcript_request_id' => $transcriptRequest->id,
            'delivery_mode' => $data['delivery_mode'],
            ...$this->persistApprovalUpload($request),
        ];

        return $this->officeGate(
            'transcripts.ready',
            $transcriptRequest,
            $payload,
            'Mark transcript ready',
            function () use ($request, $transcriptRequest, $data) {
                try {
                    $updated = $this->service->markReady(
                        $transcriptRequest,
                        $request->user(),
                        $data['delivery_mode'],
                        $request->file('file'),
                    );
                } catch (InvalidArgumentException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }

                return $this->service->staffPayload($updated);
            },
            $this->transcriptNavKey($transcriptRequest),
        );
    }

    public function reject(Request $request, TranscriptRequest $transcriptRequest)
    {
        abort_unless($request->user()->hasPermission('transcripts.process'), 403);

        $data = $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ]);

        return $this->officeGate(
            'transcripts.reject',
            $transcriptRequest,
            ['transcript_request_id' => $transcriptRequest->id, ...$data],
            'Reject transcript request',
            fn () => $this->service->staffPayload(
                $this->service->reject($transcriptRequest, $request->user(), $data['reason'])
            ),
            $this->transcriptNavKey($transcriptRequest),
        );
    }

    public function staffDownload(Request $request, TranscriptRequest $transcriptRequest)
    {
        abort_unless($request->user()->hasPermission('transcripts.view'), 403);

        return $this->service->downloadResponse($transcriptRequest);
    }

    private function transcriptNavKey(TranscriptRequest $transcriptRequest): string
    {
        $transcriptRequest->loadMissing(['program', 'student.program']);
        $program = $transcriptRequest->program ?? $transcriptRequest->student?->program;

        return OfficeApprovalCatalog::transcriptNavKey(
            $program ? TranscriptChannel::forProgram($program) : null
        );
    }
}
