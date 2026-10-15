-- Diagnostik: cek migrasi mana yang BELUM jalan di database ini.
-- Jalankan lewat phpMyAdmin (SQL tab) di database production Hostinger.
-- Baris yang present = 0 berarti migrasi terkait belum dijalankan di sini.

SELECT 'employee_competencies.scheduled_training_date' AS item,
       EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='employee_competencies' AND column_name='scheduled_training_date') AS present,
       '2026_08_21_add_quiz_scheduling.sql' AS migration_file
UNION ALL SELECT 'employee_competencies.attendance_confirmed',
       EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='employee_competencies' AND column_name='attendance_confirmed'),
       '2026_08_21_add_quiz_scheduling.sql'
UNION ALL SELECT 'employee_competencies.quiz_submitted_at',
       EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='employee_competencies' AND column_name='quiz_submitted_at'),
       '2026_08_21_add_quiz_scheduling.sql'
UNION ALL SELECT 'table competency_questions',
       EXISTS(SELECT 1 FROM information_schema.TABLES WHERE table_schema=DATABASE() AND table_name='competency_questions'),
       '2026_08_21_add_quiz_scheduling.sql'
UNION ALL SELECT 'table competency_question_choices',
       EXISTS(SELECT 1 FROM information_schema.TABLES WHERE table_schema=DATABASE() AND table_name='competency_question_choices'),
       '2026_08_21_add_quiz_scheduling.sql'
UNION ALL SELECT 'table employee_quiz_answers',
       EXISTS(SELECT 1 FROM information_schema.TABLES WHERE table_schema=DATABASE() AND table_name='employee_quiz_answers'),
       '2026_08_21_add_quiz_scheduling.sql'

UNION ALL SELECT 'competencies.passing_score',
       EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='competencies' AND column_name='passing_score'),
       '2026_08_26_add_passing_score.sql'

UNION ALL SELECT 'admins.role',
       EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='admins' AND column_name='role'),
       '2026_08_27_add_admin_roles.sql'

UNION ALL SELECT 'table competency_history',
       EXISTS(SELECT 1 FROM information_schema.TABLES WHERE table_schema=DATABASE() AND table_name='competency_history'),
       '2026_08_27_add_competency_history.sql'

UNION ALL SELECT 'employees.is_deleted',
       EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='is_deleted'),
       '2026_08_27_add_employee_soft_delete.sql'

UNION ALL SELECT 'table position_requirements',
       EXISTS(SELECT 1 FROM information_schema.TABLES WHERE table_schema=DATABASE() AND table_name='position_requirements'),
       '2026_08_27_add_position_requirements.sql'

UNION ALL SELECT 'unique key employees.nik',
       EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE table_schema=DATABASE() AND table_name='employees' AND non_unique=0 AND seq_in_index=1 AND column_name='nik'),
       '2026_08_27_ensure_unique_constraints.sql'
UNION ALL SELECT 'unique key competencies.code',
       EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE table_schema=DATABASE() AND table_name='competencies' AND non_unique=0 AND seq_in_index=1 AND column_name='code'),
       '2026_08_27_ensure_unique_constraints.sql'
UNION ALL SELECT 'unique key admins.username',
       EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE table_schema=DATABASE() AND table_name='admins' AND non_unique=0 AND seq_in_index=1 AND column_name='username'),
       '2026_08_27_ensure_unique_constraints.sql'
UNION ALL SELECT 'unique key employee_competencies(employee_id,competency_id)',
       EXISTS(SELECT 1 FROM (
             SELECT index_name FROM information_schema.STATISTICS
             WHERE table_schema=DATABASE() AND table_name='employee_competencies' AND non_unique=0
             GROUP BY index_name
             HAVING SUM(column_name IN ('employee_id','competency_id'))=2 AND COUNT(*)=2
           ) x),
       '2026_08_27_ensure_unique_constraints.sql'

UNION ALL SELECT 'competencies.portal_reset_allowed',
       EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='competencies' AND column_name='portal_reset_allowed'),
       '2026_10_10_add_portal_reset_restriction.sql'

UNION ALL SELECT 'employees.supervisor_nik',
       EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='supervisor_nik'),
       '2026_10_10_add_portal_roles.sql'
UNION ALL SELECT 'table portal_roles',
       EXISTS(SELECT 1 FROM information_schema.TABLES WHERE table_schema=DATABASE() AND table_name='portal_roles'),
       '2026_10_10_add_portal_roles.sql'
UNION ALL SELECT 'table portal_menus',
       EXISTS(SELECT 1 FROM information_schema.TABLES WHERE table_schema=DATABASE() AND table_name='portal_menus'),
       '2026_10_10_add_portal_roles.sql'
UNION ALL SELECT 'table portal_role_permissions',
       EXISTS(SELECT 1 FROM information_schema.TABLES WHERE table_schema=DATABASE() AND table_name='portal_role_permissions'),
       '2026_10_10_add_portal_roles.sql'
UNION ALL SELECT 'employees.portal_role_id',
       EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='portal_role_id'),
       '2026_10_10_add_portal_roles.sql'

UNION ALL SELECT 'employee_competencies.quiz_retry_until',
       EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='employee_competencies' AND column_name='quiz_retry_until'),
       '2026_10_10_add_quiz_retry_window.sql'

UNION ALL SELECT 'portal_menus.team_quiz_results',
       EXISTS(SELECT 1 FROM portal_menus WHERE slug='team_quiz_results'),
       '2026_10_16_add_quiz_results_menu.sql'

ORDER BY present ASC, migration_file ASC;
