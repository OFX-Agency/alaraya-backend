<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ShipOrderData;
use App\Models\ShareLink;
use App\Models\Client;
use App\Models\Vehicle;
use App\Models\Drivers;
use App\Models\ShippingLine;
use App\Models\Destination;
use App\Models\OperatingOrder;

class ReportsController extends Controller
{
    public function vehicleReport($number)
    {
        try {
            $searchValue = $number;

            // Check if ship order exists
            $shipOrder = ShipOrderData::where('order_number', 'LIKE', "%{$searchValue}%")->first();
            if (!$shipOrder) {
                return response()->json(['error' => 'Ship order not found'], 404);
            }

            $report = ShipOrderData::query()
                ->where('order_number', 'LIKE', "%{$searchValue}%")
                ->with('operatingOrder')
                ->with('operatingOrder.vehicles.vehicle')
                ->with('operatingOrder.drivers.driver')
                ->with('policies.vehicleDriverAssignments.vehicle')
                ->with('policies.vehicleDriverAssignments.driver')
                ->with('policies.vehicleDriverAssignments.shipContainers.torrentContainers')
                ->with('shipLineClients.client')
                ->with('shipLineClients.shipLineClientFactories.factory')
                ->get();
            return response()->json(
                [
                    "ship_orders_data" => $report,
                    "ship_order_type" => $shipOrder->getShipOrderType(),
                ],
                200
            );
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function torrentsReports($number)
    {
        try {
            $searchValue = $number;
            // Check if ship order exists
            $shipOrder = ShipOrderData::where('order_number', 'LIKE', "%{$searchValue}%")->first();
            if (!$shipOrder) {
                return response()->json(['error' => 'Ship order not found'], 404);
            }

            $report = ShipOrderData::query()
                ->where('order_number', 'LIKE', "%{$searchValue}%")
                ->with('policies.vehicleDriverAssignments.shipContainers.torrentContainers')
                ->with('operatingOrder')
                ->with('operatingOrder.torrentContainers.container')
                ->with('shipLineClients.client')
                ->with('shipLineClients.shipLineClientFactories.factory')
                ->get();
            return response()->json(
                [
                    "ship_orders_data" => $report,
                    "ship_order_type" => $shipOrder->getShipOrderType(),
                ],
                200
            );
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function LoadingWithdrawalReport($number)
    {
        try {
            $searchValue = $number;

            // Check if ship order exists
            $shipOrder = ShipOrderData::where('order_number', 'LIKE', "%{$searchValue}%")->first();
            if (!$shipOrder) {
                return response()->json(['error' => 'Ship order not found'], 404);
            }

            $report = ShipOrderData::query()
                ->where('order_number', 'LIKE', "%{$searchValue}%")
                ->with('operatingOrder.vehicles.vehicle')
                ->with('operatingOrder.drivers.driver')
                ->with('operatingOrder.torrentContainers')
                ->with('operatingOrder.torrentContainers.container')
                ->with('shipLineClients.shipLineClientFactories.factory')
                ->with('shipLineClients.client')
                ->get();
            return response()->json(
                [
                    "ship_orders_data" => $report,
                    "ship_order_type" => $shipOrder->getShipOrderType(),
                ],
                200
            );
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function clientAccountStatements(Request $request)
    {
        try {
            // Search ship orders by order number and/or client name
            $query = ShipOrderData::with([
                'shipPolicies',
                'shipBookings',
                'shipLineClients.client',
                'shipLineClients.shipLineClientFactories.factory',
                'operatingOrder',
                'policies.vehicleDriverAssignments.vehicle',
                'policies.vehicleDriverAssignments.driver',
                'policies.vehicleDriverAssignments.driverExtras',
                'policies.transportReceipts' => fn ($q) => $q->whereNull('cancelled_at')->whereNull('migrated_to_order_receipt_at'),
                'policies',
                'transportReceipt' => fn ($q) => $q->whereNull('cancelled_at')->whereNull('migrated_to_order_receipt_at'),
                'orderReceipts.items',
                'orderReceipts.client'
            ]);

            if ($request->user() && !$request->user()->hasRole('admin')) {
                $query->whereHas('treasuries', fn ($q) => $q->whereIn('treasuries.id', $request->user()->treasuries()->pluck('treasuries.id')));
            }
            $number = $request->number;
            $clientName = $request->clientName;

            // Apply filters if parameters are provided
            if (!empty($number)) {
                $query->where('order_number', 'LIKE', "%{$number}%");
            }

            if (!empty($clientName)) {
                $query->whereHas('shipLineClients.client', function ($query) use ($clientName) {
                    $query->where('client_name', 'LIKE', "%{$clientName}%");
                });
            }

            if ($request->filled('client_id')) {
                $query->whereHas('shipLineClients', fn ($q) => $q->where('client_id', $request->client_id));
            }
            $shipOrders = $query->get();

            if ($shipOrders->isEmpty()) {
                return response()->json([
                    'error' => 'No ship orders found for the given order number and client name'
                ], 404);
            }

            // Initialize totals
            $totalShipOrdersCount = $shipOrders->count();
            $totalOperatingOrders = 0;
            $totalOrdersNoloans = 0;
            $totalTransportReceiptsSum = 0;
            $totalOrderReceiptsSum = 0;
            $totalNoloanSum = 0;
            $totalDriverExtrasSum = 0;
            $totalCovenantAmountSum = 0;

            $shipOrdersDetails = [];

            foreach ($shipOrders as $shipOrder) {
                $orderReceipts = $shipOrder->orderReceipts->filter(function ($receipt) use ($clientName, $request) {
                    if ($request->filled('client_id') && $receipt->client_id != $request->client_id) return false;
                    return empty($clientName) || mb_stripos($receipt->client->client_name ?? '', $clientName) !== false;
                })->values();
                $orderReceiptsSum = $orderReceipts->where('status', 'posted')->sum(fn ($r) => \App\Services\ReceiptAccounting::cents($r->total));
                $totalOrderReceiptsSum += $orderReceiptsSum;
                // Count operating orders
                $operatingOrdersCount = $shipOrder->operatingOrder ? 1 : 0;
                $totalOperatingOrders += $operatingOrdersCount;

                // Add noloans
                $noloans = $shipOrder->noloans ?? 0;
                $totalOrdersNoloans += $noloans;

                // Calculate transport receipts sum for this ship order
                $transportReceiptsSum = 0;
                if ($shipOrder->transportReceipt) {
                    foreach ($shipOrder->transportReceipt as $receipt) {
                        $transportReceiptsSum +=
                            ($receipt->army_scales ?? 0) +
                            ($receipt->roads_and_bridges ?? 0) +
                            ($receipt->road_cards ?? 0) +
                            ($receipt->governorate_voucher ?? 0) +
                            ($receipt->tips ?? 0) +
                            ($receipt->official_receipts ?? 0) +
                            ($receipt->overnight_leave ?? 0) +
                            ($receipt->tarif_receipts ?? 0) +
                            ($receipt->third_party_car_rental ?? 0) +
                            ($receipt->customs_clearance ?? 0) +
                            ($receipt->bill_of_lading_amendment ?? 0) +
                            ($receipt->third_party_vehicle_leave ?? 0) +
                            ($receipt->brokers ?? 0) +
                            ($receipt->vgm ?? 0) +
                            ($receipt->x_ray ?? 0) +
                            ($receipt->data_entry ?? 0) +
                            ($receipt->yard_receipts ?? 0) +
                            ($receipt->port_authority_receipts ?? 0) +
                            ($receipt->port_weight_fees ?? 0) +
                            ($receipt->agency_receipts ?? 0) +
                            ($receipt->explosives_receipt ?? 0) +
                            ($receipt->bascule_scale_receipt ?? 0) +
                            ($receipt->cashier_receipt ?? 0) +
                            ($receipt->reweighing_receipt ?? 0) +
                            ($receipt->sina_marine_receipts ?? 0) +
                            ($receipt->tunnel_ferry_receipts ?? 0) +
                            ($receipt->container_repair_receipt ?? 0) +
                            ($receipt->x_ray_leave ?? 0) +
                            ($receipt->allocation ?? 0) +
                            ($receipt->guarantee ?? 0) +
                            ($receipt->form ?? 0) +
                            ($receipt->port_receipts ?? 0);
                    }
                }
                $totalTransportReceiptsSum += $transportReceiptsSum;

                // =========================
                // FIX: vehicleDriverAssignments is a hasMany relation (a Collection),
                // not a single model. Must loop over each assignment.
                // GUARD: some rows can resolve this to a non-Collection (e.g. bool)
                // if there's an attribute/relation name collision on the Policy
                // model — coerce to an empty collection instead of crashing.
                // =========================
                $driverExtrasSum = 0;
                foreach ($shipOrder->policies as $policy) {
                    $assignments = $this->safeAssignments($policy);
                    foreach ($assignments as $assignment) {
                        if ($assignment->driverExtras) {
                            foreach ($assignment->driverExtras as $extra) {
                                $driverExtrasSum += ((float) $extra->extra_amount);
                            }
                        }
                    }
                }
                $totalDriverExtrasSum += $driverExtrasSum;

                // Calculate covenant amount sum for this ship order
                $covenantAmountSum = 0;
                foreach ($shipOrder->policies as $policy) {
                    $covenantAmountSum += ($policy->covenant_amount ?? 0);
                }
                $totalCovenantAmountSum += $covenantAmountSum;

                // Add to total noloan sum
                $totalNoloanSum += $noloans;

                // Prepare ship order details
                $shipOrdersDetails[] = [
                    'order_number' => $shipOrder->order_number,
                    'order_id' => $shipOrder->id,
                    'order_type' => $shipOrder->order_type,
                    'orderBooking' => $shipOrder->shipBookings->map(function ($booking) {
                        return [
                            'booking_number' => $booking->booking_number,
                            'booking_date' => $booking->booking_date,
                        ];
                    }),
                    'orderPolicy' => $shipOrder->shipPolicies->map(function ($policy) {
                        return [
                            'policy_number' => $policy->policy_number,
                            'policy_date' => $policy->policy_date,
                        ];
                    }),
                    'noloans' => $noloans,
                    'shipping_date' => $shipOrder->shipping_date,
                    'aging_date' => $shipOrder->aging_date,
                    'containers_type' => $shipOrder->containers_type,
                    'containers_number' => $shipOrder->containers_number,
                    'loading_way' => $shipOrder->loading_way,
                    'transfers_count' => $shipOrder->transfers_count,
                    'has_operating_order' => $operatingOrdersCount > 0,
                    'transport_receipts_sum' => $transportReceiptsSum,
                    'order_receipts' => $orderReceipts,
                    'order_receipts_sum' => \App\Services\ReceiptAccounting::decimal($orderReceiptsSum),
                    'driver_extras_total' => $driverExtrasSum,
                    'covenant_amount_total' => $covenantAmountSum,
                    'transportReceipt' => ($shipOrder->transportReceipt ?? collect())->map(function ($transportReceipt) {
                        $policy = $transportReceipt->policy;
                        return [
                            'policy_number' => $policy->policy_number ?? null,
                            'covenant_amount' => $policy->covenant_amount ?? null,
                            'clearance_date' => $policy->clearance_date ?? null,
                            'transport_receipt_details' => [
                                'army_scales' => $transportReceipt->army_scales,
                                'roads_and_bridges' => $transportReceipt->roads_and_bridges,
                                'road_cards' => $transportReceipt->road_cards,
                                'governorate_voucher' => $transportReceipt->governorate_voucher,
                                'tips' => $transportReceipt->tips,
                                'official_receipts' => $transportReceipt->official_receipts,
                                'overnight_leave' => $transportReceipt->overnight_leave,
                                'tarif_receipts' => $transportReceipt->tarif_receipts,
                                'third_party_car_rental' => $transportReceipt->third_party_car_rental,
                                'customs_clearance' => $transportReceipt->customs_clearance,
                                'bill_of_lading_amendment' => $transportReceipt->bill_of_lading_amendment,
                                'third_party_vehicle_leave' => $transportReceipt->third_party_vehicle_leave,
                                'brokers' => $transportReceipt->brokers,
                                'vgm' => $transportReceipt->vgm,
                                'x_ray' => $transportReceipt->x_ray,
                                'data_entry' => $transportReceipt->data_entry,
                                'yard_receipts' => $transportReceipt->yard_receipts,
                                'port_authority_receipts' => $transportReceipt->port_authority_receipts,
                                'port_weight_fees' => $transportReceipt->port_weight_fees,
                                'agency_receipts' => $transportReceipt->agency_receipts,
                                'explosives_receipt' => $transportReceipt->explosives_receipt,
                                'bascule_scale_receipt' => $transportReceipt->bascule_scale_receipt,
                                'cashier_receipt' => $transportReceipt->cashier_receipt,
                                'reweighing_receipt' => $transportReceipt->reweighing_receipt,
                                'sina_marine_receipts' => $transportReceipt->sina_marine_receipts,
                                'tunnel_ferry_receipts' => $transportReceipt->tunnel_ferry_receipts,
                                'container_repair_receipt' => $transportReceipt->container_repair_receipt,
                                'x_ray_leave' => $transportReceipt->x_ray_leave,
                                'allocation' => $transportReceipt->allocation,
                                'guarantee' => $transportReceipt->guarantee,
                                'form' => $transportReceipt->form,
                                'port_receipts' => $transportReceipt->port_receipts,
                            ]
                        ];
                    }),
                    // =========================
                    // FIX: flatMap over each policy's assignments (plural relation)
                    // instead of treating the relation as a single record.
                    // GUARD: same non-Collection coercion as above.
                    // =========================
                    'vehicle_driver_assignments' => $shipOrder->policies->flatMap(function ($policy) {
                        $assignments = $this->safeAssignments($policy);
                        return $assignments->map(function ($assignment) {
                            return [
                                'id' => $assignment->id,
                                'vehicle_info' => $assignment->vehicle ?? null,
                                'driver_info' => $assignment->driver ?? null,
                                'driver_extras' => $assignment->driverExtras ?? null,
                            ];
                        });
                    })->unique('id')->values(),
                    'clients' => $shipOrder->shipLineClients->map(function ($shipLineClient) {
                        return [
                            'client_name' => $shipLineClient->client->client_name ?? null,
                            'contact_number' => $shipLineClient->client->contact_number ?? null,
                            'factory_name' => $shipLineClient->shipLineClientFactories->map(function ($f) {
                                return $f->factory->factory_name ?? null;
                            })->filter()->implode(', ') ?: null,
                        ];
                    })
                ];
            }

            // Calculate net amount
            $netAmount = ($totalNoloanSum - $totalCovenantAmountSum) + $totalDriverExtrasSum + ($totalOrderReceiptsSum / 100);

            return response()->json([
                'success' => true,
                'data' => [
                    'ship_orders_count' => $totalShipOrdersCount,
                    'operating_orders' => $totalOperatingOrders,
                    'total_orders_noloans' => $totalOrdersNoloans,
                    'ship_orders_details' => $shipOrdersDetails,
                    'total_sum_transport_receipts' => $totalTransportReceiptsSum,
                    'total_sum_order_receipts' => \App\Services\ReceiptAccounting::decimal($totalOrderReceiptsSum),
                    'total_sum_noloan' => $totalNoloanSum,
                    'total_driver_extras' => $totalDriverExtrasSum,
                    'total_covenant_amount' => $totalCovenantAmountSum,
                    'net_amount' => $netAmount,
                    'policies' => $shipOrders->pluck('policies')->flatten()->unique('id')->values()
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Failed to generate client account statements',
                'message' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile(),
            ], 500);
        }
    }

    public function vehicleStatement(Request $request)
    {
        try {
            // Parse each filter independently — none of them should depend on another being present.
            $isCleared = $request->has('is_cleared')
                ? filter_var($request->input('is_cleared'), FILTER_VALIDATE_BOOLEAN)
                : null;

            $fromDate = $request->filled('from_date') ? $request->input('from_date') : null;
            $toDate = $request->filled('to_date') ? $request->input('to_date') : null;
            $vehicleNumber = $request->filled('vehicle_number') ? $request->input('vehicle_number') : null;
            $search = $request->filled('search') ? trim($request->query('search')) : null;

            // Pick which date column to range on: if the caller told us cleared/not-cleared,
            // use the column that makes sense for that state. Otherwise default to created_at.
            $dateColumn = $isCleared === true ? 'clearance_date' : 'created_at';

            $applyFilters = function ($query) use ($isCleared, $fromDate, $toDate, $vehicleNumber, $dateColumn) {
                if (!is_null($isCleared)) {
                    if ($isCleared === true) {
                        $query->whereNotNull('clearance_date');
                    } else {
                        $query->whereNull('clearance_date');
                    }
                }

                if ($fromDate && $toDate) {
                    $query->whereBetween($dateColumn, [$fromDate, $toDate]);
                }

                if ($vehicleNumber) {
                    $query->whereHas('vehicleDriverAssignments.vehicle', function ($q) use ($vehicleNumber) {
                        $q->where('vehicle_number', 'like', "%{$vehicleNumber}%");
                    });
                }
            };

            $query = ShipOrderData::with([
                'policies' => function ($query) use ($applyFilters) {
                    $applyFilters($query);

                    $query->with([
                        'user',
                        'settled_user',
                        'transportReceipts',
                        'vehicleDriverAssignments',
                        'vehicleDriverAssignments.vehicle',
                        'vehicleDriverAssignments.driver',
                        'vehicleDriverAssignments.driverExtras',
                    ]);
                },
                'shipLineClients.client',
                'shipLineClients.shippingLine',
                'shipLineClients.destination',
                'shipLineClients.shipLineClientFactories.factory',
            ]);

            // A vehicle statement is policy-based. Always require at least one policy
            // matching the active filters so unassigned ship orders do not affect the
            // unfiltered report while disappearing from both clearance states.
            $query->whereHas('policies', $applyFilters);

            if ($search !== null && $search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('ship_order_data.id', 'like', "%{$search}%")
                        ->orWhere('order_number', 'like', "%{$search}%")
                        ->orWhereHas('shipLineClients.client', function ($q) use ($search) {
                            $q->where('client_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('policies', function ($q) use ($search) {
                            $q->where('policy_number', 'like', "%{$search}%")
                                ->orWhereHas('vehicleDriverAssignments.driver', function ($q) use ($search) {
                                    $q->where('driver_name', 'like', "%{$search}%");
                                })
                                ->orWhereHas('vehicleDriverAssignments.vehicle', function ($q) use ($search) {
                                    $q->where('vehicle_number', 'like', "%{$search}%");
                                });
                        });
                });
            }

            $totalNoloanSum = 0;
            $totalCovenantAmountSum = 0;
            $totalDriverExtrasSum = 0;

            // Preserve report totals across all matching orders while loading them in bounded batches.
            $totalsQuery = clone $query;
            $totalsQuery->setEagerLoads([
                'policies' => function ($query) use ($applyFilters) {
                    $applyFilters($query);
                    $query->with('vehicleDriverAssignments.driverExtras');
                },
            ]);
            $totalsQuery->chunkById(100, function ($shipOrders) use (&$totalNoloanSum, &$totalCovenantAmountSum, &$totalDriverExtrasSum) {
                foreach ($shipOrders as $shipOrder) {
                    foreach ($shipOrder->policies as $policy) {
                        // Settlement applies the ship-order noloan amount to each policy,
                        // so aggregate it at the same level as covenant and driver extras.
                        // This also makes cleared + uncleared totals reconcile with "all"
                        // when an order contains policies in both states.
                        $totalNoloanSum += $shipOrder->noloans ?? 0;
                        $totalCovenantAmountSum += $policy->covenant_amount ?? 0;
                        foreach ($this->safeAssignments($policy) as $assignment) {
                            foreach ($assignment->driverExtras ?? [] as $extra) {
                                $totalDriverExtrasSum += $extra->extra_amount ?? 0;
                            }
                        }
                    }
                }
            }, 'ship_order_data.id', 'id');

            $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
            $page = $query->orderBy('ship_order_data.id')->paginate($perPage);

            $vehiclesWithExtras = $page->getCollection()->map(function ($shipOrder) {
                $driverExtrasSum = 0;
                $covenantAmountSum = 0;
                $noloans = $shipOrder->noloans ?? 0;

                foreach ($shipOrder->policies as $policy) {
                    $policyDriverExtrasSum = 0;
                    $policyCovenantAmount = ($policy->covenant_amount ?? 0);
                    $covenantAmountSum += $policyCovenantAmount;

                    $assignments = $this->safeAssignments($policy);
                    foreach ($assignments as $assignment) {
                        if ($assignment->driverExtras) {
                            foreach ($assignment->driverExtras as $extra) {
                                $extraAmount = ($extra->extra_amount ?? 0);
                                $driverExtrasSum += $extraAmount;
                                $policyDriverExtrasSum += $extraAmount;
                            }
                        }
                    }

                    $policy->net_amount = ($noloans - $policyCovenantAmount) + $policyDriverExtrasSum;
                }

                $shipOrder->driver_extras_total = $driverExtrasSum;
                $shipOrder->covenant_amount_total = $covenantAmountSum;
                $shipOrder->noloans = $noloans;

                return $shipOrder;
            });

            $netAmount = ($totalNoloanSum - $totalCovenantAmountSum) + $totalDriverExtrasSum;

            return response()->json([
                'success' => true,
                'data' => $vehiclesWithExtras,
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                ],
                'totals' => [
                    'total_noloan' => $totalNoloanSum,
                    'total_covenant_amount' => $totalCovenantAmountSum,
                    'total_driver_extras' => $totalDriverExtrasSum,
                    'net_amount' => $netAmount
                ]
            ], 200)
                ->header('X-Current-Page', $page->currentPage())
                ->header('X-Last-Page', $page->lastPage())
                ->header('X-Per-Page', $page->perPage())
                ->header('X-Total-Count', $page->total());
        } catch (\Throwable $th) {
            \Log::error('Vehicle Statement Error:', [
                'message' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to generate vehicle statement',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Safely resolve a policy's vehicleDriverAssignments relation as a Collection.
     * Guards against cases where this resolves to a non-Collection (e.g. a bool,
     * which happens if an attribute/relation name collision shadows the relation
     * on the Policy model) instead of throwing "Attempt to read property on bool".
     */
    private function safeAssignments($policy)
    {
        $assignments = $policy->vehicleDriverAssignments ?? null;

        if ($assignments instanceof \Illuminate\Support\Collection) {
            return $assignments;
        }

        if ($assignments instanceof \App\Models\VehicleDriverAssignment) {
            // Shouldn't happen after hasMany fix, but don't lose real data if it does
            return collect([$assignments]);
        }

        return collect();
    }

    public function GenerateShareLink(Request $request)
    {
        try {
            $request->validate([
                'type' => 'required|string',
                'body' => 'required|array',
            ]);

            $serialNumber = ShareLink::generateSerialNumber();
            $link = ShareLink::create([
                'serial_number' => $serialNumber,
                'user_id' => auth()->id(),
                'type' => $request->type,
                'body' => json_encode($request->body),
            ]);

            return response()->json([
                'serial' => $link->serial_number,
                'url' => "https://port-operations-management-system.vercel.app/reports/share-links/" . $link->serial_number,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Failed to generate share link',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getSharedReport(Request $request, $serial)
    {
        try {
            $link = ShareLink::where('serial_number', $serial)->firstOrFail();

            // Ensure body is an array
            $body = is_array($link->body) ? $link->body : (is_string($link->body) ? json_decode($link->body, true) : []);

            $number = $body['number'] ?? '';
            $number = intval($number);
            $request->merge($body); // now $request has all body fields
            switch ($link->type) {
                case 'vehicle_report':
                    $report = $this->vehicleReport($number ?? '');
                    break;
                case 'torrents_report':
                    $report = $this->torrentsReports($number ?? '');
                    break;
                case 'loading_withdrawal_report':
                    $report = $this->LoadingWithdrawalReport($number ?? '');
                    break;
                case 'client_account_statements':
                    $report = $this->clientAccountStatements($request);
                    break;
                case 'vehicle_statement':
                    $report = $this->vehicleStatement($request);
                    break;
                default:
                    return response()->json(['error' => 'Invalid report type'], 400);
            }

            return response()->json([
                'success' => true,
                'report_type' => $link->type,
                'data' => $report->getData()
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $th) {
            return response()->json([
                'error' => 'Share link not found',
                'message' => 'The provided serial number does not exist'
            ], 404);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Failed to retrieve shared report',
                'message' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile(),
            ], 500);
        }
    }

    public function dashboard()
    {
        $clients = Client::all()->count();
        $vehicles = Vehicle::all()->count();
        $drivers = Drivers::all()->count();
        $shiplines = ShippingLine::all()->count();
        $destinations = Destination::all()->count();
        $opertatingOrders = OperatingOrder::all()->count();

        $shipOrders = ShipOrderData::all();
        $shipOrderCount = $shipOrders->count();
        $todayShipOrders = $shipOrders->where('created_at', '>=', now()->startOfDay());
        $yesterdayShipOrders = $shipOrders->where('created_at', '>=', now()->subDay()->startOfDay())->where('created_at', '<', now()->startOfDay());

        return response()->json([
            'clients' => $clients,
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'shipOrders' => $shipOrderCount,
            'shiplines' => $shiplines,
            'destinations' => $destinations,
            'opertatingOrders' => $opertatingOrders,
            'todayShipOrders' => $todayShipOrders,
            'yesterdayShipOrders' => $yesterdayShipOrders,
            'todayShipOrdersCount' => $todayShipOrders->count(),
            'yesterdayShipOrdersCount' => $yesterdayShipOrders->count(),
        ]);
    }
}
