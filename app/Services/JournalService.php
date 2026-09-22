<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class JournalService
{
    /**
     * Membuat jurnal berimbang.
     *
     * Satu reference_type + reference_id hanya boleh
     * menghasilkan satu jurnal.
     *
     * Jika request yang sama dikirim ulang dengan data
     * yang sama, jurnal lama dikembalikan.
     *
     * Jika referensinya sama tetapi isi berbeda,
     * transaksi ditolak.
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
         * Validasi tanggal jurnal.
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

        /**
         * Normalisasi referensi.
         */
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

        $referenceNumber = trim($referenceNumber ?? '');
        $referenceNumber = $referenceNumber === ''
            ? null
            : $referenceNumber;

        $description = trim($description ?? '');
        $description = $description === ''
            ? null
            : $description;

        if (mb_strlen($referenceNumber ?? '') > 100) {
            throw new RuntimeException(
                'Nomor referensi jurnal terlalu panjang.'
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

        /**
         * Normalisasi sekaligus validasi seluruh baris jurnal.
         */
        $normalizedLines = $this->normalizeLines($lines);

        try {
            return DB::transaction(function () use (
                $transactionDate,
                $referenceType,
                $referenceId,
                $referenceNumber,
                $description,
                $userId,
                $normalizedLines
            ): JournalEntry {
                /**
                 * Cek apakah transaksi sumber sudah pernah
                 * menghasilkan jurnal.
                 */
                $existing = JournalEntry::query()
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    /**
                     * Jangan hanya mengembalikan jurnal lama.
                     *
                     * Pastikan request ulang benar-benar identik
                     * dengan request pertama.
                     */
                    $this->assertExistingPostMatches(
                        existing: $existing,
                        transactionDate: $transactionDate,
                        referenceNumber: $referenceNumber,
                        description: $description,
                        userId: $userId,
                        lines: $normalizedLines
                    );

                    return $existing->load('lines.account');
                }

                /**
                 * Kunci akun-akun yang dipakai.
                 */
                $accountIds = collect($normalizedLines)
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

                if (
                    $accounts->count()
                    !== $accountIds->count()
                ) {
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
                 * Buat header jurnal.
                 *
                 * entry_number sementara diperlukan karena
                 * ID database belum diketahui sebelum INSERT.
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
                 * Bentuk nomor jurnal final berdasarkan ID.
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

                /**
                 * Simpan detail debit-kredit.
                 */
                foreach (
                    $normalizedLines as $index => $line
                ) {
                    JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $line['account_id'],
                        'line_number' => $index + 1,
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                        'description' => $line['description'],
                    ]);
                }

                return $entry->load('lines.account');
            }, attempts: 3);
        } catch (QueryException $exception) {
            /**
             * Database unique constraint merupakan lapisan
             * pertahanan terakhir terhadap concurrent posting.
             *
             * Bila request paralel kalah balapan saat INSERT,
             * cari jurnal yang sudah berhasil dibuat oleh
             * request pertama dan validasi isinya.
             */
            if (! $this->isDuplicateKeyException($exception)) {
                throw $exception;
            }

            $existing = JournalEntry::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            $this->assertExistingPostMatches(
                existing: $existing,
                transactionDate: $transactionDate,
                referenceNumber: $referenceNumber,
                description: $description,
                userId: $userId,
                lines: $normalizedLines
            );

            return $existing->load('lines.account');
        }
    }

    /**
     * Membalik jurnal yang telah diposting.
     *
     * Jurnal asal tidak dihapus.
     * Debit dan kredit dibuat kebalikannya.
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

        $referenceNumber = trim($referenceNumber ?? '');
        $referenceNumber = $referenceNumber === ''
            ? null
            : $referenceNumber;

        $description = trim($description ?? '');
        $description = $description === ''
            ? null
            : $description;

        if (mb_strlen($referenceNumber ?? '') > 100) {
            throw new RuntimeException(
                'Nomor referensi reversal terlalu panjang.'
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
             *
             * Semua reversal atas jurnal yang sama harus melewati
             * lock ini sehingga diproses secara berurutan.
             */
            $original = JournalEntry::query()
                ->whereKey($journalEntry->id)
                ->lockForUpdate()
                ->firstOrFail();

            /**
             * Jika reversal sudah ada, request hanya boleh
             * dianggap idempotent bila seluruh payload sama.
             */
            $existingReversal = JournalEntry::query()
                ->where('reversal_of_id', $original->id)
                ->lockForUpdate()
                ->first();

            if ($existingReversal) {
                $this->assertExistingReversalMatches(
                    existing: $existingReversal,
                    transactionDate: $transactionDate,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    referenceNumber: $referenceNumber,
                    description: $description,
                    userId: $userId
                );

                return $existingReversal
                    ->load('lines.account');
            }

            /**
             * Hanya jurnal posted yang boleh dibalik.
             */
            if (
                $original->status
                !== JournalEntry::STATUS_POSTED
            ) {
                throw new RuntimeException(
                    'Hanya jurnal aktif yang dapat dibalik.'
                );
            }

            /**
             * Reversal tidak boleh secara kronologis
             * mendahului jurnal asal.
             */
            if (
                $transactionDate
                < $original->transaction_date->toDateString()
            ) {
                throw new RuntimeException(
                    'Tanggal reversal tidak boleh mendahului jurnal asal.'
                );
            }

            /**
             * Reference reversal tidak boleh dipakai
             * oleh jurnal lain.
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
                ->where(
                    'journal_entry_id',
                    $original->id
                )
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

            /**
             * Jangan melakukan reversal atas jurnal
             * yang struktur debit-kreditnya rusak.
             */
            foreach ($lines as $line) {
                $debit = BigDecimal::of($line->debit);
                $credit = BigDecimal::of($line->credit);

                if (
                    ($debit->isZero()
                        && $credit->isZero())
                    || (
                        ! $debit->isZero()
                        && ! $credit->isZero()
                    )
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
             * Buat jurnal reversal.
             *
             * Akun tidak diwajibkan masih aktif karena transaksi
             * historis harus tetap dapat dikoreksi.
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
             * Tukar sisi debit dan kredit.
             */
            foreach ($lines as $index => $line) {
                $lineDescription =
                    $line->description !== null
                        && trim($line->description) !== ''
                    ? 'Reversal: '.$line->description
                    : 'Reversal';

                JournalLine::create([
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'line_number' => $index + 1,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => mb_substr(
                        $lineDescription,
                        0,
                        255
                    ),
                ]);
            }

            /**
             * Jurnal asal tidak dihapus.
             */
            $original->update([
                'status' => JournalEntry::STATUS_REVERSED,
            ]);

            return $reversal->load('lines.account');
        }, attempts: 3);
    }

    /**
     * Normalisasi dan validasi detail jurnal.
     *
     * @param array<int, array{
     *     account_id:int,
     *     debit:string,
     *     credit:string,
     *     description?:string|null
     * }> $lines
     * @return array<int, array{
     *     account_id:int,
     *     debit:string,
     *     credit:string,
     *     description:string|null
     * }>
     */
    private function normalizeLines(
        array $lines
    ): array {
        if (count($lines) < 2) {
            throw new RuntimeException(
                'Jurnal minimal memiliki dua baris.'
            );
        }

        $normalized = [];

        $totalDebit = BigDecimal::of('0.00');
        $totalCredit = BigDecimal::of('0.00');

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

            $accountId = (int) $line['account_id'];

            if ($accountId <= 0) {
                throw new RuntimeException(
                    'ID akun jurnal tidak valid.'
                );
            }

            $debitValue = (string) $line['debit'];
            $creditValue = (string) $line['credit'];

            /**
             * decimal(15,2) menyediakan maksimal
             * 13 digit sebelum desimal.
             */
            if (
                ! preg_match(
                    '/^[0-9]{1,13}(\.[0-9]{1,2})?$/D',
                    $debitValue
                )
                || ! preg_match(
                    '/^[0-9]{1,13}(\.[0-9]{1,2})?$/D',
                    $creditValue
                )
            ) {
                throw new RuntimeException(
                    'Nilai debit atau kredit tidak valid.'
                );
            }

            $debit = BigDecimal::of($debitValue)
                ->toScale(2);

            $credit = BigDecimal::of($creditValue)
                ->toScale(2);

            if (
                $debit->isNegative()
                || $credit->isNegative()
            ) {
                throw new RuntimeException(
                    'Debit dan kredit tidak boleh bernilai negatif.'
                );
            }

            if (
                ($debit->isZero()
                    && $credit->isZero())
                || (
                    ! $debit->isZero()
                    && ! $credit->isZero()
                )
            ) {
                throw new RuntimeException(
                    'Setiap baris jurnal harus memiliki salah satu nilai debit atau kredit.'
                );
            }

            $lineDescription = trim(
                (string) ($line['description'] ?? '')
            );

            $lineDescription = $lineDescription === ''
                ? null
                : $lineDescription;

            if (
                mb_strlen($lineDescription ?? '') > 255
            ) {
                throw new RuntimeException(
                    'Keterangan baris jurnal terlalu panjang.'
                );
            }

            $normalized[] = [
                'account_id' => $accountId,
                'debit' => (string) $debit,
                'credit' => (string) $credit,
                'description' => $lineDescription,
            ];

            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);
        }

        if (! $totalDebit->isEqualTo($totalCredit)) {
            throw new RuntimeException(
                'Jurnal tidak seimbang. Total debit harus sama dengan total kredit.'
            );
        }

        return $normalized;
    }

    /**
     * Memastikan jurnal lama benar-benar merupakan
     * hasil dari request yang identik.
     *
     * Request dengan referensi sama tetapi payload berbeda
     * tidak boleh dianggap idempotent.
     *
     * @param array<int, array{
     *     account_id:int,
     *     debit:string,
     *     credit:string,
     *     description:string|null
     * }> $lines
     */
    private function assertExistingPostMatches(
        JournalEntry $existing,
        string $transactionDate,
        ?string $referenceNumber,
        ?string $description,
        ?int $userId,
        array $lines
    ): void {
        if (
            $existing->transaction_date->toDateString()
                !== $transactionDate
            || $existing->reference_number
                !== $referenceNumber
            || $existing->description
                !== $description
            || $existing->posted_by
                !== $userId
        ) {
            throw new RuntimeException(
                'Referensi jurnal sudah digunakan dengan data yang berbeda.'
            );
        }

        $existingLines = JournalLine::query()
            ->where(
                'journal_entry_id',
                $existing->id
            )
            ->orderBy('line_number')
            ->get();

        if ($existingLines->count() !== count($lines)) {
            throw new RuntimeException(
                'Referensi jurnal sudah digunakan dengan detail yang berbeda.'
            );
        }

        foreach ($lines as $index => $line) {
            $existingLine = $existingLines[$index];

            if (
                (int) $existingLine->account_id
                    !== $line['account_id']
                || ! BigDecimal::of($existingLine->debit)
                    ->isEqualTo($line['debit'])
                || ! BigDecimal::of($existingLine->credit)
                    ->isEqualTo($line['credit'])
                || $existingLine->description
                    !== $line['description']
            ) {
                throw new RuntimeException(
                    'Referensi jurnal sudah digunakan dengan detail yang berbeda.'
                );
            }
        }
    }

    /**
     * Memastikan pemanggilan reversal kedua benar-benar
     * merupakan retry dari reversal pertama.
     */
    private function assertExistingReversalMatches(
        JournalEntry $existing,
        string $transactionDate,
        string $referenceType,
        int $referenceId,
        ?string $referenceNumber,
        ?string $description,
        int $userId
    ): void {
        if (
            $existing->transaction_date->toDateString()
                !== $transactionDate
            || $existing->reference_type
                !== $referenceType
            || (int) $existing->reference_id
                !== $referenceId
            || $existing->reference_number
                !== $referenceNumber
            || $existing->description
                !== $description
            || (int) $existing->posted_by
                !== $userId
        ) {
            throw new RuntimeException(
                'Jurnal asal sudah memiliki reversal dengan data yang berbeda.'
            );
        }
    }

    /**
     * Mengenali duplicate key dari database.
     *
     * MySQL menggunakan error code 1062.
     */
    private function isDuplicateKeyException(
        QueryException $exception
    ): bool {
        return isset($exception->errorInfo[1])
            && (int) $exception->errorInfo[1] === 1062;
    }
}
