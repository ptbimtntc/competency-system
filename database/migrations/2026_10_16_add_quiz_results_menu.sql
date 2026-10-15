-- Menu "Hasil Quiz" di portal (atasan bisa review jawaban benar/salah bawahan)
-- Run this once against the target database (local dev AND production).

INSERT INTO portal_menus (slug, label, icon, has_actions, sort_order) VALUES
  ('team_quiz_results', 'Hasil Quiz', 'bi-patch-question-fill', 0, 7);

-- Supervisor default bisa melihat hasil quiz timnya (view only, tidak ada aksi)
INSERT INTO portal_role_permissions (role_id, menu_slug, can_view, can_execute)
SELECT r.id, 'team_quiz_results', 1, 0
FROM portal_roles r
WHERE r.slug = 'supervisor';

-- Superadmin: akses penuh (konsisten dengan role lain, kode juga hardcode bypass)
INSERT INTO portal_role_permissions (role_id, menu_slug, can_view, can_execute)
SELECT r.id, 'team_quiz_results', 1, 1
FROM portal_roles r
WHERE r.slug = 'superadmin';

-- Disabled: tanpa akses (hanya untuk konsistensi tampilan)
INSERT INTO portal_role_permissions (role_id, menu_slug, can_view, can_execute)
SELECT r.id, 'team_quiz_results', 0, 0
FROM portal_roles r
WHERE r.slug = 'disabled';
