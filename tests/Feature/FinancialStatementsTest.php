<?php

use App\Filament\Pages\BalanceSheet;
use App\Filament\Pages\IncomeStatement;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\FinancialReportingService;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian hanya boleh berjalan dalam environment testing.');
    }

    config([
        'database.connections.financial_statements_test' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'database' => 'banksampah_testing',
        ],
        'database.default' => 'financial_statements_test',
    ]);
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql'
        || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'banksampah_testing') {
        throw new RuntimeException('Pengujian hanya boleh memakai MySQL banksampah_testing.');
    }

    $connection->beginTransaction();
    app(AccountSeeder::class)->run();
});

afterEach(function (): void {
    if (config('database.default') === 'financial_statements_test' && DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
});

function financialStatementJournal(string $date, string $debitKey, string $creditKey, string $amount): JournalEntry
{
    return app(JournalService::class)->post(
        transactionDate: $date,
        referenceType: 'statement_'.Str::ulid(),
        referenceId: 1,
        referenceNumber: null,
        description: 'Jurnal pengujian laporan keuangan',
        userId: null,
        lines: [
            ['account_id' => Account::query()->where('system_key', $debitKey)->sole()->id, 'debit' => $amount, 'credit' => '0.00'],
            ['account_id' => Account::query()->where('system_key', $creditKey)->sole()->id, 'debit' => '0.00', 'credit' => $amount],
        ]
    );
}

test('laba rugi hanya memasukkan periode sedangkan neraca membawa modal dan laba tahun sebelumnya', function (): void {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(10, 0));
    financialStatementJournal('2025-12-30', 'cash', 'opening_balance', '1000.00');
    financialStatementJournal('2025-12-31', 'cash', 'sales_revenue', '200.05');
    financialStatementJournal('2026-01-01', 'cash', 'sales_revenue', '100.10');
    financialStatementJournal('2026-01-10', 'operating_expense', 'cash', '30.03');
    financialStatementJournal('2026-01-31', 'cash', 'sales_revenue', '40.04');
    financialStatementJournal('2026-01-31', 'cogs', 'cash', '10.01');
    financialStatementJournal('2026-01-31', 'cash', 'customer_savings', '50.05');
    financialStatementJournal('2026-02-01', 'cash', 'sales_revenue', '999.99');
    $ignored = financialStatementJournal('2026-01-15', 'cash', 'sales_revenue', '888.88');
    $ignored->update(['status' => 'draft']);
    $service = app(FinancialReportingService::class);

    $income = $service->incomeStatement('2026-01-01', '2026-01-31');
    $balance = $service->balanceSheet('2026-01-31');

    expect($income['valid'])->toBeTrue();
    expect($income['totals'])->toBe(['revenue' => '140.14', 'expense' => '40.04', 'net_profit' => '100.10']);
    expect($balance['totals'])->toBe([
        'assets' => '1350.20', 'liabilities' => '50.05', 'equity' => '1000.00',
        'unclosed_earnings' => '300.15', 'equity_including_earnings' => '1300.15',
        'liabilities_and_equity' => '1350.20',
    ]);
    expect($balance['difference'])->toBe('0.00');
    expect($balance['balanced'])->toBeTrue();
});

test('reversal pada bulan berikutnya mempertahankan laba asal dan menampilkan rugi pada periode pembalik', function (): void {
    $this->travelTo(now()->setDate(2026, 2, 2)->setTime(10, 0));
    $original = financialStatementJournal('2026-01-31', 'cash', 'sales_revenue', '100.05');
    app(JournalService::class)->reverse(
        journalEntry: $original,
        transactionDate: '2026-02-01',
        referenceType: 'statement_reversal',
        referenceId: $original->id,
        referenceNumber: null,
        description: 'Pembalik laporan uji',
        userId: User::factory()->create()->id,
    );
    $service = app(FinancialReportingService::class);

    $january = $service->incomeStatement('2026-01-01', '2026-01-31');
    $february = $service->incomeStatement('2026-02-01', '2026-02-02');
    $before = $service->balanceSheet('2026-01-31');
    $after = $service->balanceSheet('2026-02-01');

    expect($january['totals']['net_profit'])->toBe('100.05');
    expect($february['totals']['net_profit'])->toBe('-100.05');
    expect($before['totals']['assets'])->toBe('100.05');
    expect($before['totals']['unclosed_earnings'])->toBe('100.05');
    expect($before['balanced'])->toBeTrue();
    expect($after['totals']['assets'])->toBe('0.00');
    expect($after['totals']['unclosed_earnings'])->toBe('0.00');
    expect($after['balanced'])->toBeTrue();
});

