<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\Auditable;
use App\Observers\AuditableObserver;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
#[ObservedBy([AuditableObserver::class])]
class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;
    use Auditable;
    use HasApiTokens;
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected array $auditExcluded = [
        'password',
        'remember_token',
    ];

    public function auditExcluded(): array
    {
        return $this->auditExcluded;
    }
}