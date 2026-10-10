<?php

namespace App\Console\Commands;

use App\Models\OrderReceipt;
use App\Models\OrderReceiptItem;
use App\Models\ShipOrderData;
use App\Models\TransportReceipt;
use App\Models\TreasuryDeduction;
use App\Services\ReceiptAccounting as Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconcileReceipts extends Command
{
    protected $signature = 'receipts:reconcile {mapping : Reviewed JSON mapping file} {--apply : Apply metadata only; default is dry-run}';

    protected $description = 'Link reviewed legacy deductions and optionally classify null-policy receipts; never changes treasury balances.';

    public function handle(): int
    {
        try {
            $mapping = json_decode(file_get_contents($this->argument('mapping')), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($mapping) || ! array_is_list($mapping)) {
                throw new \RuntimeException('Mapping must be a JSON list.');
            }
            foreach ($mapping as $row) {
                DB::transaction(function () use ($row) {
                    $original = TransportReceipt::findOrFail($row['receipt_id']);
                    $order = ShipOrderData::lockForUpdate()->findOrFail($original->ship_order_id);
                    $receipt = TransportReceipt::lockForUpdate()->findOrFail($original->id);
                    if ($receipt->cancelled_at) {
                        throw new \RuntimeException('Cannot reconcile a cancelled receipt.');
                    }
                    $ids = $row['deduction_ids'] ?? [];
                    if (! $ids || count(array_unique($ids)) !== count($ids)) {
                        throw new \RuntimeException('Provide unique, reviewed deduction_ids.');
                    }
                    $deductions = TreasuryDeduction::whereIn('id', $ids)->lockForUpdate()->get();
                    if ($deductions->count() !== count($ids)) {
                        throw new \RuntimeException('Missing deduction.');
                    }
                    if ($deductions->pluck('treasury_id')->unique()->count() !== 1) {
                        throw new \RuntimeException('Mixed treasuries require manual reconciliation.');
                    }
                    $treasuryId = $deductions->first()->treasury_id;
                    if ($treasuryId != $row['treasury_id']) {
                        throw new \RuntimeException('Treasury does not match reviewed mapping.');
                    }
                    $total = array_sum(array_map(fn ($v) => Money::cents($v), $receipt->only(TransportReceipt::EXPENSE_FIELDS)));
                    $deductionTotal = $deductions->sum(fn ($d) => Money::cents($d->amount));
                    if ($total !== $deductionTotal) {
                        throw new \RuntimeException('Deduction total does not match receipt; reconcile historical discrepancy first.');
                    }
                    foreach ($deductions as $deduction) {
                        if (! str_starts_with($deduction->type ?? '', 'transport_receipt')) {
                            throw new \RuntimeException('Unexpected deduction type.');
                        }
                        if ($deduction->transport_receipt_id && $deduction->transport_receipt_id != $receipt->id) {
                            throw new \RuntimeException('Deduction already belongs to another receipt.');
                        }
                    }
                    $converted = OrderReceipt::where('legacy_transport_receipt_id', $receipt->id)->first();
                    if ($receipt->migrated_to_order_receipt_at) {
                        if (! $converted || $converted->client_id != ($row['client_id'] ?? null) || $converted->treasury_id != $treasuryId) {
                            throw new \RuntimeException('Mapping conflicts with previous conversion.');
                        }
                        $this->line('Already converted receipt '.$receipt->id);

                        return;
                    }
                    foreach ($deductions as $deduction) {
                        if ($deduction->order_receipt_id) {
                            throw new \RuntimeException('Deduction already belongs to an order receipt.');
                        }
                    }
                    if ($receipt->treasury_id && $receipt->treasury_id != $treasuryId) {
                        throw new \RuntimeException('Original treasury conflicts with mapping.');
                    }
                    if ($receipt->policy_id) {
                        if (! $receipt->policy || $receipt->policy->ship_order_data_id != $receipt->ship_order_id) {
                            throw new \RuntimeException('Policy/order mismatch.');
                        }
                        if ($this->option('apply')) {
                            $receipt->update(['treasury_id' => $treasuryId]);
                            foreach ($deductions as $deduction) {
                                $deduction->update(['transport_receipt_id' => $receipt->id]);
                            }
                        }
                    } else {
                        if (! $order->shipLineClients()->where('client_id', $row['client_id'] ?? null)->exists()) {
                            throw new \RuntimeException('Choose an associated client for conversion.');
                        }
                        if ($total <= 0) {
                            throw new \RuntimeException('Cannot convert a nonpositive receipt.');
                        }
                        foreach (TransportReceipt::EXPENSE_FIELDS as $field) {
                            if (Money::cents($receipt->$field) < 0) {
                                throw new \RuntimeException('Negative legacy item requires manual reconciliation.');
                            }
                        }
                        if ($this->option('apply')) {
                            $converted = OrderReceipt::create(['ship_order_id' => $order->id, 'client_id' => $row['client_id'],
                                'treasury_id' => $treasuryId, 'created_by' => $deductions->first()->user_id,
                                'receipt_number' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
                                'request_hash' => hash('sha256', 'legacy:'.$receipt->id), 'receipt_date' => $receipt->created_at->format('Y-m-d'),
                                'total' => Money::decimal($total), 'legacy_transport_receipt_id' => $receipt->id,
                                'notes' => 'Converted legacy transport receipt #'.$receipt->id, 'created_at' => $receipt->created_at]);
                            foreach (TransportReceipt::EXPENSE_FIELDS as $field) {
                                $amount = Money::cents($receipt->$field);
                                if ($amount < 0) {
                                    throw new \RuntimeException('Negative legacy item requires manual reconciliation.');
                                }
                                if ($amount > 0) {
                                    $converted->items()->create(['category' => isset(OrderReceiptItem::CATEGORIES[$field]) ? $field : 'other',
                                        'description' => 'Legacy item: '.$field, 'amount' => Money::decimal($amount)]);
                                }
                            }
                            foreach ($deductions as $deduction) {
                                $deduction->update(['order_receipt_id' => $converted->id, 'transport_receipt_id' => null]);
                            }
                            $receipt->update(['treasury_id' => $treasuryId, 'migrated_to_order_receipt_at' => now()]);
                        }
                    }
                    $this->line(($this->option('apply') ? 'Reconciled ' : 'Dry-run validated ').$receipt->id);
                }, 3);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
