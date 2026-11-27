<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('transmanagement.superadmin.password');

        if (! $password) {
            if (app()->environment('production')) {
                throw new RuntimeException('SUPERADMIN_PASSWORD wajib diisi di production.');
            }

            $password = 'password';
        }

        $user = User::firstOrCreate(
            ['email' => config('transmanagement.superadmin.email')],
            [
                'name' => 'Superadmin',
                'password' => $password,
            ],
        );

        $user->assignRole('superadmin');
    }
}
