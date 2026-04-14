<?php

namespace App\Models;

use App\Traits\HasReadableId;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OfficeStaff extends Model
{
    use HasFactory, HasReadableId, SoftDeletes;

    protected $table = 'office_staff';

    protected $fillable = [
        'user_id',
        'staff_id',
    ];

    protected function getReadableIdConfig(): array
    {
        return [
            'prefix' => 'EMP',
            'column' => 'staff_id',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'office_staff_unit', 'office_staff_id', 'unit_id')
            ->withTimestamps();
    }
}
