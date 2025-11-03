<?php
declare(strict_types=1);

// file: remove_from_cart.php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/db_connection.php'; // provides $conn (PDO)

/*
POST JSON examples:

// 1) Remove by cart_id
{ "cart_id": 12 }

// 2) Remove by product_id (row belonging to the current shopkeeper)
{ "product_id": 5 }

// 3) Decrement quantity by 1
{ "cart_id": 12, "decrement": true }

// 4) Set quantity explicitly (0 deletes)
{ "product_id": 5, "quantity": 2 }

// Optional (server-to-server): override session
{ "shopkeeper_id": 9, "product_id": 5 }
*/

function fail(string $msg, int $code = 400, array $extra = []): never {
  http_response_code($code);
  echo json_encode(['success' => false, 'error' => $msg, 'data' => $extra], JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('method_not_allowed', 405);
  }

  // Accept JSON or x-www-form-urlencoded
  $payload = [];
  $raw = file_get_contents('php://input');
  if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $payload = $decoded;
  }
  if (empty($payload) && !empty($_POST)) {
    $payload = $_POST;
  }

  // Determine shopkeeper
  $shopkeeperId = isset($_SESSION['shopkeeper_id']) ? (int)$_SESSION['shopkeeper_id'] : 0;
  if (!$shopkeeperId && isset($payload['shopkeeper_id'])) {
    $shopkeeperId = (int)$payload['shopkeeper_id'];
  }
  if ($shopkeeperId <= 0) {
    fail('unauthorized_or_missing_shopkeeper', 401);
  }

  // Identify the target row
  $cartId    = isset($payload['cart_id'])    ? (int)$payload['cart_id']    : 0;
  $productId = isset($payload['product_id']) ? (int)$payload['product_id'] : 0;

  if ($cartId <= 0 && $productId <= 0) {
    fail('missing_cart_id_or_product_id');
  }

  // Operation mode
  $hasQuantity = array_key_exists('quantity', $payload);
  $quantity    = $hasQuantity ? (int)$payload['quantity'] : null;
  $decrement   = !empty($payload['decrement']);

  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // First, find the row to ensure it belongs to the shopkeeper
  if ($cartId > 0) {
    $q = $conn->prepare("SELECT id, product_id, quantity FROM cart WHERE id = :id AND shopkeeper_id = :sid");
    $q->execute([':id' => $cartId, ':sid' => $shopkeeperId]);
  } else { // by product_id
    $q = $conn->prepare("SELECT id, product_id, quantity FROM cart WHERE product_id = :pid AND shopkeeper_id = :sid");
    $q->execute([':pid' => $productId, ':sid' => $shopkeeperId]);
  }

  $row = $q->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    fail('cart_item_not_found', 404);
  }

  $targetId   = (int)$row['id'];
  $currentQty = (int)$row['quantity'];

  // Decide new action
  if ($hasQuantity) {
    // Set to specific quantity (0 => delete)
    if ($quantity !== null && $quantity > 0) {
      $u = $conn->prepare("UPDATE cart SET quantity = :q WHERE id = :id AND shopkeeper_id = :sid");
      $u->execute([':q' => $quantity, ':id' => $targetId, ':sid' => $shopkeeperId]);
      $action = 'updated';
      $newQty = $quantity;
    } else {
      $d = $conn->prepare("DELETE FROM cart WHERE id = :id AND shopkeeper_id = :sid");
      $d->execute([':id' => $targetId, ':sid' => $shopkeeperId]);
      $action = 'deleted';
      $newQty = 0;
    }
  } elseif ($decrement) {
    // Decrement by 1; if becomes 0, delete
    $newQty = max(0, $currentQty - 1);
    if ($newQty > 0) {
      $u = $conn->prepare("UPDATE cart SET quantity = :q WHERE id = :id AND shopkeeper_id = :sid");
      $u->execute([':q' => $newQty, ':id' => $targetId, ':sid' => $shopkeeperId]);
      $action = 'decremented';
    } else {
      $d = $conn->prepare("DELETE FROM cart WHERE id = :id AND shopkeeper_id = :sid");
      $d->execute([':id' => $targetId, ':sid' => $shopkeeperId]);
      $action = 'deleted';
    }
  } else {
    // Default: delete the row entirely
    $d = $conn->prepare("DELETE FROM cart WHERE id = :id AND shopkeeper_id = :sid");
    $d->execute([':id' => $targetId, ':sid' => $shopkeeperId]);
    $action = 'deleted';
    $newQty = 0;
  }

  // Recompute quick summary (items + subtotal)
  $sumSql = "
    SELECT
      COUNT(*) AS lines,
      COALESCE(SUM(c.quantity * i.price), 0) AS subtotal
    FROM cart c
    JOIN inventory i ON i.id = c.product_id
    WHERE c.shopkeeper_id = :sid
  ";
  $sumStmt = $conn->prepare($sumSql);
  $sumStmt->execute([':sid' => $shopkeeperId]);
  $sum = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['lines' => 0, 'subtotal' => 0];

  echo json_encode([
    'success'  => true,
    'action'   => $action,
    'cart_id'  => $targetId,
    'new_qty'  => $newQty,
    'summary'  => [
      'lines'    => (int)$sum['lines'],
      'subtotal' => round((float)$sum['subtotal'], 2),
    ],
  ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  error_log('remove_from_cart.php error: ' . $e->getMessage());
  fail('server_error', 500);
}
