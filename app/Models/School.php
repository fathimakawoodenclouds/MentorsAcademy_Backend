<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasReadableId;

use App\Traits\HasSlug;

class School extends Model
{
    use HasFactory, SoftDeletes, HasReadableId, HasSlug;

    protected $fillable = [
        'unit_id',
        'school_id',
        'name',
        'email',
        'phone',
        'location',
        'city',
        'state',
        'pincode',
        'address',
        'academic_year',
        'status',
        'coordinator_id',
        'slug'
    ];

    protected function getReadableIdConfig(): array
    {
        return [
            'prefix' => 'SCH',
            'column' => 'school_id'
        ];
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function coordinator()
    {
        return $this->belongsTo(Coordinator::class);
    }

    public function coaches()
    {
        return $this->hasMany(Coach::class);
    }

    public function schoolActivities()
    {
        return $this->hasMany(SchoolActivity::class);
    }
}
