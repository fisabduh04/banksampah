<?php

use App\Services\FinancialControlService;
use App\Services\LegacyInventoryCostReconciliationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('inventory:reconcile-legacy-costs {--deposits= : ID setoran, dipisahkan koma} {--sales= : ID penjualan, dipisahkan koma} {--approved-by= : Identitas pemberi persetujuan} {--approver-id= : ID pengguna penyetuju keuangan untuk penerapan} {--reason= : Alasan koreksi} {--apply : Terapkan koreksi; tanpa opsi ini hanya pratinjau}', function (LegacyInventoryCostReconciliationService $service): int {
    try {
        $parseIds = function (?string $value): array {
            if ($value === null || $value === '') {
                return [];
            }
            if (! preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/', $value)) {
                throw new UnexpectedValueException('Daftar ID transaksi harus berupa angka positif yang dipisahkan koma.');
            }

            return array_map('intval', explode(',', $value));
        };
        $result = $service->reconcile(
            $parseIds($this->option('deposits')), $parseIds($this->option('sales')),
            (string) $this->option('approved-by'), (string) $this->option('reason'), (bool) $this->option('apply'),
            $this->option('approver-id') ? (int) $this->option('approver-id') : null,
        );
        $this->info($result['already_applied'] ? 'Koreksi sudah pernah diterapkan; tidak ada perubahan baru.' : ($result['applied'] ? 'Koreksi berhasil diterapkan.' : 'Pratinjau; belum ada perubahan data.'));
        $this->table(['Ledger sumber', 'Nilai persediaan hasil koreksi', 'HPP penjualan hasil koreksi'], [
            [$result['movements'], $result['inventory_value'], $result['sale_cost']],
        ]);

        return 0;
    } catch (UnexpectedValueException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
})->purpose('Merekonsiliasi biaya ledger lama melalui pembalik, pengganti, dan snapshot audit.');

Artisan::command('finance:close-period {date : Tanggal akhir periode YYYY-MM-DD} {--by= : ID penyetuju keuangan} {--reason= : Alasan dan referensi pemeriksaan}', function (FinancialControlService $service): int {
    try {
        $service->closeThrough((string) $this->argument('date'), (int) $this->option('by'), (string) $this->option('reason'));
        $this->info('Periode telah dikunci dan persetujuan dicatat dalam jejak audit.');

        return 0;
    } catch (UnexpectedValueException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
})->purpose('Menutup periode transaksi setelah pemeriksaan bukti pembayaran.');
