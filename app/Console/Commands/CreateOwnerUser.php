<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateOwnerUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'owner:create 
                            {--email= : The email address for the owner}
                            {--name= : The name for the owner}
                            {--password= : The password for the owner (optional, will prompt if not provided)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new owner/super_admin user. This is the only way to create owner accounts.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('======================================');
        $this->info('     CREATE OWNER / SUPER ADMIN');
        $this->info('======================================');
        $this->newLine();

        // Check if there's already an owner
        $existingOwners = User::whereIn('role', ['owner', 'super_admin'])->count();
        if ($existingOwners > 0) {
            $this->warn("⚠️  There are already {$existingOwners} owner(s) in the system.");
            if (!$this->confirm('Do you want to create another owner?', false)) {
                $this->info('Cancelled.');
                return 1;
            }
        }

        // Get name
        $name = $this->option('name');
        if (!$name) {
            $name = $this->ask('Enter owner name');
        }

        // Get email
        $email = $this->option('email');
        if (!$email) {
            $email = $this->ask('Enter owner email');
        }

        // Validate email
        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email|unique:users,email',
        ]);

        if ($validator->fails()) {
            $this->error('❌ Validation failed:');
            foreach ($validator->errors()->all() as $error) {
                $this->error("   - {$error}");
            }
            return 1;
        }

        // Get password
        $password = $this->option('password');
        if (!$password) {
            $password = $this->secret('Enter password (min 8 characters)');
            $passwordConfirm = $this->secret('Confirm password');

            if ($password !== $passwordConfirm) {
                $this->error('❌ Passwords do not match!');
                return 1;
            }
        }

        // Validate password
        if (strlen($password) < 8) {
            $this->error('❌ Password must be at least 8 characters!');
            return 1;
        }

        // Confirm creation
        $this->newLine();
        $this->info('📋 Owner Details:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Name', $name],
                ['Email', $email],
                ['Role', 'owner (super_admin)'],
            ]
        );

        if (!$this->confirm('Create this owner account?', true)) {
            $this->info('Cancelled.');
            return 1;
        }

        // Create the owner
        try {
            $owner = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => 'owner', // atau 'super_admin' tergantung sistem Anda
                'email_verified_at' => now(),
                'force_password_change' => false, // Owner tidak perlu ganti password
            ]);

            $this->newLine();
            $this->info('✅ Owner account created successfully!');
            $this->newLine();
            $this->table(
                ['Field', 'Value'],
                [
                    ['ID', $owner->id],
                    ['Name', $owner->name],
                    ['Email', $owner->email],
                    ['Role', $owner->role],
                    ['Created At', $owner->created_at->format('Y-m-d H:i:s')],
                ]
            );
            $this->newLine();
            $this->info('🔐 Login URL: ' . url('/login'));
            $this->info('📊 Owner Dashboard: ' . url('/owner/dashboard'));
            $this->newLine();

            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Failed to create owner: ' . $e->getMessage());
            return 1;
        }
    }
}
