<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OperatingOrder;
use App\Models\OperatingOrderVehicle;
use App\Models\OperatingOrderDriver;
use App\Models\TorrentContainer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class OperatingOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        try {
            $query = OperatingOrder::query()->with([
                'shipOrderData',
                'shipOrderData.policies',
                // Booking number (export) / inbound bill of lading number (import) and the
                // treasuries the order is charged to, shown in the executed-orders table.
                'shipOrderData.shipBookings:id,ship_order_data_id,booking_number',
                'shipOrderData.shipPolicies:id,ship_order_data_id,policy_number',
                'shipOrderData.treasuries:id,name,is_main',
                'drivers.driver',
                'vehicles.vehicle',
                'torrentContainers.container',
            ]);

            if (!($user->can('view_any operation_orders') || $user->hasRole('admin'))) {
                $query->whereHas('shipOrderData.treasuries', function ($q) use ($user) {
                    $q->whereIn('treasuries.id', $user->treasuries->pluck('id'));
                });
            }

            if ($request->filled('search')) {
                $search = trim($request->query('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('id', 'like', "%{$search}%")
                        ->orWhereHas('shipOrderData', function ($q) use ($search) {
                            $q->where('order_number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('shipOrderData.shipBookings', function ($q) use ($search) {
                            $q->where('booking_number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('shipOrderData.shipPolicies', function ($q) use ($search) {
                            $q->where('policy_number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('shipOrderData.policies', function ($q) use ($search) {
                            $q->where('policy_number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('shipOrderData.shipLineClients.client', function ($q) use ($search) {
                            $q->where('client_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('drivers.driver', function ($q) use ($search) {
                            $q->where('driver_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('vehicles.vehicle', function ($q) use ($search) {
                            $q->where('vehicle_number', 'like', "%{$search}%");
                        });
                });
            }

            $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
            $orders = $query
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($perPage);

            return response()->json($orders);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                // Operating Order Data
                'ship_order_data_id' => 'required|exists:ship_order_data,id',
                'is_operating_order' => 'required|boolean',
                'cause_note' => 'nullable|string',
                'operating_order_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'operating_order_location' => 'nullable|string',
                'operating_order_mail_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',

                // Torrents Data
                'is_torrents' => 'nullable|boolean',
                'torrents_cause_note' => 'nullable|string',
                'torrents_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'pull_torrents_date' => 'nullable|date',
                'load_torrents_date' => 'nullable|date',

                // Release and Assignment Data
                'release_and_assignment_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'release_and_assignment_requirements' => 'nullable|string',

                // Vehicles and Drivers Data
                'driver_ids' => 'nullable|array',
                'driver_ids.*' => 'exists:drivers,id',
                'vehicle_ids' => 'nullable|array',
                'vehicle_ids.*' => 'exists:vehicles,id',

                // Torrent Containers Data
                'torrent_containers' => 'nullable|array',
                'torrent_containers.*.container_id' => 'required|exists:ship_containers_details,id',
                'torrent_containers.*.torrent_number' => 'required|string',
            ]);

            // Operating Order Image
            if ($request->hasFile('operating_order_image')) {
                $image = $request->file('operating_order_image')->store('operating_order_images', 'public');
                $validatedData['operating_order_image'] = URL::to(Storage::url($image));
            }
            // operating_order_mail_image
            if ($request->hasFile('operating_order_mail_image')) {
                $image = $request->file('operating_order_mail_image')->store('operating_order_mail_images', 'public');
                $validatedData['operating_order_mail_image'] = URL::to(Storage::url($image));
            }
            // torrents_image
            if ($request->hasFile('torrents_image')) {
                $image = $request->file('torrents_image')->store('torrents_images', 'public');
                $validatedData['torrents_image'] = URL::to(Storage::url($image));
            }
            // release_and_assignment_image
            if ($request->hasFile('release_and_assignment_image')) {
                $image = $request->file('release_and_assignment_image')->store('release_and_assignment_images', 'public');
                $validatedData['release_and_assignment_image'] = URL::to(Storage::url($image));
            }
            return DB::transaction(function () use ($validatedData) {
                // Create Operating Order
                $operatingOrder = OperatingOrder::create([
                    'ship_order_data_id' => $validatedData['ship_order_data_id'],
                    'is_operating_order' => $validatedData['is_operating_order'],
                    'cause_note' => $validatedData['cause_note'] ?? null,
                    'operating_order_image' => $validatedData['operating_order_image'] ?? null,
                    'operating_order_location' => $validatedData['operating_order_location'] ?? null,
                    'operating_order_mail_image' => $validatedData['operating_order_mail_image'] ?? null,
                    'is_torrents' => $validatedData['is_torrents'] ?? false,
                    'torrents_cause_note' => $validatedData['torrents_cause_note'] ?? null,
                    'torrents_image' => $validatedData['torrents_image'] ?? null,
                    'pull_torrents_date' => $validatedData['pull_torrents_date'] ?? null,
                    'load_torrents_date' => $validatedData['load_torrents_date'] ?? null,
                    'release_and_assignment_image' => $validatedData['release_and_assignment_image'] ?? null,
                    'release_and_assignment_requirements' => $validatedData['release_and_assignment_requirements'] ?? null,
                ]);


                // Attach Drivers
                if (isset($validatedData['driver_ids'])) {
                    foreach ($validatedData['driver_ids'] as $driverId) {
                        OperatingOrderDriver::create([
                            'operating_order_id' => $operatingOrder->id,
                            'driver_id' => $driverId,
                        ]);
                    }
                }

                // Attach Vehicles
                if (isset($validatedData['vehicle_ids'])) {
                    foreach ($validatedData['vehicle_ids'] as $vehicleId) {
                        OperatingOrderVehicle::create([
                            'operating_order_id' => $operatingOrder->id,
                            'vehicle_id' => $vehicleId,
                        ]);
                    }
                }

                // Create Torrent Containers
                if (isset($validatedData['torrent_containers'])) {
                    foreach ($validatedData['torrent_containers'] as $torrentContainer) {
                        TorrentContainer::create([
                            'operating_order_id' => $operatingOrder->id,
                            'container_id' => $torrentContainer['container_id'],
                            'torrent_number' => $torrentContainer['torrent_number'],
                        ]);
                    }
                }

                return response()->json([
                    'message' => 'Operating order created successfully',
                    'data' => $operatingOrder->load([
                        'shipOrderData',
                        'drivers.driver',
                        'vehicles.vehicle',
                        'torrentContainers.container',
                    ])
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create operating order',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($operating_order)
    {
        $order = OperatingOrder::with([
            'shipOrderData',
            'shipOrderData.shipLineClients',
            'shipOrderData.shipPolicies',
            'shipOrderData.shipBookings',
            'shipOrderData.shipContactData',
            'drivers.driver',
            'vehicles.vehicle',
            'torrentContainers.container',
        ])->findOrFail($operating_order);

        return response()->json($order);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $operating_order)
    {
        try {
            $order = OperatingOrder::findOrFail($operating_order);

            $validatedData = $request->validate([
                // Operating Order Data
                'is_operating_order' => 'sometimes|boolean',
                'cause_note' => 'nullable|string',
                'operating_order_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'operating_order_location' => 'nullable|string',
                'operating_order_mail_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',

                // Torrents Data
                'is_torrents' => 'sometimes|boolean',
                'torrents_cause_note' => 'nullable|string',
                'torrents_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'pull_torrents_date' => 'nullable|date',
                'load_torrents_date' => 'nullable|date',

                // Release and Assignment Data
                'release_and_assignment_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'release_and_assignment_requirements' => 'nullable|string',

                // Vehicles and Drivers Data
                'driver_ids' => 'sometimes|array',
                'driver_ids.*' => 'exists:drivers,id',

                'vehicle_ids' => 'nullable|array',
                'vehicle_ids.*' => 'exists:vehicles,id',

                // Torrent Containers Data
                'torrent_containers' => 'sometimes|array',
                'torrent_containers.*.container_id' => 'required|exists:ship_containers_details,id',
                'torrent_containers.*.torrent_number' => 'required|string',
            ]);

            // Operating Order Image
            if ($request->hasFile('operating_order_image')) {
                $image = $request->file('operating_order_image')->store('operating_order_images', 'public');
                $validatedData['operating_order_image'] = URL::to(Storage::url($image));
            }
            // operating_order_mail_image
            if ($request->hasFile('operating_order_mail_image')) {
                $image = $request->file('operating_order_mail_image')->store('operating_order_mail_images', 'public');
                $validatedData['operating_order_mail_image'] = URL::to(Storage::url($image));
            }
            // torrents_image
            if ($request->hasFile('torrents_image')) {
                $image = $request->file('torrents_image')->store('torrents_images', 'public');
                $validatedData['torrents_image'] = URL::to(Storage::url($image));
            }
            // release_and_assignment_image
            if ($request->hasFile('release_and_assignment_image')) {
                $image = $request->file('release_and_assignment_image')->store('release_and_assignment_images', 'public');
                $validatedData['release_and_assignment_image'] = URL::to(Storage::url($image));
            }

            return DB::transaction(function () use ($order, $validatedData) {
                // Update Operating Order
                $order->update([
                    'is_operating_order' => $validatedData['is_operating_order'] ?? $order->is_operating_order,
                    'cause_note' => $validatedData['cause_note'] ?? $order->cause_note,
                    'operating_order_image' => $validatedData['operating_order_image'] ?? $order->operating_order_image,
                    'operating_order_location' => $validatedData['operating_order_location'] ?? $order->operating_order_location,
                    'operating_order_mail_image' => $validatedData['operating_order_mail_image'] ?? $order->operating_order_mail_image,
                    'is_torrents' => $validatedData['is_torrents'] ?? $order->is_torrents,
                    'torrents_cause_note' => $validatedData['torrents_cause_note'] ?? $order->torrents_cause_note,
                    'torrents_image' => $validatedData['torrents_image'] ?? $order->torrents_image,
                    'pull_torrents_date' => $validatedData['pull_torrents_date'] ?? $order->pull_torrents_date,
                    'load_torrents_date' => $validatedData['load_torrents_date'] ?? $order->load_torrents_date,
                    'release_and_assignment_image' => $validatedData['release_and_assignment_image'] ?? $order->release_and_assignment_image,
                    'release_and_assignment_requirements' => $validatedData['release_and_assignment_requirements'] ?? $order->release_and_assignment_requirements,
                ]);

                // Sync Drivers if provided
                if (isset($validatedData['driver_ids'])) {
                    $order->drivers()->delete();
                    foreach ($validatedData['driver_ids'] as $driverId) {
                        OperatingOrderDriver::create([
                            'operating_order_id' => $order->id,
                            'driver_id' => $driverId,
                        ]);
                    }
                }

                // Sync Vehicles if provided
                if (isset($validatedData['vehicle_ids'])) {
                    $order->vehicles()->delete();
                    foreach ($validatedData['vehicle_ids'] as $vehicleId) {
                        OperatingOrderVehicle::create([
                            'operating_order_id' => $order->id,
                            'vehicle_id' => $vehicleId,
                        ]);
                    }
                }

                // Sync Torrent Containers if provided
                if (isset($validatedData['torrent_containers'])) {
                    $order->torrentContainers()->delete();
                    foreach ($validatedData['torrent_containers'] as $torrentContainer) {
                        TorrentContainer::create([
                            'operating_order_id' => $order->id,
                            'container_id' => $torrentContainer['container_id'],
                            'torrent_number' => $torrentContainer['torrent_number'],
                        ]);
                    }
                }

                return response()->json([
                    'message' => 'Operating order updated successfully',
                    'data' => $order->load([
                        'shipOrderData',
                        'drivers.driver',
                        'vehicles.vehicle',
                        'torrentContainers.container',
                    ])
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update operating order',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy($operating_order)
    {
        $user = auth()->user();
        try {

            if (!$user->hasRole('admin')) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            $order = OperatingOrder::findOrFail($operating_order);

            DB::transaction(function () use ($order) {
                $order->drivers()->delete();
                $order->vehicles()->delete();
                $order->torrentContainers()->delete();
                $order->delete();
            });

            return response()->json([
                'message' => 'Operating order deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete operating order',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
