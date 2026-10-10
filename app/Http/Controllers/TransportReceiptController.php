<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use App\Models\ShipOrderData;
use App\Models\TransportReceipt;
use App\Services\ReceiptAccounting as Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransportReceiptController extends Controller
{
    public function index(Request $request)
    {
        $query = TransportReceipt::with('shipOrder', 'policy')->whereNull('migrated_to_order_receipt_at');
        if (! $request->user()->hasRole('admin')) {
            $ids = $request->user()->treasuries()->pluck('treasuries.id');
            $query->where(function ($q) use ($ids) {
                $q->whereIn('treasury_id', $ids)->orWhere(function ($legacy) use ($ids) {
                    $legacy->whereNull('treasury_id')->whereHas('shipOrder.treasuries', fn ($t) => $t->whereIn('treasuries.id', $ids));
                });
            });
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    private function authorizeReceipt(Request $request, TransportReceipt $receipt): void
    {
        if ($receipt->treasury_id) {
            Money::authorizeTreasury($request->user(), $receipt->treasury_id);
        } else {
            abort_unless($request->user()->hasRole('admin') || $receipt->shipOrder->treasuries()
                ->whereIn('treasuries.id', $request->user()->treasuries()->pluck('treasuries.id'))->exists(), 403);
        }
    }

    public function show(Request $request, $id)
    {
        $receipt = TransportReceipt::findOrFail($id);
        $this->authorizeReceipt($request, $receipt);

        return response()->json(['success' => true, 'data' => $receipt->load('shipOrder', 'policy')]);
    }

    private function rules(): array
    {
        return array_fill_keys(TransportReceipt::EXPENSE_FIELDS, ['nullable', 'numeric', 'min:0',
            'max:999999.99', 'regex:/^[0-9]{1,6}(\.[0-9]{1,2})?$/']);
    }

    private function total(array $data): int
    {
        return array_sum(array_map(fn ($value) => Money::cents($value), $data));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules() + [
            'ship_order_id' => 'required|integer|exists:ship_order_data,id',
            'policy_id' => ['required', 'integer', Rule::exists('policies', 'id')->where('ship_order_data_id', $request->ship_order_id)],
            'treasury_id' => 'sometimes|required|integer|exists:treasuries,id',
        ]);

        return DB::transaction(function () use ($request, $data) {
            $order = ShipOrderData::lockForUpdate()->findOrFail($data['ship_order_id']);
            $policy = Policy::lockForUpdate()->findOrFail($data['policy_id']);
            abort_if($policy->ship_order_data_id != $order->id, 409, 'Policy order changed.');
            abort_if($policy->settled || $policy->transportReceipts()->exists(), 409, 'Policy is settled or already has a receipt.');
            $treasuryId = $data['treasury_id'] ?? $order->treasuries()->value('treasuries.id');
            if (! $treasuryId || ! $order->treasuries()->where('treasuries.id', $treasuryId)->exists()) {
                throw ValidationException::withMessages(['treasury_id' => 'Choose a treasury associated with this order.']);
            }
            Money::authorizeTreasury($request->user(), $treasuryId);
            $data['treasury_id'] = $treasuryId;
            $total = $this->total(array_intersect_key($data, $this->rules()));
            if ($total <= 0) {
                throw ValidationException::withMessages(['amount' => 'Receipt total must be positive.']);
            }
            $receipt = TransportReceipt::create($data);
            Money::post($treasuryId, $total, $request->user(), ['transport_receipt_id' => $receipt->id, 'type' => 'transport_receipt'], 'مصاريف إيصال النقل #'.$receipt->id.' للأوردر #'.$order->order_number);

            return response()->json(['success' => true, 'data' => $receipt->load('shipOrder', 'policy')], 201);
        }, 3);
    }

    private function editable(TransportReceipt $receipt): void
    {
        abort_if($receipt->cancelled_at || $receipt->migrated_to_order_receipt_at, 409, 'Receipt is cancelled or migrated.');
        abort_if(! $receipt->treasury_id, 409, 'Legacy receipt requires treasury reconciliation before editing or cancellation.');
        abort_if(! $receipt->policy || $receipt->policy->settled, 409, 'Policy is missing or settled.');
        abort_if($receipt->policy->ship_order_data_id != $receipt->ship_order_id, 409, 'Policy/order mismatch requires reconciliation.');
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate($this->rules() + ['ship_order_id' => 'prohibited', 'policy_id' => 'prohibited', 'treasury_id' => 'prohibited']);
        $original = TransportReceipt::findOrFail($id);
        $this->authorizeReceipt($request, $original);

        return DB::transaction(function () use ($request, $id, $data, $original) {
            ShipOrderData::lockForUpdate()->findOrFail($original->ship_order_id);
            if ($original->policy_id) {
                Policy::lockForUpdate()->findOrFail($original->policy_id);
            }
            $receipt = TransportReceipt::lockForUpdate()->findOrFail($id);
            $this->editable($receipt);
            $old = $this->total($receipt->only(TransportReceipt::EXPENSE_FIELDS));
            $new = $this->total(array_merge($receipt->only(TransportReceipt::EXPENSE_FIELDS), $data));
            if ($new <= 0) {
                throw ValidationException::withMessages(['amount' => 'Receipt total must be positive.']);
            }
            Money::post($receipt->treasury_id, $new - $old, $request->user(), ['transport_receipt_id' => $receipt->id, 'type' => 'transport_receipt_adjustment'], 'تعديل إيصال النقل #'.$receipt->id);
            $receipt->update($data);

            return response()->json(['success' => true, 'data' => $receipt->load('shipOrder', 'policy')]);
        }, 3);
    }

    // Backwards-compatible DELETE route now cancels and refunds, retaining the record.
    public function destroy(Request $request, $id)
    {
        abort_unless($request->user()->hasRole('admin'), 403);
        $data = $request->validate(['reason' => 'required|string|max:2000']);
        $original = TransportReceipt::findOrFail($id);

        return DB::transaction(function () use ($request, $id, $data, $original) {
            ShipOrderData::lockForUpdate()->findOrFail($original->ship_order_id);
            if ($original->policy_id) {
                Policy::lockForUpdate()->findOrFail($original->policy_id);
            }
            $receipt = TransportReceipt::lockForUpdate()->findOrFail($id);
            if (! $receipt->cancelled_at) {
                $this->editable($receipt);
                Money::post($receipt->treasury_id, -$this->total($receipt->only(TransportReceipt::EXPENSE_FIELDS)), $request->user(),
                    ['transport_receipt_id' => $receipt->id, 'type' => 'transport_receipt_reversal'], 'إلغاء إيصال النقل #'.$receipt->id);
                $receipt->update(['cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'cancellation_reason' => $data['reason']]);
            }

            return response()->json(['success' => true, 'message' => 'Transport receipt cancelled', 'data' => $receipt]);
        }, 3);
    }
}
