<?php

use App\Filament\Resources\Deposits\Pages\CreateDeposit;
use App\Models\BalanceMutation;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\WastePrice;
use App\Models\WasteType;
use App\Services\DepositService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

/** @return array<string, string> */
function depositPrecisionFingerprint(): array
{
    $result = [];
    foreach (['customers', 'waste_types', 'waste_prices', 'users', 'deposits', 'deposit_items', 'balance_mutations', 'inventory_movements', 'journal_entries', 'journal_lines'] as $table) {
        $result[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
    }

    return $result;
}

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Precision tests require the testing environment.');
    }
    config([
        'database.connections.deposit_precision_test' => [
            ...config('database.connections.mysql'), 'url' => null, 'host' => '127.0.0.1',
            'port' => 3306, 'unix_socket' => '', 'database' => 'banksampah_testing',
        ],
        'database.default' => 'deposit_precision_test',
    ]);
    $identity = DB::selectOne('SELECT DATABASE() AS name, @@hostname AS host');
    if ($identity->name !== 'banksampah_testing' || $identity->host !== 'DESKTOP-PDMMRQ1') {
        throw new RuntimeException('Wrong precision test database.');
    }
    $this->precisionBaseline = depositPrecisionFingerprint();
    DB::beginTransaction();
    $this->travelTo(new DateTimeImmutable('2026-09-25 10:00:00+07:00'));
});

afterEach(function (): void {
    if (isset($this->precisionBaseline)) {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        expect(depositPrecisionFingerprint())->toBe($this->precisionBaseline);
        DB::purge('deposit_precision_test');
    }
});

/**
 * @param  array<string, string>  $header
 * @param  array<string, string>  $item
 */
function depositPrecisionFixture(array $header = [], array $item = []): Deposit
{
    $customer = Customer::create(['customer_code' => 'DP-'.Str::ulid(), 'name' => 'Nasabah Uji Presisi']);
    $waste = WasteType::create(['code' => 'DP-'.Str::ulid(), 'name' => 'Bahan Uji Presisi']);
    $deposit = Deposit::create([
        'deposit_number' => 'DP-'.Str::ulid(), 'customer_id' => $customer->id,
        'transaction_date' => '2026-09-25', 'status' => 'draft',
        'total_weight' => '2.000', 'total_amount' => '1200.00', ...$header,
    ]);
    $deposit->items()->create([
        'waste_type_id' => $waste->id, 'weight' => '2.000', 'price' => '600.00', 'subtotal' => '1200.00', ...$item,
    ]);

    return $deposit;
}

test('deposit posting rejects even the smallest stored mismatch without changing any ledger', function (array $header, array $item, string $message): void {
    $deposit = depositPrecisionFixture($header, $item);
    $before = depositPrecisionFingerprint();

    expect(fn () => app(DepositService::class)->post($deposit))->toThrow(Exception::class, $message);

    expect($deposit->fresh()->status)->toBe('draft');
    expect(depositPrecisionFingerprint())->toBe($before);
})->with([
    'header one cent too high' => [['total_amount' => '1200.01'], [], 'Nilai setoran tidak sesuai'],
    'header one cent too low' => [['total_amount' => '1199.99'], [], 'Nilai setoran tidak sesuai'],
    'weight one gram too high' => [['total_weight' => '2.001'], [], 'Total berat tidak sesuai'],
    'weight one gram too low' => [['total_weight' => '1.999'], [], 'Total berat tidak sesuai'],
    'item one cent too high' => [['total_amount' => '1200.01'], ['subtotal' => '1200.01'], 'subtotal bahan yang tidak sesuai'],
    'item one cent too low' => [['total_amount' => '1199.99'], ['subtotal' => '1199.99'], 'subtotal bahan yang tidak sesuai'],
]);

test('deposit posting rejects nonpositive weight or price without changing any ledger', function (array $item, string $message): void {
    $deposit = depositPrecisionFixture(item: $item);
    $before = depositPrecisionFingerprint();

    expect(fn () => app(DepositService::class)->post($deposit))->toThrow(Exception::class, $message);
    expect(depositPrecisionFingerprint())->toBe($before);
})->with([
    'zero weight' => [['weight' => '0.000'], 'berat bahan yang nol atau negatif'],
    'negative weight' => [['weight' => '-0.001'], 'berat bahan yang nol atau negatif'],
    'zero price' => [['price' => '0.00'], 'harga bahan yang nol atau negatif'],
    'negative price' => [['price' => '-0.01'], 'harga bahan yang nol atau negatif'],
]);

