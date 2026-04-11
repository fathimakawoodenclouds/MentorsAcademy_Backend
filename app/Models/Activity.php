<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Traits\HasSlug;

class Activity extends Model
{
    use SoftDeletes, HasSlug;

    protected $fillable = ['name', 'status', 'slug'];

    public function activityHead()
    {
        return $this->hasOne(ActivityHead::class);
    }
}
