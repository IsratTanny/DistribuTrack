<?php
declare(strict_types=1);

// file: update_cart.php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/db_connection.php'; // provides $conn (PDO)

function fail(string $msg, int $code = 400, array $extra = []): never {
  http_response_code($code);
  echo json_encode(['success' => false, 'error' => $msg, 'data' => $extra], JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('method_not_allowed', 405);

  // Accept JSON or x-www-form-urlencoded
  $payload = [];
  $raw = file_get_contents('php://input');
  if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $payload = $decoded;
  }
  if (empty($payload) && !empty($_POST)) $payload = $_POST;

  // Determine shopkeeper
  $shopkeeperId = isset($_SESSION['shopkeeper_id']) ? (int)$_SESSION['shopkeeper_id'] : 0;
  if (!$shopkeeperId && isset($payload['shopkeeper_id'])) {
    // Optional server-to-server override
    $shopkeeperId = (int)$payload['shopkeeper_id'];
  }
  if ($shopkeeperId <= 0) fail('unauthorized_or_missing_shopkeeper', 401);

  $cartId    = isset($payload['cart_id'])    ? (int)$payload['cart_id']    : 0;
  $productId = isset($payload['product_id']) ? (int)$payload['product_id'] : 0;

  if ($cartId <= 0 && $productId <= 0) fail('missing_cart_id_or_product_id');

  $hasQuantity = array_key_exists('quantity', $payload);
  $quantity    = $hasQuantity ? (int)$payload['quantity'] : null;
  $increment   = !empty($payload['increment']);
  $decrement   = !empty($payload['decrement']);

  // Fetch the cart line for this shopkeeper
  if ($cartId > 0) {
    $q = $conn->prepare("
      SELECT c.id, c.product_id, c.quantity, i.product_name, i.price, i.quantity AS stock, COALESCE(i.is_active,1) AS is_active
      FROM cart c
      JOIN inventory i ON i.id = c.product_id
      WHERE c.id = :id AND c.shopkeeper_id = :sid
      LIMIT 1
    ");
    $q->execute([':id' => $cartId, ':sid' => $shopkeeperId]);
  } else {
    $q = $conn->prepare("
      SELECT c.id, c.product_id, c.quantity, i.product_name, i.price, i.quantity AS stock, COALESCE(i.is_active,1) AS is_active
      FROM cart c
      JOIN inventory i ON i.id = c.product_id
      WHERE c.product_id = :pid AND c.shopkeeper_id = :sid
      LIMIT 1
    ");
    $q->execute([':pid' => $productId, ':sid' => $shopkeeperId]);
  }

  $row = $q->fetch(PDO::FETCH_ASSOC);
  if (!$row) fail('cart_item_not_found', 404);

  $targetId   = (int)$row['id'];
  $productId  = (int)$row['product_id'];
  $currentQty = (int)$row['quantity'];
  $stock      = max(0, (int)$row['stock']);
  $isActive   = (int)$row['is_active'] === 1;

  if (!$isActive) {
    // Product archived/unavailable -> remove line
    $del = $conn->prepare("DELETE FROM cart WHERE id = :id AND shopkeeper_id = :sid");
    $del->execute([':id' => $targetId, ':sid' => $shopkeeperId]);
    fail('product_unavailable_removed_from_cart', 409, ['cart_id' => $targetId]);
  }

  // Decide new quantity
  if ($hasQuantity) {
    $newQty = (int)$quantity;
  } elseif ($increment) {
    $newQty = $currentQty + 1;
  } elseif ($decrement) {
    $newQty = $currentQty - 1;
  } else {
    fail('no_update_operation_specified'); // nothing to do
  }

  // If new quantity <= 0, delete the line
  if ($newQty <= 0) {
    $d = $conn->prepare("DELETE FROM cart WHERE id = :id AND shopkeeper_id = :sid");
    $d->execute([':id' => $targetId, ':sid' => $shopkeeperId]);

    // Summary after deletion
    $sumStmt = $conn->prepare("
      SELECT COUNT(*) AS lines, COALESCE(SUM(c.quantity * i.price),0) AS subtotal
      FROM cart c
      JOIN inventory i ON i.id = c.product_id
      WHERE c.shopkeeper_id = :sid
    ");
    $sumStmt->execute([':sid' => $shopkeeperId]);
    $sum = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['lines' => 0, 'subtotal' => 0];

    echo json_encode([
      'success' => true,
      'action'  => 'deleted',
      'line'    => ['cart_id' => $targetId, 'product_id' => $productId, 'new_qty' => 0],
      'summary' => ['lines' => (int)$sum['lines'], 'subtotal' => round((float)$sum['subtotal'], 2)]
    ], JSON_UNESCAPED_SLASHES);
    exit;
  }

  // Validate stock
  if ($newQty > $stock) {
    fail('insufficient_stock', 409, [
      'requested' => $newQty,
      'available' => $stock,
      'product_id' => $productId,
      'product_name' => $row['product_name']
    ]);
  }

  // Perform update
  $u = $conn->prepare("UPDATE cart SET quantity = :q WHERE id = :id AND shopkeeper_id = :sid");
  $u->execute([':q' => $newQty, ':id' => $targetId, ':sid' => $shopkeeperId]);

  // Re-query updated line amount
  $lineStmt = $conn->prepare("
    SELECT c.id AS cart_id, c.quantity, i.id AS product_id, i.product_name, i.price, (c.quantity * i.price) AS line_total
    FROM cart c
    JOIN inventory i ON i.id = c.product_id
    WHERE c.id = :id AND c.shopkeeper_id = :sid
  ");
  $lineStmt->execute([':id' => $targetId, ':sid' => $shopkeeperId]);
  $line = $lineStmt->fetch(PDO::FETCH_ASSOC);

  // Summary
  $sumStmt = $conn->prepare("
    SELECT COUNT(*) AS lines, COALESCE(SUM(c.quantity * i.price),0) AS subtotal
    FROM cart c
    JOIN inventory i ON i.id = c.product_id
    WHERE c.shopkeeper_id = :sid
  ");
  $sumStmt->execute([':sid' => $shopkeeperId]);
  $sum = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['lines' => 0, 'subtotal' => 0];

  echo json_encode([
    'success' => true,
    'action'  => ($increment ? 'incremented' : ($decrement ? 'decremented' : 'updated')),
    'line'    => [
      'cart_id'      => (int)$line['cart_id'],
      'product_id'   => (int)$line['product_id'],
      'product_name' => $line['product_name'],
      'price'        => round((float)$line['price'], 2),
      'quantity'     => (int)$line['quantity'],
      'line_total'   => round((float)$line['line_total'], 2),
      'stock'        => $stock
    ],
    'summary' => [
      'lines'    => (int)$sum['lines'],
      'subtotal' => round((float)$sum['subtotal'], 2)
    ]
  ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  error_log('update_cart.php error: ' . $e->getMessage());
  fail('server_error', 500);
}
