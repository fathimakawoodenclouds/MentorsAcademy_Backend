<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasReadableId;
use App\Traits\HasSlug;

class UnitHead extends Model
{
    use HasFactory, SoftDeletes, HasReadableId, HasSlug;

    protected $table = 'unit_heads_data';

    protected $fillable = [
        'staff_id',
        'user_id',
        'unit_id',
        'slug'
    ];

    protected function getReadableIdConfig(): array
    {
        return [
            'prefix' => 'UH',
            'column' => 'staff_id',
            'use_year' => true
        ];
    }

    protected function getSlugSourceColumn(): string
    {
        return 'staff_id';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
