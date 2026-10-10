<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Treasury;
use App\Models\TreasuryTransactions;
use App\Models\TreasuryDeduction;
use App\Models\TreasuryShiftHandle;
use Illuminate\Support\Facades\DB;

class TreasuryOperationsController extends Controller
{
    /**
     * Deposit amount into the main treasury.
     */

    public function deposit(Request $request)
    {
        // Validate the request data
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                // Find the main treasury
                $mainTreasury = Treasury::where('is_main', true)->lockForUpdate()->firstOrFail();
                $userId = auth()->user()->id;
                // Create a new transaction for the deposit
                $transaction = new TreasuryTransactions();
                $transaction->user_id = $userId;
                $transaction->receivable_id = $mainTreasury->id;
                $transaction->payable_id = $mainTreasury->id;
                $transaction->amount = $request->input('amount');
                $transaction->description = $request->input('description', 'Deposit to main treasury');
                $transaction->save();

                // Update the main treasury balance
                $mainTreasury->balance += $request->input('amount');
                $mainTreasury->save();

                return response()->json(['message' => 'Deposit successful', 'transaction' => $transaction], 201);
            }, 3);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Send amount from the main treasury to another treasury.
     */
    public function send(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'receivable_id' => 'required|exists:treasuries,id',
            'description' => 'nullable|string|max:255',
        ]);

        try {
            return DB::transaction(function () use ($validatedData) {
                // Get the authenticated user ID
                $validatedData['user_id'] = auth()->id();

                // Find the main treasury
                $mainTreasury = Treasury::where('is_main', true)->lockForUpdate()->firstOrFail();

                // Find the receivable treasury
                $receivableTreasury = Treasury::lockForUpdate()->findOrFail($validatedData['receivable_id']);

                // Check if the main treasury has sufficient balance
                if ($mainTreasury->balance < $validatedData['amount']) {
                    return response()->json(['error' => 'Insufficient balance in the main treasury'], 400);
                }

                // check if the receivable treasury is not the main treasury
                if ($receivableTreasury->id === $mainTreasury->id) {
                    return response()->json(['error' => 'Cannot transfer to the main treasury'], 400);
                }

                // check if the user is authenticated
                if (!$validatedData['user_id']) {
                    return response()->json(['error' => 'User not authenticated'], 401);
                }
                // Create a new transaction for the transfer
                $transaction = new TreasuryTransactions();
                $transaction->user_id = $validatedData['user_id'];
                $transaction->receivable_id = $validatedData['receivable_id'];
                $transaction->payable_id = $mainTreasury->id;
                $transaction->amount = $validatedData['amount'];
                $transaction->description = $validatedData['description'] ?? 'Transfer from main treasury';
                $transaction->save();

                // Update the main treasury balance
                $mainTreasury->balance -= $validatedData['amount'];
                $mainTreasury->save();

                // Update the receivable treasury balance
                $receivableTreasury->balance += $validatedData['amount'];
                $receivableTreasury->save();

                return response()->json(['message' => 'Transfer successful', 'transaction' => $transaction], 201);
            }, 3);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     *  Deduct amount from sub-treasury with type and reason.
     */

    public function deduction(Request $request)
    {
        // validate data
        $validatedData = $request->validate([
            'treasury_id' => 'required|exists:treasuries,id',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string',
            'type' => 'required|string|max:255',
        ]);

        // Get the authenticated user ID
        $validatedData['user_id'] = auth()->id();

        try {
            return DB::transaction(function () use ($validatedData) {
                // Find the treasury
                $treasury = Treasury::lockForUpdate()->findOrFail($validatedData['treasury_id']);

                // Check if the treasury has sufficient balance
                if ($treasury->balance < $validatedData['amount']) {
                    return response()->json(['error' => 'Insufficient balance in the treasury'], 400);
                }

                // check if the user is authenticated
                if (!$validatedData['user_id']) {
                    return response()->json(['error' => 'User not authenticated'], 401);
                }

                // Create a new deduction record
                $deduction = new TreasuryDeduction();
                $deduction->user_id = $validatedData['user_id'];
                $deduction->treasury_id = $validatedData['treasury_id'];
                $deduction->amount = $validatedData['amount'];
                $deduction->reason = $validatedData['reason'] ?? 'Deduction from treasury';
                $deduction->type = $validatedData['type'];
                $deduction->save();

                // Update the treasury balance
                $treasury->balance -= $validatedData['amount'];
                $treasury->save();
                return response()->json(['message' => 'Deduction successful', 'deduction' => $deduction], 201);
            }, 3);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateDeduction(Request $request, $id)
{
    // validate data
    $validatedData = $request->validate([
        'treasury_id' => 'required|exists:treasuries,id',
        'amount' => 'required|numeric|min:0.01',
        'reason' => 'nullable|string',
        'type' => 'required|string|max:255',
    ]);

    try {
        $deduction = TreasuryDeduction::findOrFail($id);
        if ($deduction->order_receipt_id || $deduction->transport_receipt_id || str_starts_with($deduction->type ?? '', 'order_receipt') || str_starts_with($deduction->type ?? '', 'transport_receipt')) {
            return response()->json(['message' => 'Use the receipt workflow for receipt-generated deductions.'], 409);
        }

        DB::beginTransaction();

        // Lock rows to avoid race conditions on balance updates
        $oldTreasury = Treasury::lockForUpdate()->findOrFail($deduction->treasury_id);
        $newTreasury = $validatedData['treasury_id'] == $deduction->treasury_id
            ? $oldTreasury
            : Treasury::lockForUpdate()->findOrFail($validatedData['treasury_id']);

        // Step 1: reverse the old deduction from its original treasury
        $oldTreasury->balance += $deduction->amount;

        // Step 2: check the (possibly new) target treasury has enough balance
        // for the new amount, after the reversal above if it's the same treasury
        if ($newTreasury->balance < $validatedData['amount']) {
            DB::rollBack();
            return response()->json(['error' => 'Insufficient balance in the treasury'], 400);
        }

        // Step 3: apply the new deduction amount to the target treasury
        $newTreasury->balance -= $validatedData['amount'];

        $oldTreasury->save();
        if ($newTreasury->isNot($oldTreasury)) {
            $newTreasury->save();
        }

        // Step 4: update the deduction record itself
        $deduction->treasury_id = $validatedData['treasury_id'];
        $deduction->amount = $validatedData['amount'];
        $deduction->reason = $validatedData['reason'] ?? $deduction->reason;
        $deduction->type = $validatedData['type'];
        $deduction->save();

        DB::commit();
        return response()->json(['message' => 'Deduction updated successfully', 'deduction' => $deduction], 200);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

public function destroyDeduction($id)
{
    try {
        $deduction = TreasuryDeduction::findOrFail($id);
        if ($deduction->order_receipt_id || $deduction->transport_receipt_id || str_starts_with($deduction->type ?? '', 'order_receipt') || str_starts_with($deduction->type ?? '', 'transport_receipt')) {
            return response()->json(['message' => 'Use the receipt workflow for receipt-generated deductions.'], 409);
        }

        DB::beginTransaction();

        // Lock the treasury row to avoid race conditions
        $treasury = Treasury::lockForUpdate()->findOrFail($deduction->treasury_id);

        // Restore the balance that was deducted
        $treasury->balance += $deduction->amount;
        $treasury->save();

        $deduction->delete();

        DB::commit();
        return response()->json(['message' => 'Deduction deleted successfully'], 200);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['error' => $e->getMessage()], 500);
    }
}
    /**
     * Assign shift to treasury.
     */

    public function shiftHandle(Request $request)
    {
        // Validate data.
        $validatedData = $request->validate([
            'treasury_id' => 'required|exists:treasuries,id',
            'action' => 'required|string|max:255',
        ]);

        // Get the authenticated user ID
        $validatedData['user_id'] = auth()->id();

        try {
            // check if the user is authenticated
            if (!$validatedData['user_id']) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            $treasury = Treasury::findOrFail($validatedData['treasury_id']);
            // Create a new shift handle record
            $shiftHandle = new TreasuryShiftHandle();
            $shiftHandle->treasury_id = $validatedData['treasury_id'];
            $shiftHandle->user_id = $validatedData['user_id'];
            $shiftHandle->amount = $treasury->balance ?? 0;
            $shiftHandle->action = $validatedData['action'];
            $shiftHandle->save();

            return response()->json(['message' => 'Shift assigned successfully', 'shift_handle' => $shiftHandle], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
