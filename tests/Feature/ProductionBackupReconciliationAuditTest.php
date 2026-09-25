<?php

use App\Services\ReconciliationService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (getenv('RECONCILIATION_AUDIT') !== '20260924-b050b725') {
        $this->markTestSkipped('Opt-in audit requires the isolated 24 September backup copy.');
    }

    if (! app()->environment('testing')) {
        throw new RuntimeException('Audit tests require the testing environment.');
    }

    config([
        'database.connections.production_backup_audit' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'host' => '127.0.0.1',
            'port' => '3306',
            'unix_socket' => '',
            'database' => 'banksampah_reconciliation_testing_20260924_b050b725',
            'timezone' => '+07:00',
        ],
        'database.default' => 'production_backup_audit',
    ]);

    $connection = DB::connection();
    $identity = $connection->selectOne('SELECT DATABASE() AS name, @@hostname AS host');
    if ($identity->name !== 'banksampah_reconciliation_testing_20260924_b050b725'
        || $identity->host !== 'DESKTOP-PDMMRQ1') {
        throw new RuntimeException('Wrong audit database.');
    }

    $connection->statement('SET SESSION TRANSACTION READ ONLY');
    $connection->beginTransaction();
    $this->travelTo(new DateTimeImmutable('2026-09-24 21:05:00+07:00'));
});

afterEach(function (): void {
    if (config('database.default') === 'production_backup_audit') {
        if (DB::connection()->transactionLevel() > 0) {
            DB::connection()->rollBack();
        }
        DB::purge('production_backup_audit');
    }
});

/** @return list<array<string, mixed>> */
function septemberBackupAuditQuery(string $name): array
{
    $sql = file_get_contents(storage_path('app/private/reconciliation-20260924-queries.sql'));
    if (! preg_match('/-- name: '.preg_quote($name, '/').'\R(.*?);/s', $sql, $match)) {
        throw new RuntimeException('Unknown audit query.');
    }

    return array_map(fn (object $row): array => (array) $row, DB::select($match[1]));
}

test('backup controls show missing GL balances without treating empty cash books as proof of physical cash', function (): void {
    $service = app(ReconciliationService::class);

    expect($service->customerSavingsAsOf('2026-09-24'))
        ->toMatchArray(['customer_balance' => '137000.00', 'gl_balance' => '0.00', 'difference' => '137000.00', 'balanced' => false]);
    expect($service->inventoryAsOf('2026-09-24'))
        ->toMatchArray(['inventory_balance' => '6600.00', 'gl_balance' => '0.00', 'difference' => '6600.00', 'balanced' => false]);
    expect($service->cashAndBankAsOf('2026-09-24')['balanced'])->toBeTrue();
    $this->assertDatabaseCount('cash_accounts', 0);
    $this->assertDatabaseCount('cash_mutations', 0);
});

test('missing references include twelve ledger events and three potential cash flows while excluding the draft', function (): void {
    $events = collect(septemberBackupAuditQuery('missing_events'));

    expect($events)->toHaveCount(12);
    expect($events->whereNotNull('cash_direction')->pluck('amount')->sort()->values()->all())
        ->toBe(['150.00', '850.00', '1500.00']);
    expect($events->where('reference_type', 'withdrawal')->pluck('reference_id')->sort()->values()->all())
        ->toBe([1, 3]);
    expect(septemberBackupAuditQuery('draft_withdrawals_excluded')[0])
        ->toMatchArray(['id' => 2, 'amount' => '100.00', 'status' => 'draft']);
});

test('withdrawal identity cannot be recovered by the reused human readable number', function (): void {
    $orphan = collect(septemberBackupAuditQuery('missing_events'))->where('orphan', 1)->sole();

    expect($orphan)->toMatchArray(['reference_type' => 'withdrawal', 'reference_id' => 1, 'reference_number' => null, 'amount' => '150.00', 'customer_id' => 3]);
    $this->assertDatabaseHas('withdrawals', ['id' => 2, 'withdrawal_number' => 'WD-2026-000001', 'customer_id' => 2, 'amount' => '100.00', 'status' => 'draft']);
    $this->assertDatabaseMissing('withdrawals', ['id' => 1]);
});

test('changed deposit header disagrees with both historical posting and cancellation snapshots', function (): void {
    $events = collect(septemberBackupAuditQuery('missing_events'))
        ->whereIn('reference_type', ['deposit', 'deposit_cancellation'])->where('reference_id', 4);

    expect($events)->toHaveCount(2);
    foreach ($events as $event) {
        expect($event)->toMatchArray([
            'transaction_date' => '2026-09-15', 'amount' => '3000.00', 'customer_id' => 3,
            'source_date' => '2026-07-26', 'source_amount' => '54900.00', 'source_customer_id' => 16,
        ]);
    }
});

