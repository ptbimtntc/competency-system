-- Scheduled training + multiple-choice quiz + auto-scoring
-- Run this once against the target database (local dev AND production).

ALTER TABLE employee_competencies
    ADD COLUMN scheduled_training_date DATE NULL AFTER training_date,
    ADD COLUMN attendance_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER scheduled_training_date,
    ADD COLUMN quiz_submitted_at DATETIME NULL AFTER attendance_confirmed,
    MODIFY COLUMN status ENUM('NOT_TAKEN','ASSIGNED','VALID','EXPIRING_SOON','EXPIRED') NOT NULL DEFAULT 'NOT_TAKEN';

CREATE TABLE competency_questions (
  id INT NOT NULL AUTO_INCREMENT,
  competency_id INT NOT NULL,
  question_text TEXT NOT NULL,
  allow_multiple_answers TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_cq_competency (competency_id),
  CONSTRAINT fk_cq_competency FOREIGN KEY (competency_id) REFERENCES competencies(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE competency_question_choices (
  id INT NOT NULL AUTO_INCREMENT,
  question_id INT NOT NULL,
  option_label ENUM('A','B','C','D') NOT NULL,
  choice_text TEXT NOT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  points INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_question_option (question_id, option_label),
  CONSTRAINT fk_cqc_question FOREIGN KEY (question_id) REFERENCES competency_questions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employee_quiz_answers (
  id INT NOT NULL AUTO_INCREMENT,
  employee_competency_id INT NOT NULL,
  question_id INT NOT NULL,
  choice_id INT NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_eqa_ec (employee_competency_id),
  KEY fk_eqa_question (question_id),
  KEY fk_eqa_choice (choice_id),
  CONSTRAINT fk_eqa_ec FOREIGN KEY (employee_competency_id) REFERENCES employee_competencies(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_eqa_question FOREIGN KEY (question_id) REFERENCES competency_questions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_eqa_choice FOREIGN KEY (choice_id) REFERENCES competency_question_choices(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
