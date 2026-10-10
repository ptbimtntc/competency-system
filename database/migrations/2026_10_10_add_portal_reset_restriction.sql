-- Izinkan superadmin portal membatasi competency mana saja yang boleh
-- direset (FAILED -> ASSIGNED) oleh login portal (supervisor/superadmin).
-- Default TRUE supaya perilaku lama (semua competency boleh direset) tidak
-- berubah sampai superadmin secara eksplisit menonaktifkan salah satunya.
-- Run this once against the target database (local dev AND production).

ALTER TABLE competencies
    ADD COLUMN portal_reset_allowed TINYINT(1) NOT NULL DEFAULT 1 AFTER passing_score;
