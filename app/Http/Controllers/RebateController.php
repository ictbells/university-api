<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceRebate;
use App\Models\RebateType;
use App\Services\AuditWriter;
use App\Services\RebateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class RebateController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(
        private RebateService $rebates,
        private AuditWriter $audit,
    ) {}

    public function types(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $query = RebateType::query()->orderBy('name');
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return ['data' => $query->get()->map(fn (RebateType $type) => $this->serializeType($type))->values()];
    }

    public function storeType(Request $request)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $this->validatedType($request);

        return $this->officeGate('finance.store_rebate_type', null, $data, 'Create rebate type '.$data['name'], function () use ($data) {
            $type = RebateType::query()->create($data);
            $this->audit->record('rebate_type.created', 'Rebate type '.$type->name.' created', 'fees', 'rebate_type', $type->id, null, $type);

            return $this->serializeType($type);
        });
    }

    public function updateType(Request $request, RebateType $rebateType)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $this->validatedType($request, $rebateType);

        return $this->officeGate(
            'finance.update_rebate_type',
            $rebateType,
            ['rebate_type_id' => $rebateType->id, ...$data],
            'Update rebate type '.$rebateType->name,
            function () use ($rebateType, $data) {
                $before = $rebateType->toArray();
                $rebateType->fill($data)->save();
                $this->audit->record(
                    'rebate_type.updated',
                    'Rebate type '.$rebateType->name.' updated',
                    'fees',
                    'rebate_type',
                    $rebateType->id,
                    $before,
                    $rebateType->fresh(),
                );

                return $this->serializeType($rebateType->fresh());
            },
        );
    }

    public function destroyType(Request $request, RebateType $rebateType)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        if ($rebateType->rebates()->exists()) {
            return response()->json([
                'message' => 'This rebate type has been used on invoices. Deactivate it instead of deleting.',
            ], 422);
        }

        return $this->officeGate(
            'finance.destroy_rebate_type',
            $rebateType,
            ['rebate_type_id' => $rebateType->id],
            'Delete rebate type '.$rebateType->name,
            function () use ($rebateType) {
                $before = $rebateType->toArray();
                $rebateType->delete();
                $this->audit->record('rebate_type.deleted', 'Rebate type '.$rebateType->name.' deleted', 'fees', 'rebate_type', $rebateType->id, $before, null);

                return response()->noContent();
            },
        );
    }

    public function apply(Request $request, Invoice $invoice)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $request->validate([
            'rebate_type_id' => ['required', 'integer', 'exists:rebate_types,id'],
            'kind' => ['nullable', Rule::in(['percent', 'amount'])],
            'value' => ['nullable', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $type = RebateType::query()->findOrFail($data['rebate_type_id']);
        $kind = $data['kind'] ?? $type->kind;
        $value = isset($data['value']) ? (float) $data['value'] : (float) $type->default_value;

        if ($kind === 'percent' && $value > 100) {
            return response()->json(['message' => 'A percentage rebate cannot exceed 100%.'], 422);
        }

        return $this->officeGate(
            'finance.apply_rebate',
            $invoice,
            ['invoice_id' => $invoice->id, ...$data],
            'Apply rebate to invoice '.$invoice->number,
            function () use ($request, $invoice, $type, $kind, $value, $data) {
                try {
                    $rebate = $this->rebates->apply(
                        $invoice,
                        $type,
                        $kind,
                        $value,
                        trim($data['reason']),
                        $request->user(),
                    );
                } catch (RuntimeException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }

                $invoice = $invoice->fresh(['items', 'rebates.rebateType', 'rebates.appliedBy']);
                $this->audit->record(
                    'invoice.rebate.applied',
                    'Rebate '.$type->name.' applied to invoice '.$invoice->number,
                    'fees',
                    'invoice',
                    $invoice->id,
                    null,
                    [
                        'rebate_id' => $rebate->id,
                        'amount' => (float) $rebate->amount,
                        'balance' => (float) $invoice->balance,
                    ],
                    trim($data['reason']),
                );

                return [
                    'rebate' => $this->serializeRebate($rebate),
                    'invoice' => $invoice,
                ];
            },
        );
    }

    public function reverse(Request $request, Invoice $invoice, InvoiceRebate $rebate)
    {
        abort_unless($request->user()->hasPermission('finance.invoices.manage'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return $this->officeGate(
            'finance.reverse_rebate',
            $invoice,
            ['invoice_id' => $invoice->id, 'invoice_rebate_id' => $rebate->id, ...$data],
            'Reverse rebate on invoice '.$invoice->number,
            function () use ($request, $invoice, $rebate, $data) {
                try {
                    $rebate = $this->rebates->reverse($invoice, $rebate, trim($data['reason']), $request->user());
                } catch (RuntimeException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }

                $invoice = $invoice->fresh(['items', 'rebates.rebateType', 'rebates.appliedBy']);
                $this->audit->record(
                    'invoice.rebate.reversed',
                    'Rebate reversed on invoice '.$invoice->number,
                    'fees',
                    'invoice',
                    $invoice->id,
                    null,
                    [
                        'rebate_id' => $rebate->id,
                        'amount' => (float) $rebate->amount,
                        'balance' => (float) $invoice->balance,
                    ],
                    trim($data['reason']),
                );

                return [
                    'rebate' => $this->serializeRebate($rebate),
                    'invoice' => $invoice,
                ];
            },
        );
    }

    private function validatedType(Request $request, ?RebateType $existing = null): array
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('rebate_types', 'name')->whereNull('deleted_at')->ignore($existing?->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'kind' => ['required', Rule::in(['percent', 'amount'])],
            'default_value' => ['required', 'numeric', 'min:0.01'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($data['kind'] === 'percent' && (float) $data['default_value'] > 100) {
            abort(response()->json(['message' => 'A percentage rebate cannot exceed 100%.'], 422));
        }

        $data['description'] = isset($data['description']) ? (trim((string) $data['description']) ?: null) : null;
        $data['name'] = trim($data['name']);
        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : ($existing?->is_active ?? true);

        return $data;
    }

    private function serializeType(RebateType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'description' => $type->description,
            'kind' => $type->kind,
            'default_value' => (float) $type->default_value,
            'is_active' => (bool) $type->is_active,
            'created_at' => $type->created_at,
        ];
    }

    private function serializeRebate(InvoiceRebate $rebate): array
    {
        return [
            'id' => $rebate->id,
            'invoice_id' => $rebate->invoice_id,
            'rebate_type_id' => $rebate->rebate_type_id,
            'kind' => $rebate->kind,
            'value' => (float) $rebate->value,
            'amount' => (float) $rebate->amount,
            'reason' => $rebate->reason,
            'applied_by' => $rebate->applied_by,
            'applied_by_name' => $rebate->appliedBy?->name,
            'type_name' => $rebate->rebateType?->name,
            'reversed_at' => $rebate->reversed_at,
            'reverse_reason' => $rebate->reverse_reason,
            'created_at' => $rebate->created_at,
        ];
    }
}
