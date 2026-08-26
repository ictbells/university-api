<?php

namespace App\Http\Controllers;

use App\Models\TranscriptRequest;
use App\Services\TranscriptRequestService;
use App\Support\TranscriptRequestSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class TranscriptRequestController extends Controller
{
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
        ]);

        try {
            return response()->json($this->service->lookup($data['matric_number'], $data['email']));
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
            'copies' => 'nullable|integer|min:1|max:10',
            'purpose' => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->service->create(
                $data['matric_number'],
                $data['email'],
                (int) $data['program_id'],
                (int) ($data['copies'] ?? 1),
                $data['purpose'] ?? null,
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

    public function verify(string $token, string $reference)
    {
        try {
            return response()->json($this->service->verifyPayment($token, $reference));
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

        $perPage = min(50, max(10, (int) $request->input('per_page', 25)));
        $query = TranscriptRequest::query()
            ->with(['student.program', 'invoice', 'processor'])
            ->latest('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('public_token', 'like', '%'.$search.'%')
                    ->orWhere('contact_email', 'like', '%'.$search.'%')
                    ->orWhereHas('student', function ($sq) use ($search) {
                        $sq->where('matric_number', 'like', '%'.$search.'%')
                            ->orWhere('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%');
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

        return $this->service->startProcessing($transcriptRequest, $request->user());
    }

    public function ready(Request $request, TranscriptRequest $transcriptRequest)
    {
        abort_unless($request->user()->hasPermission('transcripts.process'), 403);

        $data = $request->validate([
            'delivery_mode' => ['required', Rule::in(TranscriptRequest::DELIVERY_MODES)],
            'file' => 'nullable|file|mimes:pdf|max:10240',
        ]);

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
    }

    public function reject(Request $request, TranscriptRequest $transcriptRequest)
    {
        abort_unless($request->user()->hasPermission('transcripts.process'), 403);

        $data = $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ]);

        $updated = $this->service->reject($transcriptRequest, $request->user(), $data['reason']);

        return $this->service->staffPayload($updated);
    }

    public function staffDownload(Request $request, TranscriptRequest $transcriptRequest)
    {
        abort_unless($request->user()->hasPermission('transcripts.view'), 403);

        return $this->service->downloadResponse($transcriptRequest);
    }
}
