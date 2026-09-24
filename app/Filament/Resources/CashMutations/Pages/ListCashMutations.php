<?php

namespace App\Filament\Resources\CashMutations\Pages;

use App\Filament\Resources\CashMutations\CashMutationResource;
use App\Filament\TransactionFailureNotification;
use App\Models\Account;
use App\Models\CashAccount;
use App\Services\CashMutationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;
use RuntimeException;

class ListCashMutations extends ListRecords
{
    protected static string $resource = CashMutationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /**
             * =========================================================
             * PENERIMAAN KAS
             * =========================================================
             *
             * Jurnal:
             * Debit  Kas / Bank
             * Kredit Akun Lawan
             */
            Action::make('penerimaanKas')
                ->label('Penerimaan Kas')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->form([
                    /**
                     * Digunakan untuk mencegah penyimpanan ganda
                     * apabila tombol terkirim lebih dari satu kali.
                     */
                    Hidden::make('idempotency_key')
                        ->default(
                            fn (): string => (string) Str::uuid()
                        )
                        ->required(),

                    DatePicker::make('transaction_date')
                        ->label('Tanggal Penerimaan')
                        ->default(now())
                        ->required()
                        ->maxDate(now())
                        ->validationMessages([
                            'required' => 'Tanggal penerimaan wajib diisi.',
                        ]),

                    Select::make('cash_account_id')
                        ->label('Akun Kas/Bank')
                        ->options(
                            fn (): array => CashAccount::query()
                                ->where('is_active', true)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(
                                    fn (CashAccount $account): array => [
                                        $account->id => $account->code
                                            .' — '
                                            .$account->name,
                                    ]
                                )
                                ->all()
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Pilih akun Kas/Bank.',
                        ])
                        ->helperText(
                            'Pilih tempat uang benar-benar diterima.'
                        ),

                    /**
                     * Akun lawan diperlukan agar transaksi
                     * dapat langsung menghasilkan jurnal.
                     *
                     * Akun Kas dan Bank dikeluarkan dari pilihan
                     * karena perpindahan antar Kas/Bank nantinya
                     * menggunakan proses Transfer Antar Akun.
                     */
                    Select::make('counter_account_id')
                        ->label('Akun Lawan')
                        ->options(
                            fn (): array => Account::query()
                                ->where('is_active', true)
                                ->where('is_postable', true)
                                ->where(function ($query): void {
                                    $query
                                        ->whereNull('system_key')
                                        ->orWhereNotIn(
                                            'system_key',
                                            CashMutationService::MANUAL_COUNTER_ACCOUNT_FORBIDDEN_SYSTEM_KEYS
                                        );
                                })
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(
                                    fn (Account $account): array => [
                                        $account->id => $account->code
                                            .' — '
                                            .$account->name,
                                    ]
                                )
                                ->all()
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Pilih akun lawan penerimaan.',
                        ])
                        ->helperText(
                            'Contoh: Saldo Awal, Modal, Pendapatan lain, atau akun lain sesuai sumber penerimaan. '
                            .'Akun kontrol Kas/Bank, Piutang, Persediaan, dan Tabungan dikelola melalui transaksi bisnis masing-masing.'
                        ),

                    TextInput::make('amount')
                        ->label('Nominal Penerimaan')
                        ->numeric()
                        ->prefix('Rp')
                        ->required()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->validationMessages([
                            'required' => 'Nominal penerimaan wajib diisi.',
                            'numeric' => 'Nominal harus berupa angka.',
                        ]),

                    TextInput::make('reference_number')
                        ->label('Nomor Referensi')
                        ->placeholder(
                            'Contoh: bukti kas masuk'
                        )
                        ->maxLength(100),

                    Textarea::make('description')
                        ->label('Keterangan')
                        ->placeholder(
                            'Contoh: tambahan modal operasional'
                        )
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->modalHeading('Catat Penerimaan Kas')
                ->modalDescription(
                    'Gunakan untuk penerimaan uang yang tidak berasal dari pembayaran penjualan ke pengepul.'
                )
                ->modalSubmitActionLabel('Simpan Penerimaan')
                ->action(function (array $data): void {
                    try {
                        $userId = auth()->id();

                        if (! $userId) {
                            throw new RuntimeException(
                                'Pengguna tidak terautentikasi.'
                            );
                        }

                        app(CashMutationService::class)
                            ->recordManualReceipt(
                                cashAccountId: (int) $data['cash_account_id'],
                                transactionDate: $data['transaction_date'],
                                amount: (string) $data['amount'],
                                referenceNumber: $data['reference_number'] ?? null,
                                description: $data['description'] ?? null,
                                counterAccountId: (int) $data['counter_account_id'],
                                userId: (int) $userId,
                                idempotencyKey: $data['idempotency_key']
                            );

                        Notification::make()
                            ->title(
                                'Penerimaan kas berhasil dicatat'
                            )
                            ->body(
                                'Mutasi Kas/Bank dan jurnal akuntansi telah dibuat.'
                            )
                            ->success()
                            ->send();

                    } catch (\Throwable $exception) {
                        TransactionFailureNotification::send(
                            $exception,
                            'Penerimaan kas tidak dapat dicatat'
                        );
                    }
                }),

