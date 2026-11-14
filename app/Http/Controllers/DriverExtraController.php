<?php

namespace App\Http\Controllers;

use App\Http\Resources\DriverExtraResource;
use App\Models\DriverExtra;
use App\Models\Policy;
use App\Models\Treasury;
use App\Models\VehicleDriverAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverExtraController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $extras = DriverExtra::with(
                'vehicleDriverAssignment.vehicle',
                'vehicleDriverAssignment.driver',
                'vehicleDriverAssignment.policy',
                'vehicleDriverAssignment.policy.shipOrderData',
            )->get();

            return response()->json(DriverExtraResource::collection($extras), 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve driver extras', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'vehicle_driver_assignment_id' => 'required|exists:vehicle_driver_assignments,id',
                'extras' => 'required|array|min:1',
                'extras.*.extra_amount' => 'required|numeric|min:0',
                'extras.*.extra_type' => 'required|string|max:255',
            ]);

            $checkIssue = VehicleDriverAssignment::find($validatedData['vehicle_driver_assignment_id']);
            if ($checkIssue->driverExtras()->exists() || $checkIssue->policy_id == null) {
                return response()->json(['error' => '
                تعذر إضافة ملحقات السائق، لأن هذا الإسناد يحتوي على ملحقات مسبقًا أو لا توجد بوليصة مرتبطة به
                '], 422);
            }
            $createdExtras = [];

            foreach ($validatedData['extras'] as $item) {

                $item['vehicle_driver_assignment_id'] = $validatedData['vehicle_driver_assignment_id'];
                $extra = DriverExtra::create($item);

                $createdExtras[] = $extra->load('vehicleDriverAssignment');
            }

            return response()->json([
                'message' => 'Driver extras created successfully',
                'data' => DriverExtraResource::collection($createdExtras),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create driver extras',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $extra = DriverExtra::with(
                'vehicleDriverAssignment.vehicle',
                'vehicleDriverAssignment.driver',
                'vehicleDriverAssignment.policy',
                'vehicleDriverAssignment.policy.shipOrderData',
            )->findOrFail($id);

            return response()->json(new DriverExtraResource($extra), 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve driver extra', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        // Matches the UI: only admins can edit driver additions.
        abort_unless($request->user()?->hasRole('admin'), 403);
        try {
            $extra = DriverExtra::findOrFail($id);
            $validatedData = $request->validate([
                'vehicle_driver_assignment_id' => 'sometimes|required|exists:vehicle_driver_assignments,id',
                'extra_amount' => 'sometimes|required|numeric|min:0',
                'extra_type' => 'sometimes|required|string|max:255',
            ]);
            $extra->update($validatedData);
            return response()->json([
                'message' => 'Driver extra updated successfully',
                'data' => new DriverExtraResource($extra->load('vehicleDriverAssignment')),
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update driver extra', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        // Matches the UI: only admins can delete driver additions.
        abort_unless($request->user()?->hasRole('admin'), 403);
        try {
            $extra = DriverExtra::findOrFail($id);

            $extra->delete();

            return response()->json(['message' => 'Driver extra deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete driver extra', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get extras by vehicle driver assignment.
     */
    public function getByAssignment(string $assignmentId)
    {
        try {
            $extras = DriverExtra::where('vehicle_driver_assignment_id', $assignmentId)
                ->with('vehicleDriverAssignment')
                ->get();

            return response()->json(DriverExtraResource::collection($extras), 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve extras for assignment', 'message' => $e->getMessage()], 500);
        }
    }

    public function settle(Request $request)
    {
        $validatedData = $request->validate([
            'clear_ids'   => 'required|array|min:1',
            'clear_ids.*' => 'integer|exists:policies,id',
            'treasury_id' => 'required|integer|exists:treasuries,id',
        ]);

        $user = auth()->user();

        try {
            DB::beginTransaction();

            // Lock the chosen treasury once — it's the same for every policy in this batch
            $treasury = Treasury::lockForUpdate()->find($validatedData['treasury_id']);

            if (!$treasury) {
                DB::rollBack();
                return response()->json([
                    'message' => 'الخزينة المحددة غير موجودة.',
                ], 422);
            }

            $policies = Policy::whereIn('id', $validatedData['clear_ids'])
                ->where('settled', false)
                ->with([
                    'shipOrderData',
                    'vehicleDriverAssignments.driverExtras',
                ])
                ->lockForUpdate()
                ->get();

            if ($policies->count() !== count($validatedData['clear_ids'])) {
                DB::rollBack();
                return response()->json([
                    'message' => 'One or more policies are already settled or not accessible.',
                ], 422);
            }

            foreach ($policies as $policy) {
                $shipOrder = $policy->shipOrderData;

                if (!$shipOrder) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'لم يتم العثور على طلب الشحن الخاص بالوثيقة رقم #' . $policy->policy_number,
                    ], 422);
                }

                // Calculate driver extras for this policy
                $driverExtrasSum = 0;
                $assignments = $policy->vehicleDriverAssignments;
                $assignmentsList = $assignments instanceof \Illuminate\Support\Collection
                    ? $assignments
                    : ($assignments instanceof \App\Models\VehicleDriverAssignment ? collect([$assignments]) : collect());

                foreach ($assignmentsList as $assignment) {
                    if ($assignment->driverExtras) {
                        foreach ($assignment->driverExtras as $extra) {
                            $driverExtrasSum += ($extra->extra_amount ?? 0);
                        }
                    }
                }

                $noloans = $shipOrder->noloans ?? 0;
                $covenantAmount = $policy->covenant_amount ?? 0;
                $netAmount = ($noloans - $covenantAmount) + $driverExtrasSum;

                if ($netAmount > 0) {
                    if ($treasury->balance < $netAmount) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'الرصيد غير كافٍ في الخزينة (' . $treasury->name . ') لتسوية الوثيقة رقم #' . $policy->policy_number,
                        ], 400);
                    }

                    $treasury->balance -= $netAmount;

                    $treasury->deductions()->create([
                        'user_id' => $user->id,
                        'amount' => $netAmount,
                        'reason' => 'تصفية وثيقة رقم #' . $policy->policy_number,
                        'type' => 'transport_receipt',
                    ]);
                } elseif ($netAmount < 0) {
                    $refundAmount = abs($netAmount);
                    $treasury->balance += $refundAmount;

                    $treasury->deductions()->create([
                        'user_id' => $user->id,
                        'amount' => $refundAmount,
                        'reason' => 'استرداد متبقي عهدة بعد تصفية وثيقة رقم #' . $policy->policy_number,
                        'type' => 'refund',
                    ]);
                }

                $policy->update([
                    'settled' => true,
                    'clearance_date' => now(),
                    'settled_user' => $user->id,
                ]);
            }

            // Save the treasury balance once, after all policies in the batch are applied
            $treasury->save();

            DB::commit();

            return response()->json(['message' => 'Policies settled successfully'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to settle policies',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
