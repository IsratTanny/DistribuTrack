<?php
declare(strict_types=1);

// file: fetch_orders.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_connection.php';

session_start();

/**
 * Example calls:
 *   fetch_orders.php?scope=today          → distributor dashboard today’s orders
 *   fetch_orders.php?distributor_id=3     → orders for distributor #3
 *   fetch_orders.php?shopkeeper_id=5      → orders placed by shopkeeper #5
 *   fetch_orders.php?include_items=1      → include line item details
 *   fetch_orders.php?status=Pending       → filter by status
 */

function fail(string $msg, int $code = 400, array $extra = []): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg, 'data' => $extra], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        fail('method_not_allowed', 405);
    }

    $payload = $method === 'POST' ? json_decode(file_get_contents('php://input'), true) ?? [] : $_GET;

    // Session fallback
    $distributorId = isset($_SESSION['distributor_id']) ? (int)$_SESSION['distributor_id'] : (int)($payload['distributor_id'] ?? 0);
    $shopkeeperId  = isset($_SESSION['shopkeeper_id'])  ? (int)$_SESSION['shopkeeper_id']  : (int)($payload['shopkeeper_id'] ?? 0);

    $scope    = strtolower(trim((string)($payload['scope'] ?? '')));
    $status   = trim((string)($payload['status'] ?? ''));
    $orderId  = isset($payload['order_id']) ? (int)$payload['order_id'] : 0;
    $withItems = isset($payload['include_items']) && (int)$payload['include_items'] === 1;

    $params = [];
    $where  = [];

    if ($distributorId > 0) {
        $where[] = 'o.distributor_id = :did';
        $params[':did'] = $distributorId;
    } elseif ($shopkeeperId > 0) {
        $where[] = 'o.shopkeeper_id = :sid';
        $params[':sid'] = $shopkeeperId;
    }

    if ($orderId > 0) {
        $where[] = 'o.id = :oid';
        $params[':oid'] = $orderId;
    }

    if ($status !== '') {
        $where[] = 'o.status = :status';
        $params[':status'] = $status;
    }

    if ($scope === 'today') {
        $where[] = "DATE(o.created_at) = CURDATE()";
    }

    $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // ---- Main Query (orders) ----
    $sql = "
        SELECT
            o.id AS order_id,
            o.shopkeeper_id,
            o.distributor_id,
            o.total_amount,
            o.status,
            o.created_at,
            s.shop_name AS shopkeeper_name,
            s.address   AS shopkeeper_address,
            d.company_name AS distributor_name
        FROM orders o
        LEFT JOIN shopkeeper s ON s.id = o.shopkeeper_id
        LEFT JOIN distributor d ON d.id = o.distributor_id
        $whereSQL
        ORDER BY o.created_at DESC
        LIMIT 200
    ";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---- Optionally Fetch Line Items ----
    $itemsByOrder = [];
    if ($withItems && $orders) {
        $orderIds = array_column($orders, 'order_id');
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $itemSql = "
            SELECT
                oi.id,
                oi.order_id,
                oi.product_id,
                i.product_name,
                i.image_path,
                oi.quantity,
                oi.price,
                (oi.quantity * oi.price) AS line_total
            FROM order_items oi
            LEFT JOIN inventory i ON i.id = oi.product_id
            WHERE oi.order_id IN ($in)
            ORDER BY oi.order_id, oi.id
        ";
        $itemStmt = $conn->prepare($itemSql);
        $itemStmt->execute($orderIds);
        $rows = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $oid = (int)$r['order_id'];
            $itemsByOrder[$oid][] = [
                'id'           => (int)$r['id'],
                'product_id'   => (int)$r['product_id'],
                'product_name' => $r['product_name'] ?? 'Unnamed',
                'image_path'   => $r['image_path'] ?? null,
                'quantity'     => (int)$r['quantity'],
                'price'        => (float)$r['price'],
                'line_total'   => round((float)$r['line_total'], 2),
            ];
        }
    }

    // ---- Normalize Output ----
    $result = [];
    foreach ($orders as $o) {
        $oid = (int)$o['order_id'];
        $order = [
            'order_id'          => $oid,
            'shopkeeper_id'     => (int)$o['shopkeeper_id'],
            'shopkeeper_name'   => $o['shopkeeper_name'] ?? null,
            'shopkeeper_address'=> $o['shopkeeper_address'] ?? null,
            'distributor_id'    => (int)$o['distributor_id'],
            'distributor_name'  => $o['distributor_name'] ?? null,
            'status'            => $o['status'] ?? 'Pending',
            'total_amount'      => (float)$o['total_amount'],
            'created_at'        => $o['created_at'],
        ];
        if ($withItems) {
            $order['items'] = $itemsByOrder[$oid] ?? [];
        }
        $result[] = $order;
    }

    echo json_encode([
        'success' => true,
        'data'    => $result,
        'summary' => [
            'count' => count($result),
            'scope' => $scope ?: 'all'
        ]
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('fetch_orders.php error: ' . $e->getMessage());
    fail('server_error', 500);
}
