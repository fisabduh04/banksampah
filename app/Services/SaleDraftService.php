<?php

namespace App\Services;

use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleDraftService
{
    /** Kunci header sebelum Filament menyimpan header maupun rincian. */
    public function lockDraft(Sale $sale): Sale
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Penyimpanan draft harus berada dalam transaksi database.');
        }
        $lockedSale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
        if (! $lockedSale->isDraft()) {
            throw ValidationException::withMessages(['data.items' => 'Hanya draft penjualan yang boleh diubah atau dihapus.']);
        }

        return $lockedSale;
    }

    /** Angka resmi dihitung dari kolom desimal, bukan total kiriman browser. */
    public function recalculate(Sale $sale): void
    {
        $sale = $this->lockDraft($sale);
        if (! $sale->collector()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['data.collector_id' => 'Pilih pengepul yang masih aktif.']);
        }
        $items = $sale->items()->with('wasteType')->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['data.items' => 'Rincian penjualan wajib diisi.']);
        }
        foreach ($items as $item) {
            if (! $item->wasteType?->is_active || (float) $item->weight <= 0 || (float) $item->price <= 0) {
                throw ValidationException::withMessages(['data.items' => 'Pilih jenis sampah aktif dengan berat dan harga lebih dari nol.']);
            }
        }
        $sale->items()->update(['subtotal' => DB::raw('ROUND(weight * price, 2)'),
            'cost_price' => 0, 'cost_total' => 0, 'gross_profit' => 0]);
        $summary = $sale->items()->selectRaw('SUM(weight) AS weight, SUM(subtotal) AS amount')->first();
        $sale->update([
            'sale_number' => 'PJ-'.$sale->transaction_date->format('Ymd').'-'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT),
            'total_weight' => $summary->weight, 'total_amount' => $summary->amount,
            'total_cost' => 0, 'gross_profit' => 0, 'payment_status' => 'unpaid',
        ]);
    }

    public function delete(Sale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            $this->lockDraft($sale)->delete();
        }, attempts: 3);
    }
}
