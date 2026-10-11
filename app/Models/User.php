<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\StaffRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'role', 'permissions', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['role' => 'admin'];

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
            'role' => StaffRole::class,
            'permissions' => 'array',
        ];
    }

    /** Whether this staff member has an ability, from their role or, for a custom role, their ticked permissions. */
    public function allows(string $ability): bool
    {
        return $this->role === StaffRole::Custom
            ? in_array($ability, $this->permissions ?? [], true)
            : $this->role->allows($ability);
    }
}
