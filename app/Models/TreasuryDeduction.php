<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class TreasuryDeduction extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'order_receipt_id',
        'transport_receipt_id',
        'user_id',
        'treasury_id',
        'amount',
        'reason',
        'type',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('treasury_deductions');
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->description = class_basename($this) . " {$eventName}";
    }

    public function treasury()
    {
        return $this->belongsTo(Treasury::class);
    }

}
