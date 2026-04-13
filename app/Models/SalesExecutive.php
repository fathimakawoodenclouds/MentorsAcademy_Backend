<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasReadableId;

class SalesExecutive extends Model
{
    use HasFactory, SoftDeletes, HasReadableId;

    protected $fillable = [
        'user_id',
        'staff_id',
        'da_allowance',
        'ta_allowance',
    ];

    protected function getReadableIdConfig(): array
    {
        return [
            'prefix' => 'SE',
            'column' => 'staff_id'
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
