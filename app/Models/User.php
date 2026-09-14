<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['financial_role' => 'operator'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Kewenangan pembukuan terpisah dari persetujuan dan administrasi. */
    public function canPerformFinancialOperation(string $operation): bool
    {
        return match ($operation) {
            'record' => in_array($this->financial_role, ['operator', 'finance_manager', 'administrator'], true),
            'approve' => in_array($this->financial_role, ['finance_manager', 'administrator'], true),
            'administer' => $this->financial_role === 'administrator',
            default => false,
        };
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
