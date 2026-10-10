-- Employee self-service portal: hierarchy column + role/permission schema

-- hierarchy
ALTER TABLE employees
  ADD COLUMN supervisor_nik VARCHAR(50) NULL AFTER supervisor,
  ADD INDEX idx_employees_supervisor_nik (supervisor_nik);

-- roles
CREATE TABLE portal_roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  is_protected TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- menu catalog
CREATE TABLE portal_menus (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(50) NOT NULL UNIQUE,
  label VARCHAR(100) NOT NULL,
  icon VARCHAR(50) NOT NULL,
  has_actions TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0
);

-- per-role menu permissions
CREATE TABLE portal_role_permissions (
  role_id INT NOT NULL,
  menu_slug VARCHAR(50) NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 0,
  can_execute TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (role_id, menu_slug),
  FOREIGN KEY (role_id) REFERENCES portal_roles(id) ON DELETE CASCADE,
  FOREIGN KEY (menu_slug) REFERENCES portal_menus(slug) ON DELETE CASCADE
);

-- explicit role override on the employee record (NULL = automatic, hierarchy-based)
ALTER TABLE employees
  ADD COLUMN portal_role_id INT NULL AFTER supervisor_nik,
  ADD CONSTRAINT fk_employees_portal_role FOREIGN KEY (portal_role_id) REFERENCES portal_roles(id) ON DELETE SET NULL;

-- seed 3 protected roles
INSERT INTO portal_roles (slug, name, description, is_protected) VALUES
  ('supervisor', 'Supervisor', 'Role default untuk karyawan yang memiliki bawahan.', 1),
  ('superadmin', 'Superadmin', 'Akses penuh ke seluruh data & menu, tanpa batasan hierarki.', 1),
  ('disabled',   'Akses Dinonaktifkan', 'Memblokir login portal walau karyawan punya bawahan.', 1);

-- seed menu catalog
INSERT INTO portal_menus (slug, label, icon, has_actions, sort_order) VALUES
  ('dashboard', 'Dashboard Tim', 'bi-speedometer2', 0, 1),
  ('team_members', 'Anggota Tim', 'bi-people-fill', 0, 2),
  ('team_competency_matrix', 'Competency Matrix', 'bi-grid-3x3-gap-fill', 0, 3),
  ('team_competency_gap', 'Competency Gap', 'bi-bullseye', 0, 4),
  ('team_recertification', 'Recertification Due', 'bi-clock-history', 0, 5),
  ('team_attendance', 'Konfirmasi Kehadiran', 'bi-clipboard2-check-fill', 1, 6);

-- default permissions: supervisor sees everything about their own team, can execute attendance confirmation
INSERT INTO portal_role_permissions (role_id, menu_slug, can_view, can_execute)
SELECT r.id, m.slug, 1, (m.slug = 'team_attendance')
FROM portal_roles r JOIN portal_menus m ON 1=1
WHERE r.slug = 'supervisor';

-- superadmin: full access to everything (stored for display; code also hardcodes a bypass)
INSERT INTO portal_role_permissions (role_id, menu_slug, can_view, can_execute)
SELECT r.id, m.slug, 1, 1 FROM portal_roles r JOIN portal_menus m ON 1=1 WHERE r.slug = 'superadmin';

-- disabled: zero permissions (login itself is blocked in code, this is just for consistent display)
INSERT INTO portal_role_permissions (role_id, menu_slug, can_view, can_execute)
SELECT r.id, m.slug, 0, 0 FROM portal_roles r JOIN portal_menus m ON 1=1 WHERE r.slug = 'disabled';
