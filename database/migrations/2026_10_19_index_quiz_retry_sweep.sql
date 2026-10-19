-- Index untuk mempercepat & mengurangi lock contention pada sweepExpiredQuizRetries()
-- (UPDATE ... WHERE status='ASSIGNED' AND quiz_submitted_at IS NULL AND quiz_retry_until IS NOT NULL AND quiz_retry_until < NOW())
-- yang dijalankan di setiap request lewat config/database.php.
-- Run this once against the target database (local dev AND production, via phpMyAdmin).

ALTER TABLE employee_competencies
    ADD INDEX idx_quiz_retry_sweep (status, quiz_retry_until);
