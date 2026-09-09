<?php

namespace App\Services;

use App\Mail\PublicPayRequestPaidMail;
use App\Mail\PublicPayRequestReadyMail;
use App\Mail\PublicPayRequestRejectedMail;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\NinVerification;
use App\Models\PublicPayOffer;
use App\Models\PublicPayRequest;
use App\Models\Student;
use App\Models\User;
use App\Support\AppStorage;
use App\Support\NinCipher;
use App\Support\PublicPaySettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class PublicPayRequestService
{
    public function __construct(
        private InvoiceService $invoices,
        private PaymentGatewayManager $gateways,
        private AuditWriter $audit,
    ) {}

    public function meta(): array
    {
        $offers = $this->publicOffers();
        $settings = PublicPaySettings::all();

        return [
            'enabled' => PublicPaySettings::enabled() && $offers !== [],
            'university' => (string) \App\Models\Setting::getValue('university_name', 'Bells University of Technology'),
            'offers' => $offers,
            'collect_instructions' => $settings['public_pay_collect_instructions'],
            'unavailable_reason' => $this->unavailableReason($offers !== []),
        ];
    }

    public function lookup(string $nin): array
    {
        $this->assertFeatureAvailable();

        $student = $this->findStudentForRequest($nin);

        return [
            'student' => [
                'name' => trim(($student->first_name ?? '').' '.($student->last_name ?? '')),
                'matric_number' => $student->matric_number,
                'email' => $student->user?->email,
            ],
            'offers' => $this->publicOffers(),
        ];
    }

    public function create(string $nin, int $offerId, ?string $purpose = null, ?string $contactEmail = null): array
    {
        $this->assertFeatureAvailable();

        $student = $this->findStudentForRequest($nin);
        $user = $student->user;
        if (! $user) {
            throw new RuntimeException('Unable to verify student account. Contact the office.');
        }

        $offer = PublicPayOffer::query()->with('feeItem')->findOrFail($offerId);
        if (! $offer->isPubliclyAvailable()) {
            throw new RuntimeException('That service is not available for online request and payment.');
        }

        $fee = $offer->feeItem;
        $purpose = $purpose ? Str::limit(trim($purpose), 255, '') : null;
        $contactEmail = strtolower(trim((string) ($contactEmail ?: $user->email)));
        if ($contactEmail === '' || ! filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('No email is on file for this student. Enter a contact email or contact the office.');
        }

        $invoiceLabel = $offer->name;

        $existing = PublicPayRequest::query()
            ->where('student_id', $student->id)
            ->where('offer_id', $offer->id)
            ->where('status', 'awaiting_payment')
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->update([
                'contact_email' => $contactEmail,
                'purpose' => $purpose,
            ]);
            $request = $existing->fresh(['invoice', 'offer.feeItem']);
            if (! $request->invoice || ! $request->invoice->isPayable()) {
                $invoice = $this->invoices->createForFee(
                    $user,
                    $fee,
                    null,
                    $student->id,
                    null,
                    $invoiceLabel,
                );
                $request->update(['invoice_id' => $invoice->id]);
                $request = $request->fresh(['invoice', 'offer.feeItem']);
            }
        } else {
            $invoice = $this->invoices->createForFee(
                $user,
                $fee,
                null,
                $student->id,
                null,
                $invoiceLabel,
            );

            $request = PublicPayRequest::query()->create([
                'public_token' => Str::lower(Str::random(48)),
                'student_id' => $student->id,
                'offer_id' => $offer->id,
                'invoice_id' => $invoice->id,
                'contact_email' => $contactEmail,
                'purpose' => $purpose,
                'status' => 'awaiting_payment',
            ]);
            $request->load(['invoice', 'offer.feeItem']);
        }

        $payment = $this->initializePayment($request);

        return [
            'request' => $this->publicPayload($request),
            'payment' => $payment,
        ];
    }

    public function initializePayment(PublicPayRequest $request): array
    {
        $request->loadMissing(['invoice', 'student.user']);
        abort_unless($request->status === 'awaiting_payment', 422, 'This request is not awaiting payment.');
        $invoice = $request->invoice;
        abort_unless($invoice && $invoice->isPayable(), 422, 'No payable invoice for this request.');
        $user = $request->student?->user;
        abort_unless($user, 422, 'Student account missing.');

        $callback = rtrim((string) config('app.student_url'), '/')
            .'/request-pay/callback?token='.urlencode($request->public_token);

        return $this->gateways->initializeInvoice($user, $invoice, $callback);
    }

    public function showPublic(string $token): array
    {
        return $this->publicPayload($this->findByToken($token));
    }

    public function verifyPayment(string $token, string $reference, ?string $transactionId = null): array
    {
        $request = $this->findByToken($token);
        $payment = $this->gateways->verify($reference, $transactionId);
        abort_unless(
            (int) $payment->invoice_id === (int) $request->invoice_id,
            422,
            'Payment does not match this request.',
        );

        $request->refresh();

        return [
            'request' => $this->publicPayload($request),
            'payment' => $payment->load('invoice'),
        ];
    }

    public function markPaid(Invoice $invoice): void
    {
        $request = PublicPayRequest::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', ['awaiting_payment', 'cancelled'])
            ->first();

        if (! $request) {
            return;
        }

        $request->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        try {
            Mail::to($request->contact_email)->send(new PublicPayRequestPaidMail($request->fresh(['student', 'offer'])));
        } catch (\Throwable) {
            // Payment succeeded even if mail fails.
        }
    }

    public function startProcessing(PublicPayRequest $request, User $staff): PublicPayRequest
    {
        abort_unless(in_array($request->status, ['paid', 'processing'], true), 422, 'Only paid requests can be processed.');

        if ($request->status === 'paid') {
            $request->update([
                'status' => 'processing',
                'processed_by' => $staff->id,
            ]);
            $this->audit->record(
                'public_pay.processing',
                'Public pay request moved to processing',
                'public_pay',
                'public_pay_request',
                $request->id,
            );
        }

        return $request->fresh(['student', 'offer.feeItem', 'invoice', 'processor']);
    }

    public function markReady(
        PublicPayRequest $request,
        User $staff,
        string $deliveryMode,
        ?UploadedFile $upload = null,
    ): PublicPayRequest {
        abort_unless(in_array($request->status, ['paid', 'processing'], true), 422, 'Request cannot be marked ready.');
        if (! in_array($deliveryMode, PublicPayRequest::DELIVERY_MODES, true)) {
            throw new InvalidArgumentException('Invalid delivery mode.');
        }

        $path = $request->artifact_path;

        if ($deliveryMode === 'uploaded') {
            if (! $upload) {
                throw new InvalidArgumentException('Upload a file to fulfill this request.');
            }
            $ext = strtolower($upload->getClientOriginalExtension() ?: 'pdf');
            $path = $upload->storeAs(
                'public-pay/'.$request->id,
                'artifact-'.Str::random(8).'.'.$ext,
                AppStorage::diskName(),
            );
        } else {
            $path = null;
        }

        $request->update([
            'status' => 'ready',
            'delivery_mode' => $deliveryMode,
            'artifact_path' => $path,
            'processed_by' => $staff->id,
            'ready_at' => now(),
            'rejected_reason' => null,
        ]);

        $fresh = $request->fresh(['student', 'offer.feeItem', 'invoice', 'processor']);
        Mail::to($fresh->contact_email)->send(new PublicPayRequestReadyMail($fresh));

        $this->audit->record(
            'public_pay.ready',
            'Public pay request marked ready ('.$deliveryMode.')',
            'public_pay',
            'public_pay_request',
            $request->id,
        );

        return $fresh;
    }

    public function reject(PublicPayRequest $request, User $staff, string $reason): PublicPayRequest
    {
        abort_unless(in_array($request->status, ['paid', 'processing', 'awaiting_payment'], true), 422, 'Request cannot be rejected.');

        $request->update([
            'status' => 'rejected',
            'rejected_reason' => Str::limit(trim($reason), 1000, ''),
            'processed_by' => $staff->id,
        ]);

        $fresh = $request->fresh(['student', 'offer']);
        Mail::to($fresh->contact_email)->send(new PublicPayRequestRejectedMail($fresh));

        $this->audit->record(
            'public_pay.rejected',
            'Public pay request rejected',
            'public_pay',
            'public_pay_request',
            $request->id,
            null,
            ['reason' => $reason],
        );

        return $fresh;
    }

    public function downloadResponse(PublicPayRequest $request)
    {
        abort_unless($request->isDownloadable(), 404, 'Download is not available for this request.');
        abort_unless(AppStorage::exists($request->artifact_path), 404, 'File not found.');

        $matric = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($request->student?->matric_number ?: 'student')) ?: 'student';
        $offerSlug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($request->offer?->slug ?: 'request')) ?: 'request';
        $ext = pathinfo((string) $request->artifact_path, PATHINFO_EXTENSION) ?: 'pdf';

        return AppStorage::download(
            $request->artifact_path,
            $offerSlug.'-'.$matric.'.'.$ext,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOffersForStaff(?bool $activeOnly = null): array
    {
        $query = PublicPayOffer::query()->with('feeItem')->orderBy('display_order')->orderBy('name');
        if ($activeOnly === true) {
            $query->where('is_active', true);
        } elseif ($activeOnly === false) {
            $query->where('is_active', false);
        }

        return $query->get()->map(fn (PublicPayOffer $offer) => $this->offerPayload($offer))->all();
    }

    public function createOffer(array $data): PublicPayOffer
    {
        $fee = $this->resolveAssignableFee((int) $data['fee_item_id']);
        $name = trim((string) $data['name']);
        $slug = $this->uniqueSlug($data['slug'] ?? $name);

        $offer = PublicPayOffer::query()->create([
            'name' => $name,
            'slug' => $slug,
            'description' => isset($data['description']) ? trim((string) $data['description']) ?: null : null,
            'instructions' => isset($data['instructions']) ? trim((string) $data['instructions']) ?: null : null,
            'fee_item_id' => $fee->id,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'display_order' => (int) ($data['display_order'] ?? 0),
        ]);

        $this->audit->record(
            'public_pay.offer_created',
            'Public pay offer created: '.$offer->name,
            'public_pay',
            'public_pay_offer',
            $offer->id,
        );

        return $offer->load('feeItem');
    }

    public function updateOffer(PublicPayOffer $offer, array $data): PublicPayOffer
    {
        if (array_key_exists('fee_item_id', $data)) {
            $fee = $this->resolveAssignableFee((int) $data['fee_item_id']);
            $offer->fee_item_id = $fee->id;
        }
        if (array_key_exists('name', $data)) {
            $offer->name = trim((string) $data['name']);
        }
        if (array_key_exists('slug', $data) && filled($data['slug'])) {
            $offer->slug = $this->uniqueSlug((string) $data['slug'], $offer->id);
        } elseif (array_key_exists('name', $data) && ! array_key_exists('slug', $data)) {
            // Keep existing slug when only renaming.
        }
        if (array_key_exists('description', $data)) {
            $offer->description = trim((string) $data['description']) ?: null;
        }
        if (array_key_exists('instructions', $data)) {
            $offer->instructions = trim((string) $data['instructions']) ?: null;
        }
        if (array_key_exists('is_active', $data)) {
            $offer->is_active = (bool) $data['is_active'];
        }
        if (array_key_exists('display_order', $data)) {
            $offer->display_order = (int) $data['display_order'];
        }
        $offer->save();

        $this->audit->record(
            'public_pay.offer_updated',
            'Public pay offer updated: '.$offer->name,
            'public_pay',
            'public_pay_offer',
            $offer->id,
        );

        return $offer->fresh('feeItem');
    }

    public function deleteOffer(PublicPayOffer $offer): void
    {
        $open = PublicPayRequest::query()
            ->where('offer_id', $offer->id)
            ->whereIn('status', ['awaiting_payment', 'paid', 'processing'])
            ->exists();
        if ($open) {
            throw new RuntimeException('Cannot delete an offer with open requests. Deactivate it instead.');
        }

        $offer->delete();
        $this->audit->record(
            'public_pay.offer_deleted',
            'Public pay offer deleted: '.$offer->name,
            'public_pay',
            'public_pay_offer',
            $offer->id,
        );
    }

    public function staffPayload(PublicPayRequest $request): array
    {
        $request->loadMissing(['student.program', 'offer.feeItem', 'invoice', 'processor']);
        $student = $request->student;
        $offer = $request->offer;

        return [
            'id' => $request->id,
            'public_token' => $request->public_token,
            'status' => $request->status,
            'delivery_mode' => $request->delivery_mode,
            'purpose' => $request->purpose,
            'contact_email' => $request->contact_email,
            'rejected_reason' => $request->rejected_reason,
            'paid_at' => $request->paid_at?->toIso8601String(),
            'ready_at' => $request->ready_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
            'has_artifact' => filled($request->artifact_path),
            'downloadable' => $request->isDownloadable(),
            'invoice' => $request->invoice?->only(['id', 'number', 'amount', 'balance', 'status', 'category']),
            'processor' => $request->processor?->only(['id', 'name', 'email']),
            'offer' => $offer ? $this->offerPayload($offer) : null,
            'student' => $student ? [
                'id' => $student->id,
                'name' => trim(($student->first_name ?? '').' '.($student->last_name ?? '')),
                'matric_number' => $student->matric_number,
                'programme' => $student->program?->name,
                'status' => $student->status,
            ] : null,
        ];
    }

    public function publicPayload(PublicPayRequest $request): array
    {
        $request->loadMissing(['invoice', 'offer.feeItem']);
        $offer = $request->offer;

        return [
            'token' => $request->public_token,
            'status' => $request->status,
            'purpose' => $request->purpose,
            'delivery_mode' => $request->delivery_mode,
            'downloadable' => $request->isDownloadable(),
            'paid_at' => $request->paid_at?->toIso8601String(),
            'ready_at' => $request->ready_at?->toIso8601String(),
            'amount' => $request->invoice ? (float) $request->invoice->amount : null,
            'invoice_number' => $request->invoice?->number,
            'invoice_status' => $request->invoice?->status,
            'offer' => $offer ? [
                'id' => $offer->id,
                'name' => $offer->name,
                'slug' => $offer->slug,
                'description' => $offer->description,
                'instructions' => $offer->instructions,
            ] : null,
        ];
    }

    public function offerPayload(PublicPayOffer $offer): array
    {
        $offer->loadMissing('feeItem');
        $fee = $offer->feeItem;

        return [
            'id' => $offer->id,
            'name' => $offer->name,
            'slug' => $offer->slug,
            'description' => $offer->description,
            'instructions' => $offer->instructions,
            'is_active' => (bool) $offer->is_active,
            'display_order' => (int) $offer->display_order,
            'publicly_available' => $offer->isPubliclyAvailable(),
            'fee_item_id' => $offer->fee_item_id,
            'fee' => $fee ? [
                'id' => $fee->id,
                'name' => $fee->name,
                'category' => $fee->category,
                'amount' => (float) $fee->amount,
                'is_active' => (bool) $fee->is_active,
            ] : null,
        ];
    }

    public function findByToken(string $token): PublicPayRequest
    {
        return PublicPayRequest::query()
            ->where('public_token', $token)
            ->firstOrFail();
    }

    public static function invoiceIsPublicPay(Invoice $invoice): bool
    {
        return PublicPayRequest::query()
            ->where('invoice_id', $invoice->id)
            ->exists();
    }

    private function assertFeatureAvailable(): void
    {
        $offers = $this->publicOffers();
        if (! PublicPaySettings::enabled() || $offers === []) {
            throw new RuntimeException($this->unavailableReason($offers !== []) ?: 'Public request & pay is not available.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publicOffers(): array
    {
        return PublicPayOffer::query()
            ->with('feeItem')
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (PublicPayOffer $offer) => $offer->isPubliclyAvailable())
            ->map(fn (PublicPayOffer $offer) => $this->offerPayload($offer))
            ->values()
            ->all();
    }

    private function unavailableReason(bool $hasOffers): ?string
    {
        if (! PublicPaySettings::enabled()) {
            return 'Public request & pay is turned off in Application settings.';
        }
        if (! $hasOffers) {
            return 'No services are configured for public request & pay yet.';
        }

        return null;
    }

    private function findStudentForRequest(string $nin): Student
    {
        $normalized = NinCipher::normalize($nin);
        if (strlen($normalized) !== 11) {
            throw new RuntimeException('Enter a valid 11-digit NIN.');
        }

        $hash = NinCipher::hash($normalized);
        $student = Student::query()
            ->with('user')
            ->where('nin_hash', $hash)
            ->first();

        if (! $student) {
            $verification = NinVerification::query()
                ->where('nin_hash', $hash)
                ->orderByDesc('id')
                ->first();
            if ($verification?->user_id) {
                $student = Student::query()
                    ->with('user')
                    ->where('user_id', $verification->user_id)
                    ->first();
            }
        }

        if (! $student) {
            throw new RuntimeException('We could not match that NIN to a student record. Check the number or contact the office.');
        }

        return $student;
    }

    private function resolveAssignableFee(int $feeItemId): FeeItem
    {
        $fee = FeeItem::query()->findOrFail($feeItemId);
        if (! $fee->is_active || (float) $fee->amount <= 0) {
            throw new RuntimeException('Choose an active fee item with an amount greater than zero.');
        }

        return $fee;
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug(trim($source)) ?: 'service';
        $slug = $base;
        $i = 2;
        while (
            PublicPayOffer::query()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
