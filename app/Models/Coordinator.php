<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasReadableId;
use App\Traits\HasSlug;

class Coordinator extends Model
{
    use HasFactory, SoftDeletes, HasReadableId, HasSlug;

    protected $fillable = [
        'staff_id',
        'user_id',
        'unit_id',
        'slug'
    ];

    protected function getReadableIdConfig(): array
    {
        return [
            'prefix' => 'CO',
            'column' => 'staff_id',
            'use_year' => true
        ];
    }

    protected function getSlugSourceColumn(): string
    {
        return 'staff_id'; // Slugs for staff will be based on their staff_id as names might not be unique
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function schools()
    {
        return $this->hasMany(School::class);
    }
}
