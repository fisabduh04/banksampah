<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class JournalService
{
    /**
     * Membuat jurnal berimbang.
     *
     * Format $lines:
     *
     * [
     *     [
     *         'account_id' => 1,
     *         'debit' => '1000.00',
     *         'credit' => '0.00',
     *         'description' => '...',
     *     ],
     * ]
     *
     * @param array<int, array{
     *     account_id:int,
     *     debit:string,
     *     credit:string,
     *     description?:string|null
     * }> $lines
     */
    public function post(
        string $transactionDate,
        string $referenceType,
        int $referenceId,
        ?string $referenceNumber,
        ?string $description,
        ?int $userId,
        array $lines
    ): JournalEntry {
        /**
         * Validasi tanggal.
         */
        if (
            Validator::make(
                ['date' => $transactionDate],
                ['date' => ['required', 'date_format:Y-m-d']]
            )->fails()
            || $transactionDate > now()->toDateString()
        ) {
            throw new RuntimeException(
                'Tanggal jurnal tidak valid atau melewati hari ini.'
            );
        }

        $referenceType = trim($referenceType);

        if (
            $referenceType === ''
            || mb_strlen($referenceType) > 50
        ) {
            throw new RuntimeException(
                'Jenis referensi jurnal tidak valid.'
            );
        }

        if ($referenceId <= 0) {
            throw new RuntimeException(
                'ID transaksi sumber jurnal tidak valid.'
            );
        }

        if (
            $userId !== null
            && ! User::query()->whereKey($userId)->exists()
        ) {
            throw new RuntimeException(
                'Pengguna pemosting jurnal tidak ditemukan.'
            );
        }

        if (count($lines) < 2) {
            throw new RuntimeException(
                'Jurnal minimal memiliki dua baris.'
            );
        }

        $totalDebit = BigDecimal::of('0.00');
        $totalCredit = BigDecimal::of('0.00');

        /**
         * Validasi seluruh baris sebelum menulis database.
         */
        foreach ($lines as $line) {
            if (
                ! isset(
                    $line['account_id'],
                    $line['debit'],
                    $line['credit']
                )
            ) {
                throw new RuntimeException(
                    'Struktur baris jurnal tidak lengkap.'
                );
            }

            $debit = BigDecimal::of((string) $line['debit'])
                ->toScale(2);

            $credit = BigDecimal::of((string) $line['credit'])
                ->toScale(2);

            if (
                $debit->isNegative()
                || $credit->isNegative()
            ) {
                throw new RuntimeException(
                    'Debit dan kredit tidak boleh bernilai negatif.'
                );
            }

            /**
             * Satu baris harus hanya debit atau hanya kredit.
             */
            if (
                ($debit->isZero() && $credit->isZero())
                || (! $debit->isZero() && ! $credit->isZero())
            ) {
                throw new RuntimeException(
                    'Setiap baris jurnal harus memiliki salah satu nilai debit atau kredit.'
                );
            }

            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);
        }

        /**
         * Prinsip utama double-entry accounting.
         */
        if (! $totalDebit->isEqualTo($totalCredit)) {
            throw new RuntimeException(
                'Jurnal tidak seimbang. Total debit harus sama dengan total kredit.'
            );
        }

        return DB::transaction(function () use (
            $transactionDate,
            $referenceType,
            $referenceId,
            $referenceNumber,
            $description,
            $userId,
            $lines
        ): JournalEntry {
            /**
             * Cegah jurnal ganda dari transaksi sumber yang sama.
             */
            $existing = JournalEntry::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            /**
             * Kunci seluruh akun yang dipakai.
             */
            $accountIds = collect($lines)
                ->pluck('account_id')
                ->unique()
                ->sort()
                ->values();

            $accounts = Account::query()
                ->whereIn('id', $accountIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($accounts->count() !== $accountIds->count()) {
                throw new RuntimeException(
                    'Terdapat akun jurnal yang tidak ditemukan.'
                );
            }

            foreach ($accountIds as $accountId) {
                $account = $accounts->get($accountId);

                if (! $account->isActive()) {
                    throw new RuntimeException(
                        'Terdapat akun jurnal yang sudah tidak aktif.'
                    );
                }

                if (! $account->isPostable()) {
                    throw new RuntimeException(
                        'Jurnal tidak dapat diposting langsung ke akun induk.'
                    );
                }
            }

            /**
             * Buat header sementara.
             */
            $entry = JournalEntry::create([
                'entry_number' => 'TMP-'.uniqid(),
                'transaction_date' => $transactionDate,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_number' => $referenceNumber,
                'description' => $description,
                'status' => JournalEntry::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $userId,
            ]);

            /**
             * Nomor jurnal final.
             */
            $entry->update([
                'entry_number' => 'JU-'
                    .$entry->transaction_date->format('Ymd')
                    .'-'
                    .str_pad(
                        (string) $entry->id,
                        6,
                        '0',
                        STR_PAD_LEFT
                    ),
            ]);

            foreach ($lines as $index => $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'line_number' => $index + 1,
                    'debit' => BigDecimal::of(
                        (string) $line['debit']
                    )->toScale(2),
                    'credit' => BigDecimal::of(
                        (string) $line['credit']
                    )->toScale(2),
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $entry->load('lines.account');
        }, attempts: 3);
    }

    /**
     * Membalik jurnal yang sudah diposting.
     *
     * Jurnal asal tidak dihapus.
     * Sistem membuat jurnal baru dengan posisi
     * debit dan kredit yang dibalik.
     */
    public function reverse(
        JournalEntry $journalEntry,
        string $transactionDate,
        string $referenceType,
        int $referenceId,
        ?string $referenceNumber,
        ?string $description,
        int $userId
    ): JournalEntry {
        /**
         * Validasi tanggal reversal.
         */
        if (
            Validator::make(
                ['date' => $transactionDate],
                ['date' => ['required', 'date_format:Y-m-d']]
            )->fails()
            || $transactionDate > now()->toDateString()
        ) {
            throw new RuntimeException(
                'Tanggal reversal jurnal tidak valid atau melewati hari ini.'
            );
        }

        if (! User::query()->whereKey($userId)->exists()) {
            throw new RuntimeException(
                'Pengguna pemosting reversal jurnal tidak ditemukan.'
            );
        }

        $referenceType = trim($referenceType);

        if (
            $referenceType === ''
            || mb_strlen($referenceType) > 50
            || $referenceId <= 0
        ) {
            throw new RuntimeException(
                'Referensi reversal jurnal tidak valid.'
            );
        }

        return DB::transaction(function () use (
            $journalEntry,
            $transactionDate,
            $referenceType,
            $referenceId,
            $referenceNumber,
            $description,
            $userId
        ): JournalEntry {
            /**
             * Kunci jurnal asal.
             */
            $original = JournalEntry::query()
                ->whereKey($journalEntry->id)
                ->lockForUpdate()
                ->firstOrFail();

            /**
             * Cek apakah reversal sudah pernah dibuat.
             * Jika sudah ada, kembalikan jurnal reversal tersebut.
             */
            $existingReversal = JournalEntry::query()
                ->where('reversal_of_id', $original->id)
                ->lockForUpdate()
                ->first();

            if ($existingReversal) {
                return $existingReversal->load('lines.account');
            }

            if ($original->status !== JournalEntry::STATUS_POSTED) {
                throw new RuntimeException(
                    'Hanya jurnal aktif yang dapat dibalik.'
                );
            }

            if (
                $transactionDate
                < $original->transaction_date->toDateString()
            ) {
                throw new RuntimeException(
                    'Tanggal reversal tidak boleh mendahului jurnal asal.'
                );
            }

            /**
             * Pastikan referensi reversal belum digunakan.
             */
            $referenceExists = JournalEntry::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->lockForUpdate()
                ->exists();

            if ($referenceExists) {
                throw new RuntimeException(
                    'Referensi reversal jurnal sudah digunakan.'
                );
            }

            /**
             * Kunci detail jurnal asal.
             */
            $lines = JournalLine::query()
                ->where('journal_entry_id', $original->id)
                ->orderBy('line_number')
                ->lockForUpdate()
                ->get();

            if ($lines->count() < 2) {
                throw new RuntimeException(
                    'Detail jurnal asal tidak lengkap.'
                );
            }

            $totalDebit = BigDecimal::of('0.00');
            $totalCredit = BigDecimal::of('0.00');

            foreach ($lines as $line) {
                $debit = BigDecimal::of($line->debit);
                $credit = BigDecimal::of($line->credit);

                if (
                    ($debit->isZero() && $credit->isZero())
                    || (! $debit->isZero() && ! $credit->isZero())
                    || $debit->isNegative()
                    || $credit->isNegative()
                ) {
                    throw new RuntimeException(
                        'Detail jurnal asal tidak valid.'
                    );
                }

                $totalDebit = $totalDebit->plus($debit);
                $totalCredit = $totalCredit->plus($credit);
            }

            if (! $totalDebit->isEqualTo($totalCredit)) {
                throw new RuntimeException(
                    'Jurnal asal tidak seimbang dan tidak dapat dibalik.'
                );
            }

            /**
             * Buat header reversal.
             */
            $reversal = JournalEntry::create([
                'entry_number' => 'TMP-'.uniqid(),
                'transaction_date' => $transactionDate,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_number' => $referenceNumber,
                'description' => $description,
                'status' => JournalEntry::STATUS_POSTED,
                'reversal_of_id' => $original->id,
                'posted_at' => now(),
                'posted_by' => $userId,
            ]);

            $reversal->update([
                'entry_number' => 'JU-'
                    .$reversal->transaction_date->format('Ymd')
                    .'-'
                    .str_pad(
                        (string) $reversal->id,
                        6,
                        '0',
                        STR_PAD_LEFT
                    ),
            ]);

            /**
             * Balik debit menjadi kredit dan sebaliknya.
             */
            foreach ($lines as $index => $line) {
                JournalLine::create([
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'line_number' => $index + 1,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => 'Reversal: '.($line->description ?? ''),
                ]);
            }

            /**
             * Jurnal asal tetap disimpan sebagai histori,
             * tetapi ditandai telah direversal.
             */
            $original->update([
                'status' => JournalEntry::STATUS_REVERSED,
            ]);

            return $reversal->load('lines.account');
        }, attempts: 3);
    }
}
