-- Minimum passing score per competency + FAILED status
-- Run this once against the target database (local dev AND production).

ALTER TABLE competencies
    ADD COLUMN passing_score INT NULL DEFAULT 70 AFTER validity_months;

ALTER TABLE employee_competencies
    MODIFY COLUMN status ENUM('NOT_TAKEN','ASSIGNED','VALID','EXPIRING_SOON','EXPIRED','FAILED') NOT NULL DEFAULT 'NOT_TAKEN';
