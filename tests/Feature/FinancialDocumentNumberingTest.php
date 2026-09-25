<?php

use App\Filament\Resources\Sales\Pages\CreateSale;
use App\Filament\Resources\Withdrawals\Pages\CreateWithdrawal;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\CashMutation;
use App\Models\Collector;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Models\WasteType;
use App\Models\Withdrawal;
use App\Services\FinancialDocumentService;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian penomoran hanya boleh berjalan dalam environment testing.');
    }
    config([
        'database.connections.document_numbering_test' => [
            ...config('database.connections.mysql'), 'url' => null, 'host' => '127.0.0.1',
            'port' => 3306, 'unix_socket' => '', 'database' => 'banksampah_testing',
        ],
        'database.default' => 'document_numbering_test',
    ]);
    if (DB::connection()->getDriverName() !== 'mysql'
        || DB::selectOne('SELECT DATABASE() AS name')->name !== 'banksampah_testing') {
        throw new RuntimeException('Database pengujian penomoran tidak sesuai.');
    }
    DB::beginTransaction();
    $this->travelTo(new DateTimeImmutable('2036-09-25 10:00:00+07:00'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

afterEach(function (): void {
    if (config('database.default') === 'document_numbering_test') {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge('document_numbering_test');
    }
});

/** @return array<string, mixed> */
function financialNumberingData(string $type): array
{
    if (in_array($type, ['deposit', 'withdrawal'], true)) {
        $customer = Customer::create(['customer_code' => 'FN-'.Str::ulid(), 'name' => 'Nasabah Uji Nomor']);
        $customer->balanceMutations()->create([
            'type' => 'credit', 'amount' => '100000.00', 'transaction_date' => '2036-09-25',
            'reference_type' => 'numbering_test',
        ]);

        if ($type === 'withdrawal') {
            return ['customer_id' => $customer->id, 'transaction_date' => '2036-09-25', 'amount' => '1000.00', 'status' => 'draft'];
        }

        return ['customer_id' => $customer->id, 'transaction_date' => '2036-09-25', 'total_weight' => '2.000', 'total_amount' => '10000.00', 'status' => 'draft'];
    }

    $collector = Collector::create(['code' => 'FN-'.Str::ulid(), 'name' => 'Pengepul Uji Nomor', 'is_active' => true]);
    $waste = WasteType::create(['code' => 'FN-'.Str::ulid(), 'name' => 'Bahan Uji Nomor']);

    return [
        'collector_id' => $collector->id, 'transaction_date' => '2036-09-25', 'status' => 'draft',
        'total_weight' => '2.000', 'total_amount' => '10000.00',
        'items' => [['waste_type_id' => $waste->id, 'weight' => '2.000', 'price' => '5000.00', 'subtotal' => '10000.00']],
    ];
}

test('nomor penarikan dan penjualan melanjutkan nomor pendek terbesar', function (string $type, string $model, string $column, string $prefix): void {
    $data = financialNumberingData($type);
    unset($data['items']);
    $model::create([...$data, $column => $prefix.'-2036-000005']);
    $model::create([...$data, $column => $prefix.'-2036-01M3BTP2KPAQQV52QD397S4GWM']);

    $record = app(FinancialDocumentService::class)->createDraft($model, $data, 'numbering-'.$type);

    expect($record->{$column})->toBe($prefix.'-2036-000006');
})->with([
    ['withdrawal', Withdrawal::class, 'withdrawal_number', 'WD'],
    ['sale', Sale::class, 'sale_number', 'PJ'],
]);

test('formulir penarikan dan penjualan memakai nomor pendek dan menolak pengiriman ulang', function (string $type, string $page, string $column, string $prefix): void {
    $this->actingAs(User::factory()->create());
    $form = Livewire::test($page)->fillForm(financialNumberingData($type));
    $key = $form->get('creationNumber');

    $form->call('create')->assertHasNoFormErrors();
    $form->set('isCreating', false)->call('create')->assertHasFormErrors([$column]);

    expect($form->instance()->record->fresh()->{$column})->toBe($prefix.'-2036-000001');
    $this->assertDatabaseHas($type === 'sale' ? 'sales' : 'withdrawals', [
        $column => $prefix.'-2036-000001', 'idempotency_key' => $key,
    ]);
    $this->assertDatabaseHas('document_number_sequences', ['prefix' => $prefix, 'year' => 2036, 'last_number' => 1]);
})->with([
    ['withdrawal', CreateWithdrawal::class, 'withdrawal_number', 'WD'],
    ['sale', CreateSale::class, 'sale_number', 'PJ'],
]);

test('setiap jenis transaksi mempunyai urutan sendiri', function (): void {
    $withdrawalData = financialNumberingData('withdrawal');
    $saleData = financialNumberingData('sale');
    unset($saleData['items']);
    $service = app(FinancialDocumentService::class);

    $withdrawal = $service->createDraft(Withdrawal::class, $withdrawalData, 'independent-withdrawal');
    $sale = $service->createDraft(Sale::class, $saleData, 'independent-sale');

    expect($withdrawal->withdrawal_number)->toBe('WD-2036-000001');
    expect($sale->sale_number)->toBe('PJ-2036-000001');
});

/** @return array<string, string> */
function numberingFinancialSnapshot(): array
{
    $snapshot = [];
    foreach (['deposits', 'withdrawals', 'sales', 'sale_payments', 'balance_mutations', 'inventory_movements', 'cash_mutations', 'journal_entries', 'journal_lines'] as $table) {
        $records = DB::table($table)->orderBy('id')->get()->map(function (object $record): array {
            $data = (array) $record;
            foreach (['deposit_number', 'withdrawal_number', 'sale_number', 'idempotency_key', 'description', 'reference_number', 'updated_at'] as $column) {
                unset($data[$column]);
            }

            return $data;
        });
        $snapshot[$table] = hash('sha256', $records->toJson());
    }

    return $snapshot;
}

test('perapian nomor lama memperbarui rujukan dan menyimpan audit tanpa mengubah nilai keuangan', function (string $type, string $model, string $column, string $prefix): void {
    $data = financialNumberingData($type);
    unset($data['items']);
    $oldNumber = $prefix.'-2036-01M3BTP2KPAQQV52QD397S4GWM';
    $document = $model::create([...$data, $column => $oldNumber, 'status' => 'posted']);
    $newNumber = $prefix.'-2036-000001';
    $journal = JournalEntry::create([
        'entry_number' => 'TEST-'.Str::ulid(), 'reference_type' => $type, 'reference_id' => $document->id,
        'reference_number' => $oldNumber, 'description' => 'Transaksi '.$oldNumber,
        'transaction_date' => '2036-09-25', 'status' => 'posted',
    ]);
    $unrelated = JournalEntry::create([
        'entry_number' => 'TEST-'.Str::ulid(), 'reference_type' => 'unrelated', 'reference_id' => $document->id,
        'reference_number' => $oldNumber, 'description' => 'Catatan '.$oldNumber,
        'transaction_date' => '2036-09-25', 'status' => 'posted',
    ]);
    if ($type !== 'sale') {
        DB::table('balance_mutations')->insert([
            'customer_id' => $data['customer_id'], 'type' => 'credit', 'amount' => '1000.00',
            'reference_type' => $type, 'reference_id' => $document->id, 'transaction_date' => '2036-09-25',
            'description' => 'Transaksi '.$oldNumber,
        ]);
    }
    if ($type !== 'withdrawal') {
        $waste = WasteType::create(['code' => 'RN-'.Str::ulid(), 'name' => 'Bahan Uji Rujukan']);
        InventoryMovement::create([
            'waste_type_id' => $waste->id, 'movement_type' => 'in', 'quantity' => '2.000',
            'unit_cost' => '5000.00', 'total_cost' => '10000.00', 'reference_type' => $type,
            'reference_id' => $document->id, 'transaction_date' => '2036-09-25', 'description' => 'Transaksi '.$oldNumber,
        ]);
    } else {
        $cash = CashAccount::create(['code' => 'RN-'.Str::ulid(), 'name' => 'Kas Uji Nomor', 'account_type' => 'cash']);
        CashMutation::create([
            'cash_account_id' => $cash->id, 'mutation_type' => 'out', 'amount' => '1000.00',
            'reference_type' => $type.'_cancellation', 'reference_id' => $document->id,
            'reference_number' => 'REV-'.$oldNumber, 'description' => 'Pembatalan '.$oldNumber,
            'transaction_date' => '2036-09-25',
        ]);
    }
    $before = numberingFinancialSnapshot();

    $changes = app(FinancialDocumentService::class)->renumberLegacyDocuments(apply: true);

    expect($changes)->toContain([
        'reference_type' => $type, 'reference_id' => $document->id,
        'old_number' => $oldNumber, 'new_number' => $newNumber,
    ]);
    expect(numberingFinancialSnapshot())->toBe($before);
    expect($document->fresh()->{$column})->toBe($newNumber);
    $this->assertDatabaseHas('document_number_changes', ['old_number' => $oldNumber, 'new_number' => $newNumber]);
    $this->assertDatabaseHas('journal_entries', ['id' => $journal->id, 'reference_number' => $newNumber, 'description' => 'Transaksi '.$newNumber]);
    $this->assertDatabaseHas('journal_entries', ['id' => $unrelated->id, 'reference_number' => $oldNumber]);
    if ($type !== 'sale') {
        $this->assertDatabaseHas('balance_mutations', ['reference_type' => $type, 'reference_id' => $document->id, 'description' => 'Transaksi '.$newNumber]);
    }
    if ($type !== 'withdrawal') {
        $this->assertDatabaseHas('inventory_movements', ['reference_type' => $type, 'reference_id' => $document->id, 'description' => 'Transaksi '.$newNumber]);
    } else {
        $this->assertDatabaseHas('cash_mutations', ['reference_type' => $type.'_cancellation', 'reference_id' => $document->id, 'reference_number' => 'REV-'.$newNumber]);
    }
    expect(fn () => app(FinancialDocumentService::class)->createDraft($model, $data, $oldNumber))
        ->toThrow(ValidationException::class, 'Permintaan ini sudah tersimpan.');
    expect(app(FinancialDocumentService::class)->renumberLegacyDocuments(apply: true))->toBe([]);
})->with([
    ['deposit', Deposit::class, 'deposit_number', 'ST'],
    ['withdrawal', Withdrawal::class, 'withdrawal_number', 'WD'],
    ['sale', Sale::class, 'sale_number', 'PJ'],
]);

test('pratinjau penomoran tidak mengubah dokumen urutan atau audit', function (): void {
    $data = financialNumberingData('deposit');
    $oldNumber = 'ST-2036-01M3BTP2KPAQQV52QD397S4GWM';
    $document = Deposit::create([...$data, 'deposit_number' => $oldNumber, 'status' => 'posted']);

    $this->artisan('bank-sampah:renumber-documents')->assertSuccessful();

    expect($document->fresh()->deposit_number)->toBe($oldNumber);
    $this->assertDatabaseMissing('document_number_changes', ['old_number' => $oldNumber]);
    $this->assertDatabaseMissing('document_number_sequences', ['prefix' => 'ST', 'year' => 2036]);
});

test('kegagalan audit membatalkan perubahan nomor dan urutan', function (): void {
    $data = financialNumberingData('deposit');
    $oldNumber = 'ST-2036-01M3BTP2KPAQQV52QD397S4GWM';
    $document = Deposit::create([...$data, 'deposit_number' => $oldNumber, 'status' => 'posted']);
    DB::table('document_number_changes')->insert([
        'reference_type' => 'deposit', 'reference_id' => $document->id,
        'old_number' => $oldNumber, 'new_number' => 'ST-2036-000999', 'changed_at' => now(),
    ]);

    expect(fn () => app(FinancialDocumentService::class)->renumberLegacyDocuments(apply: true))
        ->toThrow(UniqueConstraintViolationException::class);

    expect($document->fresh()->deposit_number)->toBe($oldNumber);
    $this->assertDatabaseMissing('document_number_sequences', ['prefix' => 'ST', 'year' => 2036]);
});

test('perapian penjualan menyelaraskan keterangan pembayaran dan rincian jurnal', function (): void {
    $data = financialNumberingData('sale');
    unset($data['items']);
    $oldNumber = 'PJ-2036-01M3BTP2KPAQQV52QD397S4GWM';
    $sale = Sale::create([...$data, 'sale_number' => $oldNumber, 'status' => 'posted']);
    $payment = SalePayment::create([
        'sale_id' => $sale->id, 'payment_number' => 'BYR-20360925-000001',
        'payment_date' => '2036-09-25', 'amount' => '10000.00', 'payment_method' => 'cash', 'status' => 'posted',
    ]);
    $journal = JournalEntry::create([
        'entry_number' => 'TEST-'.Str::ulid(), 'reference_type' => 'sale_payment', 'reference_id' => $payment->id,
        'reference_number' => $payment->payment_number, 'description' => 'Pembayaran penjualan '.$oldNumber,
        'transaction_date' => '2036-09-25', 'status' => 'posted',
    ]);
    $account = Account::create([
        'code' => 'RN-'.Str::ulid(), 'name' => 'Akun Uji Nomor', 'account_type' => 'asset', 'normal_balance' => 'debit',
    ]);
    $lineId = DB::table('journal_lines')->insertGetId([
        'journal_entry_id' => $journal->id, 'account_id' => $account->id, 'line_number' => 1,
        'debit' => '10000.00', 'credit' => '0.00', 'description' => 'Pembayaran '.$oldNumber,
    ]);
    $before = numberingFinancialSnapshot();

    app(FinancialDocumentService::class)->renumberLegacyDocuments(apply: true);

    expect(numberingFinancialSnapshot())->toBe($before);
    $this->assertDatabaseHas('journal_entries', [
        'id' => $journal->id, 'reference_number' => 'BYR-20360925-000001', 'description' => 'Pembayaran penjualan PJ-2036-000001',
    ]);
    $this->assertDatabaseHas('journal_lines', ['id' => $lineId, 'description' => 'Pembayaran PJ-2036-000001']);
});
