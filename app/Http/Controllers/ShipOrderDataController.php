<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ShipOrderData;
use App\Models\ShipLineClient;
use App\Models\ShipLineClientFactory;
use App\Models\ShipPolicy;
use App\Models\ShipBooking;
use App\Models\ShipContactData;
use App\Models\ShipContainersDetail;
use App\Models\ClearanceData;
use Illuminate\Support\Facades\DB;

class ShipOrderDataController extends Controller
{

    /*
    * Display a listing of the Ship Order Data along with related data.
    */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = ShipOrderData::query()->with([
            'shipLineClients.client',
            'shipLineClients.shippingLine',
            'shipLineClients.destination',
            'shipLineClients.shipLineClientFactories.factory',
            'shipPolicies.shipContainersDetails',
            'shipBookings.shipContainersDetails',
            'shipBookings.clearanceData',
            'shipContactData',
            'treasuries',
            'orderReceipts.items',
            'orderReceipts.client',
            'operatingOrder'
        ]);

        if (!($user->can('view_any ship_order_data') || $user->hasRole('admin'))) {
            $query->whereHas('treasuries', function ($q) use ($user) {
                $q->whereIn('treasuries.id', $user->treasuries->pluck('id'));
            });
        }

        if ($request->filled('search')) {
            $search = trim($request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhereHas('shipLineClients.client', function ($q) use ($search) {
                        $q->where('client_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('shipLineClients.shippingLine', function ($q) use ($search) {
                        $q->where('shipping_line_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('shipLineClients.destination', function ($q) use ($search) {
                        $q->where('destination_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('shipPolicies', function ($q) use ($search) {
                        $q->where('policy_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('shipBookings', function ($q) use ($search) {
                        $q->where('booking_number', 'like', "%{$search}%");
                    });
            });
        }

        $shipOrders = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json([
            'data' => $shipOrders
        ]);
    }

    /*
    * Generate a unique order number in the format ORD-{YEAR}-{SEQUENCE}
    */
    public function generateOrderNumber()
    {
        $year = now()->year;
        $month = now()->month;
        $day = now()->day;

        return DB::transaction(function () use ($year, $month, $day) {

            // Lock latest row overall (prevents duplicates)
            $lastOrder = ShipOrderData::lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $nextNumber = $lastOrder
                ? ((int) substr($lastOrder->order_number, -4)) + 1
                : 1;

            return sprintf(
                'ORD-%d-%02d-%02d-%04d',
                $year,
                $month,
                $day,
                $nextNumber
            );
        });
    }

    /**
     * Store a newly created Ship Order Data along with related data.
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                // Ship Order Data
                'order_number' => 'required|string|unique:ship_order_data,order_number',
                'order_type' => 'required|in:import,export',
                'client_requirements' => 'nullable|string',
                'noloans' => 'nullable|integer',
                'shipping_date' => 'nullable',
                'aging_date' => 'nullable',
                'notes' => 'nullable|string',
                'containers_type' => 'nullable|string',
                'containers_number' => 'nullable|string',
                'loading_way' => 'nullable|string',
                'handel_way' => 'nullable|string',
                'transfers_count' => 'nullable|integer',

                // Ship Line Client Data
                'client_id' => 'required|exists:clients,id',
                'shipping_line_id' => 'required|exists:shipping_lines,id',
                'destination_id' => 'required|exists:destinations,id',

                // Factories (array)
                'factories' => 'required|array|min:1',
                'factories.*.factory_id' => 'required|exists:factories,id',

                // Ship Policies (array) - mutually exclusive with bookings
                'policies' => 'required_without:bookings|array|min:1',
                'policies.*.policy_number' => 'required|string|unique:ship_policies,policy_number',
                'policies.*.containers' => 'sometimes|array',
                'policies.*.containers.*.container_number' => 'sometimes|string',

                // Ship Bookings (array) - mutually exclusive with policies
                'bookings' => 'required_without:policies|array|min:1',
                'bookings.*.booking_number' => 'required|string|unique:ship_bookings,booking_number',
                'bookings.*.containers' => 'sometimes|array',
                'bookings.*.containers.*.container_number' => 'sometimes|string',

                // Ship Contact Data
                'contact_loading_name' => 'required|string',
                'contact_loading_number' => 'required|string',
                'contact_customs_officer_name' => 'required|string',
                'contact_customs_officer_number' => 'required|string',

                // Clearance Data (optional)
                'clearance_data' => 'nullable|array',
                'clearance_data.clearance_type' => 'nullable|string',
                'clearance_data.customs_location' => 'nullable|string',
                'clearance_data.redirect_location' => 'nullable|string',
                'treasury_id' => 'nullable|integer|exists:treasuries,id',
            ]);

            return DB::transaction(function () use ($validatedData) {
                // Generate order number
                // $orderNumber = $this->generateOrderNumber();
                // Create Ship Order Data
                $shipOrderData = ShipOrderData::create([
                    'order_number' => $validatedData['order_number'],
                    'order_type' => $validatedData['order_type'],
                    'client_requirements' => $validatedData['client_requirements'] ?? null,
                    'noloans' => $validatedData['noloans'] ?? 0,
                    'shipping_date' => $validatedData['shipping_date'] ?? null,
                    'aging_date' => $validatedData['aging_date'] ?? null,
                    'notes' => $validatedData['notes'] ?? null,
                    'containers_type' => $validatedData['containers_type'] ?? null,
                    'containers_number' => $validatedData['containers_number'] ?? null,
                    'loading_way' => $validatedData['loading_way'] ?? null,
                    'transfers_count' => $validatedData['transfers_count'] ?? 1,
                ]);

                $shipOrderData->treasuries()->sync($validatedData['treasury_id']);
                // Create Ship Line Client
                $shipLineClient = ShipLineClient::create([
                    'ship_order_data_id' => $shipOrderData->id,
                    'client_id' => $validatedData['client_id'],
                    'shipping_line_id' => $validatedData['shipping_line_id'],
                    'destination_id' => $validatedData['destination_id'],
                ]);

                // Create Ship Line Client Factories
                foreach ($validatedData['factories'] as $factory) {
                    ShipLineClientFactory::create([
                        'ship_line_client_id' => $shipLineClient->id,
                        'factory_id' => $factory['factory_id'],
                    ]);
                }

                // Create Ship Policies and their containers (if provided)
                if (isset($validatedData['policies'])) {
                    foreach ($validatedData['policies'] as $policyData) {
                        $policy = ShipPolicy::create([
                            'ship_order_data_id' => $shipOrderData->id,
                            'policy_number' => $policyData['policy_number'],
                        ]);

                        // Create containers for this policy
                        foreach ($policyData['containers'] as $containerData) {
                            ShipContainersDetail::create([
                                'policy_id' => $policy->id,
                                'container_number' => $containerData['container_number'],
                            ]);
                        }
                    }
                }

                // Create Ship Bookings and their containers (if provided)
                if (isset($validatedData['bookings'])) {
                    foreach ($validatedData['bookings'] as $bookingData) {
                        $booking = ShipBooking::create([
                            'ship_order_data_id' => $shipOrderData->id,
                            'booking_number' => $bookingData['booking_number'],
                        ]);

                        // Create containers for this booking
                        foreach ($bookingData['containers'] as $containerData) {
                            ShipContainersDetail::create([
                                'booking_id' => $booking->id,
                                'container_number' => $containerData['container_number'],
                            ]);
                        }

                        // Create Clearance Data if provided
                        if (!empty($validatedData['clearance_data'])) {
                            ClearanceData::create([
                                'booking_id' => $booking->id,
                                'clearance_type' => $validatedData['clearance_data']['clearance_type'] ?? null,
                                'customs_location' => $validatedData['clearance_data']['customs_location'] ?? null,
                                'redirect_location' => $validatedData['clearance_data']['redirect_location'] ?? null,
                            ]);
                        }
                    }
                }

                // Create Ship Contact Data
                ShipContactData::create([
                    'ship_order_data_id' => $shipOrderData->id,
                    'contact_loading_name' => $validatedData['contact_loading_name'],
                    'contact_loading_number' => $validatedData['contact_loading_number'],
                    'contact_customs_officer_name' => $validatedData['contact_customs_officer_name'],
                    'contact_customs_officer_number' => $validatedData['contact_customs_officer_number'],
                ]);

                return response()->json([
                    'message' => 'Ship order data created successfully',
                    'data' => $shipOrderData->load([
                        'shipLineClients.client',
                        'shipLineClients.shippingLine',
                        'shipLineClients.destination',
                        'shipLineClients.shipLineClientFactories.factory',
                        'shipPolicies.shipContainersDetails',
                        'shipBookings.shipContainersDetails',
                        'shipBookings.clearanceData',
                        'shipContactData'
                    ])
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create ship order data', 'message' => $e->getMessage()], 500);
        }
    }

    /*
    * Display the specified Ship Order Data along with related data.
    */
    public function show($id)
    {
        $shipOrderData = ShipOrderData::with([
            'shipLineClients.client',
            'shipLineClients.shippingLine',
            'shipLineClients.destination',
            'shipLineClients.shipLineClientFactories.factory',
            'shipPolicies.shipContainersDetails.assignmentContainerPivots',
            'shipBookings.shipContainersDetails.assignmentContainerPivots',
            'shipBookings.clearanceData',
            'shipContactData',
            'treasuries',
            'orderReceipts.items',
            'orderReceipts.client'
        ])->findOrFail($id);

        // Add 'assigned' key to containers
        foreach ($shipOrderData->shipPolicies as $policy) {
            foreach ($policy->shipContainersDetails as $container) {
                $container->assigned = $container->assignmentContainerPivots->isNotEmpty();
            }
        }

        foreach ($shipOrderData->shipBookings as $booking) {
            foreach ($booking->shipContainersDetails as $container) {
                $container->assigned = $container->assignmentContainerPivots->isNotEmpty();
            }
        }

        return response()->json([
            'data' => $shipOrderData
        ]);
    }
    /*
 * Update the specified Ship Order Data along with related data.
 */
    public function update(Request $request, $id)
    {
        try {
            $shipOrderData = ShipOrderData::findOrFail($id);

            $validatedData = $request->validate([
                'order_number' => 'required|string|unique:ship_order_data,order_number,' . $shipOrderData->id,
                'order_type' => 'required|in:import,export',
                'client_requirements' => 'nullable|string',
                'noloans' => 'nullable|integer',
                'shipping_date' => 'nullable|date',
                'aging_date' => 'nullable|date',
                'notes' => 'nullable|string',
                'containers_type' => 'nullable|string',
                'containers_number' => 'nullable|string',
                'loading_way' => 'nullable|string',
                'handel_way' => 'nullable|string',
                'transfers_count' => 'nullable|integer',

                'client_id' => 'required|exists:clients,id',
                'shipping_line_id' => 'required|exists:shipping_lines,id',
                'destination_id' => 'required|exists:destinations,id',

                'factories' => 'required|array|min:1',
                'factories.*.factory_id' => 'required|exists:factories,id',

                'policies' => 'required_without:bookings|array|min:1',
                'policies.*.id' => 'nullable|integer|exists:ship_policies,id',
                'policies.*.policy_number' => 'required|string',
                'policies.*.containers' => 'sometimes|array',
                'policies.*.containers.*.id' => 'nullable|integer|exists:ship_containers_details,id',
                'policies.*.containers.*.container_number' => 'sometimes|string',

                'bookings' => 'required_without:policies|array|min:1',
                'bookings.*.id' => 'nullable|integer|exists:ship_bookings,id',
                'bookings.*.booking_number' => 'required|string',
                'bookings.*.containers' => 'sometimes|array',
                'bookings.*.containers.*.id' => 'nullable|integer|exists:ship_containers_details,id',
                'bookings.*.containers.*.container_number' => 'sometimes|string',

                'contact_loading_name' => 'required|string',
                'contact_loading_number' => 'required|string',
                'contact_customs_officer_name' => 'required|string',
                'contact_customs_officer_number' => 'required|string',

                'clearance_data' => 'nullable|array',
                'clearance_data.clearance_type' => 'nullable|string',
                'clearance_data.customs_location' => 'nullable|string',
                'clearance_data.redirect_location' => 'nullable|string',
                'treasury_id' => 'nullable|integer|exists:treasuries,id',
            ]);

            return DB::transaction(function () use ($validatedData, $shipOrderData) {
                $shipOrderData = ShipOrderData::lockForUpdate()->findOrFail($shipOrderData->id);
                $existingClient = $shipOrderData->shipLineClients()->first();
                if ($existingClient && $existingClient->client_id != $validatedData['client_id']
                    && $shipOrderData->orderReceipts()->where('client_id', $existingClient->client_id)->exists()) {
                    return response()->json(['message' => 'A client with receipt history cannot be removed from this order.'], 409);
                }

                // =========================
                // Update Ship Order
                // =========================
                $shipOrderData->update([
                    'order_number'        => $validatedData['order_number'],
                    'order_type'          => $validatedData['order_type'],
                    'client_requirements' => $validatedData['client_requirements'] ?? null,
                    'noloans'             => $validatedData['noloans'] ?? 0,
                    'shipping_date'       => $validatedData['shipping_date'] ?? null,
                    'aging_date'          => $validatedData['aging_date'] ?? null,
                    'notes'               => $validatedData['notes'] ?? null,
                    'containers_type'     => $validatedData['containers_type'] ?? null,
                    'containers_number'   => $validatedData['containers_number'] ?? null,
                    'loading_way'         => $validatedData['loading_way'] ?? null,
                    'transfers_count'     => $validatedData['transfers_count'] ?? 1,
                ]);

                // Sync treasury (non-destructive: sync replaces pivot rows only, IDs are stable)
                if (isset($validatedData['treasury_id'])) {
                    $shipOrderData->treasuries()->sync($validatedData['treasury_id']);
                }

                // =========================
                // Update Ship Line Client
                // updateOrCreate is safe here: it's a 1:1 keyed on ship_order_data_id,
                // so ID is preserved when a matching row already exists.
                // =========================
                $shipLineClient = ShipLineClient::updateOrCreate(
                    ['ship_order_data_id' => $shipOrderData->id],
                    [
                        'client_id'        => $validatedData['client_id'],
                        'shipping_line_id' => $validatedData['shipping_line_id'],
                        'destination_id'   => $validatedData['destination_id'],
                    ]
                );

                // =========================
                // Sync Factories — add only, never delete
                // =========================
                $incomingFactoryIds = collect($validatedData['factories'])->pluck('factory_id')->toArray();
                $existingFactoryIds = $shipLineClient->shipLineClientFactories()->pluck('factory_id')->toArray();

                $factoriesToAdd = array_diff($incomingFactoryIds, $existingFactoryIds);
                foreach ($factoriesToAdd as $factoryId) {
                    ShipLineClientFactory::create([
                        'ship_line_client_id' => $shipLineClient->id,
                        'factory_id'          => $factoryId,
                    ]);
                }

                // =========================
                // Update Ship Policies — match by id first, never delete
                // FIX: firstOrCreate only matched on policy_number, so editing that
                // field (or any resend where it didn't match exactly) silently
                // created a duplicate policy instead of updating the existing one,
                // orphaning its containers/receipts/vehicle assignments. Now we
                // resolve the existing row by primary key when the frontend sends
                // one back, and only fall back to matching by number for genuinely
                // new policies.
                // =========================
                if (isset($validatedData['policies'])) {
                    foreach ($validatedData['policies'] as $policyData) {
                        $policy = null;

                        if (!empty($policyData['id'])) {
                            $policy = ShipPolicy::where('ship_order_data_id', $shipOrderData->id)
                                ->where('id', $policyData['id'])
                                ->first();
                        }

                        if ($policy) {
                            $policy->update([
                                'policy_number' => $policyData['policy_number'],
                            ]);
                        } else {
                            // No id supplied (or it didn't belong to this order) —
                            // treat as new, but still avoid a dupe if a policy with
                            // this exact number already exists.
                            $policy = ShipPolicy::firstOrCreate(
                                [
                                    'ship_order_data_id' => $shipOrderData->id,
                                    'policy_number'      => $policyData['policy_number'],
                                ]
                            );
                        }

                        // Sync containers — match by id, add new, update existing,
                        // and delete any container that belongs to this policy but
                        // was not included in this request's payload.
                        if (isset($policyData['containers'])) {
                            $incomingContainerIds = [];

                            foreach ($policyData['containers'] as $containerData) {
                                if (!empty($containerData['id'])) {
                                    ShipContainersDetail::where('id', $containerData['id'])
                                        ->where('policy_id', $policy->id)
                                        ->update([
                                            'container_number' => $containerData['container_number'],
                                        ]);

                                    $incomingContainerIds[] = $containerData['id'];
                                    continue;
                                }

                                $existingContainer = ShipContainersDetail::where('policy_id', $policy->id)
                                    ->where('container_number', $containerData['container_number'])
                                    ->first();

                                if ($existingContainer) {
                                    $incomingContainerIds[] = $existingContainer->id;
                                } else {
                                    $newContainer = ShipContainersDetail::create([
                                        'policy_id'        => $policy->id,
                                        'container_number' => $containerData['container_number'],
                                    ]);

                                    $incomingContainerIds[] = $newContainer->id;
                                }
                            }

                            ShipContainersDetail::where('policy_id', $policy->id)
                                ->whereNotIn('id', $incomingContainerIds)
                                ->delete();
                        }
                    }
                }

                // =========================
                // Update Bookings — match by id first, never delete
                // Same fix as policies above: booking_number alone can't reliably
                // identify an existing row across an edit.
                // =========================
                if (isset($validatedData['bookings'])) {
                    foreach ($validatedData['bookings'] as $bookingData) {
                        $booking = null;

                        if (!empty($bookingData['id'])) {
                            $booking = ShipBooking::where('ship_order_data_id', $shipOrderData->id)
                                ->where('id', $bookingData['id'])
                                ->first();
                        }

                        if ($booking) {
                            $booking->update([
                                'booking_number' => $bookingData['booking_number'],
                            ]);
                        } else {
                            $booking = ShipBooking::firstOrCreate(
                                [
                                    'ship_order_data_id' => $shipOrderData->id,
                                    'booking_number'     => $bookingData['booking_number'],
                                ]
                            );
                        }

                        // Sync containers — match by id, add new, update existing,
                        // and delete any container that belongs to this booking but
                        // was not included in this request's payload.
                        if (isset($bookingData['containers'])) {
                            $incomingContainerIds = [];

                            foreach ($bookingData['containers'] as $containerData) {
                                if (!empty($containerData['id'])) {
                                    ShipContainersDetail::where('id', $containerData['id'])
                                        ->where('booking_id', $booking->id)
                                        ->update([
                                            'container_number' => $containerData['container_number'],
                                        ]);

                                    $incomingContainerIds[] = $containerData['id'];
                                    continue;
                                }

                                $existingContainer = ShipContainersDetail::where('booking_id', $booking->id)
                                    ->where('container_number', $containerData['container_number'])
                                    ->first();

                                if ($existingContainer) {
                                    $incomingContainerIds[] = $existingContainer->id;
                                } else {
                                    $newContainer = ShipContainersDetail::create([
                                        'booking_id'       => $booking->id,
                                        'container_number' => $containerData['container_number'],
                                    ]);

                                    $incomingContainerIds[] = $newContainer->id;
                                }
                            }

                            ShipContainersDetail::where('booking_id', $booking->id)
                                ->whereNotIn('id', $incomingContainerIds)
                                ->delete();
                        }

                        // Upsert Clearance Data — updateOrCreate is safe (preserves ID on update)
                        if (!empty($validatedData['clearance_data'])) {
                            ClearanceData::updateOrCreate(
                                ['booking_id' => $booking->id],
                                [
                                    'clearance_type'    => $validatedData['clearance_data']['clearance_type'] ?? null,
                                    'customs_location'  => $validatedData['clearance_data']['customs_location'] ?? null,
                                    'redirect_location' => $validatedData['clearance_data']['redirect_location'] ?? null,
                                ]
                            );
                        }
                    }
                }

                // =========================
                // Update Contact — updateOrCreate preserves ID
                // =========================
                ShipContactData::updateOrCreate(
                    ['ship_order_data_id' => $shipOrderData->id],
                    [
                        'contact_loading_name'           => $validatedData['contact_loading_name'],
                        'contact_loading_number'         => $validatedData['contact_loading_number'],
                        'contact_customs_officer_name'   => $validatedData['contact_customs_officer_name'],
                        'contact_customs_officer_number' => $validatedData['contact_customs_officer_number'],
                    ]
                );

                return response()->json([
                    'message' => 'Ship order updated successfully',
                    'data'    => $shipOrderData->load([
                        'shipLineClients.client',
                        'shipLineClients.shippingLine',
                        'shipLineClients.destination',
                        'shipLineClients.shipLineClientFactories.factory',
                        'shipPolicies.shipContainersDetails',
                        'shipBookings.shipContainersDetails',
                        'shipBookings.clearanceData',
                        'shipContactData',
                    ]),
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to update ship order data',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Remove the specified Ship Order Data along with all related data.
     */
    public function destroy($id)
    {
        $user = auth()->user();
        try {

            if (!$user->hasRole('admin')) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            $shipOrderData = ShipOrderData::findOrFail($id);
            if ($shipOrderData->orderReceipts()->exists() || $shipOrderData->transportReceipt()->exists()) {
                return response()->json(['message' => 'Orders with receipt history cannot be deleted.'], 409);
            }

            DB::transaction(function () use ($shipOrderData) {

                // Delete related policies containers
                ShipContainersDetail::whereIn(
                    'policy_id',
                    ShipPolicy::where('ship_order_data_id', $shipOrderData->id)->pluck('id')
                )->delete();

                // Delete related booking containers
                ShipContainersDetail::whereIn(
                    'booking_id',
                    ShipBooking::where('ship_order_data_id', $shipOrderData->id)->pluck('id')
                )->delete();

                // Delete clearance data
                ClearanceData::whereIn(
                    'booking_id',
                    ShipBooking::where('ship_order_data_id', $shipOrderData->id)->pluck('id')
                )->delete();

                // Delete policies & bookings
                ShipPolicy::where('ship_order_data_id', $shipOrderData->id)->delete();
                ShipBooking::where('ship_order_data_id', $shipOrderData->id)->delete();

                // Delete factories
                ShipLineClientFactory::whereIn(
                    'ship_line_client_id',
                    ShipLineClient::where('ship_order_data_id', $shipOrderData->id)->pluck('id')
                )->delete();

                // Delete ship line client
                ShipLineClient::where('ship_order_data_id', $shipOrderData->id)->delete();

                // Delete contact data
                ShipContactData::where('ship_order_data_id', $shipOrderData->id)->delete();

                // Finally delete order
                $shipOrderData->delete();
            });

            return response()->json([
                'message' => 'Ship order deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete ship order',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
