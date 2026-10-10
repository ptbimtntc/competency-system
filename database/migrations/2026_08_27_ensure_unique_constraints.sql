-- Pastikan UNIQUE constraint yang diandalkan logika aplikasi benar-benar ada
-- Run this once against the target database (local dev AND production).
--
-- Logika upsert import (employees / competencies / employee_competencies) dan
-- pengecekan "sudah terdaftar" di form Add mengandalkan kolom-kolom ini unik.
-- Kalau constraint-nya tidak ada di DB (mis. dibuat manual di dev tapi tidak
-- di production), import bisa diam-diam membuat baris kembar.
--
-- Skrip ini idempotent: tiap ALTER hanya dijalankan kalau constraint-nya
-- belum ada (MySQL tidak punya "ADD INDEX IF NOT EXISTS"). Kalau tabel sudah
-- terlanjur berisi data duplikat, ALTER akan GAGAL -- bersihkan duplikatnya
-- dulu lalu jalankan ulang.

-- 1. employees.nik unik
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE table_schema = DATABASE() AND table_name = 'employees'
             AND non_unique = 0 AND seq_in_index = 1 AND column_name = 'nik');
SET @s := IF(@c = 0,
  'ALTER TABLE employees ADD UNIQUE KEY uq_employees_nik (nik)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. competencies.code unik
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE table_schema = DATABASE() AND table_name = 'competencies'
             AND non_unique = 0 AND seq_in_index = 1 AND column_name = 'code');
SET @s := IF(@c = 0,
  'ALTER TABLE competencies ADD UNIQUE KEY uq_competencies_code (code)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. competencies.name unik (import menolak nama yang bentrok)
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE table_schema = DATABASE() AND table_name = 'competencies'
             AND non_unique = 0 AND seq_in_index = 1 AND column_name = 'name');
SET @s := IF(@c = 0,
  'ALTER TABLE competencies ADD UNIQUE KEY uq_competencies_name (name)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. admins.username unik
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE table_schema = DATABASE() AND table_name = 'admins'
             AND non_unique = 0 AND seq_in_index = 1 AND column_name = 'username');
SET @s := IF(@c = 0,
  'ALTER TABLE admins ADD UNIQUE KEY uq_admins_username (username)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 5. employee_competencies (employee_id, competency_id) unik -- 1 pasangan per employee
SET @c := (SELECT COUNT(*) FROM (
             SELECT index_name
             FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE()
               AND table_name = 'employee_competencies'
               AND non_unique = 0
             GROUP BY index_name
             HAVING SUM(column_name IN ('employee_id', 'competency_id')) = 2
                AND COUNT(*) = 2
           ) x);
SET @s := IF(@c = 0,
  'ALTER TABLE employee_competencies ADD UNIQUE KEY uq_ec_employee_competency (employee_id, competency_id)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
