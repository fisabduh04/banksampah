<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'Arus.samudro@poltera.ac.id'],
            [
                'name' => 'admin',
                'email_verified_at' => null,
                'password' => Hash::make('Pengmas2026'),
            ],
        );
        // User::firstOrCreate(
        //     ['email' => 'fisabduh04@gmail.com'],
        //     [
        //         'name' => 'arus',
        //         'email_verified_at' => null,
        //         'password' => Hash::make('password'),
        //     ],
        // );
    }
}
