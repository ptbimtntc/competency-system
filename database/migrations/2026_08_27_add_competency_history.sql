-- Riwayat siklus training per employee_competency (audit trail sertifikasi)
-- Run this once against the target database (local dev AND production).
--
-- Setiap kali sebuah competency employee "selesai" (lewat kuis, edit manual,
-- import, atau dijadwalkan ulang / re-training), sistem menyimpan snapshot
-- kondisinya ke tabel ini. Baris employee_competencies tetap 1 per pasangan
-- (employee, competency) dan tetap ditimpa seperti sebelumnya -- tabel ini
-- yang menyimpan jejak "pernah lulus 2024, diperpanjang 2025, dst".

CREATE TABLE competency_history (
  id INT NOT NULL AUTO_INCREMENT,
  employee_competency_id INT NOT NULL,
  employee_id INT NOT NULL,
  competency_id INT NOT NULL,
  training_date DATE NULL,
  scheduled_training_date DATE NULL,
  expiry_date DATE NULL,
  score INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'NOT_TAKEN',
  certificate_number VARCHAR(100) NULL,
  trainer VARCHAR(150) NULL,
  training_provider VARCHAR(150) NULL,
  notes TEXT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  recorded_by VARCHAR(150) NULL,
  recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ch_ec (employee_competency_id),
  KEY idx_ch_employee (employee_id),
  KEY idx_ch_competency (competency_id),
  CONSTRAINT fk_ch_ec FOREIGN KEY (employee_competency_id)
    REFERENCES employee_competencies(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
