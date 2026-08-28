<?php
/*
|--------------------------------------------------------------------------
| Role & hak akses admin
|--------------------------------------------------------------------------
|
| Role disimpan di $_SESSION['admin_role'] (diisi oleh auth.php / login.php).
|
|   superadmin : akses penuh + kelola akun admin
|   admin      : akses penuh ke data, tanpa kelola akun
|   viewer     : hanya lihat & export
|
| Default aman kalau role belum ter-set: 'viewer' (least privilege) untuk
| pengecekan, tetapi auth.php mengisi 'admin' bila kolom role belum ada
| (kompatibel dengan kondisi sebelum migrasi).
|
*/
function current_admin_role(): string
{
    return $_SESSION['admin_role'] ?? 'viewer';
}

function admin_is_superadmin(): bool
{
    return current_admin_role() === 'superadmin';
}

function admin_can_write(): bool
{
    return in_array(current_admin_role(), ['superadmin', 'admin'], true);
}

/*
| Panggil di awal setiap handler yang MENULIS data (setelah csrf_validate()).
*/
function require_writer(): void
{
    if (!admin_can_write()) {
        http_response_code(403);
        die('Akses ditolak: akun Anda hanya memiliki hak lihat (read-only).');
    }
}

/*
| Panggil di awal halaman yang hanya boleh dibuka super admin.
*/
function require_superadmin(): void
{
    if (!admin_is_superadmin()) {
        http_response_code(403);
        die('Akses ditolak: hanya Super Admin yang dapat membuka halaman ini.');
    }
}

function role_label(string $role): string
{
    return match ($role) {
        'superadmin' => 'Super Admin',
        'admin' => 'Admin',
        'viewer' => 'Viewer (read-only)',
        default => $role,
    };
}

function allowed_admin_roles(): array
{
    return ['superadmin', 'admin', 'viewer'];
}
