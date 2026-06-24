<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Membuat akun admin jika belum ada
        User::updateOrCreate(
            ['email' => 'admin@tuturtulis.com'], // Kita gunakan format email/username bebas
            [
                'name' => 'Admin TuturTulis',
                'password' => Hash::make('admin'), // Password di-encrypt demi keamanan
            ]
        );
    }
}
