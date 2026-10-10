<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use App\Models\ShipOrderData;
use App\Models\VehicleDriverAssignment;
use App\Models\TreasuryDeduction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PolicyController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        try {
            $query = Policy::query()->with([
                // ship order data and its related data
                'shipOrderData',
                'shipOrderData.shipLineClients',
                'shipOrderData.shipPolicies',
                'shipOrderData.shipPolicies.shipContainersDetails',
                'shipOrderData.shipBookings',
                'shipOrderData.shipBookings.clearanceData',
                'shipOrderData.shipBookings.shipContainersDetails',
                'shipOrderData.shipContactData',
                // operating order and its related data
                'operatingOrder',
                'vehicleDriverAssignments',
                'vehicleDriverAssignments.vehicle',
                'vehicleDriverAssignments.driver',
                'vehicleDriverAssignments.shipContainers',
                'user',
            ]);

            if (!($user->can('view_any policies') || $user->hasRole('admin'))) {
                $query->whereHas('shipOrderData.treasuries', function ($q) use ($user) {
                    $q->whereIn('treasuries.id', $user->treasuries->pluck('id'));
                });
            }

            if ($request->filled('search')) {
                $search = trim($request->query('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('id', 'like', "%{$search}%")
                        ->orWhere('policy_number', 'like', "%{$search}%")
                        ->orWhereHas('shipOrderData', function ($q) use ($search) {
                            $q->where('order_number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('shipOrderData.shipLineClients.client', function ($q) use ($search) {
                            $q->where('client_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('shipOrderData.shipContactData', function ($q) use ($search) {
                            $q->where('contact_loading_name', 'like', "%{$search}%")
                                ->orWhere('contact_customs_officer_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('vehicleDriverAssignments.driver', function ($q) use ($search) {
                            $q->where('driver_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('vehicleDriverAssignments.vehicle', function ($q) use ($search) {
                            $q->where('vehicle_number', 'like', "%{$search}%");
                        });
                });
            }

            $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
            $policies = $query
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($perPage);

            return response()->json($policies);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve policies: ' . $e->getMessage()], 500);
        }
    }

    public function create()
    {
        $shipOrderData = ShipOrderData::with(['policies'])->get();
        return response()->json($shipOrderData);
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'ship_order_data_id' => 'required|exists:ship_order_data,id',
                'operating_order_id' => 'required|exists:operating_orders,id',
                'covenant_amount' => 'nullable|numeric|min:0',
                'policy_type' => 'boolean',
                'policy_aging_date' => 'required_if:policy_type,true|nullable|string',
                'policy_loading_date' => 'required_if:policy_type,true|nullable|string',
                'vehicle_driver_assignments' => 'required|array|min:1',
                'vehicle_driver_assignments.*.vehicle_id' => 'required|exists:vehicles,id',
                'vehicle_driver_assignments.*.driver_id' => 'required|exists:drivers,id',
                'vehicle_driver_assignments.*.ship_container_ids' => 'nullable|array',
                'vehicle_driver_assignments.*.ship_container_ids.*' => 'nullable|exists:ship_containers_details,id',
            ]);

            $shipOrderData = ShipOrderData::findOrFail($validated['ship_order_data_id']);

            if (!$shipOrderData->canCreateMorePolicies()) {
                return response()->json([
                    'message' => 'Cannot create more policies. Maximum limit of ' . $shipOrderData->transfers_count . ' policies reached.',
                    'remaining_slots' => $shipOrderData->remainingPolicySlots()
                ], 422);
            }

            DB::beginTransaction();
            // Create policy
            $policyData = $request->only([
                'ship_order_data_id',
                'operating_order_id',
                'covenant_amount',
                'policy_type',
                'policy_aging_date',
                'policy_loading_date'
            ]);



            $policyData['user_id'] = auth()->id(); // Assuming you want to associate the policy with the authenticated user
            $policy = Policy::create($policyData);
            $treasury = $shipOrderData->treasuries()->lockForUpdate()->first();

            if ($treasury) {
                $covenantAmount = $policyData['covenant_amount'] ?? 0;

                if ($covenantAmount > 0) {
                    if ($treasury->balance < $covenantAmount) {
                        DB::rollBack();

                        return response()->json([
                            'message' => 'الرصيد غير كافٍ في الخزينة'
                        ], 400);
                    }

                    $treasury->balance -= $covenantAmount;
                    $treasury->save();

                    $treasury->deductions()->create([
                        'user_id' => $policyData['user_id'],
                        'amount' => $covenantAmount,
                        'reason' => 'مصاريف العهدة لوثيقة رقم #' . $policy->policy_number,
                        'type' => 'transport_receipt',
                    ]);
                }
            }

            // Create vehicle driver assignments with multiple containers
            $assignments = [];
            if (isset($validated['vehicle_driver_assignments'])) {
                foreach ($validated['vehicle_driver_assignments'] as $assignmentData) {
                    $assignment = VehicleDriverAssignment::create([
                        'vehicle_id' => $assignmentData['vehicle_id'],
                        'driver_id' => $assignmentData['driver_id'],
                        'policy_id' => $policy->id,
                    ]);

                    if (isset($assignmentData['ship_container_ids'])) {
                        // Attach multiple ship containers to the assignment
                        $assignment->syncShipContainers($assignmentData['ship_container_ids']);
                    }
                    $assignments[] = $assignment->load(['vehicle', 'driver', 'shipContainers']);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Policy created successfully with vehicle assignments and containers',
                'policy' => $policy->load(['shipOrderData', 'operatingOrder', 'vehicleDriverAssignments.vehicle', 'vehicleDriverAssignments.driver', 'vehicleDriverAssignments.shipContainers', 'user'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create policy',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        $policy = Policy::with([
            // ship order data and its related data
            'shipOrderData',
            'shipOrderData.shipLineClients',
            'shipOrderData.shipLineClients.client',
            'shipOrderData.shipLineClients.shippingLine',
            'shipOrderData.shipLineClients.destination',
            'shipOrderData.shipLineClients.shipLineClientFactories.factory',
            'shipOrderData.shipPolicies',
            'shipOrderData.shipBookings',
            'shipOrderData.shipBookings.clearanceData',
            'shipOrderData.shipContactData',
            // operating order and its related data
            'operatingOrder',
            'operatingOrder.torrentContainers',
            'vehicleDriverAssignments',
            'vehicleDriverAssignments.vehicle',
            'vehicleDriverAssignments.driver',
            'vehicleDriverAssignments.shipContainers',
            'user'
        ])->findOrFail($id);

        return response()->json($policy);
    }

    public function edit(string $id)
    {
        $policy = Policy::with(['shipOrderData', 'operatingOrder'])->findOrFail($id);
        return response()->json($policy);
    }

    public function update(Request $request, string $id)
    {
        try {
            DB::beginTransaction();

            $policy = Policy::lockForUpdate()->findOrFail($id);

            $validated = $request->validate([
                'ship_order_data_id' => 'sometimes|required|exists:ship_order_data,id',
                'operating_order_id' => 'sometimes|required|exists:operating_orders,id',
                'covenant_amount' => 'nullable|numeric|min:0',
                'policy_type' => 'sometimes|boolean',
                'policy_aging_date' => 'required_if:policy_type,true|nullable|string',
                'policy_loading_date' => 'required_if:policy_type,true|nullable|string|after_or_equal:policy_aging_date',
                'vehicle_driver_assignments' => 'sometimes|array|min:1',
                'vehicle_driver_assignments.*.vehicle_id' => 'required|exists:vehicles,id',
                'vehicle_driver_assignments.*.driver_id' => 'required|exists:drivers,id',
                'vehicle_driver_assignments.*.ship_container_ids' => 'required|array|min:1',
                'vehicle_driver_assignments.*.ship_container_ids.*' => 'required|exists:ship_containers_details,id',
            ]);

            $oldShipOrderDataId = $policy->ship_order_data_id;
            $oldCovenantAmount = $policy->covenant_amount ?? 0;

            $newShipOrderDataId = $validated['ship_order_data_id'] ?? $oldShipOrderDataId;
            // covenant_amount is nullable, so only treat it as "changed" if the key was actually sent
            $newCovenantAmount = array_key_exists('covenant_amount', $validated)
                ? ($validated['covenant_amount'] ?? 0)
                : $oldCovenantAmount;

            $shipOrderChanged = (int) $oldShipOrderDataId !== (int) $newShipOrderDataId;
            if ($shipOrderChanged && $policy->transportReceipts()->exists()) {
                DB::rollBack();
                return response()->json(['message' => 'A policy with receipt history cannot be moved to another order.'], 409);
            }

            // Existing deduction row tied to this policy (if any)
            $existingDeduction = $oldCovenantAmount > 0
                ? TreasuryDeduction::where('type', 'transport_receipt')
                ->where('reason', 'مصاريف العهدة لوثيقة رقم #' . $policy->policy_number)
                ->latest()
                ->first()
                : null;

            if ($shipOrderChanged) {
                // --- Ship order (and therefore treasury) changed ---

                // 1. Refund the OLD treasury the full old covenant amount, and drop the old deduction
                if ($oldCovenantAmount > 0) {
                    $oldShipOrderData = ShipOrderData::find($oldShipOrderDataId);
                    $oldTreasury = $oldShipOrderData?->treasuries()->lockForUpdate()->first();

                    if ($oldTreasury) {
                        $oldTreasury->balance += $oldCovenantAmount;
                        $oldTreasury->save();
                    }

                    $existingDeduction?->delete();
                }

                // 2. Deduct the NEW covenant amount from the NEW treasury
                if ($newCovenantAmount > 0) {
                    $newShipOrderData = ShipOrderData::findOrFail($newShipOrderDataId);
                    $newTreasury = $newShipOrderData->treasuries()->lockForUpdate()->first();

                    if ($newTreasury) {
                        if ($newTreasury->balance < $newCovenantAmount) {
                            DB::rollBack();

                            return response()->json([
                                'message' => 'الرصيد غير كافٍ في الخزينة'
                            ], 400);
                        }

                        $newTreasury->balance -= $newCovenantAmount;
                        $newTreasury->save();

                        $newTreasury->deductions()->create([
                            'user_id' => auth()->id(),
                            'amount' => $newCovenantAmount,
                            'reason' => 'مصاريف العهدة لوثيقة رقم #' . $policy->policy_number,
                            'type' => 'transport_receipt',
                        ]);
                    }
                }
            } else {
                // --- Same ship order / treasury, only the amount may have changed ---
                $difference = $newCovenantAmount - $oldCovenantAmount;

                if ($difference !== 0) {
                    $shipOrderData = ShipOrderData::find($oldShipOrderDataId);
                    $treasury = $shipOrderData?->treasuries()->lockForUpdate()->first();

                    if ($treasury) {
                        if ($difference > 0 && $treasury->balance < $difference) {
                            DB::rollBack();

                            return response()->json([
                                'message' => 'الرصيد غير كافٍ في الخزينة'
                            ], 400);
                        }

                        $treasury->balance -= $difference; // handles both increase and decrease/refund
                        $treasury->save();

                        if ($newCovenantAmount <= 0) {
                            // Amount cleared entirely -> drop the deduction record
                            $existingDeduction?->delete();
                        } elseif ($existingDeduction) {
                            $existingDeduction->update([
                                'amount' => $newCovenantAmount,
                                'user_id' => auth()->id(),
                                'reason' => 'تعديل مصاريف العهدة لوثيقة رقم #' . $policy->policy_number,

                            ]);
                        } else {
                            $treasury->deductions()->create([
                                'user_id' => auth()->id(),
                                'amount' => $newCovenantAmount,
                                'reason' => 'مصاريف العهدة لوثيقة رقم #' . $policy->policy_number,
                                'type' => 'transport_receipt',
                            ]);
                        }
                    }
                }
            }

            // Update policy data
            $policyData = $request->only([
                'ship_order_data_id',
                'operating_order_id',
                'covenant_amount',
                'policy_type',
                'policy_aging_date',
                'policy_loading_date'
            ]);
            $policy->update($policyData);

            // Update vehicle driver assignments if provided
            if (isset($validated['vehicle_driver_assignments'])) {
                $policy->vehicleDriverAssignments()->delete();

                foreach ($validated['vehicle_driver_assignments'] as $assignmentData) {
                    $assignment = VehicleDriverAssignment::create([
                        'vehicle_id' => $assignmentData['vehicle_id'],
                        'driver_id' => $assignmentData['driver_id'],
                        'policy_id' => $policy->id,
                    ]);

                    $assignment->syncShipContainers($assignmentData['ship_container_ids']);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Policy updated successfully',
                'policy' => $policy->load(['shipOrderData', 'operatingOrder', 'vehicleDriverAssignments.vehicle', 'vehicleDriverAssignments.driver', 'vehicleDriverAssignments.shipContainers', 'user'])
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Policy not found'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update policy',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        $user = auth()->user();
        if (!$user->hasRole('admin')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $policy = Policy::findOrFail($id);
        if ($policy->transportReceipts()->exists()) return response()->json(['message' => 'Policies with receipt history cannot be deleted.'], 409);
        try {
            DB::beginTransaction();
            $policy = Policy::lockForUpdate()->findOrFail($id);
            if ($policy->transportReceipts()->exists()) {
                DB::rollBack();
                return response()->json(['message' => 'Policies with receipt history cannot be deleted.'], 409);
            }
            $treasury = $policy->shipOrderData->treasuries()->lockForUpdate()->first();
            // Refund covenant amount to treasury if applicable
            if ($treasury && $policy->covenant_amount > 0) {
                $treasury->balance += $policy->covenant_amount;
                $treasury->save();
                // Optionally, you can also log this refund in the deductions table
                $treasury->deductions()->create([
                    'user_id' => $policy->user_id,
                    'amount' => $policy->covenant_amount,
                    'reason' => 'Refund for deleted policy #' . $policy->policy_number,
                    'type' => 'refund',
                ]);
            }
            // Delete related vehicle driver assignments first
            $policy->vehicleDriverAssignments()->delete();

            // Delete the policy
            $policy->delete();

            DB::commit();
            return response()->json([
                'message' => 'Policy and related vehicle assignments deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to delete policy',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getAssignmentByShipOrderAndPolicy($shipOrderId, $policyId)
    {
        $assignment = VehicleDriverAssignment::whereHas('policy', function ($query) use ($policyId) {
            $query->where('id', $policyId);
        })->whereHas('policy.shipOrderData', function ($query) use ($shipOrderId) {
            $query->where('id', $shipOrderId);
        })->with(['vehicle', 'driver', 'shipContainers'])->first();

        if (!$assignment) {
            return response()->json([
                'message' => 'No assignment found for the given ship order and policy'
            ], 404);
        }

        return response()->json($assignment);
    }
    public function generatePolicyNumber()
    {
        $policyNumber = Policy::generatePolicyNumber();
        return response()->json([
            'policy_number' => $policyNumber
        ]);
    }

    public function getByShipOrderData($shipOrderDataId)
    {
        $shipOrderData = ShipOrderData::with(['policies' => function ($query) {
            $query->with([
                'operatingOrder',
                'vehicleDriverAssignments.vehicle',
                'vehicleDriverAssignments.driver',
                'vehicleDriverAssignments.shipContainers',
                'user'
            ]);
        }])->findOrFail($shipOrderDataId);

        return response()->json([
            'ship_order_data' => $shipOrderData,
            'policies' => $shipOrderData->policies,
            'remaining_slots' => $shipOrderData->remainingPolicySlots(),
            'can_create_more' => $shipOrderData->canCreateMorePolicies()
        ]);
    }

    public function addVehicleAssignment(Request $request, $policyId)
    {
        $validated = $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'driver_id' => 'required|exists:drivers,id',
            'ship_container_ids' => 'required|array|min:1',
            'ship_container_ids.*' => 'required|exists:ship_containers_details,id',
        ]);

        $policy = Policy::findOrFail($policyId);

        if ($policy->vehicleDriverAssignments()->exists()) {
            return response()->json([
                'message' => '
                لا يُسمح بربط أكثر من مركبة واحدة بالوثيقة الواحدة.
                '
            ], 422);
        }

        $assignment = VehicleDriverAssignment::create([
            'vehicle_id' => $validated['vehicle_id'],
            'driver_id' => $validated['driver_id'],
            'policy_id' => $policy->id,
        ]);

        // Attach multiple ship containers to the assignment
        $assignment->syncShipContainers($validated['ship_container_ids']);

        return response()->json([
            'message' => 'Vehicle assignment added successfully with containers',
            'assignment' => $assignment->load(['vehicle', 'driver', 'shipContainers'])
        ], 201);
    }

    public function removeVehicleAssignment($assignmentId)
    {
        $assignment = VehicleDriverAssignment::findOrFail($assignmentId);
        $assignment->delete();

        return response()->json([
            'message' => 'Vehicle assignment removed successfully'
        ]);
    }

    public function updateVehicleAssignment(Request $request, $assignmentId)
    {
        $validated = $request->validate([
            'vehicle_id' => 'sometimes|required|exists:vehicles,id',
            'driver_id' => 'sometimes|required|exists:drivers,id',
            'ship_container_ids' => 'sometimes|required|array|min:1',
            'ship_container_ids.*' => 'required|exists:ship_containers_details,id',
        ]);

        $assignment = VehicleDriverAssignment::findOrFail($assignmentId);

        // Update assignment basic fields
        $assignmentData = $request->only(['vehicle_id', 'driver_id']);
        if (!empty($assignmentData)) {
            $assignment->update($assignmentData);
        }

        // Update ship containers if provided
        if (isset($validated['ship_container_ids'])) {
            $assignment->syncShipContainers($validated['ship_container_ids']);
        }

        return response()->json([
            'message' => 'Vehicle assignment updated successfully',
            'assignment' => $assignment->load(['vehicle', 'driver', 'shipContainers'])
        ]);
    }

    // New helper functions for container management
    public function addContainersToAssignment(Request $request, $assignmentId)
    {
        $validated = $request->validate([
            'ship_container_ids' => 'required|array|min:1',
            'ship_container_ids.*' => 'required|exists:ship_containers_details,id',
        ]);

        $assignment = VehicleDriverAssignment::findOrFail($assignmentId);
        $assignment->attachShipContainers($validated['ship_container_ids']);

        return response()->json([
            'message' => 'Containers added to assignment successfully',
            'assignment' => $assignment->load(['vehicle', 'driver', 'shipContainers'])
        ]);
    }

    public function removeContainersFromAssignment(Request $request, $assignmentId)
    {
        $validated = $request->validate([
            'ship_container_ids' => 'required|array|min:1',
            'ship_container_ids.*' => 'required|exists:ship_containers_details,id',
        ]);

        $assignment = VehicleDriverAssignment::findOrFail($assignmentId);
        $assignment->detachShipContainers($validated['ship_container_ids']);

        return response()->json([
            'message' => 'Containers removed from assignment successfully',
            'assignment' => $assignment->load(['vehicle', 'driver', 'shipContainers'])
        ]);
    }
}