test('deposit posting keeps savings inventory and journals equal after rounding each item', function (string $weight, string $price, string $subtotal, string $totalWeight, string $totalAmount): void {
    $deposit = depositPrecisionFixture(
        ['total_weight' => $totalWeight, 'total_amount' => $totalAmount],
        ['weight' => $weight, 'price' => $price, 'subtotal' => $subtotal],
    );
    $deposit->items()->create([
        'waste_type_id' => $deposit->items()->sole()->waste_type_id,
        'weight' => $weight, 'price' => $price, 'subtotal' => $subtotal,
    ]);

    app(DepositService::class)->post($deposit);

    expect($deposit->fresh()->status)->toBe('posted');
    $balance = BalanceMutation::where('reference_type', 'deposit')->where('reference_id', $deposit->id)->sole();
    expect($balance->amount)->toBe($totalAmount);
    $inventory = InventoryMovement::where('reference_type', 'deposit')->where('reference_id', $deposit->id);
    expect($inventory->count())->toBe(2);
    expect((string) $inventory->sum('quantity'))->toBe($totalWeight);
    expect((string) $inventory->sum('total_cost'))->toBe($totalAmount);
    $journal = JournalEntry::where('reference_type', 'deposit')->where('reference_id', $deposit->id)->sole();
    expect((string) $journal->lines()->sum('debit'))->toBe($totalAmount);
    expect((string) $journal->lines()->sum('credit'))->toBe($totalAmount);
})->with([
    'half a cent rounds up per item' => ['1.005', '1.00', '1.01', '2.010', '2.02'],
    'less than half a cent rounds down' => ['1.004', '1.00', '1.00', '2.008', '2.00'],
    'fractional weight and price' => ['0.125', '0.12', '0.02', '0.250', '0.04'],
]);

test('deposit posting rejects rounding the grand total instead of summing rounded items', function (): void {
    $deposit = depositPrecisionFixture(
        ['total_weight' => '2.010', 'total_amount' => '2.01'],
        ['weight' => '1.005', 'price' => '1.00', 'subtotal' => '1.01'],
    );
    $deposit->items()->create([
        'waste_type_id' => $deposit->items()->sole()->waste_type_id,
        'weight' => '1.005', 'price' => '1.00', 'subtotal' => '1.01',
    ]);
    $before = depositPrecisionFingerprint();

    expect(fn () => app(DepositService::class)->post($deposit))->toThrow(Exception::class, 'Nilai setoran tidak sesuai');
    expect(depositPrecisionFingerprint())->toBe($before);
});

test('deposit form saves rounded item subtotals whose total can be posted without a discrepancy', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->actingAs(User::factory()->create());
    $customer = Customer::create(['customer_code' => 'DP-'.Str::ulid(), 'name' => 'Nasabah Form Presisi']);
    $waste = WasteType::create(['code' => 'DP-'.Str::ulid(), 'name' => 'Bahan Form Presisi']);
    $form = Livewire::test(CreateDeposit::class)->fillForm([
        'customer_id' => $customer->id, 'transaction_date' => '2026-09-25',
        'items' => [
            ['waste_type_id' => $waste->id, 'weight' => '1.000', 'price' => '1.00', 'subtotal' => '1.00'],
            ['waste_type_id' => $waste->id, 'weight' => '1.000', 'price' => '1.00', 'subtotal' => '1.00'],
        ],
    ]);
    $keys = array_keys($form->get('data.items'));

    $form->set('data.items.'.$keys[0].'.weight', '1.005')
        ->set('data.items.'.$keys[1].'.weight', '1.005');

    expect((string) $form->get('data.total_amount'))->toBe('2.02');
    $form->call('create')->assertHasNoFormErrors();
    $deposit = $form->instance()->record;
    expect($deposit->fresh()->total_amount)->toBe('2.02');
    expect($deposit->items()->pluck('subtotal')->all())->toBe(['1.01', '1.01']);

    app(DepositService::class)->post($deposit);

    expect($deposit->fresh()->status)->toBe('posted');
    expect(BalanceMutation::where('reference_type', 'deposit')->where('reference_id', $deposit->id)->sole()->amount)->toBe('2.02');
    expect((string) InventoryMovement::where('reference_type', 'deposit')->where('reference_id', $deposit->id)->sum('total_cost'))->toBe('2.02');
});

test('deposit form keeps decimal totals when selecting a price removing an item and clearing a weight', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->actingAs(User::factory()->create());
    $waste = WasteType::create(['code' => 'DP-'.Str::ulid(), 'name' => 'Bahan Harga Desimal']);
    WastePrice::create(['waste_type_id' => $waste->id, 'price' => '0.12', 'effective_from' => '2026-09-25', 'is_active' => true]);
    $form = Livewire::test(CreateDeposit::class)->fillForm([
        'items' => [
            ['weight' => '0.125', 'price' => '0.00', 'subtotal' => '0.00'],
            ['weight' => '0.125', 'price' => '0.00', 'subtotal' => '0.00'],
        ],
    ]);
    $keys = array_keys($form->get('data.items'));

    $form->set('data.items.'.$keys[0].'.waste_type_id', $waste->id)
        ->set('data.items.'.$keys[1].'.waste_type_id', $waste->id);

    expect((string) $form->get('data.total_amount'))->toBe('0.04');
    expect((string) $form->get('data.items.'.$keys[0].'.subtotal'))->toBe('0.02');

    $form->set('data.items', [$keys[0] => $form->get('data.items.'.$keys[0])]);

    expect((string) $form->get('data.total_amount'))->toBe('0.02');
    expect((string) $form->get('data.total_weight'))->toBe('0.125');

    $form->set('data.items.'.$keys[0].'.weight', '');

    expect($form->get('data.total_amount'))->toEqual(0);
});
