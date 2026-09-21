<?php

use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

test('tanggal hari ini mengikuti pergantian hari WIB', function (string $instant, string $expectedDate): void {
    $this->travelTo(Carbon::parse($instant));

    $actualDate = now()->toDateString();

    expect($actualDate)->toBe($expectedDate);
})->with([
    'sesaat sebelum tengah malam WIB' => ['2026-09-14T16:59:59Z', '2026-09-14'],
    'tepat tengah malam WIB' => ['2026-09-14T17:00:00Z', '2026-09-15'],
    'pukul 06.59 WIB' => ['2026-09-14T23:59:59Z', '2026-09-15'],
    'pukul 07.00 WIB' => ['2026-09-15T00:00:00Z', '2026-09-15'],
]);