test('cancelled deposits contain repeated stock receipts and the current source implies negative ACCU stock', function (): void {
    $duplicates = septemberBackupAuditQuery('repeated_inventory_sources');
    $types = collect(septemberBackupAuditQuery('inventory_by_type'))->keyBy('waste_type_id');
    $consistentQuantity = DB::selectOne("SELECT
        COALESCE((SELECT SUM(di.weight) FROM deposit_items di JOIN deposits d ON d.id=di.deposit_id WHERE d.status='posted' AND di.waste_type_id=1),0)
        - (SELECT SUM(si.weight) FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='posted' AND si.waste_type_id=1) AS quantity");

    expect(array_column($duplicates, 'reference_id'))->toBe([2, 3, 4]);
    expect(array_column($duplicates, 'movements'))->toBe([2, 2, 2]);
    expect($types[1])->toMatchArray(['quantity' => '20.000', 'total_cost' => '6600.00']);
    expect((string) $consistentQuantity->quantity)->toBe('-2.000');
    expect(septemberBackupAuditQuery('consistency_checks')[0]['inventory_cancellations'])->toBe(0);
});

test('posting missing deposit journals alone leaves a 138000 inventory valuation gap', function (): void {
    $deposits = septemberBackupAuditQuery('posted_deposits_zero_cost');
    $missingCost = BigDecimal::of('0.00');
    foreach ($deposits as $deposit) {
        $missingCost = $missingCost->plus($deposit['item_cost']);
    }

    expect(array_column($deposits, 'movement_id'))->toBe([8, 9]);
    expect((string) $missingCost)->toBe('138000.00');
    expect(array_column($deposits, 'total_cost'))->toBe(['0.00', '0.00']);
    expect(septemberBackupAuditQuery('negative_savings_history')[0])
        ->toMatchArray(['customer_id' => 3, 'balance_after' => '-150.00']);
});

test('conditional opening projection balances but exposes an unverified debit equity of 129400', function (): void {
    $lines = collect(septemberBackupAuditQuery('conditional_opening_projection'))->keyBy('account_code');
    $debits = BigDecimal::of('0.00');
    $credits = BigDecimal::of('0.00');
    foreach ($lines as $line) {
        $debits = $debits->plus($line['debit']);
        $credits = $credits->plus($line['credit']);
    }

    expect((string) $debits)->toBe('137000.00');
    expect((string) $credits)->toBe('137000.00');
    expect($lines['3101'])->toMatchArray(['debit' => '129400.00', 'credit' => '0.00']);
    expect($lines['1101']['debit'])->toBe('500.00');
    expect($lines['1201']['debit'])->toBe('500.00');
    $this->assertDatabaseCount('journal_entries', 0);
    $this->assertDatabaseCount('journal_lines', 0);
    $this->assertDatabaseCount('cash_mutations', 0);
});

test('opening plus historical backfill doubles savings even though the source reference pairs differ', function (): void {
    $totals = septemberBackupAuditQuery('control_totals')[0];
    $historicalSavings = BigDecimal::of('0.00');
    foreach (septemberBackupAuditQuery('missing_events') as $event) {
        if ($event['balance_direction'] === 'credit') {
            $historicalSavings = $historicalSavings->plus($event['amount']);
        } elseif ($event['balance_direction'] === 'debit') {
            $historicalSavings = $historicalSavings->minus($event['amount']);
        }
    }

    expect((string) $historicalSavings)->toBe('137000.00');
    expect((string) $historicalSavings->plus($totals['savings']))->toBe('274000.00');
    expect(collect(septemberBackupAuditQuery('missing_events'))->where('reference_type', 'opening_balance'))->toHaveCount(0);
});

test('cash projection changes by 150 when an orphan is silently omitted', function (): void {
    $totals = septemberBackupAuditQuery('control_totals')[0];

    expect((string) BigDecimal::of($totals['receipts'])->minus($totals['withdrawals_with_source']))->toBe('650.00');
    expect((string) BigDecimal::of($totals['receipts'])->minus($totals['withdrawals_in_ledger']))->toBe('500.00');
    expect(septemberBackupAuditQuery('savings_by_customer'))->toBe([
        ['customer_id' => 2, 'balance' => '0.00'],
        ['customer_id' => 3, 'balance' => '77000.00'],
        ['customer_id' => 72, 'balance' => '60000.00'],
    ]);
});
