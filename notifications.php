<?php
declare(strict_types=1);

// file: notifications.php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/db_connection.php'; // exposes $conn (PDO)

/*
Query params (all optional):
- role=distributor|shopkeeper   (auto-detected from session if omitted)
- scope=today|all               (default: today for distributors, all for shopkeepers)
- limit_orders=10               (max 50)
- limit_stock=10                (max 50; distributor only)
- low_stock_threshold=5         (default 5; distributor only)
*/

function fail(string $msg, int $code = 400, array $extra = []): never {
  http_response_code($code);
  echo json_encode(['success' => false, 'error' => $msg, 'data' => $extra], JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  $role = $_GET['role'] ?? '';
  $scope = $_GET['scope'] ?? '';

  $distributorId = isset($_SESSION['distributor_id']) ? (int)$_SESSION['distributor_id'] : 0;
  $shopkeeperId  = isset($_SESSION['shopkeeper_id'])  ? (int)$_SESSION['shopkeeper_id']  : 0;

  if ($role === '') {
    if ($distributorId > 0) $role = 'distributor';
    elseif ($shopkeeperId > 0) $role = 'shopkeeper';
  }
  $role = strtolower(trim((string)$role));

  if ($role !== 'distributor' && $role !== 'shopkeeper') {
    // allow explicit override via query if not in session
    $distributorId = isset($_GET['distributor_id']) ? (int)$_GET['distributor_id'] : $distributorId;
    $shopkeeperId  = isset($_GET['shopkeeper_id'])  ? (int)$_GET['shopkeeper_id']  : $shopkeeperId;

    if ($distributorId > 0) $role = 'distributor';
    elseif ($shopkeeperId > 0) $role = 'shopkeeper';
    else fail('unauthorized_or_role_missing', 401);
  }

  // Defaults
  $limitOrders = isset($_GET['limit_orders']) && is_numeric($_GET['limit_orders'])
    ? max(1, min(50, (int)$_GET['limit_orders'])) : 10;

  $limitStock = isset($_GET['limit_stock']) && is_numeric($_GET['limit_stock'])
    ? max(1, min(50, (int)$_GET['limit_stock'])) : 10;

  $lowStockThreshold = isset($_GET['low_stock_threshold']) && is_numeric($_GET['low_stock_threshold'])
    ? max(0, (int)$_GET['low_stock_threshold']) : 5;

  if ($role === 'distributor') {
    if ($distributorId <= 0) fail('unauthorized_distributor', 401);

    $isToday = ($scope ?: 'today') === 'today';

    $params = [':did' => $distributorId];
    $where = "o.distributor_id = :did";
    if ($isToday) $where .= " AND DATE(o.created_at) = CURDATE()";

    // 1) New orders (today by default)
    $sqlOrders = "
      SELECT
        o.id AS order_id,
        o.total_amount,
        o.status,
        o.created_at,
        s.shop_name  AS shopkeeper_name,
        s.address    AS shopkeeper_address
      FROM orders o
      LEFT JOIN shopkeeper s ON s.id = o.shopkeeper_id
      WHERE $where
      ORDER BY o.created_at DESC
      LIMIT :lim
    ";
    $stmt = $conn->prepare($sqlOrders);
    $stmt->bindValue(':did', $distributorId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limitOrders, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];

    foreach ($orders as $o) {
      $oid = (int)$o['order_id'];
      $amount = isset($o['total_amount']) ? (float)$o['total_amount'] : 0.0;
      $title = 'New order received';
      $message = sprintf(
        'Order #%d from %s — total ৳%s.',
        $oid,
        $o['shopkeeper_name'] ?? 'Shopkeeper',
        number_format($amount, 2, '.', '')
      );
      $notifications[] = [
        'id'         => "order-$oid",
        'type'       => 'order_new',
        'priority'   => 'normal',
        'title'      => $title,
        'message'    => $message,
        'created_at' => $o['created_at'],
        'link'       => 'sales-reports.html', // adjust if you build a dedicated orders page
        'meta'       => [
          'order_id' => $oid,
          'shopkeeper_name' => $o['shopkeeper_name'] ?? null,
          'status' => $o['status'] ?? 'Pending',
          'total_amount' => $amount,
        ],
      ];
    }

    // 2) Low stock alerts
    $sqlLowStock = "
      SELECT i.id, i.product_name, i.quantity AS stock, i.price
      FROM inventory i
      WHERE i.distributor_id = :did AND i.quantity <= :th
      ORDER BY i.quantity ASC, i.id ASC
      LIMIT :lim
    ";
    $ls = $conn->prepare($sqlLowStock);
    $ls->bindValue(':did', $distributorId, PDO::PARAM_INT);
    $ls->bindValue(':th',  $lowStockThreshold, PDO::PARAM_INT);
    $ls->bindValue(':lim', $limitStock, PDO::PARAM_INT);
    $ls->execute();
    $lowStock = $ls->fetchAll(PDO::FETCH_ASSOC);

    foreach ($lowStock as $p) {
      $pid = (int)$p['id'];
      $title = 'Low stock alert';
      $message = sprintf(
        '"%s" is low on stock (%d left).',
        $p['product_name'] ?? 'Product',
        (int)($p['stock'] ?? 0)
      );
      $notifications[] = [
        'id'         => "lowstock-$pid",
        'type'       => 'inventory_low',
        'priority'   => 'high',
        'title'      => $title,
        'message'    => $message,
        'created_at' => date('Y-m-d H:i:s'),
        'link'       => 'inventory-management.html',
        'meta'       => [
          'product_id' => $pid,
          'stock'      => (int)($p['stock'] ?? 0),
          'price'      => isset($p['price']) ? (float)$p['price'] : null,
        ],
      ];
    }

    echo json_encode([
      'success' => true,
      'role'    => 'distributor',
      'data'    => $notifications,
      'summary' => [
        'new_orders' => count($orders),
        'low_stock'  => count($lowStock),
        'scope'      => $isToday ? 'today' : 'all',
        'threshold'  => $lowStockThreshold,
      ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
  }

  // ---- Shopkeeper notifications ----
  if ($role === 'shopkeeper') {
    if ($shopkeeperId <= 0) fail('unauthorized_shopkeeper', 401);

    $isToday = ($scope ?: 'all') === 'today';
    $where = "o.shopkeeper_id = :sid";
    if ($isToday) $where .= " AND DATE(o.created_at) = CURDATE()";

    // Recent orders (for updates/status visibility)
    $sqlOrders = "
      SELECT
        o.id AS order_id,
        o.total_amount,
        o.status,
        o.created_at,
        d.company_name AS distributor_name
      FROM orders o
      LEFT JOIN distributor d ON d.id = o.distributor_id
      WHERE $where
      ORDER BY o.created_at DESC
      LIMIT :lim
    ";
    $stmt = $conn->prepare($sqlOrders);
    $stmt->bindValue(':sid', $shopkeeperId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limitOrders, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];
    foreach ($orders as $o) {
      $oid = (int)$o['order_id'];
      $amount = isset($o['total_amount']) ? (float)$o['total_amount'] : 0.0;
      $title = 'Order update';
      $message = sprintf(
        'Order #%d with %s is %s — total ৳%s.',
        $oid,
        $o['distributor_name'] ?? 'Distributor',
        $o['status'] ?? 'Pending',
        number_format($amount, 2, '.', '')
      );
      $notifications[] = [
        'id'         => "order-$oid",
        'type'       => 'order_status',
        'priority'   => 'normal',
        'title'      => $title,
        'message'    => $message,
        'created_at' => $o['created_at'],
        'link'       => 'shopkeeper-dashboard.html',
        'meta'       => [
          'order_id' => $oid,
          'status'   => $o['status'] ?? 'Pending',
          'total_amount' => $amount,
        ],
      ];
    }

    // Cart reminder (if items present)
    $sqlCart = "SELECT COUNT(*) AS items FROM cart WHERE shopkeeper_id = :sid";
    $cstmt = $conn->prepare($sqlCart);
    $cstmt->execute([':sid' => $shopkeeperId]);
    $cartItems = (int)$cstmt->fetchColumn();

    if ($cartItems > 0) {
      $notifications[] = [
        'id'         => "cart-$shopkeeperId",
        'type'       => 'cart_reminder',
        'priority'   => 'low',
        'title'      => 'You have items in your cart',
        'message'    => "You have $cartItems item(s) waiting in your cart.",
        'created_at' => date('Y-m-d H:i:s'),
        'link'       => 'cart.php',
        'meta'       => ['items' => $cartItems],
      ];
    }

    echo json_encode([
      'success' => true,
      'role'    => 'shopkeeper',
      'data'    => $notifications,
      'summary' => [
        'orders_considered' => count($orders),
        'cart_items'        => $cartItems,
        'scope'             => $isToday ? 'today' : 'all',
      ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
  }

  fail('unsupported_role', 400);

} catch (Throwable $e) {
  error_log('notifications.php error: ' . $e->getMessage());
  fail('server_error', 500);
}
