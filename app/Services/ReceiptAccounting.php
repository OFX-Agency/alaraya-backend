<?php

namespace App\Services;

use App\Models\Treasury;
use App\Models\TreasuryDeduction;
use Illuminate\Validation\ValidationException;

class ReceiptAccounting
{
    public static function cents($amount): int
    {
        $value = (string) ($amount ?? '0');
        if (! preg_match('/^(-?)([0-9]{1,13})(?:\.([0-9]{1,2}))?$/', $value, $parts)) {
            throw ValidationException::withMessages(['amount' => 'Use a decimal amount with at most two decimal places.']);
        }
        $cents = ((int) $parts[2] * 100) + (int) str_pad($parts[3] ?? '', 2, '0');

        return ($parts[1] === '-' ? -1 : 1) * $cents;
    }

    public static function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function authorizeTreasury($user, int $treasuryId): void
    {
        abort_unless($user->hasRole('admin') || $user->treasuries()->where('treasuries.id', $treasuryId)->exists(), 403);
    }

    // Caller owns the transaction. All receipt flows lock order, then receipt/policy, then treasury.
    public static function post(int $treasuryId, int $cents, $user, array $source, string $reason): void
    {
        self::authorizeTreasury($user, $treasuryId);
        if ($cents === 0) {
            return;
        }
        $treasury = Treasury::lockForUpdate()->findOrFail($treasuryId);
        $balance = self::cents($treasury->balance);
        if ($cents > $balance) {
            throw ValidationException::withMessages(['treasury_id' => 'الرصيد غير كافٍ في الخزينة']);
        }
        if (abs($balance - $cents) > 999999999999999) {
            throw ValidationException::withMessages(['amount' => 'Treasury balance exceeds the supported amount.']);
        }
        $treasury->update(['balance' => self::decimal($balance - $cents)]);
        TreasuryDeduction::create(array_merge($source, [
            'treasury_id' => $treasuryId, 'user_id' => $user->id,
            'amount' => self::decimal($cents), 'reason' => $reason,
        ]));
    }
}
