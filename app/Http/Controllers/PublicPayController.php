<?php

namespace App\Http\Controllers;

use App\Models\PublicPayOffer;
use App\Models\PublicPayRequest;
use App\Services\PublicPayRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class PublicPayController extends Controller
{
    public function __construct(private PublicPayRequestService $service) {}

    public function meta()
    {
        return response()->json($this->service->meta());
    }

    public function lookup(Request $request)
    {
        $data = $request->validate([
            'nin' => 'required|string|max:32',
        ]);

        try {
            return response()->json($this->service->lookup($data['nin']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nin' => 'required|string|max:32',
            'offer_id' => 'required|integer|exists:public_pay_offers,id',
            'purpose' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
        ]);

        try {
            $result = $this->service->create(
                $data['nin'],
                (int) $data['offer_id'],
                $data['purpose'] ?? null,
                $data['contact_email'] ?? null,
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

    public function indexOffers(Request $request)
    {
        abort_unless($request->user()->hasPermission('public_pay.offers') || $request->user()->hasPermission('public_pay.view'), 403);

        $activeOnly = null;
        if ($request->has('active')) {
            $activeOnly = filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return response()->json([
            'data' => $this->service->listOffersForStaff($activeOnly),
        ]);
    }

    public function storeOffer(Request $request)
    {
        abort_unless($request->user()->hasPermission('public_pay.offers'), 403);

        $data = $request->validate([
            'name' => 'required|string|max:160',
            'slug' => 'nullable|string|max:160',
            'description' => 'nullable|string|max:2000',
            'instructions' => 'nullable|string|max:2000',
            'fee_item_id' => 'required|integer|exists:fee_items,id',
            'is_active' => 'sometimes|boolean',
            'display_order' => 'nullable|integer|min:0|max:9999',
        ]);

        try {
            $offer = $this->service->createOffer($data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->service->offerPayload($offer), 201);
    }

    public function updateOffer(Request $request, PublicPayOffer $publicPayOffer)
    {
        abort_unless($request->user()->hasPermission('public_pay.offers'), 403);

        $data = $request->validate([
            'name' => 'sometimes|string|max:160',
            'slug' => 'nullable|string|max:160',
            'description' => 'nullable|string|max:2000',
            'instructions' => 'nullable|string|max:2000',
            'fee_item_id' => 'sometimes|integer|exists:fee_items,id',
            'is_active' => 'sometimes|boolean',
            'display_order' => 'nullable|integer|min:0|max:9999',
        ]);

        try {
            $updated = $this->service->updateOffer($publicPayOffer, $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->service->offerPayload($updated));
    }

    public function destroyOffer(Request $request, PublicPayOffer $publicPayOffer)
    {
        abort_unless($request->user()->hasPermission('public_pay.offers'), 403);

        try {
            $this->service->deleteOffer($publicPayOffer);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('public_pay.view'), 403);

        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'offer_id' => 'nullable|integer|exists:public_pay_offers,id',
            'status' => ['nullable', 'string', Rule::in(PublicPayRequest::STATUSES)],
        ]);

        $perPage = min(50, max(10, (int) $request->input('per_page', 25)));
        $query = PublicPayRequest::query()
            ->with(['student.program', 'offer.feeItem', 'invoice', 'processor'])
            ->latest('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($offerId = (int) $request->input('offer_id')) {
            $query->where('offer_id', $offerId);
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
                    ->orWhereHas('student', function ($sq) use ($search) {
                        $sq->where('matric_number', 'like', '%'.$search.'%')
                            ->orWhere('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('offer', function ($oq) use ($search) {
                        $oq->where('name', 'like', '%'.$search.'%')
                            ->orWhere('slug', 'like', '%'.$search.'%');
                    });
            });
        }

        $page = $query->paginate($perPage);

        return [
            'data' => collect($page->items())->map(fn (PublicPayRequest $row) => $this->service->staffPayload($row))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'settings' => \App\Support\PublicPaySettings::all(),
        ];
    }

    public function staffShow(Request $request, PublicPayRequest $publicPayRequest)
    {
        abort_unless($request->user()->hasPermission('public_pay.view'), 403);

        return $this->service->staffPayload($publicPayRequest);
    }

    public function start(Request $request, PublicPayRequest $publicPayRequest)
    {
        abort_unless($request->user()->hasPermission('public_pay.process'), 403);

        return $this->service->staffPayload(
            $this->service->startProcessing($publicPayRequest, $request->user())
        );
    }

    public function ready(Request $request, PublicPayRequest $publicPayRequest)
    {
        abort_unless($request->user()->hasPermission('public_pay.process'), 403);

        $data = $request->validate([
            'delivery_mode' => ['required', Rule::in(PublicPayRequest::DELIVERY_MODES)],
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        try {
            $updated = $this->service->markReady(
                $publicPayRequest,
                $request->user(),
                $data['delivery_mode'],
                $request->file('file'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->service->staffPayload($updated);
    }

    public function reject(Request $request, PublicPayRequest $publicPayRequest)
    {
        abort_unless($request->user()->hasPermission('public_pay.process'), 403);

        $data = $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ]);

        return $this->service->staffPayload(
            $this->service->reject($publicPayRequest, $request->user(), $data['reason'])
        );
    }

    public function staffDownload(Request $request, PublicPayRequest $publicPayRequest)
    {
        abort_unless($request->user()->hasPermission('public_pay.view'), 403);

        return $this->service->downloadResponse($publicPayRequest);
    }
}
