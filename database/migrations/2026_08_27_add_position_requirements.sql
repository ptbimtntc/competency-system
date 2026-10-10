-- Required competency per posisi + dasar laporan gap kompetensi
-- Run this once against the target database (local dev AND production).
--
-- Menyimpan daftar competency yang WAJIB dimiliki tiap posisi (kolom
-- employees.position yang berupa teks bebas). Halaman "Competency Gap"
-- membandingkan kebutuhan ini dengan status competency tiap karyawan.

CREATE TABLE position_requirements (
  id INT NOT NULL AUTO_INCREMENT,
  position VARCHAR(150) NOT NULL,
  competency_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_position_competency (position, competency_id),
  KEY idx_pr_competency (competency_id),
  CONSTRAINT fk_pr_competency FOREIGN KEY (competency_id)
    REFERENCES competencies(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
