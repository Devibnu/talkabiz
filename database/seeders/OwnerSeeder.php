<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OwnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * PENTING: Owner hanya bisa dibuat via seeder atau artisan command!
     * Tidak ada UI untuk membuat owner.
     * 
     * Cara pakai:
     * php artisan db:seed --class=OwnerSeeder
     * 
     * Atau gunakan command:
     * php artisan owner:create
     */
    public function run(): void
    {
        // Cek apakah sudah ada owner
        $existingOwner = User::whereIn('role', ['owner', 'super_admin'])->first();
        
        if ($existingOwner) {
            $this->command->warn('Owner already exists: ' . $existingOwner->email);
            $this->command->info('Skipping owner creation.');
            return;
        }

        // Create default owner
        $owner = User::create([
            'name' => 'Owner Talkabiz',
            'email' => 'owner@talkabiz.com',
            'password' => Hash::make('owner@talkabiz2024!'),
            'role' => 'owner',
            'email_verified_at' => now(),
            'force_password_change' => true, // Wajib ganti password saat login pertama
        ]);

        $this->command->info('✅ Owner created successfully!');
        $this->command->table(
            ['Field', 'Value'],
            [
                ['Name', $owner->name],
                ['Email', $owner->email],
                ['Role', $owner->role],
                ['Default Password', 'owner@talkabiz2024!'],
            ]
        );
        $this->command->warn('⚠️  IMPORTANT: Change the password immediately after first login!');
    }
}
