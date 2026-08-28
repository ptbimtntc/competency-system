<?php
/*
|--------------------------------------------------------------------------
| Pagination helper
|--------------------------------------------------------------------------
|
| paginate($totalRows, $perPage): hitung state halaman dari $_GET['page'].
| render_pagination($state, $baseParams): render nav Bootstrap, tetap
| membawa parameter filter lain (search, team, deleted, dll).
|
*/
function paginate(int $totalRows, int $perPage = 25): array
{
    $perPage = max(1, $perPage);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    if ($page < 1) {
        $page = 1;
    }
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    return [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $totalRows,
        'total_pages' => $totalPages,
        'offset' => ($page - 1) * $perPage,
    ];
}

function render_pagination(array $state, array $baseParams = []): string
{
    if ($state['total_pages'] <= 1) {
        return '';
    }
    $page = $state['page'];
    $totalPages = $state['total_pages'];

    $link = function (int $p) use ($baseParams): string {
        $baseParams['page'] = $p;
        return '?' . http_build_query($baseParams, '', '&', PHP_QUERY_RFC3986);
    };

    // Jendela nomor halaman di sekitar halaman aktif.
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    $items = '';

    $prevDisabled = $page <= 1 ? ' disabled' : '';
    $items .= '<li class="page-item' . $prevDisabled . '">'
        . '<a class="page-link" href="' . htmlspecialchars($link(max(1, $page - 1))) . '">&laquo;</a></li>';

    if ($start > 1) {
        $items .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($link(1)) . '">1</a></li>';
        if ($start > 2) {
            $items .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
    }
    for ($p = $start; $p <= $end; $p++) {
        $active = $p === $page ? ' active' : '';
        $items .= '<li class="page-item' . $active . '">'
            . '<a class="page-link" href="' . htmlspecialchars($link($p)) . '">' . $p . '</a></li>';
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $items .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        $items .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($link($totalPages)) . '">'
            . $totalPages . '</a></li>';
    }

    $nextDisabled = $page >= $totalPages ? ' disabled' : '';
    $items .= '<li class="page-item' . $nextDisabled . '">'
        . '<a class="page-link" href="' . htmlspecialchars($link(min($totalPages, $page + 1))) . '">&raquo;</a></li>';

    return '<nav><ul class="pagination justify-content-center mb-0">' . $items . '</ul></nav>';
}
