<?php

namespace App\Services;

use App\Models\Deposit;
use App\Models\Sale;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FinancialDocumentService
{
    private const DOCUMENTS = [
        Deposit::class => ['ST', 'deposit_number', 'deposit'],
        Withdrawal::class => ['WD', 'withdrawal_number', 'withdrawal'],
        Sale::class => ['PJ', 'sale_number', 'sale'],
    ];

    /**
     * @param  class-string<Deposit>|class-string<Withdrawal>|class-string<Sale>  $modelClass
     * @param  array<string, mixed>  $data
     */
    public function createDraft(string $modelClass, array $data, string $idempotencyKey): Model
    {
        [, $column] = self::DOCUMENTS[$modelClass];

        if ($idempotencyKey === '') {
            throw ValidationException::withMessages(['data.'.$column => 'Buka kembali formulir transaksi.']);
        }

        try {
            return DB::transaction(function () use ($modelClass, $data, $idempotencyKey, $column): Model {
                if ($modelClass::query()->where('idempotency_key', $idempotencyKey)
                    ->orWhere($column, $idempotencyKey)->exists()) {
                    throw ValidationException::withMessages([
                        'data.'.$column => 'Permintaan ini sudah tersimpan. Periksa dokumen yang sudah dibuat.',
                    ]);
                }

                return $modelClass::create([
                    ...$data,
                    $column => $this->reserveNumber($modelClass, now()->year),
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'draft',
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages([
                'data.'.$column => 'Nomor transaksi sudah tersimpan. Periksa dokumen yang sudah dibuat.',
            ]);
        }
    }

    /** @return list<array{reference_type: string, reference_id: int, old_number: string, new_number: string}> */
    public function renumberLegacyDocuments(bool $apply = false): array
    {
        DB::beginTransaction();

        try {
            $changes = [];

            foreach (self::DOCUMENTS as $modelClass => [$prefix, $column, $type]) {
                foreach ($modelClass::query()->where($column, 'like', $prefix.'-%')->lazyById() as $candidate) {
                    if (! preg_match('/^'.$prefix.'-([0-9]{4})-(.{26})$/D', $candidate->{$column}, $matches)
                        || ! Str::isUlid($matches[2])) {
                        continue;
                    }

                    $document = $modelClass::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    if ($document->{$column} !== $candidate->{$column}) {
                        continue;
                    }

                    $oldNumber = $document->{$column};
                    $newNumber = $this->reserveNumber($modelClass, (int) $matches[1]);

                    /** Koreksi metadata saja; jangan menjalankan ulang posting transaksi. */
                    $modelClass::query()->whereKey($document->id)->update([
                        $column => $newNumber,
                        'idempotency_key' => $document->idempotency_key ?? $oldNumber,
                    ]);
                    $this->updateReferenceNumbers($type, $document->id, $oldNumber, $newNumber);

                    if ($type === 'sale') {
                        foreach ($document->payments()->pluck('id') as $paymentId) {
                            $this->updateReferenceNumbers('sale_payment', $paymentId, $oldNumber, $newNumber);
                        }
                    }

                    $change = [
                        'reference_type' => $type,
                        'reference_id' => $document->id,
                        'old_number' => $oldNumber,
                        'new_number' => $newNumber,
                    ];
                    DB::table('document_number_changes')->insert([...$change, 'changed_at' => now()]);
                    $changes[] = $change;
                }
            }

            if ($apply) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            return $changes;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /** @param class-string<Deposit>|class-string<Withdrawal>|class-string<Sale> $modelClass */
    private function reserveNumber(string $modelClass, int $year): string
    {
        [$prefix, $column] = self::DOCUMENTS[$modelClass];
        DB::table('document_number_sequences')->insertOrIgnore([
            'prefix' => $prefix, 'year' => $year, 'last_number' => 0,
        ]);
        $sequenceQuery = DB::table('document_number_sequences')->where('prefix', $prefix)->where('year', $year);
        $sequence = (clone $sequenceQuery)->lockForUpdate()->first();
        $lastNumber = (int) $sequence->last_number;

        /** Mulai dari nomor urut lama, bukan akhiran ULID atau ID database. */
        if ($lastNumber === 0) {
            foreach ($modelClass::query()->select(['id', $column])
                ->where($column, 'like', $prefix.'-'.$year.'-%')->lazyById() as $document) {
                if (preg_match('/^'.$prefix.'-'.$year.'-([0-9]{6,})$/D', $document->{$column}, $matches)) {
                    $lastNumber = max($lastNumber, (int) $matches[1]);
                }
            }
        }

        $nextNumber = $lastNumber + 1;
        $sequenceQuery->update(['last_number' => $nextNumber]);

        return sprintf('%s-%d-%06d', $prefix, $year, $nextNumber);
    }

    private function updateReferenceNumbers(string $type, int $id, string $oldNumber, string $newNumber): void
    {
        foreach ([
            'balance_mutations' => ['description'],
            'inventory_movements' => ['description'],
            'cash_mutations' => ['reference_number', 'description'],
            'journal_entries' => ['reference_number', 'description'],
        ] as $table => $columns) {
            $records = DB::table($table)->whereIn('reference_type', [$type, $type.'_cancellation'])
                ->where('reference_id', $id)->lockForUpdate()->get();

            foreach ($records as $record) {
                $updates = [];
                foreach ($columns as $column) {
                    if ($record->{$column} !== null && str_contains($record->{$column}, $oldNumber)) {
                        $updates[$column] = str_replace($oldNumber, $newNumber, $record->{$column});
                    }
                }
                if ($updates !== []) {
                    DB::table($table)->where('id', $record->id)->update($updates);
                }
                if ($table === 'journal_entries') {
                    foreach (DB::table('journal_lines')->where('journal_entry_id', $record->id)->lockForUpdate()->get() as $line) {
                        if ($line->description !== null && str_contains($line->description, $oldNumber)) {
                            DB::table('journal_lines')->where('id', $line->id)->update([
                                'description' => str_replace($oldNumber, $newNumber, $line->description),
                            ]);
                        }
                    }
                }
            }
        }
    }
}
