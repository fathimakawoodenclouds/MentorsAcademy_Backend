<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesIncentiveRecord extends Model
{
    protected $fillable = [
        'sales_executive_id',
        'product_category',
        'quantity',
        'unit_amount',
        'total_amount',
        'earned_month',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function salesExecutive(): BelongsTo
    {
        return $this->belongsTo(SalesExecutive::class);
    }
}
