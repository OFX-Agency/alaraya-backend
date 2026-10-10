<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class TransportReceipt extends Model
{
    use HasFactory, LogsActivity;

    public const EXPENSE_FIELDS = ['army_scales', 'roads_and_bridges', 'road_cards', 'governorate_voucher', 'tips', 'official_receipts', 'overnight_leave', 'tarif_receipts', 'third_party_car_rental', 'customs_clearance', 'bill_of_lading_amendment', 'third_party_vehicle_leave', 'brokers', 'vgm', 'x_ray', 'data_entry', 'yard_receipts', 'port_authority_receipts', 'port_weight_fees', 'agency_receipts', 'explosives_receipt', 'bascule_scale_receipt', 'cashier_receipt', 'reweighing_receipt', 'sina_marine_receipts', 'tunnel_ferry_receipts', 'container_repair_receipt', 'port_receipts', 'x_ray_leave', 'allocation', 'guarantee', 'form'];

    protected $casts = ['cancelled_at' => 'datetime'];

    protected $fillable = [
        'treasury_id', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'migrated_to_order_receipt_at',
        'ship_order_id',
        'policy_id',
        'army_scales',
        'roads_and_bridges',
        'road_cards',
        'governorate_voucher',
        'tips',
        'official_receipts',
        'overnight_leave',
        'tarif_receipts',
        'third_party_car_rental',
        'customs_clearance',
        'bill_of_lading_amendment',
        'third_party_vehicle_leave',
        'brokers',
        'vgm',
        'x_ray',
        'data_entry',
        'yard_receipts',
        'port_authority_receipts',
        'port_weight_fees',
        'agency_receipts',
        'explosives_receipt',
        'bascule_scale_receipt',
        'cashier_receipt',
        'reweighing_receipt',
        'sina_marine_receipts',
        'tunnel_ferry_receipts',
        'container_repair_receipt',
        'port_receipts',
        'x_ray_leave',
        'allocation',
        'guarantee',
        'form',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('transport_receipts');
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->description = class_basename($this) . " {$eventName}";
    }

    /**
     * Relationship: Expense belongs to Ship Order
     */
    public function shipOrder()
    {
        return $this->belongsTo(ShipOrderData::class, 'ship_order_id');
    }

    /**
     * Relationship: Expense belongs to Ship Policy
     */
    public function policy()
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }
}
