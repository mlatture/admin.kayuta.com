<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReservationDraft extends Model
{
    use HasFactory;

    protected $fillable = [
        'draft_id',
        'cart_data',
        'subtotal',
        'discount_total',
        'estimated_tax',
        'platform_fee_total',
        'grand_total',
        'discount_reason',
        'coupon_code',
        'credit_amount',
        'original_cart_id',
        'customer_id',
        'is_modification',
        'original_reservation_ids',
    ];

    protected $casts = [
        'cart_data' => 'array',
        'original_reservation_ids' => 'array',
    ];
}
