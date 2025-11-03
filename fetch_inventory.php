<?php
declare(strict_types=1);

// file: fetch_inventory.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_connection.php'; // exposes $conn (PDO)

function fail(string $msg, int $code = 400, array $extra = []): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg, 'data' => $extra], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // ---- Read & sanitize inputs ----
    $distributorId = isset($_GET['distributor_id']) && is_numeric($_GET['distributor_id'])
        ? (int)$_GET['distributor_id'] : null;

    // Search query: matches name/description/distributor
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

    // Only items with stock > 0 (handy for shopkeeper browsing)
    $inStockOnly = isset($_GET['in_stock_only']) && (int)$_GET['in_stock_only'] === 1;

    // Active products only (default true). Pass is_active=0 explicitly to include inactive.
    $isActive = isset($_GET['is_active']) ? (int)$_GET['is_active'] : 1;

    // Pagination
    $limit  = isset($_GET['limit'])  && is_numeric($_GET['limit'])  ? max(1, min(100, (int)$_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    // ---- Build WHERE dynamically & count query ----
    $where = [];
    $params = [];

    if ($distributorId !== null) {
        $where[] = 'i.distributor_id = :distributor_id';
        $params[':distributor_id'] = $distributorId;
    }

    if ($isActive === 1) {
        $where[] = 'i.is_active = 1';
    } elseif ($isActive === 0) {
        // no filter (include inactive as well)
    } else {
        // invalid flag -> default to active
        $where[] = 'i.is_active = 1';
    }

    if ($inStockOnly) {
        $where[] = 'i.quantity > 0';
    }

    if ($q !== '') {
        $where[] = '(i.product_name LIKE :q OR i.description LIKE :q OR d.company_name LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // ---- Main SQL ----
    // Use created_at if present in your schema; otherwise fallback to id DESC.
    $sqlBase = "
        FROM inventory i
        INNER JOIN distributor d ON i.distributor_id = d.id
        $whereSql
    ";

    $sql = "
        SELECT
            i.id,
            i.product_name,
            i.description,
            i.price,
            i.quantity AS stock,
            i.image_path,
            i.distributor_id,
            d.company_name AS distributor
        $sqlBase
        ORDER BY i.created_at DESC, i.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $conn->prepare($sql);

    // bind dynamic params
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---- Count for pagination (optional but useful) ----
    $countSql = "SELECT COUNT(*) $sqlBase";
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    // ---- Normalize/format response ----
    $products = array_map(function(array $r): array {
        return [
            'id'           => (int)$r['id'],
            'product_name' => (string)$r['product_name'],
            'description'  => (string)($r['description'] ?? ''),
            'price'        => isset($r['price']) ? (float)$r['price'] : 0.0,
            'stock'        => (int)($r['stock'] ?? 0),
            'image_path'   => $r['image_path'] ?? null,
            'distributor'  => (string)($r['distributor'] ?? '—'),
            'distributor_id' => isset($r['distributor_id']) ? (int)$r['distributor_id'] : null,
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'data'    => $products,
        'paging'  => [
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
            'returned' => count($products),
        ],
        'filters' => [
            'distributor_id' => $distributorId,
            'q'              => $q !== '' ? $q : null,
            'in_stock_only'  => $inStockOnly,
            'is_active'      => $isActive === 1 ? 1 : 0,
        ],
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('fetch_inventory.php error: ' . $e->getMessage());
    fail('server_error', 500);
}
