<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class ShipOrderData extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'order_number',
        'order_type',
        'client_requirements',
        'noloans',
        'shipping_date',
        'aging_date',
        'notes',
        'containers_type',
        'containers_number',
        'loading_way',
        'transfers_count',
        'handel_way',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('ship_orders');
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->description = class_basename($this) . " {$eventName}";
    }
    public function treasuries()
    {
        return $this->belongsToMany(
            Treasury::class,
            'order_treasury'
        );
    }
    public function orderReceipts()
    {
        return $this->hasMany(OrderReceipt::class, 'ship_order_id');
    }

    public function transportReceipt()
    {
        return $this->hasMany(TransportReceipt::class, 'ship_order_id', 'id');
    }
    public function operatingOrder()
    {
        return $this->hasOne(OperatingOrder::class);
    }
    public function shipLineClients()
    {
        return $this->hasMany(ShipLineClient::class, 'ship_order_data_id');
    }

    public function shipPolicies()
    {
        return $this->hasMany(ShipPolicy::class, 'ship_order_data_id');
    }

    public function shipBookings()
    {
        return $this->hasMany(ShipBooking::class, 'ship_order_data_id');
    }

    public function shipContactData()
    {
        return $this->hasOne(ShipContactData::class, 'ship_order_data_id');
    }

    public function getShipOrderType()
    {
        if ($this->shipPolicies()->exists()) {
            return $this->shipPolicies()->first();
        } elseif ($this->shipBookings()->exists()) {
            return $this->shipBookings()->first();
        }

        return null;
    }
    public function policies()
    {
        return $this->hasMany(Policy::class);
    }
    public function canCreateMorePolicies()
    {
        return $this->policies()->count() < $this->transfers_count;
    }

    public function remainingPolicySlots()
    {
        return max(0, $this->transfers_count - $this->policies()->count());
    }
}
