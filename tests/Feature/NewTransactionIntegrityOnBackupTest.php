<?php

use App\Filament\Resources\Deposits\Pages\CreateDeposit;
use App\Filament\Resources\Sales\Pages\CreateSale;
use App\Filament\Resources\Sales\Pages\EditSale;
use App\Filament\Resources\Withdrawals\Pages\CreateWithdrawal;
use App\Filament\Widgets\FinancialOverview;
use App\Models\BalanceMutation;
use App\Models\CashAccount;
use App\Models\CashMutation;
use App\Models\Collector;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositItem;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\WasteType;
use App\Models\Withdrawal;
use App\Services\CashMutationService;
use App\Services\DashboardReportingService;
use App\Services\DepositService;
use App\Services\SalePaymentService;
use App\Services\SalePostingService;
use App\Services\WithdrawalService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

/** @return array<string, string> */
function newTransactionBackupFingerprint(): array
{
    $result = [];
    foreach (['users', 'customers', 'collectors', 'accounts', 'cash_accounts', 'cash_mutations', 'balance_mutations', 'inventory_movements', 'deposits', 'deposit_items', 'withdrawals', 'sales', 'sale_items', 'sale_payments', 'journal_entries', 'journal_lines', 'waste_types'] as $table) {
        $result[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
    }

    return $result;
}

beforeEach(function (): void {
    if (getenv('NEW_TRANSACTION_AUDIT') !== '20260924-b050b725') {
        $this->markTestSkipped('Requires the isolated integrity test copy; no migrations or seeders.');
    }
    if (! app()->environment('testing')) {
        throw new RuntimeException('Testing environment required.');
    }
    config([
        'database.connections.new_transaction_audit' => [
            ...config('database.connections.mysql'), 'url' => null, 'host' => '127.0.0.1',
            'port' => 3306, 'unix_socket' => '', 'database' => 'banksampah_integrity_testing_20260924_b050b725',
        ],
        'database.default' => 'new_transaction_audit',
    ]);
    $identity = DB::selectOne('SELECT DATABASE() AS name, @@hostname AS host');
    if ($identity->name !== 'banksampah_integrity_testing_20260924_b050b725' || $identity->host !== 'DESKTOP-PDMMRQ1') {
        throw new RuntimeException('Wrong test target.');
    }
    $this->auditBaseline = newTransactionBackupFingerprint();
    DB::beginTransaction();
    $this->travelTo(new DateTimeImmutable('2026-09-24 22:00:00+07:00'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

afterEach(function (): void {
    if (config('database.default') === 'new_transaction_audit') {
        Event::forget('eloquent.creating: '.JournalLine::class);
        Event::forget('eloquent.creating: '.DepositItem::class);
        Event::forget('eloquent.creating: '.SaleItem::class);
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        expect(newTransactionBackupFingerprint())->toBe($this->auditBaseline);
        DB::purge('new_transaction_audit');
    }
});

/** @return array{user: User, customer: Customer, waste: WasteType, cash: CashAccount, collector: Collector} */
function newTransactionFixture(): array
{
    $user = User::factory()->create();
    $customer = Customer::create(['customer_code' => 'AUDIT-'.Str::ulid(), 'name' => 'Synthetic transaction customer']);
    $waste = WasteType::create(['code' => 'AUDIT-'.Str::ulid(), 'name' => 'Synthetic transaction stock']);
    $cash = CashAccount::create(['code' => 'A-'.Str::ulid(), 'name' => 'Synthetic transaction cash', 'account_type' => 'cash', 'is_active' => true]);
    $collector = Collector::create(['code' => 'A-'.Str::ulid(), 'name' => 'Synthetic collector', 'is_active' => true]);
    BalanceMutation::create(['customer_id' => $customer->id, 'type' => 'credit', 'amount' => '100000.00', 'reference_type' => 'test_fixture', 'reference_id' => $customer->id, 'transaction_date' => '2026-09-24']);
    InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'in', 'quantity' => '100.000', 'unit_cost' => '600.00', 'total_cost' => '60000.00', 'reference_type' => 'test_fixture', 'reference_id' => $waste->id, 'transaction_date' => '2026-09-24']);
    CashMutation::create(['cash_account_id' => $cash->id, 'mutation_type' => 'in', 'amount' => '100000.00', 'reference_type' => 'test_fixture', 'reference_id' => $cash->id, 'transaction_date' => '2026-09-24']);

    return compact('user', 'customer', 'waste', 'cash', 'collector');
}

/** @param array{user: User, customer: Customer, waste: WasteType, cash: CashAccount, collector: Collector} $fixture */
function newTransactionDocument(string $type, array $fixture): Deposit|Withdrawal|Sale
{
    $number = 'TEST-'.Str::ulid();
    if ($type === 'deposit') {
        $record = Deposit::create(['deposit_number' => $number, 'customer_id' => $fixture['customer']->id, 'transaction_date' => '2026-09-24', 'status' => 'draft', 'total_weight' => '2.000', 'total_amount' => '1200.00']);
        $record->items()->create(['waste_type_id' => $fixture['waste']->id, 'weight' => '2.000', 'price' => '600.00', 'subtotal' => '1200.00']);

        return $record;
    }
    if ($type === 'withdrawal') {
        return Withdrawal::create(['withdrawal_number' => $number, 'customer_id' => $fixture['customer']->id, 'cash_account_id' => $fixture['cash']->id, 'transaction_date' => '2026-09-24', 'status' => 'draft', 'amount' => '1000.00']);
    }
    $record = Sale::create(['sale_number' => $number, 'collector_id' => $fixture['collector']->id, 'transaction_date' => '2026-09-24', 'status' => $type === 'payment' ? 'posted' : 'draft', 'total_weight' => '2.000', 'total_amount' => '2000.00', 'total_cost' => '0.00', 'gross_profit' => '0.00', 'payment_status' => 'unpaid']);
    $record->items()->create(['waste_type_id' => $fixture['waste']->id, 'weight' => '2.000', 'price' => '1000.00', 'subtotal' => '2000.00', 'cost_price' => '0.00', 'cost_total' => '0.00', 'gross_profit' => '0.00']);

    return $record;
}

function postNewTransactionDocument(Deposit|Withdrawal|Sale $record, int $userId): void
{
    match (true) {
        $record instanceof Deposit => app(DepositService::class)->post($record, $userId),
        $record instanceof Withdrawal => app(WithdrawalService::class)->post($record, $userId),
        $record instanceof Sale => app(SalePostingService::class)->post($record, $userId),
    };
}

function failNewTransactionSecondJournalLine(): void
{
    $lines = 0;
    Event::listen('eloquent.creating: '.JournalLine::class, function () use (&$lines): void {
        if (++$lines === 2) {
            throw new RuntimeException('Injected journal line failure');
        }
    });
}

test('new posting rolls back every document and ledger change when the second journal line fails', function (string $type): void {
    $fixture = newTransactionFixture();
    $document = newTransactionDocument($type, $fixture);
    $before = newTransactionBackupFingerprint();
    failNewTransactionSecondJournalLine();

    expect(fn () => postNewTransactionDocument($document, $fixture['user']->id))->toThrow(RuntimeException::class, 'Injected journal line failure');
    expect(newTransactionBackupFingerprint())->toBe($before);
})->with(['deposit', 'withdrawal', 'sale']);

test('repeating a posted document is rejected without duplicating its ledgers', function (string $type): void {
    $fixture = newTransactionFixture();
    $document = newTransactionDocument($type, $fixture);
    postNewTransactionDocument($document, $fixture['user']->id);
    $beforeRetry = newTransactionBackupFingerprint();

    expect(fn () => postNewTransactionDocument($document, $fixture['user']->id))->toThrow(Exception::class, 'Hanya transaksi');
    expect(newTransactionBackupFingerprint())->toBe($beforeRetry);
    expect($document->fresh()->getRawOriginal('posted_at'))->not->toBeNull();
    expect((int) $document->fresh()->getRawOriginal('posted_by'))->toBe($fixture['user']->id);
    expect(JournalEntry::where('reference_type', $type)->where('reference_id', $document->id)->count())->toBe(1);
})->with(['deposit', 'withdrawal', 'sale']);

test('new payment retry returns one payment and rejects altered amount or omitted cash account', function (): void {
    $fixture = newTransactionFixture();
    $sale = newTransactionDocument('payment', $fixture);
    $key = (string) Str::uuid();
    $service = app(SalePaymentService::class);
    $payment = $service->recordPayment($sale, '500.00', '2026-09-24', 'cash', null, null, $fixture['user']->id, $key, $fixture['cash']->id);
    $beforeRetry = newTransactionBackupFingerprint();

    expect($service->recordPayment($sale, '500.00', '2026-09-24', 'cash', null, null, $fixture['user']->id, $key, $fixture['cash']->id)->id)->toBe($payment->id);
    expect(fn () => $service->recordPayment($sale, '500.00', '2026-09-24', 'cash', null, null, $fixture['user']->id, $key, null))->toThrow(RuntimeException::class, 'data yang berbeda');
    expect(fn () => $service->recordPayment($sale, '600.00', '2026-09-24', 'cash', null, null, $fixture['user']->id, $key, $fixture['cash']->id))->toThrow(RuntimeException::class, 'data yang berbeda');
    expect(newTransactionBackupFingerprint())->toBe($beforeRetry);
});

test('new payment and sale payment status roll back with cash and journal when journal writing fails', function (): void {
    $fixture = newTransactionFixture();
    $sale = newTransactionDocument('payment', $fixture);
    $before = newTransactionBackupFingerprint();
    failNewTransactionSecondJournalLine();

    expect(fn () => app(SalePaymentService::class)->recordPayment($sale, '500.00', '2026-09-24', 'cash', null, null, $fixture['user']->id, (string) Str::uuid(), $fixture['cash']->id))->toThrow(RuntimeException::class, 'Injected journal line failure');
    expect(newTransactionBackupFingerprint())->toBe($before);
});

test('new cash receipt rolls back with journal when its second line fails', function (): void {
    $fixture = newTransactionFixture();
    $service = app(CashMutationService::class);
    $key = (string) Str::uuid();
    $counter = (int) DB::table('accounts')->where('system_key', 'opening_balance')->value('id');
    $before = newTransactionBackupFingerprint();
    failNewTransactionSecondJournalLine();

    expect(fn () => $service->recordManualReceipt($fixture['cash']->id, '2026-09-24', '500.00', null, null, $counter, $fixture['user']->id, $key))->toThrow(RuntimeException::class, 'Injected journal line failure');
    expect(newTransactionBackupFingerprint())->toBe($before);
});

test('create and post withdrawal rolls back the newly created document on journal failure', function (): void {
    $fixture = newTransactionFixture();
    $this->actingAs($fixture['user']);
    $before = newTransactionBackupFingerprint();
    failNewTransactionSecondJournalLine();

    Livewire::test(CreateWithdrawal::class)->fillForm([
        'customer_id' => $fixture['customer']->id, 'cash_account_id' => $fixture['cash']->id,
        'available_balance' => '100000.00', 'transaction_date' => '2026-09-24', 'amount' => '1000.00', 'status' => 'posted',
    ])->call('create')->assertHasFormErrors(['amount']);
    expect(newTransactionBackupFingerprint())->toBe($before);
});

test('replayed withdrawal form cannot create another document even when browser submit guard resets', function (): void {
    $fixture = newTransactionFixture();
    $this->actingAs($fixture['user']);
    $component = Livewire::test(CreateWithdrawal::class)->fillForm([
        'customer_id' => $fixture['customer']->id, 'cash_account_id' => $fixture['cash']->id,
        'available_balance' => '100000.00', 'transaction_date' => '2026-09-24', 'amount' => '1000.00', 'status' => 'posted',
    ])->call('create')->assertHasNoFormErrors();
    $beforeRetry = newTransactionBackupFingerprint();

    $component->set('isCreating', false)->call('create')->assertHasFormErrors(['withdrawal_number']);
    expect(newTransactionBackupFingerprint())->toBe($beforeRetry);
});

test('backup dashboard describes missing cash accounts while preserving discrepancy warnings', function (): void {
    $snapshot = app(DashboardReportingService::class)->snapshot('2026-09-24');

    expect($snapshot['cards']['cash']['has_accounts'])->toBeFalse();
    expect($snapshot['balanced'])->toBeFalse();
    Livewire::test(FinancialOverview::class, ['snapshot' => $snapshot])
        ->assertSee('Belum ada rekening kas untuk direkonsiliasi')
        ->assertSee('Belum ada rekening bank untuk direkonsiliasi')
        ->assertSee('Selisih dengan GL')->assertDontSee('Sesuai GL');
});

test('a real cash account with matching balances still displays the GL match', function (): void {
    CashAccount::create(['code' => 'TEST-EMPTY', 'name' => 'Test account', 'account_type' => 'cash', 'is_active' => true]);
    $snapshot = app(DashboardReportingService::class)->snapshot('2026-09-24');

    expect($snapshot['cards']['cash']['has_accounts'])->toBeTrue();
    Livewire::test(FinancialOverview::class, ['snapshot' => $snapshot])->assertSee('Sesuai GL')
        ->assertDontSee('Belum ada rekening kas untuk direkonsiliasi');
});

test('drafts with partial prior ledger records are rejected before creating additional mutations', function (string $type, string $ledger): void {
    $fixture = newTransactionFixture();
    $document = newTransactionDocument($type, $fixture);
    if ($ledger === 'balance') {
        BalanceMutation::create(['customer_id' => $fixture['customer']->id, 'type' => 'credit', 'amount' => '1200.00', 'reference_type' => $type, 'reference_id' => $document->id, 'transaction_date' => '2026-09-24']);
    } elseif ($ledger === 'inventory') {
        InventoryMovement::create(['waste_type_id' => $fixture['waste']->id, 'movement_type' => 'in', 'quantity' => '2.000', 'unit_cost' => '600.00', 'total_cost' => '1200.00', 'reference_type' => $type, 'reference_id' => $document->id, 'transaction_date' => '2026-09-24']);
    } elseif ($ledger === 'cash') {
        CashMutation::create(['cash_account_id' => $fixture['cash']->id, 'mutation_type' => 'out', 'amount' => '1000.00', 'reference_type' => $type, 'reference_id' => $document->id, 'transaction_date' => '2026-09-24']);
    } else {
        JournalEntry::create(['entry_number' => 'TEST-'.Str::ulid(), 'transaction_date' => '2026-09-24', 'reference_type' => $type, 'reference_id' => $document->id, 'status' => 'posted']);
    }
    $before = newTransactionBackupFingerprint();

    expect(fn () => postNewTransactionDocument($document, $fixture['user']->id))->toThrow(Exception::class, 'sudah');
    expect(newTransactionBackupFingerprint())->toBe($before);
})->with([
    ['deposit', 'balance'], ['deposit', 'inventory'], ['deposit', 'journal'],
    ['withdrawal', 'cash'], ['withdrawal', 'journal'], ['sale', 'journal'],
]);

test('new deposit cancellation cannot make customer savings negative after a withdrawal', function (): void {
    $fixture = newTransactionFixture();
    BalanceMutation::where('customer_id', $fixture['customer']->id)->where('reference_type', 'test_fixture')->update(['amount' => '0.00']);
    $deposit = newTransactionDocument('deposit', $fixture);
    postNewTransactionDocument($deposit, $fixture['user']->id);
    $withdrawal = newTransactionDocument('withdrawal', $fixture);
    $withdrawal->update(['amount' => '150.00']);
    postNewTransactionDocument($withdrawal, $fixture['user']->id);
    $before = newTransactionBackupFingerprint();

    expect(fn () => app(DepositService::class)->cancel($deposit, 'Uji pembatalan setelah penarikan', $fixture['user']->id, true))->toThrow(Exception::class, 'saldo nasabah tidak mencukupi');
    expect(newTransactionBackupFingerprint())->toBe($before);
});

test('draft header and relationship items roll back together on item creation failure', function (string $type): void {
    $fixture = newTransactionFixture();
    $this->actingAs($fixture['user']);
    $component = $type === 'deposit' ? CreateDeposit::class : CreateSale::class;
    $itemClass = $type === 'deposit' ? DepositItem::class : SaleItem::class;
    $form = Livewire::test($component)->fillForm([
        $type === 'deposit' ? 'customer_id' : 'collector_id' => $type === 'deposit' ? $fixture['customer']->id : $fixture['collector']->id,
        'transaction_date' => '2026-09-24', 'status' => 'draft', 'total_weight' => '2.000', 'total_amount' => '1200.00',
        'items' => [['waste_type_id' => $fixture['waste']->id, 'weight' => '2.000', 'price' => '600.00', 'subtotal' => '1200.00']],
    ]);
    $before = newTransactionBackupFingerprint();
    Event::listen('eloquent.creating: '.$itemClass, function (): void {
        throw new RuntimeException('Injected item failure');
    });

    expect(fn () => $form->call('create'))->toThrow(RuntimeException::class, 'Injected item failure');
    expect(newTransactionBackupFingerprint())->toBe($before);
})->with(['deposit', 'sale']);

test('replayed deposit and sale forms cannot create duplicate drafts', function (string $type): void {
    $fixture = newTransactionFixture();
    $this->actingAs($fixture['user']);
    $component = $type === 'deposit' ? CreateDeposit::class : CreateSale::class;
    $form = Livewire::test($component)->fillForm([
        $type === 'deposit' ? 'customer_id' : 'collector_id' => $type === 'deposit' ? $fixture['customer']->id : $fixture['collector']->id,
        'transaction_date' => '2026-09-24', 'status' => 'posted', 'total_weight' => '2.000', 'total_amount' => '1200.00',
        'items' => [['waste_type_id' => $fixture['waste']->id, 'weight' => '2.000', 'price' => '600.00', 'subtotal' => '1200.00']],
    ])->call('create')->assertHasNoFormErrors();
    $before = newTransactionBackupFingerprint();

    expect($form->instance()->record->fresh()->status)->toBe('draft');
    $form->set('isCreating', false)->call('create')->assertHasFormErrors([$type === 'deposit' ? 'deposit_number' : 'sale_number']);
    expect(newTransactionBackupFingerprint())->toBe($before);
})->with(['deposit', 'sale']);

test('missing cash accounts never hide a nonzero GL difference', function (): void {
    $snapshot = app(DashboardReportingService::class)->snapshot('2026-09-24');
    $snapshot['cards']['cash']['gl_balance'] = '100.00';
    $snapshot['cards']['cash']['difference'] = '-100.00';
    $snapshot['cards']['cash']['balanced'] = false;

    Livewire::test(FinancialOverview::class, ['snapshot' => $snapshot])
        ->assertSee('Belum ada rekening kas untuk direkonsiliasi')->assertSee('100,00')
        ->assertSee('Selisih dengan GL')->assertDontSee('Sesuai GL');
});

test('sale edit preserves its immutable request number and rejects edits after posting', function (): void {
    $fixture = newTransactionFixture();
    $this->actingAs($fixture['user']);
    $sale = newTransactionDocument('sale', $fixture);
    $number = $sale->sale_number;
    Livewire::test(EditSale::class, ['record' => $sale->id])->fillForm(['notes' => 'Draft revised'])
        ->call('save')->assertHasNoFormErrors();
    expect($sale->fresh()->sale_number)->toBe($number);
    $page = Livewire::test(EditSale::class, ['record' => $sale->id])->instance();
    postNewTransactionDocument($sale, $fixture['user']->id);
    $before = newTransactionBackupFingerprint();

    expect(fn () => (new ReflectionMethod($page, 'beforeValidate'))->invoke($page))
        ->toThrow(ValidationException::class, 'sudah dibukukan');
    expect(newTransactionBackupFingerprint())->toBe($before);
});

test('successful new cancellations preserve the actor and date and cannot be repeated', function (string $type): void {
    $fixture = newTransactionFixture();
    $document = newTransactionDocument($type, $fixture);
    postNewTransactionDocument($document, $fixture['user']->id);
    $service = $type === 'deposit' ? app(DepositService::class) : app(WithdrawalService::class);
    $service->cancel($document, 'Koreksi transaksi pengujian', $fixture['user']->id, true);
    $before = newTransactionBackupFingerprint();

    expect($document->fresh()->getRawOriginal('cancelled_at'))->not->toBeNull();
    expect((int) $document->fresh()->getRawOriginal('cancelled_by'))->toBe($fixture['user']->id);
    expect($document->fresh()->getRawOriginal('cancellation_reason'))->toContain('Koreksi transaksi pengujian');
    expect(fn () => $service->cancel($document, 'Koreksi transaksi pengujian', $fixture['user']->id, true))->toThrow(Exception::class);
    expect(newTransactionBackupFingerprint())->toBe($before);
})->with(['deposit', 'withdrawal']);
