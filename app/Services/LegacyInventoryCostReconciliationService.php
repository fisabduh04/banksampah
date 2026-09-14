<?php

namespace App\Services;

use App\Models\Deposit;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\WasteType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class LegacyInventoryCostReconciliationService
{
    /**
     * Khusus ledger lama yang seluruh biayanya nol dan hanya berisi setoran/penjualan.
     * Riwayat dengan mutasi lain ditolak agar koreksi tidak menebak nilai transaksi.
     *
     * @param  array<int>  $depositIds
     * @param  array<int>  $saleIds
     * @return array{applied: bool, already_applied: bool, movements: int, inventory_value: string, sale_cost: string}
     */
    public function reconcile(array $depositIds, array $saleIds, string $approvedBy, string $reason, bool $apply = false, ?int $approverUserId = null): array
    {
        if ($depositIds === [] || trim($approvedBy) === '' || trim($reason) === ''
            || mb_strlen($approvedBy) > 255 || mb_strlen($reason) > 2000) {
            throw new UnexpectedValueException('Setoran, identitas pemberi persetujuan, dan alasan koreksi wajib diisi dengan benar.');
        }
        if ($apply) {
            $approverUserId = app(FinancialControlService::class)->authorize('approve', $approverUserId ?? auth()->id())->id;
        }
        $depositIds = array_values(array_unique($depositIds));
        $saleIds = array_values(array_unique($saleIds));
        sort($depositIds);
        sort($saleIds);

        return DB::transaction(function () use ($depositIds, $saleIds, $approvedBy, $reason, $apply, $approverUserId): array {
            if ($apply) {
                app(FinancialControlService::class)->ensureOpen(now()->toDateString());
            }
            /** Urutan header lalu jenis sampah mengikuti service transaksi normal. */
            $sales = Sale::query()->whereKey($saleIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $deposits = Deposit::query()->whereKey($depositIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($sales->count() !== count($saleIds) || $deposits->count() !== count($depositIds)) {
                throw new UnexpectedValueException('Transaksi sumber tidak ditemukan.');
            }
            $sourceItems = [];
            $sourceHeaders = [];
            $paymentSnapshots = [];
            foreach (['deposit' => $deposits, 'sale' => $sales] as $type => $records) {
                foreach ($records as $record) {
                    $items = $record->items()->orderBy('waste_type_id')->lockForUpdate()->get();
                    if ($items->isEmpty()) {
                        throw new UnexpectedValueException('Rincian transaksi sumber kosong.');
                    }
                    $amount = BigDecimal::of(0);
                    $weight = BigDecimal::of(0);
                    foreach ($items as $item) {
                        $amount = $amount->plus($item->subtotal);
                        $weight = $weight->plus($item->weight);
                        $sourceItems[$type.':'.$record->id.':'.$item->waste_type_id] = $item;
                    }
                    if (! $amount->isEqualTo($record->total_amount) || ! $weight->isEqualTo($record->total_weight)) {
                        throw new UnexpectedValueException('Total header transaksi tidak sesuai dengan rincian.');
                    }
                    $sourceHeaders[$type.':'.$record->id] = $record->getAttributes();
                    if ($type === 'sale') {
                        $paymentSnapshots[$record->id] = $record->payments()->orderBy('id')->lockForUpdate()->get()
                            ->map(fn ($payment): array => $payment->getAttributes())->all();
                    }
                }
            }
            $wasteIds = collect($sourceItems)->pluck('waste_type_id')->unique()->sort()->values()->all();
            WasteType::query()->whereKey($wasteIds)->orderBy('id')->lockForUpdate()->get();
            $ledger = InventoryMovement::query()->whereIn('waste_type_id', $wasteIds)->orderBy('transaction_date')->orderBy('id')->lockForUpdate()->get();
            $originals = $ledger->filter(fn (InventoryMovement $movement): bool => ($movement->reference_type === 'deposit' && in_array($movement->reference_id, $depositIds))
                || ($movement->reference_type === 'sale' && in_array($movement->reference_id, $saleIds)));
            $audits = DB::table('inventory_cost_reconciliations')->whereIn('inventory_movement_id', $originals->modelKeys())
                ->lockForUpdate()->get();
            if ($audits->isNotEmpty()) {
                if ($audits->count() !== $originals->count() || $audits->contains(fn ($audit): bool => ! $audit->replacement_movement_id)) {
                    throw new UnexpectedValueException('Ditemukan rekonsiliasi parsial. Periksa audit sebelum melanjutkan.');
                }
                $existing = json_decode($audits->first()->corrected_snapshot, true, flags: JSON_THROW_ON_ERROR);
                if ($existing['scope'] !== [$depositIds, $saleIds]) {
                    throw new UnexpectedValueException('Lingkup transaksi berbeda dari rekonsiliasi yang sudah tercatat.');
                }
                $summary = $existing['summary'];

                return [...$summary, 'applied' => false, 'already_applied' => true];
            }
            if ($ledger->count() !== $originals->count() || $originals->count() !== count($sourceItems)
                || $sales->contains(fn (Sale $sale): bool => ! $sale->isPosted())
                || $deposits->contains(fn (Deposit $deposit): bool => $deposit->status !== 'posted')) {
                throw new UnexpectedValueException('Riwayat berubah atau terdapat transaksi di luar lingkup koreksi. Rekonsiliasi ditolak.');
            }

            $balances = [];
            $plans = [];
            $saleCosts = [];
            $seen = [];
            foreach ($originals as $movement) {
                $key = $movement->reference_type.':'.$movement->reference_id.':'.$movement->waste_type_id;
                $item = $sourceItems[$key] ?? null;
                if (! $item || isset($seen[$key]) || $movement->quantity !== $item->weight
                    || $movement->unit_cost !== '0.00' || $movement->total_cost !== '0.00') {
                    throw new UnexpectedValueException('Ledger tidak cocok dengan rincian sumber atau sudah memiliki biaya.');
                }
                $seen[$key] = true;
                $weight = BigDecimal::of($item->weight);
                $price = BigDecimal::of($item->price);
                if ($weight->isLessThanOrEqualTo(0) || $price->isLessThanOrEqualTo(0)
                    || ! $weight->multipliedBy($price)->toScale(2, RoundingMode::HalfUp)->isEqualTo($item->subtotal)) {
                    throw new UnexpectedValueException('Berat, harga, atau subtotal sumber tidak valid.');
                }
                $wasteId = $movement->waste_type_id;
                $balance = $balances[$wasteId] ?? ['quantity' => BigDecimal::of(0), 'value' => BigDecimal::of(0)];
                if ($movement->reference_type === 'deposit') {
                    if ($movement->movement_type !== 'in') {
                        throw new UnexpectedValueException('Arah mutasi setoran tidak valid.');
                    }
                    $cost = BigDecimal::of($item->subtotal);
                    $unitCost = $price;
                    $balance = ['quantity' => $balance['quantity']->plus($weight), 'value' => $balance['value']->plus($cost)];
                } else {
                    if ($movement->movement_type !== 'out' || $weight->isGreaterThan($balance['quantity'])
                        || $item->cost_total !== '0.00' || $item->cost_price !== '0.00') {
                        throw new UnexpectedValueException('Stok atau snapshot HPP sumber tidak sesuai untuk koreksi.');
                    }
                    $cost = $balance['value']->multipliedBy($weight)->dividedBy($balance['quantity'], 2, RoundingMode::HalfUp);
                    $unitCost = $cost->dividedBy($weight, 2, RoundingMode::HalfUp);
                    $balance = ['quantity' => $balance['quantity']->minus($weight), 'value' => $balance['value']->minus($cost)];
                    $saleCosts[$item->sale_id] = ($saleCosts[$item->sale_id] ?? BigDecimal::of(0))->plus($cost);
                }
                $balances[$wasteId] = $balance;
                $plans[] = ['movement' => $movement, 'item' => $item,
                    'unit_cost' => (string) $unitCost->toScale(2), 'total_cost' => (string) $cost->toScale(2)];
            }
            $inventoryValue = BigDecimal::of(0);
            foreach ($balances as $balance) {
                $inventoryValue = $inventoryValue->plus($balance['value']);
            }
            $totalSaleCost = BigDecimal::of(0);
            foreach ($saleCosts as $cost) {
                $totalSaleCost = $totalSaleCost->plus($cost);
            }
            $summary = ['applied' => $apply, 'already_applied' => false, 'movements' => count($plans),
                'inventory_value' => (string) $inventoryValue->toScale(2), 'sale_cost' => (string) $totalSaleCost->toScale(2)];
            if (! $apply) {
                return $summary;
            }

            foreach ($plans as $plan) {
                app(FinancialControlService::class)->ensureOpen($plan['movement']->transaction_date->toDateString());
                $movement = $plan['movement'];
                $item = $plan['item'];
                $saleId = $movement->reference_type === 'sale' ? $movement->reference_id : null;
                $snapshot = ['movement' => $movement->getAttributes(), 'item' => $item->getAttributes(),
                    'header' => $sourceHeaders[$movement->reference_type.':'.$movement->reference_id],
                    'payments' => $saleId ? $paymentSnapshots[$saleId] : []];
                $auditId = DB::table('inventory_cost_reconciliations')->insertGetId([
                    'inventory_movement_id' => $movement->id, 'sale_id' => $saleId,
                    'approved_by' => trim($approvedBy), 'approver_user_id' => $approverUserId, 'reason' => trim($reason),
                    'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'corrected_snapshot' => json_encode(['scope' => [$depositIds, $saleIds], 'summary' => $summary], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $base = ['waste_type_id' => $movement->waste_type_id, 'quantity' => '0.000',
                    'reference_id' => $movement->id, 'transaction_date' => now()->toDateString()];
                $reversal = InventoryMovement::create([...$base,
                    'movement_type' => $movement->movement_type === 'in' ? 'out' : 'in',
                    'unit_cost' => $movement->unit_cost, 'total_cost' => $movement->total_cost,
                    'reference_type' => 'cost_reconciliation_reversal',
                    'description' => 'Pembalik biaya ledger #'.$movement->id.'; audit #'.$auditId.'.',
                ]);
                $replacement = InventoryMovement::create([...$base,
                    'movement_type' => $movement->movement_type, 'unit_cost' => $plan['unit_cost'], 'total_cost' => $plan['total_cost'],
                    'reference_type' => 'cost_reconciliation',
                    'description' => 'Koreksi biaya ledger #'.$movement->id.'; audit #'.$auditId.'. '.$reason,
                ]);
                if ($saleId) {
                    /** Perubahan snapshot posted selalu disertai salinan nilai asal dan ledger pembalik. */
                    $item->update(['cost_price' => $plan['unit_cost'], 'cost_total' => $plan['total_cost'],
                        'gross_profit' => (string) BigDecimal::of($item->subtotal)->minus($plan['total_cost'])->toScale(2)]);
                    $sales[$saleId]->update(['total_cost' => (string) $saleCosts[$saleId]->toScale(2),
                        'gross_profit' => (string) BigDecimal::of($sales[$saleId]->total_amount)->minus($saleCosts[$saleId])->toScale(2)]);
                }
                DB::table('inventory_cost_reconciliations')->where('id', $auditId)->update([
                    'reversal_movement_id' => $reversal->id, 'replacement_movement_id' => $replacement->id,
                    'corrected_snapshot' => json_encode(['scope' => [$depositIds, $saleIds], 'summary' => $summary, 'movement' => $replacement->getAttributes(),
                        'item' => $item->getAttributes(), 'header' => $saleId ? $sales[$saleId]->getAttributes() : $snapshot['header']], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            }

            return $summary;
        }, attempts: 3);
    }
}
