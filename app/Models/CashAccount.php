<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashAccount extends Model
{
    public const TYPE_CASH = 'cash';

    public const TYPE_BANK = 'bank';

    /**
     * Field yang boleh diisi secara mass assignment.
     */
    protected $fillable = [
        'code',
        'name',
        'account_type',
        'bank_name',
        'account_number',
        'account_holder',
        'is_active',
        'notes',
    ];

    /**
     * Casting tipe data.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Daftar jenis akun Kas/Bank yang diizinkan.
     */
    public static function accountTypes(): array
    {
        return [
            self::TYPE_CASH => 'Kas Tunai',
            self::TYPE_BANK => 'Rekening Bank',
        ];
    }

    /**
     * Cek apakah akun merupakan kas tunai.
     */
    public function isCash(): bool
    {
        return $this->account_type === self::TYPE_CASH;
    }

    /**
     * Cek apakah akun merupakan rekening bank.
     */
    public function isBank(): bool
    {
        return $this->account_type === self::TYPE_BANK;
    }

    /**
     * Cek apakah akun masih aktif.
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Relasi mutasi kas.
     *
     * Relasi ini akan aktif setelah tabel cash_mutations
     * dibuat pada tahap berikutnya.
     */
    public function mutations(): HasMany
    {
        return $this->hasMany(CashMutation::class);
    }
}
