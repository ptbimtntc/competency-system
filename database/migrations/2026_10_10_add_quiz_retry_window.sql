-- Jendela waktu pengerjaan ulang kuis (reset quiz dari dashboard, 1 jam default)
-- Run this once against the target database (local dev AND production).

ALTER TABLE employee_competencies
    ADD COLUMN quiz_retry_until DATETIME NULL AFTER quiz_submitted_at;

-- Dashboard perlu status has_actions supaya opsi "execute" muncul di form role portal
UPDATE portal_menus SET has_actions = 1 WHERE slug = 'dashboard';

-- Supervisor default bisa menjalankan aksi reset kuis dari dashboard timnya
UPDATE portal_role_permissions prp
INNER JOIN portal_roles r ON r.id = prp.role_id
SET prp.can_execute = 1
WHERE r.slug = 'supervisor' AND prp.menu_slug = 'dashboard';
