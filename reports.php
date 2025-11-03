<?php
declare(strict_types=1);

// file: reports.php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/db_connection.php'; // provides $conn (PDO)

function fail(string $msg, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  $distributorId = $_SESSION['distributor_id'] ?? 0;
  $shopkeeperId  = $_SESSION['shopkeeper_id']  ?? 0;

  if ($distributorId <= 0 && $shopkeeperId <= 0) {
    fail('unauthorized', 401);
  }

  $role = $distributorId ? 'distributor' : 'shopkeeper';

  // ---- Filters ----
  $range  = strtolower(trim($_GET['range'] ?? 'month'));
  $status = $_GET['status'] ?? null;
  $start  = $_GET['start_date'] ?? null;
  $end    = $_GET['end_date'] ?? null;

  $params = [];
  $where  = [];

  // Base role filter
  if ($role === 'distributor') {
    $where[] = 'o.distributor_id = :did';
    $params[':did'] = $distributorId;
  } else {
    $where[] = 'o.shopkeeper_id = :sid';
    $params[':sid'] = $shopkeeperId;
  }

  // Status filter
  if ($status && in_array($status, ['Pending', 'Paid', 'Delivered', 'Cancelled'], true)) {
    $where[] = 'o.status = :status';
    $params[':status'] = $status;
  }

  // Date filter logic
  $dateExpr = 'DATE(o.created_at)';
  $now = new DateTime('now');

  switch ($range) {
    case 'today':
      $where[] = "$dateExpr = CURDATE()";
      break;
    case 'week':
      $where[] = "$dateExpr >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
      break;
    case 'month':
      $where[] = "$dateExpr >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
      break;
    case 'year':
      $where[] = "$dateExpr >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
      break;
    case 'custom':
      if ($start && $end) {
        $where[] = "$dateExpr BETWEEN :start AND :end";
        $params[':start'] = $start;
        $params[':end']   = $end;
      }
      break;
    default:
      // all time (no date filter)
      break;
  }

  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

  // ---- 1) Summary: total sales, orders count, items sold ----
  $sqlSummary = "
    SELECT 
      COALESCE(SUM(o.total_amount),0) AS total_sales,
      COUNT(DISTINCT o.id) AS orders_count,
      COALESCE(SUM(oi.quantity),0) AS items_sold
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    $whereSql
  ";
  $stmt = $conn->prepare($sqlSummary);
  foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $stmt->execute();
  $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_sales' => 0, 'orders_count' => 0, 'items_sold' => 0];

  // ---- 2) Top 5 Selling Products ----
  $sqlTop = "
    SELECT 
      i.id AS product_id,
      i.product_name,
      SUM(oi.quantity) AS total_sold,
      SUM(oi.quantity * oi.price) AS revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN inventory i ON i.id = oi.product_id
    $whereSql
    GROUP BY i.id, i.product_name
    ORDER BY total_sold DESC
    LIMIT 5
  ";
  $topStmt = $conn->prepare($sqlTop);
  foreach ($params as $k => $v) {
    $topStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $topStmt->execute();
  $topProducts = $topStmt->fetchAll(PDO::FETCH_ASSOC);

  // ---- 3) Recent Orders ----
  $sqlOrders = "
    SELECT 
      o.id AS order_id,
      o.total_amount,
      o.status,
      o.created_at,
      s.shop_name AS shopkeeper_name,
      d.company_name AS distributor_name
    FROM orders o
    LEFT JOIN shopkeeper s ON s.id = o.shopkeeper_id
    LEFT JOIN distributor d ON d.id = o.distributor_id
    $whereSql
    ORDER BY o.created_at DESC
    LIMIT 10
  ";
  $oStmt = $conn->prepare($sqlOrders);
  foreach ($params as $k => $v) {
    $oStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $oStmt->execute();
  $recentOrders = $oStmt->fetchAll(PDO::FETCH_ASSOC);

  // ---- Build Response ----
  echo json_encode([
    'success' => true,
    'role' => $role,
    'filters' => [
      'range' => $range,
      'status' => $status ?: 'all'
    ],
    'summary' => [
      'total_sales'   => round((float)$summary['total_sales'], 2),
      'orders_count'  => (int)$summary['orders_count'],
      'items_sold'    => (int)$summary['items_sold']
    ],
    'top_products' => $topProducts,
    'recent_orders' => $recentOrders
  ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  error_log('reports.php error: ' . $e->getMessage());
  fail('server_error', 500);
}
