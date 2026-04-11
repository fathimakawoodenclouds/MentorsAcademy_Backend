<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasReadableId;
use App\Traits\HasSlug;

class ActivityHead extends Model
{
    use HasFactory, SoftDeletes, HasReadableId, HasSlug;

    protected $fillable = [
        'staff_id',
        'user_id',
        'activity_id',
        'department',
        'slug'
    ];

    protected function getReadableIdConfig(): array
    {
        return [
            'prefix' => 'AH',
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

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