test('klasifikasi akun tidak bergantung kode dan histori kontra tetap tampil meskipun nonaktif', function (): void {
    $this->travelTo(now()->setDate(2026, 2, 2));
    $revenue = Account::query()->where('system_key', 'sales_revenue')->sole();
    $revenue->update(['code' => '1100-REV']);
    financialStatementJournal('2026-01-10', 'cash', 'sales_revenue', '20.01');
    financialStatementJournal('2026-01-11', 'operating_expense', 'cash', '30.02');
    $cash = Account::query()->where('system_key', 'cash')->sole();
    $cash->update(['is_active' => false, 'is_postable' => false]);
    $revenue->update(['is_active' => false, 'is_postable' => false]);
    $bank = Account::query()->where('system_key', 'bank')->sole();
    $parent = Account::query()->create([
        'code' => 'PARENT', 'name' => 'Induk tanpa jurnal', 'account_type' => Account::TYPE_ASSET,
        'normal_balance' => Account::NORMAL_DEBIT, 'is_postable' => false,
    ]);
    $service = app(FinancialReportingService::class);

    $income = $service->incomeStatement('2026-01-01', '2026-01-31');
    $balance = $service->balanceSheet('2026-01-31');

    expect($income['rows']->get($revenue->id)['balance'])->toBe('20.01');
    expect($income['totals']['net_profit'])->toBe('-10.01');
    expect($balance['rows']->get($cash->id)['balance'])->toBe('-10.01');
    expect($balance['rows']->get($bank->id)['balance'])->toBe('0.00');
    expect($balance['rows']->has($parent->id))->toBeFalse();
    expect($balance['totals']['unclosed_earnings'])->toBe('-10.01');
    expect($balance['balanced'])->toBeTrue();
    $empty = $service->incomeStatement('2026-02-01', '2026-02-02');
    expect($empty['totals']['net_profit'])->toBe('0.00');
    expect($empty['rows']->get($revenue->id)['balance'])->toBe('0.00');
});

test('pemindahan laba ke ekuitas tidak menggandakan laba belum ditutup', function (): void {
    $this->travelTo(now()->setDate(2026, 2, 2));
    financialStatementJournal('2025-12-30', 'cash', 'sales_revenue', '100.00');
    financialStatementJournal('2025-12-31', 'sales_revenue', 'opening_balance', '100.00');
    financialStatementJournal('2026-01-01', 'cash', 'sales_revenue', '10.01');

    $report = app(FinancialReportingService::class)->balanceSheet('2026-01-31');

    expect($report['totals']['assets'])->toBe('110.01');
    expect($report['totals']['equity'])->toBe('100.00');
    expect($report['totals']['unclosed_earnings'])->toBe('10.01');
    expect($report['totals']['equity_including_earnings'])->toBe('110.01');
    expect($report['balanced'])->toBeTrue();
});

test('laporan mempertahankan presisi agregat besar dan hanya membaca database', function (): void {
    $this->travelTo(now()->setDate(2026, 2, 2));
    foreach (range(1, 10) as $number) {
        financialStatementJournal('2026-01-01', 'cash', 'sales_revenue', '9999999999999.99');
    }
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    try {
        $income = app(FinancialReportingService::class)->incomeStatement('2026-01-01', '2026-01-31');
        $balance = app(FinancialReportingService::class)->balanceSheet('2026-01-31');
        $queries = $connection->getQueryLog();
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }

    expect($income['totals']['net_profit'])->toBe('99999999999999.90');
    expect($balance['totals']['assets'])->toBe('99999999999999.90');
    expect($balance['difference'])->toBe('0.00');
    expect($balance['balanced'])->toBeTrue();
    expect(view('filament.financial-amount', ['amount' => $income['totals']['net_profit']])->render())
        ->toContain('Rp 99.999.999.999.999,90');
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
});

test('neraca mengungkap selisih jurnal yang rusak tanpa memaksakan seimbang', function (): void {
    $this->travelTo(now()->setDate(2026, 2, 2));
    $journal = financialStatementJournal('2026-01-01', 'cash', 'opening_balance', '10.00');
    /** Simulasi data tidak balance; service laporan harus menampilkan selisihnya. */
    $journal->lines()->where('debit', '10.00')->update(['debit' => '10.01']);

    $report = app(FinancialReportingService::class)->balanceSheet('2026-01-31');

    expect($report['difference'])->toBe('0.01');
    expect($report['balanced'])->toBeFalse();
});

