<?php

namespace App\Filament\Concerns;

trait UsesIndonesianLocale
{
    /** Berlaku pada permintaan awal dan pembaruan Livewire halaman transaksi. */
    public function bootUsesIndonesianLocale(): void
    {
        app()->setLocale('id');
        app('translator')->addLines([
            'validation.required' => ':attribute wajib diisi.',
            'validation.numeric' => ':attribute harus berupa angka.',
            'validation.date' => ':attribute harus berupa tanggal yang valid.',
            'validation.date_format' => 'Format :attribute harus :format.',
            'validation.exists' => ':attribute yang dipilih tidak tersedia.',
            'validation.in' => ':attribute yang dipilih tidak valid.',
            'validation.unique' => ':attribute sudah digunakan.',
            'validation.distinct' => ':attribute tidak boleh berulang.',
            'validation.decimal' => 'Jumlah angka desimal :attribute tidak sesuai.',
            'validation.min.numeric' => ':attribute minimal :min.',
            'validation.min.array' => ':attribute minimal berisi :min rincian.',
            'validation.min.string' => ':attribute minimal :min karakter.',
            'validation.max.numeric' => ':attribute maksimal :max.',
            'validation.max.string' => ':attribute maksimal :max karakter.',
            'validation.max.array' => ':attribute maksimal berisi :max rincian.',
            'validation.string' => ':attribute harus berupa teks.',
            'validation.array' => ':attribute harus berupa daftar.',
            'validation.boolean' => ':attribute harus berupa pilihan aktif atau tidak aktif.',
        ], 'id');
    }
}
