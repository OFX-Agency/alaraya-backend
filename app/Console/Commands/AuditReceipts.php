<?php

namespace App\Console\Commands;

use App\Models\TransportReceipt;
use App\Services\ReceiptAccounting as Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditReceipts extends Command
{
    protected $signature = 'receipts:audit';

    protected $description = 'Read-only receipt reconciliation report (JSON); run after additive migrations.';

    public function handle(): int
    {
        $rows = [];
        TransportReceipt::with('policy', 'shipOrder.treasuries')->chunkById(200, function ($receipts) use (&$rows) {
            foreach ($receipts as $receipt) {
                $total = array_sum(array_map(fn ($v) => Money::cents($v), $receipt->only(TransportReceipt::EXPENSE_FIELDS)));
                $rows[] = ['id' => $receipt->id, 'ship_order_id' => $receipt->ship_order_id,
                    'policy_id' => $receipt->policy_id, 'total' => Money::decimal($total),
                    'original_treasury_id' => $receipt->treasury_id,
                    'current_order_treasury_ids' => $receipt->shipOrder?->treasuries->pluck('id'),
                    'policy_order_mismatch' => $receipt->policy && $receipt->policy->ship_order_data_id != $receipt->ship_order_id,
                    'deduction_ids' => DB::table('treasury_deductions')->where('transport_receipt_id', $receipt->id)->pluck('id'),
                    'cancelled_at' => $receipt->cancelled_at, 'migrated_at' => $receipt->migrated_to_order_receipt_at];
            }
        });
        $this->line(json_encode(['transport_receipts' => $rows,
            'duplicate_policy_links' => DB::table('transport_receipts')->select('policy_id', DB::raw('COUNT(*) AS receipt_count'))
                ->whereNotNull('policy_id')->groupBy('policy_id')->havingRaw('COUNT(*) > 1')->get(),
            'unlinked_receipt_deductions' => DB::table('treasury_deductions')->whereNull('transport_receipt_id')->whereNull('order_receipt_id')
                ->where('type', 'like', '%receipt%')->get(['id', 'treasury_id', 'type', 'amount', 'reason', 'created_at']),
            'treasury_balances' => DB::table('treasuries')->get(['id', 'balance']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
