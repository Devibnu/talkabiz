<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserManagementController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display user list
     */
    public function index(Request $request)
    {
        Gate::authorize('manage-users');

        $query = User::query()
            ->with('klien')
            ->orderBy('created_at', 'desc');

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($role = $request->get('role')) {
            $query->where('role', $role);
        }

        $users = $query->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    /**
     * Show user details
     */
    public function show(User $user)
    {
        Gate::authorize('manage-users');

        $activityLogs = ActivityLog::forUser($user->id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return view('admin.users.show', compact('user', 'activityLogs'));
    }

    /**
     * Reset user password (generate random)
     */
    public function resetPassword(Request $request, User $user)
    {
        Gate::authorize('reset-user-password', $user);

        $request->validate([
            'force_change' => 'boolean',
        ]);

        // Generate random password
        $newPassword = Str::random(12);

        DB::transaction(function () use ($user, $newPassword, $request) {
            $user->update([
                'password' => Hash::make($newPassword),
                'force_password_change' => $request->boolean('force_change', true),
                'password_changed_at' => now(),
            ]);

            // Invalidate all user sessions
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->delete();

            // Log the action
            ActivityLog::log(
                ActivityLog::ACTION_PASSWORD_RESET,
                "Password reset by admin. Force change: " . ($request->boolean('force_change', true) ? 'Yes' : 'No'),
                $user,
                $user->id,
                auth()->id(),
                [
                    'force_change' => $request->boolean('force_change', true),
                    'sessions_invalidated' => true,
                ]
            );
        });

        return back()->with('success', "Password berhasil direset. Password baru: {$newPassword}");
    }

    /**
     * Toggle force password change
     */
    public function toggleForcePasswordChange(User $user)
    {
        Gate::authorize('reset-user-password', $user);

        $user->update([
            'force_password_change' => !$user->force_password_change,
        ]);

        ActivityLog::log(
            ActivityLog::ACTION_FORCE_PASSWORD_SET,
            "Force password change set to: " . ($user->force_password_change ? 'true' : 'false'),
            $user,
            $user->id
        );

        return back()->with('success', 'Status force password change diubah.');
    }

    /**
     * Change user role
     */
    public function updateRole(Request $request, User $user)
    {
        $request->validate([
            'role' => 'required|in:user,admin,super_admin',
        ]);

        Gate::authorize('change-user-role', [$user, $request->role]);

        $oldRole = $user->role;
        $user->update(['role' => $request->role]);

        ActivityLog::log(
            ActivityLog::ACTION_ROLE_CHANGED,
            "Role changed from {$oldRole} to {$request->role}",
            $user,
            $user->id,
            auth()->id(),
            ['old_role' => $oldRole, 'new_role' => $request->role]
        );

        return back()->with('success', 'Role berhasil diubah.');
    }

    /**
     * Delete user (soft or hard)
     */
    public function destroy(User $user)
    {
        Gate::authorize('delete-user', $user);

        $userName = $user->name;
        $userEmail = $user->email;

        // Log before delete
        ActivityLog::log(
            ActivityLog::ACTION_USER_DELETED,
            "User {$userName} ({$userEmail}) deleted",
            null,
            $user->id,
            auth()->id(),
            ['deleted_user_name' => $userName, 'deleted_user_email' => $userEmail]
        );

        // Invalidate sessions
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->delete();

        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', "User {$userName} berhasil dihapus.");
    }

    /**
     * Invalidate all user sessions
     */
    public function invalidateSessions(User $user)
    {
        Gate::authorize('reset-user-password', $user);

        $count = DB::table('sessions')
            ->where('user_id', $user->id)
            ->delete();

        ActivityLog::log(
            ActivityLog::ACTION_SESSION_INVALIDATED,
            "{$count} sessions invalidated for user",
            $user,
            $user->id,
            auth()->id(),
            ['sessions_count' => $count]
        );

        return back()->with('success', "{$count} session berhasil di-invalidate.");
    }

    /**
     * View activity log
     */
    public function activityLog(Request $request)
    {
        Gate::authorize('super-admin');

        $query = ActivityLog::with(['user', 'causer'])
            ->orderBy('created_at', 'desc');

        if ($action = $request->get('action')) {
            $query->byAction($action);
        }

        if ($userId = $request->get('user_id')) {
            $query->forUser($userId);
        }

        $logs = $query->paginate(50);

        return view('admin.activity-log', compact('logs'));
    }
}
