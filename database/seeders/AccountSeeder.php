<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Membuat akun sistem dasar Bank Sampah.
     *
     * updateOrCreate digunakan supaya seeder aman
     * dijalankan kembali tanpa menggandakan akun.
     */
    public function run(): void
    {
        $accounts = [
            [
                'code' => '1101',
                'name' => 'Kas',
                'account_type' => Account::TYPE_ASSET,
                'normal_balance' => Account::NORMAL_DEBIT,
                'system_key' => 'cash',
            ],
            [
                'code' => '1102',
                'name' => 'Bank',
                'account_type' => Account::TYPE_ASSET,
                'normal_balance' => Account::NORMAL_DEBIT,
                'system_key' => 'bank',
            ],
            [
                'code' => '1201',
                'name' => 'Piutang Pengepul',
                'account_type' => Account::TYPE_ASSET,
                'normal_balance' => Account::NORMAL_DEBIT,
                'system_key' => 'collector_receivable',
            ],
            [
                'code' => '1301',
                'name' => 'Persediaan Sampah',
                'account_type' => Account::TYPE_ASSET,
                'normal_balance' => Account::NORMAL_DEBIT,
                'system_key' => 'inventory',
            ],
            [
                'code' => '2101',
                'name' => 'Tabungan Nasabah',
                'account_type' => Account::TYPE_LIABILITY,
                'normal_balance' => Account::NORMAL_CREDIT,
                'system_key' => 'customer_savings',
            ],
            [
                'code' => '3101',
                'name' => 'Saldo Awal',
                'account_type' => Account::TYPE_EQUITY,
                'normal_balance' => Account::NORMAL_CREDIT,
                'system_key' => 'opening_balance',
            ],
            [
                'code' => '4101',
                'name' => 'Pendapatan Penjualan',
                'account_type' => Account::TYPE_REVENUE,
                'normal_balance' => Account::NORMAL_CREDIT,
                'system_key' => 'sales_revenue',
            ],
            [
                'code' => '5101',
                'name' => 'Harga Pokok Penjualan',
                'account_type' => Account::TYPE_EXPENSE,
                'normal_balance' => Account::NORMAL_DEBIT,
                'system_key' => 'cogs',
            ],
            [
                'code' => '5201',
                'name' => 'Beban Operasional',
                'account_type' => Account::TYPE_EXPENSE,
                'normal_balance' => Account::NORMAL_DEBIT,
                'system_key' => 'operating_expense',
            ],
        ];

        foreach ($accounts as $account) {
            Account::updateOrCreate(
                ['code' => $account['code']],
                [
                    ...$account,
                    'is_postable' => true,
                    'is_active' => true,
                ]
            );
        }
    }
}
