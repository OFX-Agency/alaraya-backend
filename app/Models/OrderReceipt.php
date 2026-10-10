<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class OrderReceipt extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'posted'];

    protected $hidden = ['request_hash', 'idempotency_key'];

    protected $casts = ['total' => 'decimal:2', 'receipt_date' => 'date:Y-m-d', 'cancelled_at' => 'datetime'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['request_hash', 'idempotency_key'])
            ->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('order_receipts');
    }

    public function items()
    {
        return $this->hasMany(OrderReceiptItem::class);
    }

    public function shipOrder()
    {
        return $this->belongsTo(ShipOrderData::class, 'ship_order_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function treasury()
    {
        return $this->belongsTo(Treasury::class);
    }

    public function deductions()
    {
        return $this->hasMany(TreasuryDeduction::class);
    }
}
