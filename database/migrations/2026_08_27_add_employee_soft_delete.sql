-- Soft-delete karyawan (jangan hapus permanen -> riwayat sertifikat aman)
-- Run this once against the target database (local dev AND production).
--
-- Sebelumnya employee_delete.php menghapus baris employees BESERTA seluruh
-- employee_competencies (dan lewat FK CASCADE, competency_history) secara
-- permanen. Sekarang karyawan cukup ditandai is_deleted = 1: barisnya
-- tetap ada, riwayat training/sertifikat tetap utuh, dan dia bisa
-- dipulihkan lagi. Semua halaman daftar & laporan menyaring is_deleted = 0.

ALTER TABLE employees
  ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN deleted_at DATETIME NULL;
