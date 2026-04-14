<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the staff profile associated with the user.
     */
    public function staffProfile()
    {
        return $this->hasOne(StaffProfile::class, 'user_id');
    }

    public function officeStaff()
    {
        return $this->hasOne(OfficeStaff::class, 'user_id');
    }

    public function unitHead(): HasOne
    {
        return $this->hasOne(UnitHead::class, 'user_id');
    }

    public function coordinator(): HasOne
    {
        return $this->hasOne(Coordinator::class, 'user_id');
    }

    public function coach(): HasOne
    {
        return $this->hasOne(Coach::class, 'user_id');
    }

    public function activityHead(): HasOne
    {
        return $this->hasOne(ActivityHead::class, 'user_id');
    }

    public function salesExecutive(): HasOne
    {
        return $this->hasOne(SalesExecutive::class, 'user_id');
    }
}
