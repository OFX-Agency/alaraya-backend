<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class OrderReceiptItem extends Model
{
    use LogsActivity;

    protected $fillable = ['category', 'description', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    // Port/customs/order expenses that can arise before a transport policy exists.
    public const CATEGORIES = [
        'customs_clearance' => 'تخليص جمركي',
        'bill_of_lading_amendment' => 'تعديل بوليصة الشحن',
        'brokers' => 'سمسرة', 'vgm' => 'VGM', 'x_ray' => 'أشعة',
        'data_entry' => 'إدخال بيانات', 'yard_receipts' => 'إيصالات ساحة',
        'port_authority_receipts' => 'إيصالات هيئة الميناء',
        'port_weight_fees' => 'رسوم وزن الميناء', 'agency_receipts' => 'إيصالات توكيل',
        'explosives_receipt' => 'إيصال مفرقعات', 'bascule_scale_receipt' => 'ميزان بسكول',
        'cashier_receipt' => 'إيصال كاشير', 'reweighing_receipt' => 'إعادة وزن',
        'sina_marine_receipts' => 'سينا مارين', 'tunnel_ferry_receipts' => 'نفق ومعدية',
        'container_repair_receipt' => 'إصلاح حاويات', 'port_receipts' => 'إيصالات ميناء',
        'x_ray_leave' => 'إذن أشعة', 'allocation' => 'تخصيص', 'guarantee' => 'ضمان',
        'form' => 'استمارة', 'official_receipts' => 'إيصالات رسمية', 'other' => 'أخرى',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('order_receipt_items');
    }

    public function orderReceipt()
    {
        return $this->belongsTo(OrderReceipt::class);
    }
}
