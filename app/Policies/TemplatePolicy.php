<?php

namespace App\Policies;

use App\Models\TemplatePesan;
use App\Models\Pengguna;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * TemplatePolicy
 * 
 * Policy untuk mengatur akses ke Template Pesan.
 * 
 * Rules:
 * - super_admin: akses semua
 * - owner: CRUD + submit template kliennya
 * - admin: CRUD + submit template kliennya
 * - sales: hanya view template kliennya
 */
class TemplatePolicy
{
    use HandlesAuthorization;

    // ==================== ROLE HELPERS ====================

    /**
     * Cek apakah user punya akses ke klien template
     */
    protected function milikKlienSama(Pengguna $user, TemplatePesan $template): bool
    {
        return $user->klien_id === $template->klien_id;
    }

    /**
     * Cek apakah user bisa manage (create/update/delete/submit)
     */
    protected function bisaManage(Pengguna $user): bool
    {
        return in_array($user->role, ['super_admin', 'owner', 'admin']);
    }

    /**
     * Cek apakah user bisa submit ke provider
     */
    protected function bisaSubmit(Pengguna $user): bool
    {
        // Hanya owner dan admin yang boleh submit, sales tidak
        return in_array($user->role, ['super_admin', 'owner', 'admin']);
    }

    // ==================== POLICY METHODS ====================

    /**
     * Determine whether the user can view any templates.
     */
    public function viewAny(Pengguna $user): bool
    {
        // Semua role bisa lihat daftar template (klien sendiri)
        return true;
    }

    /**
     * Determine whether the user can view the template.
     */
    public function view(Pengguna $user, TemplatePesan $template): bool
    {
        // Super admin bisa lihat semua
        if ($user->role === 'super_admin') {
            return true;
        }

        // Role lain hanya bisa lihat template klien sendiri
        return $this->milikKlienSama($user, $template);
    }

    /**
     * Determine whether the user can create templates.
     */
    public function create(Pengguna $user): bool
    {
        // owner, admin bisa buat template
        // sales TIDAK bisa
        return $this->bisaManage($user);
    }

    /**
     * Determine whether the user can update the template.
     */
    public function update(Pengguna $user, TemplatePesan $template): bool
    {
        // Super admin bisa update semua
        if ($user->role === 'super_admin') {
            return true;
        }

        // Harus milik klien sama dan punya permission manage
        if (!$this->milikKlienSama($user, $template)) {
            return false;
        }

        if (!$this->bisaManage($user)) {
            return false;
        }

        // Hanya bisa edit jika status draft atau rejected
        return in_array($template->status, [
            TemplatePesan::STATUS_DRAFT,
            TemplatePesan::STATUS_REJECTED,
        ]);
    }

    /**
     * Determine whether the user can delete the template.
     */
    public function delete(Pengguna $user, TemplatePesan $template): bool
    {
        // Super admin bisa delete semua
        if ($user->role === 'super_admin') {
            return true;
        }

        // Harus milik klien sama dan punya permission manage
        if (!$this->milikKlienSama($user, $template)) {
            return false;
        }

        return $this->bisaManage($user);
    }

    /**
     * Determine whether the user can submit template to provider.
     */
    public function submit(Pengguna $user, TemplatePesan $template): bool
    {
        // Super admin bisa submit semua
        if ($user->role === 'super_admin') {
            return true;
        }

        // Harus milik klien sama
        if (!$this->milikKlienSama($user, $template)) {
            return false;
        }

        // Sales TIDAK boleh submit
        if (!$this->bisaSubmit($user)) {
            return false;
        }

        // Template harus bisa disubmit (draft/rejected)
        return $template->bisaSubmit();
    }

    /**
     * Determine whether the user can sync template status.
     */
    public function syncStatus(Pengguna $user): bool
    {
        // owner dan admin bisa sync
        return $this->bisaManage($user);
    }

    /**
     * Determine whether the user can restore the template.
     */
    public function restore(Pengguna $user, TemplatePesan $template): bool
    {
        return $this->bisaManage($user) && $this->milikKlienSama($user, $template);
    }

    /**
     * Determine whether the user can permanently delete the template.
     */
    public function forceDelete(Pengguna $user, TemplatePesan $template): bool
    {
        return $user->role === 'super_admin';
    }
}
