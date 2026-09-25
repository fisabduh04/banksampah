<?php

use App\Services\TrialCleanupService;

/** @return array<string, mixed> */
function cleanupSnapshotFixture(string $amount = '10000.00', string $customerName = 'Nasabah Uji'): array
{
    $transaction = ['id' => '1', 'customer_id' => '7', 'total_amount' => $amount];
    $transactionHash = hash('sha256', json_encode($transaction, JSON_THROW_ON_ERROR));
    $customerHash = hash('sha256', json_encode(['id' => '7', 'name' => $customerName], JSON_THROW_ON_ERROR));

    return [
        'deposits' => [
            'count' => 1, 'sha256' => hash('sha256', $transactionHash), 'action' => 'delete_all_verified_trial_rows',
            'rows' => [['id' => '1', 'sha256' => $transactionHash, 'evidence' => $transaction]],
        ],
        'customers' => ['count' => 1, 'sha256' => hash('sha256', $customerHash), 'action' => 'preserve', 'rows' => []],
        'cache' => ['count' => 1, 'sha256' => hash('sha256', 'cached value'), 'action' => 'preserve', 'rows' => []],
        'sessions' => ['count' => 1, 'sha256' => hash('sha256', 'session payload'), 'action' => 'preserve', 'rows' => []],
    ];
}

test('table order is ignored without changing either snapshot', function (bool $cleaned): void {
    $expected = cleanupSnapshotFixture();
    if ($cleaned) {
        $expected['deposits'] = ['count' => 0, 'sha256' => hash('sha256', ''), 'action' => 'delete_all_verified_trial_rows', 'rows' => []];
    }
    $actual = array_reverse($expected, preserve_keys: true);
    $originalExpected = $expected;
    $originalActual = $actual;
    $service = new TrialCleanupService;

    expect($service->snapshotsMatch($actual, $expected))->toBeTrue();
    expect($service->snapshotsMatch($expected, $actual))->toBeTrue();
    expect($actual)->toBe($originalActual);
    expect($expected)->toBe($originalExpected);
})->with(['manifest before cleanup' => false, 'expected after cleanup' => true]);

test('a changed transaction or master row remains a mismatch despite reordered tables', function (string $amount, string $customerName): void {
    $expected = cleanupSnapshotFixture();
    $actual = array_reverse(cleanupSnapshotFixture($amount, $customerName), preserve_keys: true);

    expect((new TrialCleanupService)->snapshotsMatch($actual, $expected))->toBeFalse();
})->with([
    'one transaction amount changed' => ['10001.00', 'Nasabah Uji'],
    'one customer name changed' => ['10000.00', 'Nasabah Berubah'],
]);

test('snapshot comparison remains strict below the table keys', function (Closure $change): void {
    $expected = cleanupSnapshotFixture();
    $actual = array_reverse($expected, preserve_keys: true);
    $change($actual);

    expect((new TrialCleanupService)->snapshotsMatch($actual, $expected))->toBeFalse();
})->with([
    'cache fingerprint changed' => [static function (array &$tables): void {
        $tables['cache']['sha256'] = hash('sha256', 'changed cache value');
    }],
    'session fingerprint changed' => [static function (array &$tables): void {
        $tables['sessions']['sha256'] = hash('sha256', 'changed session payload');
    }],
    'transaction row fingerprint changed' => [static function (array &$tables): void {
        $tables['deposits']['rows'][0]['sha256'] = hash('sha256', 'changed transaction');
    }],
    'transaction evidence changed' => [static function (array &$tables): void {
        $tables['deposits']['rows'][0]['evidence']['total_amount'] = '10001.00';
    }],
    'nested key order changed' => [static function (array &$tables): void {
        $tables['deposits'] = array_reverse($tables['deposits'], preserve_keys: true);
    }],
    'count type changed' => [static function (array &$tables): void {
        $tables['deposits']['count'] = '1';
    }],
    'table missing' => [static function (array &$tables): void {
        unset($tables['customers']);
    }],
    'table added' => [static function (array &$tables): void {
        $tables['new_table'] = $tables['customers'];
    }],
]);

test('transaction row order remains significant', function (): void {
    $expected = cleanupSnapshotFixture();
    $expected['deposits']['rows'][] = ['id' => '2', 'sha256' => hash('sha256', 'second transaction'), 'evidence' => []];
    $expected['deposits']['count'] = 2;
    $actual = array_reverse($expected, preserve_keys: true);
    $actual['deposits']['rows'] = array_reverse($actual['deposits']['rows']);

    expect((new TrialCleanupService)->snapshotsMatch($actual, $expected))->toBeFalse();
});
