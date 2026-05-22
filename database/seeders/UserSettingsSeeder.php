<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\User::firstOrCreate(
            ['email' => 'm.seyam@postar.app'],
            [
                'name' => 'Mohammed Seyam',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'phone' => '+201012345678',
                'language' => 'ar',
                'settings' => [
                    'timezone' => 'Africa/Cairo',
                    'time_format' => '12h',
                    'email_notifications' => true,
                    'default_visibility' => 'unlisted',
                ]
            ]
        );
    }
}
