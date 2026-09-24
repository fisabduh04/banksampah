<?php

namespace App\Services;

use App\Models\Account;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class ReconciliationService
{
    /**
     * Bandingkan tabungan nasabah dengan akun GL pada tanggal yang sama.
     * Selisih = saldo mutasi nasabah dikurangi saldo GL.
     * Metode ini hanya membaca data dan tidak membuat jurnal penyesuaian.
     */
    public function customerSavingsAsOf(string $asOfDate): array
    {
        if (Validator::make(
            ['date' => $asOfDate],
            ['date' => ['required', 'date_format:Y-m-d']]
        )->fails()) {
            throw new InvalidArgumentException('Tanggal laporan tidak valid.');
        }

        $account = Account::query()
            ->where('system_key', 'customer_savings')
            ->sole();

        // Sisi kredit normal untuk kewajiban; saldo debit tetap bernilai negatif.
        $report = app(FinancialReportingService::class)
            ->trialBalance($asOfDate, $asOfDate);
        $row = $report['rows']->get($account->id);

        $glBalance = BigDecimal::of($row['closing_credit'] ?? '0.00')
            ->minus($row['closing_debit'] ?? '0.00');

        // Mutasi sesudah tanggal laporan tidak ikut dihitung.
        $totals = DB::table('balance_mutations')
            ->where('transaction_date', '<=', $asOfDate)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS credits"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) AS debits"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type NOT IN ('credit', 'debit') OR amount < 0 "
                .'THEN 1 ELSE 0 END), 0) AS invalid_count'
            )
            ->first();

        if ((int) $totals->invalid_count > 0) {
            throw new RuntimeException(
                'Terdapat mutasi tabungan dengan jenis atau nominal tidak valid.'
            );
        }

        $customerBalance = BigDecimal::of((string) $totals->credits)
            ->minus((string) $totals->debits);

        $difference = $customerBalance->minus($glBalance);

        return [
            'as_of_date' => $asOfDate,
            'account_id' => $account->id,
            'account_code' => $account->code,
            'gl_balance' => (string) $glBalance->toScale(2),
            'customer_balance' => (string) $customerBalance->toScale(2),
            'difference' => (string) $difference->toScale(2),
            'balanced' => $difference->isZero(),
        ];
    }
}
