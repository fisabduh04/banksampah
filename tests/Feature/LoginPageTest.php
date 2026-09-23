<?php

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian login hanya boleh berjalan dalam environment testing.');
    }

    config([
        'app.name' => 'Bank Sampah Tataniwa',
        'database.connections.login_test' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'database' => 'banksampah_testing',
        ],
        'database.default' => 'login_test',
    ]);

    $connection = DB::connection();

    if ($connection->getDriverName() !== 'mysql' || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'banksampah_testing') {
        throw new RuntimeException('Pengujian login hanya boleh memakai MySQL banksampah_testing.');
    }

    $connection->beginTransaction();

    app()->setLocale('id');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

afterEach(function (): void {
    if (config('database.default') === 'login_test' && DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
});

test('login menampilkan identitas, petunjuk, form, dan footer secara berurutan', function (): void {
    $this->get(route('filament.admin.auth.login'))
        ->assertSeeInOrder([
            'images/poltera.png',
            'images/diktisaintek-berdampak.png',
            'Bank Sampah Tataniwa',
            'Masuk ke akun Anda',
            'Silakan masukkan informasi akun Anda untuk melanjutkan.',
            'Alamat email',
            'Kata sandi',
            'Ingat saya',
            '&copy; 2026 Bank Sampah Tataniwa &middot; Politeknik Negeri Madura',
        ], escape: false)
        ->assertDontSee('Lupa kata sandi');
});

test('pengguna dapat masuk melalui form native Filament', function (bool $remember): void {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'password')
        ->set('data.remember', $remember)
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect(Filament::getUrl());

    $this->assertAuthenticatedAs($user, 'web');
})->with([
    'tanpa ingat saya' => false,
    'dengan ingat saya' => true,
]);

test('kata sandi salah menampilkan pesan kesalahan dan pengguna tetap tamu', function (): void {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'kata-sandi-salah')
        ->call('authenticate')
        ->assertHasErrors(['data.email'])
        ->assertSee(__('filament-panels::auth/pages/login.messages.failed'));

    $this->assertGuest('web');
});

test('form kosong tetap memvalidasi email dan kata sandi', function (): void {
    Livewire::test(Login::class)
        ->set('data.email', '')
        ->set('data.password', '')
        ->call('authenticate')
        ->assertHasErrors(['data.email' => 'required', 'data.password' => 'required']);

    $this->assertGuest('web');
});

test('dashboard tidak memuat penanda atau konten khusus login', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertSee('Dashboard')
        ->assertDontSee('bank-sampah-login', escape: false)
        ->assertDontSee('Silakan masukkan informasi akun Anda untuk melanjutkan.');
});
