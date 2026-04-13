<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasReadableId;

use App\Traits\HasSlug;

class Unit extends Model
{
    use HasFactory, SoftDeletes, HasReadableId, HasSlug;

    protected $fillable = [
        'unit_id',
        'name',
        'location',
        'state',
        'city',
        'pin_code',
        'status',
        'slug'
    ];

    protected function getReadableIdConfig(): array
    {
        return [
            'prefix' => 'UNT',
            'column' => 'unit_id'
        ];
    }

    public function unitHead()
    {
        return $this->hasOne(UnitHead::class);
    }

    public function schools()
    {
        return $this->hasMany(School::class);
    }

    public function coaches()
    {
        return $this->hasManyThrough(Coach::class, School::class);
    }

    public function coordinators()
    {
        return $this->hasMany(Coordinator::class);
    }
}
