<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class Policy extends Model
{
    // Deleted policies are soft-deleted and hidden from every query by default.
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'user_id',
        'ship_order_data_id',
        'operating_order_id',
        'policy_number',
        'covenant_amount',
        'policy_type',
        'policy_aging_date',
        'policy_loading_date',
        'settled',
        'clearance_date',
        'settled_user'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('policies');
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->description = class_basename($this) . " {$eventName}";
    }

    protected $casts = [
        'policy_type' => 'boolean',
        'policy_aging_date' => 'string',
        'policy_loading_date' => 'string',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function settled_user()
    {
        return $this->belongsTo(User::class, 'settled_user');
    }
    public function transportReceipts()
    {
        return $this->hasOne(TransportReceipt::class);
    }
    public function shipOrderData()
    {
        return $this->belongsTo(ShipOrderData::class);
    }

    public function operatingOrder()
    {
        return $this->belongsTo(OperatingOrder::class);
    }

    public function vehicleDriverAssignments()
    {
        return $this->hasOne(VehicleDriverAssignment::class);
    }

    public static function generatePolicyNumber()
    {
        // Include deleted policies so a deleted policy's number is never reused (it stays unique).
        $lastPolicy = static::withTrashed()->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        $nextNumber = $lastPolicy
            ? ((int) substr($lastPolicy->policy_number, strrpos($lastPolicy->policy_number, '-') + 1)) + 1
            : 1;

        return sprintf("BL-%05d", $nextNumber); // BL-00001, BL-00002, ... BL-99999
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function ($policy) {
            if (empty($policy->policy_number)) {
                $policy->policy_number = self::generatePolicyNumber();
            }
        });
    }
}
