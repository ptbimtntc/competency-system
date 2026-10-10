<?php
/**
 * Hierarchy + role resolution helpers for the employee self-service portal.
 */

function get_all_subordinate_niks(mysqli $conn, string $nik): array
{
    $visited = [$nik => true];
    $queue = [$nik];
    $subordinates = [];

    while (!empty($queue)) {
        $current = array_shift($queue);
        $stmt = mysqli_prepare(
            $conn,
            "SELECT nik FROM employees WHERE supervisor_nik = ? AND is_deleted = 0"
        );
        mysqli_stmt_bind_param($stmt, "s", $current);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $childNik = $row['nik'];
            if (isset($visited[$childNik])) {
                continue;
            }
            $visited[$childNik] = true;
            $subordinates[] = $childNik;
            $queue[] = $childNik;
        }
    }

    return $subordinates;
}

function employee_has_subordinates(mysqli $conn, string $nik): bool
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1 FROM employees WHERE supervisor_nik = ? AND is_deleted = 0 LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "s", $nik);
    mysqli_stmt_execute($stmt);
    return mysqli_stmt_get_result($stmt)->num_rows > 0;
}

/**
 * Returns the effective portal role row (id, slug, name) for an employee, or null
 * if the employee has no portal access at all.
 *
 * @param array $employee Must contain 'nik' and 'portal_role_id' keys.
 */
function resolve_portal_role(mysqli $conn, array $employee): ?array
{
    if (!empty($employee['portal_role_id'])) {
        $stmt = mysqli_prepare($conn, "SELECT id, slug, name FROM portal_roles WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $employee['portal_role_id']);
        mysqli_stmt_execute($stmt);
        $role = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        return $role ?: null;
    }

    if (employee_has_subordinates($conn, $employee['nik'])) {
        $result = mysqli_query($conn, "SELECT id, slug, name FROM portal_roles WHERE slug = 'supervisor'");
        return mysqli_fetch_assoc($result) ?: null;
    }

    return null;
}

/**
 * Builds a " AND <column> IN (?, ?, ...)" clause + bound params for scoping
 * a query to a set of NIKs. Pass null scopeNiks (superadmin) to get no
 * restriction at all.
 *
 * @return array{0: string, 1: array<int, string>}
 */
function portal_scope_where(?array $scopeNiks, string $column = 'e.nik'): array
{
    if ($scopeNiks === null || empty($scopeNiks)) {
        return ['', []];
    }
    $placeholders = implode(',', array_fill(0, count($scopeNiks), '?'));
    return [" AND {$column} IN ({$placeholders})", $scopeNiks];
}

/**
 * @return array<string, array{can_view: bool, can_execute: bool}>
 */
function portal_menu_permissions(mysqli $conn, int $roleId, string $roleSlug): array
{
    $permissions = [];
    $menuResult = mysqli_query($conn, "SELECT slug FROM portal_menus");
    while ($menu = mysqli_fetch_assoc($menuResult)) {
        $permissions[$menu['slug']] = ['can_view' => false, 'can_execute' => false];
    }

    if ($roleSlug === 'superadmin') {
        foreach ($permissions as $slug => $value) {
            $permissions[$slug] = ['can_view' => true, 'can_execute' => true];
        }
        return $permissions;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT menu_slug, can_view, can_execute FROM portal_role_permissions WHERE role_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "i", $roleId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $permissions[$row['menu_slug']] = [
            'can_view' => (bool) $row['can_view'],
            'can_execute' => (bool) $row['can_execute'],
        ];
    }

    return $permissions;
}
