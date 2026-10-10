<?php
/**
 * Auth guard for portal/*.php pages. Require this at the top of every
 * portal page except login.php. Mirrors admin/auth.php's session-check
 * pattern but for the NIK-based employee portal session.
 */

session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../admin/csrf.php";
require_once __DIR__ . "/portal_helper.php";

if (!isset($_SESSION['portal_employee_id'])) {
    header("Location: login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Re-resolve role setiap request, supaya perubahan hierarki / role dari
| admin langsung berlaku tanpa menunggu employee login ulang.
|--------------------------------------------------------------------------
*/
$portalEmployeeStmt = mysqli_prepare(
    $conn,
    "SELECT id, nik, name, portal_role_id FROM employees WHERE id = ? AND is_deleted = 0"
);
mysqli_stmt_bind_param($portalEmployeeStmt, "i", $_SESSION['portal_employee_id']);
mysqli_stmt_execute($portalEmployeeStmt);
$portalEmployee = mysqli_fetch_assoc(mysqli_stmt_get_result($portalEmployeeStmt));

$portalRole = $portalEmployee ? resolve_portal_role($conn, $portalEmployee) : null;

if (!$portalEmployee || !$portalRole || $portalRole['slug'] === 'disabled') {
    foreach ($_SESSION as $key => $value) {
        if (strpos($key, 'portal_') === 0) {
            unset($_SESSION[$key]);
        }
    }
    header("Location: login.php?error=access_revoked");
    exit;
}

$_SESSION['portal_nik'] = $portalEmployee['nik'];
$_SESSION['portal_name'] = $portalEmployee['name'];
$_SESSION['portal_role_id'] = $portalRole['id'];
$_SESSION['portal_role_slug'] = $portalRole['slug'];
$_SESSION['portal_role_name'] = $portalRole['name'];

$GLOBALS['__portal_permissions'] = portal_menu_permissions($conn, $portalRole['id'], $portalRole['slug']);

function portal_is_superadmin(): bool
{
    return ($_SESSION['portal_role_slug'] ?? '') === 'superadmin';
}

function portal_can_view(string $menuSlug): bool
{
    return $GLOBALS['__portal_permissions'][$menuSlug]['can_view'] ?? false;
}

function portal_can_execute(string $menuSlug): bool
{
    return $GLOBALS['__portal_permissions'][$menuSlug]['can_execute'] ?? false;
}

function portal_require_view(string $menuSlug): void
{
    if (!portal_can_view($menuSlug)) {
        http_response_code(403);
        die('Anda tidak memiliki akses untuk melihat halaman ini.');
    }
}

function portal_require_execute(string $menuSlug): void
{
    if (!portal_can_execute($menuSlug)) {
        http_response_code(403);
        die('Anda tidak memiliki izin untuk menjalankan aksi ini.');
    }
}

/**
 * @return array|null Null means "no restriction" (superadmin sees everyone).
 * Otherwise an array of NIKs (own NIK + all transitive subordinates).
 */
function portal_scope_niks(mysqli $conn): ?array
{
    if (portal_is_superadmin()) {
        return null;
    }
    $nik = $_SESSION['portal_nik'];
    return array_merge([$nik], get_all_subordinate_niks($conn, $nik));
}
