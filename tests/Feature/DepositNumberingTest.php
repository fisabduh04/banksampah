<?php

use App\Filament\Resources\Deposits\Pages\CreateDeposit;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositItem;
use App\Models\User;
use App\Models\WasteType;
use App\Services\FinancialDocumentService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        'database.connections.deposit_numbering_test' => [
            ...config('database.connections.mysql'), 'url' => null, 'host' => '127.0.0.1',
            'port' => 3306, 'unix_socket' => '', 'database' => 'banksampah_testing',
        ],
        'database.default' => 'deposit_numbering_test',
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
    if (config('database.default') === 'deposit_numbering_test') {
        Event::forget('eloquent.creating: '.DepositItem::class);
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge('deposit_numbering_test');
    }
});

/** @return array{customer_id: int, transaction_date: string, total_weight: string, total_amount: string} */
function depositNumberingData(): array
{
    $customer = Customer::create(['customer_code' => 'DN-'.Str::ulid(), 'name' => 'Nasabah Uji Penomoran']);

    return [
        'customer_id' => $customer->id,
        'transaction_date' => '2036-09-25',
        'total_weight' => '2.000',
        'total_amount' => '10000.00',
    ];
}

/** @return array<string, mixed> */
function depositNumberingFormData(): array
{
    $data = depositNumberingData();
    $waste = WasteType::create(['code' => 'DN-'.Str::ulid(), 'name' => 'Bahan Uji Penomoran']);

    return [
        ...$data,
        'items' => [['waste_type_id' => $waste->id, 'weight' => '2.000', 'price' => '5000.00', 'subtotal' => '10000.00']],
    ];
}

test('nomor baru melanjutkan nomor urut terbesar dan mempertahankan nomor historis', function (): void {
    $data = depositNumberingData();
    foreach (['ST-2036-000010', 'ST-2036-000001', 'ST-2036-01M3BTM4T0K3Y1CMKG2E17D4N5'] as $number) {
        Deposit::create([...$data, 'deposit_number' => $number, 'status' => 'posted']);
    }

    $first = app(FinancialDocumentService::class)->createDraft(Deposit::class, $data, 'numbering-first');
    $second = app(FinancialDocumentService::class)->createDraft(Deposit::class, $data, 'numbering-second');

    expect($first->deposit_number)->toBe('ST-2036-000011');
    expect($second->deposit_number)->toBe('ST-2036-000012');
    $this->assertDatabaseHas('deposits', [
        'deposit_number' => 'ST-2036-01M3BTM4T0K3Y1CMKG2E17D4N5', 'status' => 'posted',
    ]);
});

test('nomor dimulai dari satu setiap tahun pembuatan meskipun tanggal transaksi mundur', function (): void {
    $data = depositNumberingData();
    $first = app(FinancialDocumentService::class)->createDraft(Deposit::class, $data, 'numbering-old-year');
    $this->travelTo(new DateTimeImmutable('2037-01-01 00:00:00+07:00'));

    $nextYear = app(FinancialDocumentService::class)->createDraft(Deposit::class, $data, 'numbering-new-year');

    expect($first->deposit_number)->toBe('ST-2036-000001');
    expect($nextYear->deposit_number)->toBe('ST-2037-000001');
});

test('nomor draft yang dihapus tidak dipakai ulang', function (): void {
    $data = depositNumberingData();
    $first = app(FinancialDocumentService::class)->createDraft(Deposit::class, $data, 'numbering-deleted');
    $first->delete();

    $next = app(FinancialDocumentService::class)->createDraft(Deposit::class, $data, 'numbering-after-delete');

    expect($next->deposit_number)->toBe('ST-2036-000002');
});

test('formulir memakai nomor server dan pengiriman ulang tidak membuat draft kedua', function (): void {
    $this->actingAs(User::factory()->create());
    $data = depositNumberingFormData();
    $form = Livewire::test(CreateDeposit::class)->fillForm([
        ...$data, 'deposit_number' => 'NOMOR-PALSU', 'status' => 'posted',
    ]);
    $key = $form->get('creationNumber');

    $form->call('create')->assertHasNoFormErrors();
    $form->set('isCreating', false)->call('create')->assertHasFormErrors(['deposit_number']);

    $this->assertDatabaseHas('deposits', [
        'customer_id' => $data['customer_id'], 'deposit_number' => 'ST-2036-000001',
        'idempotency_key' => $key, 'status' => 'draft',
    ]);
    expect(Deposit::where('customer_id', $data['customer_id'])->count())->toBe(1);
    $this->assertDatabaseHas('document_number_sequences', ['year' => 2036, 'last_number' => 1]);
});

test('dua formulir yang dibuka bersamaan mendapat nomor sesuai urutan penyimpanan', function (): void {
    $this->actingAs(User::factory()->create());
    $data = depositNumberingFormData();
    $firstForm = Livewire::test(CreateDeposit::class)->fillForm($data);
    $secondForm = Livewire::test(CreateDeposit::class)->fillForm($data);

    $secondForm->call('create')->assertHasNoFormErrors();
    $firstForm->call('create')->assertHasNoFormErrors();

    expect($secondForm->instance()->record->deposit_number)->toBe('ST-2036-000001');
    expect($firstForm->instance()->record->deposit_number)->toBe('ST-2036-000002');
});

test('kegagalan detail membatalkan nomor dan draft sehingga formulir dapat dicoba kembali', function (): void {
    $this->actingAs(User::factory()->create());
    $data = depositNumberingFormData();
    $form = Livewire::test(CreateDeposit::class)->fillForm($data);
    Event::listen('eloquent.creating: '.DepositItem::class, function (): void {
        throw new RuntimeException('Gagal menyimpan rincian pengujian');
    });

    expect(fn () => $form->call('create'))->toThrow(RuntimeException::class, 'Gagal menyimpan rincian pengujian');

    $this->assertDatabaseMissing('deposits', ['customer_id' => $data['customer_id']]);
    $this->assertDatabaseMissing('document_number_sequences', ['year' => 2036]);
    Event::forget('eloquent.creating: '.DepositItem::class);

    $form->call('create')->assertHasNoFormErrors();

    $this->assertDatabaseHas('deposits', [
        'customer_id' => $data['customer_id'], 'deposit_number' => 'ST-2036-000001',
    ]);
});

test('formulir lama yang sudah tersimpan dengan ULID tidak dapat dikirim ulang', function (): void {
    $data = depositNumberingData();
    $key = 'ST-2036-01M3BTM4T0K3Y1CMKG2E17D4N5';
    Deposit::create([...$data, 'deposit_number' => $key, 'status' => 'posted']);

    expect(fn () => app(FinancialDocumentService::class)->createDraft(Deposit::class, $data, $key))
        ->toThrow(ValidationException::class, 'Permintaan ini sudah tersimpan.');

    expect(Deposit::where('customer_id', $data['customer_id'])->count())->toBe(1);
    $this->assertDatabaseMissing('document_number_sequences', ['year' => 2036]);
});

test('permintaan tanpa kunci tidak menyimpan setoran atau menghabiskan nomor', function (): void {
    $data = depositNumberingData();

    expect(fn () => app(FinancialDocumentService::class)->createDraft(Deposit::class, $data, ''))
        ->toThrow(ValidationException::class, 'Buka kembali formulir transaksi.');

    $this->assertDatabaseMissing('deposits', ['customer_id' => $data['customer_id']]);
    $this->assertDatabaseMissing('document_number_sequences', ['year' => 2036]);
});
