<?php

namespace App\Filament\Resources\Collectors\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CollectorForm
{
    /**
     * Konfigurasi formulir master pengepul.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Pengepul')
                    ->description(
                        'Masukkan data pengepul yang menjadi mitra pembeli sampah.'
                    )
                    ->schema([
                        TextInput::make('code')
                            ->label('Kode Pengepul')
                            ->placeholder('Contoh: PGP-001')
                            ->required()
                            ->maxLength(30)

                            // Kode harus unik, tetapi saat edit
                            // record saat ini harus diabaikan.
                            ->unique(ignoreRecord: true)
                            ->helperText(
                                'Gunakan kode unik untuk setiap pengepul.'
                            ),

                        TextInput::make('name')
                            ->label('Nama Pengepul')
                            ->placeholder('Nama usaha atau nama pengepul')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('contact_person')
                            ->label('Nama Kontak')
                            ->placeholder('Nama orang yang dapat dihubungi')
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->label('Nomor Telepon')
                            ->placeholder('Contoh: 081234567890')

                            /*
                             * Nomor telepon disimpan sebagai teks,
                             * bukan sebagai angka untuk perhitungan.
                             */
                            ->tel()
                            ->maxLength(30),

                        Textarea::make('address')
                            ->label('Alamat')
                            ->placeholder('Alamat pengepul')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Pengepul Aktif')
                            ->default(true)
                            ->inline(false)
                            ->helperText(
                                'Nonaktifkan apabila pengepul sudah tidak digunakan.'
                            )
                            ->columnSpanFull(),

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->placeholder(
                                'Informasi tambahan mengenai pengepul.'
                            )
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
