<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@vwn.local'],
            [
                'name' => 'Admin',
                'password' => bcrypt('change-me-immediately'),
                'is_admin' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