test('laba rugi memperingatkan neraca saldo tidak seimbang dan tetap menampilkan angka asli', function (): void {
    $this->travelTo(now()->setDate(2026, 2, 2));
    $journal = financialStatementJournal('2026-01-15', 'cash', 'sales_revenue', '10.00');
    $journal->lines()->where('credit', '10.00')->update(['credit' => '10.01']);
    $revenue = Account::query()->where('system_key', 'sales_revenue')->sole();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->actingAs(User::factory()->create());

    $report = app(FinancialReportingService::class)->incomeStatement('2026-01-01', '2026-01-31');

    expect($report['valid'])->toBeTrue();
    expect($report['balanced'])->toBeFalse();
    expect($report['totals'])->toBe(['revenue' => '10.01', 'expense' => '0.00', 'net_profit' => '10.01']);
    expect($report['rows']->get($revenue->id)['balance'])->toBe('10.01');
    Livewire::test(IncomeStatement::class)
        ->set('startDate', '2026-01-01')->set('endDate', '2026-01-31')
        ->assertSee('Peringatan: Neraca Saldo tidak seimbang')
        ->assertSee('Angka Laba Rugi tetap ditampilkan apa adanya untuk pemeriksaan.')
        ->assertSee('Rp 10,01')
        ->assertCanSeeTableRecords([$revenue->id])
        ->set('endDate', '2026-01-14')
        ->assertDontSee('Peringatan: Neraca Saldo tidak seimbang')
        ->assertSee('Rp 0,00')
        ->set('startDate', '2026-02-01')
        ->assertSee('Periode tidak valid.')
        ->assertDontSee('Peringatan: Neraca Saldo tidak seimbang');
});

test('klasifikasi master yang tidak dikenal ditolak', function (): void {
    Account::query()->where('system_key', 'cash')->update(['account_type' => 'unknown']);

    expect(fn () => app(FinancialReportingService::class)->balanceSheet('2026-01-31'))
        ->toThrow(RuntimeException::class, 'Klasifikasi akun laporan keuangan tidak valid.');
});

test('periode dan tanggal tidak sah menghasilkan laporan invalid tanpa saldo', function (?string $start, ?string $end): void {
    $income = app(FinancialReportingService::class)->incomeStatement($start, $end);

    expect($income['valid'])->toBeFalse();
    expect($income['rows'])->toBeEmpty();
    expect($income['totals']['net_profit'])->toBe('0.00');
})->with([
    'kosong' => [null, null],
    'kalender salah' => ['2026-02-30', '2026-03-01'],
    'terbalik' => ['2026-02-01', '2026-01-01'],
]);

test('neraca dengan tanggal invalid tidak dinyatakan balanced', function (): void {
    $report = app(FinancialReportingService::class)->balanceSheet('tanggal-salah');

    expect($report['valid'])->toBeFalse();
    expect($report['balanced'])->toBeFalse();
    expect($report['rows'])->toBeEmpty();
});

test('halaman laba rugi dan neraca memperbarui angka ketika tanggal berubah', function (): void {
    $this->travelTo(now()->setDate(2026, 2, 2));
    financialStatementJournal('2026-01-31', 'cash', 'sales_revenue', '1234.56');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->actingAs(User::factory()->create());

    Livewire::test(IncomeStatement::class)
        ->set('startDate', '2026-01-01')->set('endDate', '2026-01-31')
        ->assertSee('Laba / Rugi Bersih')->assertSee('Rp 1.234,56')
        ->set('endDate', '2026-01-30')->assertDontSee('Rp 1.234,56')->assertSee('Rp 0,00')
        ->set('startDate', '2026-02-01')->assertSee('Periode tidak valid.');
    Livewire::test(BalanceSheet::class)
        ->set('asOfDate', '2026-01-31')->assertSee('Neraca seimbang')->assertSee('Rp 1.234,56')
        ->set('asOfDate', '2026-01-30')->assertDontSee('Rp 1.234,56')->assertSee('Rp 0,00')
        ->set('asOfDate', '')->assertSee('Tanggal laporan tidak valid.');
});

test('halaman laporan memerlukan login dan terdaftar pada panel admin', function (): void {
    $this->get(IncomeStatement::getUrl(panel: 'admin'))->assertRedirect(route('filament.admin.auth.login'));
    $this->get(BalanceSheet::getUrl(panel: 'admin'))->assertRedirect(route('filament.admin.auth.login'));
    $this->actingAs(User::factory()->create());
    $this->get(IncomeStatement::getUrl(panel: 'admin'))->assertSee('Ringkasan Laba Rugi');
    $this->get(BalanceSheet::getUrl(panel: 'admin'))->assertSee('Posisi Keuangan');
});
