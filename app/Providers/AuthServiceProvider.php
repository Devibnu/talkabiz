<?php

namespace App\Providers;

use App\Models\TemplatePesan;
use App\Models\User;
use App\Policies\TemplatePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        TemplatePesan::class => TemplatePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        // ==================== SUPER ADMIN GATES ====================

        /**
         * Check if user is super_admin
         */
        Gate::define('super-admin', function (User $user) {
            return in_array($user->role, ['super_admin', 'superadmin']);
        });

        /**
         * Check if user is admin or higher
         */
        Gate::define('admin', function (User $user) {
            return in_array($user->role, ['super_admin', 'superadmin', 'admin']);
        });

        /**
         * Can delete a user
         * - Cannot delete super_admin
         * - Only super_admin can delete admin
         */
        Gate::define('delete-user', function (User $currentUser, User $targetUser) {
            // Cannot delete super_admin
            if (in_array($targetUser->role, ['super_admin', 'superadmin'])) {
                return false;
            }

            // Only super_admin can delete admin
            if ($targetUser->role === 'admin') {
                return in_array($currentUser->role, ['super_admin', 'superadmin']);
            }

            // Admin can delete regular users
            return in_array($currentUser->role, ['super_admin', 'superadmin', 'admin']);
        });

        /**
         * Can change user role
         * - Cannot change super_admin's role
         * - Only super_admin can promote/demote admin
         */
        Gate::define('change-user-role', function (User $currentUser, User $targetUser, ?string $newRole = null) {
            // Cannot change super_admin's role
            if (in_array($targetUser->role, ['super_admin', 'superadmin'])) {
                return false;
            }

            // Only super_admin can set admin role
            if ($newRole === 'admin' || $targetUser->role === 'admin') {
                return in_array($currentUser->role, ['super_admin', 'superadmin']);
            }

            // Admin can change regular user roles
            return in_array($currentUser->role, ['super_admin', 'superadmin', 'admin']);
        });

        /**
         * Can reset user password
         * - Only super_admin can reset admin password
         * - Admin can reset regular user password
         */
        Gate::define('reset-user-password', function (User $currentUser, User $targetUser) {
            // Only super_admin can reset admin/super_admin password
            if (in_array($targetUser->role, ['super_admin', 'superadmin', 'admin'])) {
                return in_array($currentUser->role, ['super_admin', 'superadmin']);
            }

            // Admin can reset regular user password
            return in_array($currentUser->role, ['super_admin', 'superadmin', 'admin']);
        });

        /**
         * Can manage users (view user list, etc)
         */
        Gate::define('manage-users', function (User $user) {
            return in_array($user->role, ['super_admin', 'superadmin', 'admin']);
        });

        /**
         * Can view admin dashboard
         */
        Gate::define('view-admin-dashboard', function (User $user) {
            return in_array($user->role, ['super_admin', 'superadmin', 'admin']);
        });
    }
}