            /**
             * =========================================================
             * PENGELUARAN KAS
             * =========================================================
             *
             * Jurnal:
             * Debit  Akun Lawan
             * Kredit Kas / Bank
             */
            Action::make('pengeluaranKas')
                ->label('Pengeluaran Kas')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('danger')
                ->form([
                    Hidden::make('idempotency_key')
                        ->default(
                            fn (): string => (string) Str::uuid()
                        )
                        ->required(),

                    DatePicker::make('transaction_date')
                        ->label('Tanggal Pengeluaran')
                        ->default(now())
                        ->required()
                        ->maxDate(now())
                        ->validationMessages([
                            'required' => 'Tanggal pengeluaran wajib diisi.',
                        ]),

                    Select::make('cash_account_id')
                        ->label('Akun Kas/Bank')
                        ->options(
                            fn (): array => CashAccount::query()
                                ->where('is_active', true)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(
                                    fn (CashAccount $account): array => [
                                        $account->id => $account->code
                                            .' — '
                                            .$account->name,
                                    ]
                                )
                                ->all()
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Pilih akun Kas/Bank.',
                        ])
                        ->helperText(
                            'Pilih akun tempat uang benar-benar dikeluarkan.'
                        ),

                    Select::make('counter_account_id')
                        ->label('Akun Lawan')
                        ->options(
                            fn (): array => Account::query()
                                ->where('is_active', true)
                                ->where('is_postable', true)
                                ->where(function ($query): void {
                                    $query
                                        ->whereNull('system_key')
                                        ->orWhereNotIn(
                                            'system_key',
                                            CashMutationService::MANUAL_COUNTER_ACCOUNT_FORBIDDEN_SYSTEM_KEYS
                                        );
                                })
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(
                                    fn (Account $account): array => [
                                        $account->id => $account->code
                                            .' — '
                                            .$account->name,
                                    ]
                                )
                                ->all()
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->validationMessages([
                            'required' => 'Pilih akun lawan pengeluaran.',
                        ])
                        ->helperText(
                            'Contoh: Beban Operasional atau akun aset sesuai tujuan pengeluaran. '
                            .'Akun kontrol Kas/Bank, Piutang, Persediaan, dan Tabungan dikelola melalui transaksi bisnis masing-masing.'
                        ),

                    TextInput::make('amount')
                        ->label('Nominal Pengeluaran')
                        ->numeric()
                        ->prefix('Rp')
                        ->required()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->validationMessages([
                            'required' => 'Nominal pengeluaran wajib diisi.',
                            'numeric' => 'Nominal harus berupa angka.',
                        ]),

                    TextInput::make('reference_number')
                        ->label('Nomor Referensi')
                        ->placeholder(
                            'Contoh: nota biaya transportasi'
                        )
                        ->maxLength(100),

                    Textarea::make('description')
                        ->label('Keterangan')
                        ->placeholder(
                            'Contoh: biaya transportasi penjemputan sampah'
                        )
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->modalHeading('Catat Pengeluaran Kas')
                ->modalDescription(
                    'Saldo Kas/Bank akan diperiksa dan jurnal akuntansi dibuat otomatis sebelum transaksi disimpan.'
                )
                ->modalSubmitActionLabel('Simpan Pengeluaran')
                ->action(function (array $data): void {
                    try {
                        $userId = auth()->id();

                        if (! $userId) {
                            throw new RuntimeException(
                                'Pengguna tidak terautentikasi.'
                            );
                        }

                        app(CashMutationService::class)
                            ->recordManualExpense(
                                cashAccountId: (int) $data['cash_account_id'],
                                transactionDate: $data['transaction_date'],
                                amount: (string) $data['amount'],
                                referenceNumber: $data['reference_number'] ?? null,
                                description: $data['description'] ?? null,
                                counterAccountId: (int) $data['counter_account_id'],
                                userId: (int) $userId,
                                idempotencyKey: $data['idempotency_key']
                            );

                        Notification::make()
                            ->title(
                                'Pengeluaran kas berhasil dicatat'
                            )
                            ->body(
                                'Mutasi Kas/Bank dan jurnal akuntansi telah dibuat.'
                            )
                            ->success()
                            ->send();

                    } catch (\Throwable $exception) {
                        TransactionFailureNotification::send(
                            $exception,
                            'Pengeluaran kas tidak dapat dicatat'
                        );
                    }
                }),
        ];
    }
}
