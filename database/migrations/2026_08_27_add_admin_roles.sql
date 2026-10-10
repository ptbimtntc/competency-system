-- Role untuk akun admin (pemisahan hak akses)
-- Run this once against the target database (local dev AND production).
--
-- Role:
--   superadmin : akses penuh + kelola akun admin
--   admin      : akses penuh ke data (employee/competency/training), TANPA kelola akun
--   viewer     : hanya bisa melihat & export, tidak bisa membuat/ubah/hapus/import
--
-- Semua akun yang sudah ada dijadikan superadmin supaya tetap bisa masuk
-- ke menu Admin Accounts dan mengatur role akun lain.

ALTER TABLE admins
  ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER name;

UPDATE admins SET role = 'superadmin';
