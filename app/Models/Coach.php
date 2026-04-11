<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasReadableId;

use App\Traits\HasSlug;

class Coach extends Model
{
    use HasFactory, SoftDeletes, HasReadableId, HasSlug;

    protected $fillable = [
        'staff_id',
        'user_id',
        'school_id',
        'activity_head_id',
        'activity_id',
        'experience_years',
        'specialization',
        'slug'
    ];

    protected function getReadableIdConfig(): array
    {
        return [
            'prefix' => 'CH',
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

    public function activityHead()
    {
        return $this->belongsTo(ActivityHead::class);
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
