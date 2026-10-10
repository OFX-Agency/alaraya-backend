<?php

namespace App\Http\Controllers;

use App\Models\OrderReceipt;
use App\Models\OrderReceiptItem;
use App\Models\ShipOrderData;
use App\Services\ReceiptAccounting as Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderReceiptController extends Controller
{
    public function categories()
    {
        return response()->json(['success' => true, 'data' => collect(OrderReceiptItem::CATEGORIES)
            ->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values()]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['ship_order_id' => 'sometimes|integer', 'client_id' => 'sometimes|integer',
            'status' => 'sometimes|in:posted,cancelled', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $query = OrderReceipt::with('items', 'client', 'treasury');
        if (! $request->user()->hasRole('admin')) {
            $query->whereIn('treasury_id', $request->user()->treasuries()->pluck('treasuries.id'));
        }
        foreach (['ship_order_id', 'client_id', 'status'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        return response()->json(['success' => true, 'data' => $query->latest('id')->paginate($filters['per_page'] ?? 20)]);
    }

    public function show(Request $request, $id)
    {
        $receipt = OrderReceipt::findOrFail($id);
        Money::authorizeTreasury($request->user(), $receipt->treasury_id);

        return response()->json(['success' => true, 'data' => $receipt->load('items', 'client', 'treasury')]);
    }

    private function validateReceipt(Request $request, bool $creating): array
    {
        $rules = [
            'receipt_date' => ($creating ? 'required' : 'sometimes|required').'|date_format:Y-m-d',
            'notes' => 'nullable|string|max:2000',
            'items' => ($creating ? 'required' : 'sometimes|required').'|array|min:1|max:100',
            'items.*' => 'required|array:category,description,amount',
            'items.*.category' => ['required', Rule::in(array_keys(OrderReceiptItem::CATEGORIES))],
            'items.*.description' => 'nullable|string|max:500',
            'items.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99', 'regex:/^[0-9]{1,13}(\.[0-9]{1,2})?$/'],
            'policy_id' => 'prohibited', 'total' => 'prohibited', 'status' => 'prohibited',
        ];
        if ($creating) {
            $rules += ['ship_order_id' => 'required|integer|exists:ship_order_data,id',
                'client_id' => 'required|integer|exists:clients,id',
                'treasury_id' => 'required|integer|exists:treasuries,id', 'idempotency_key' => 'required|uuid'];
        } else {
            foreach (['ship_order_id', 'client_id', 'treasury_id', 'idempotency_key'] as $key) {
                $rules[$key] = 'prohibited';
            }
        }
        $data = $request->validate($rules);
        foreach ($data['items'] ?? [] as $index => $item) {
            if ($item['category'] === 'other' && empty(trim($item['description'] ?? ''))) {
                throw ValidationException::withMessages(["items.$index.description" => 'Describe the other expense.']);
            }
        }
        if (isset($data['items'])) {
            $data['items'] = array_map(fn ($item) => ['category' => $item['category'],
                'description' => $item['description'] ?? null, 'amount' => Money::decimal(Money::cents($item['amount']))], $data['items']);
        }

        return $data;
    }

    private function total(array $items): int
    {
        $total = array_sum(array_map(fn ($item) => Money::cents($item['amount']), $items));
        if ($total > 999999999999999) {
            throw ValidationException::withMessages(['items' => 'Receipt total exceeds the supported amount.']);
        }

        return $total;
    }

    public function store(Request $request)
    {
        $data = $this->validateReceipt($request, true);
        Money::authorizeTreasury($request->user(), $data['treasury_id']);
        // Canonical payload avoids JSON object key order affecting retries.
        $hash = hash('sha256', json_encode([(int) $data['ship_order_id'], (int) $data['client_id'], (int) $data['treasury_id'],
            $data['receipt_date'], $data['notes'] ?? null, $data['items']]));
        try {
            return DB::transaction(function () use ($data, $hash, $request) {
                $order = ShipOrderData::lockForUpdate()->findOrFail($data['ship_order_id']);
                $existing = OrderReceipt::where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    abort_unless($existing->created_by === $request->user()->id && hash_equals($existing->request_hash, $hash), 409, 'Idempotency key already used for a different request.');

                    return response()->json(['success' => true, 'data' => $existing->load('items')]);
                }
                if (! $order->shipLineClients()->where('client_id', $data['client_id'])->exists()) {
                    throw ValidationException::withMessages(['client_id' => 'Choose a client associated with this order.']);
                }
                if (! $order->treasuries()->where('treasuries.id', $data['treasury_id'])->exists()) {
                    throw ValidationException::withMessages(['treasury_id' => 'Choose a treasury associated with this order.']);
                }
                $total = $this->total($data['items']);
                $items = $data['items'];
                unset($data['items']);
                $receipt = OrderReceipt::create($data + ['receipt_number' => (string) Str::uuid(), 'request_hash' => $hash,
                    'total' => Money::decimal($total), 'created_by' => $request->user()->id]);
                $receipt->items()->createMany($items);
                Money::post($receipt->treasury_id, $total, $request->user(), ['order_receipt_id' => $receipt->id, 'type' => 'order_receipt'], 'مصاريف إيصال أوردر #'.$receipt->receipt_number);

                return response()->json(['success' => true, 'data' => $receipt->load('items', 'client', 'treasury')], 201);
            }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            abort(409, 'Idempotency key is already used. Reload the existing receipt before retrying.');
        }
    }

    public function update(Request $request, $id)
    {
        $data = $this->validateReceipt($request, false);
        $original = OrderReceipt::findOrFail($id);
        Money::authorizeTreasury($request->user(), $original->treasury_id);

        return DB::transaction(function () use ($data, $id, $request, $original) {
            ShipOrderData::lockForUpdate()->findOrFail($original->ship_order_id);
            $receipt = OrderReceipt::lockForUpdate()->findOrFail($id);
            abort_if($receipt->status !== 'posted', 409, 'Cancelled receipts cannot be edited.');
            if (isset($data['items'])) {
                $total = $this->total($data['items']);
                Money::post($receipt->treasury_id, $total - Money::cents($receipt->total), $request->user(),
                    ['order_receipt_id' => $receipt->id, 'type' => 'order_receipt_adjustment'], 'تعديل إيصال أوردر #'.$receipt->receipt_number);
                // Model deletes preserve item activity history.
                foreach ($receipt->items as $item) {
                    $item->delete();
                }
                $receipt->items()->createMany($data['items']);
                unset($data['items']);
                $data['total'] = Money::decimal($total);
            }
            $receipt->update($data);

            return response()->json(['success' => true, 'data' => $receipt->fresh()->load('items', 'client', 'treasury')]);
        }, 3);
    }

    public function cancel(Request $request, $id)
    {
        abort_unless($request->user()->hasRole('admin'), 403);
        $data = $request->validate(['reason' => 'required|string|max:2000']);
        $original = OrderReceipt::findOrFail($id);

        return DB::transaction(function () use ($id, $request, $data, $original) {
            ShipOrderData::lockForUpdate()->findOrFail($original->ship_order_id);
            $receipt = OrderReceipt::lockForUpdate()->findOrFail($id);
            if ($receipt->status === 'posted') {
                Money::post($receipt->treasury_id, -Money::cents($receipt->total), $request->user(),
                    ['order_receipt_id' => $receipt->id, 'type' => 'order_receipt_reversal'], 'إلغاء إيصال أوردر #'.$receipt->receipt_number);
                $receipt->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'cancellation_reason' => $data['reason']]);
            }

            return response()->json(['success' => true, 'data' => $receipt->load('items')]);
        }, 3);
    }
}
