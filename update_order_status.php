<?php
declare(strict_types=1);

// file: update_order_status.php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/db_connection.php'; // provides $conn (PDO)

function fail(string $msg, int $code = 400, array $extra = []): never {
  http_response_code($code);
  echo json_encode(['success' => false, 'error' => $msg, 'data' => $extra], JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('method_not_allowed', 405);
  }

  // Accept JSON or form data
  $payload = [];
  $raw = file_get_contents('php://input');
  if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $payload = $decoded;
  }
  if (empty($payload) && !empty($_POST)) {
    $payload = $_POST;
  }

  $orderId = isset($payload['order_id']) ? (int)$payload['order_id'] : 0;
  $newStatus = isset($payload['status']) ? trim((string)$payload['status']) : '';
  $note = isset($payload['note']) ? trim((string)$payload['note']) : '';
  $restock = filter_var($payload['restock'] ?? false, FILTER_VALIDATE_BOOL);

  if ($orderId <= 0 || $newStatus === '') {
    fail('missing_order_id_or_status');
  }

  // Normalize status (Title Case)
  $newStatus = ucfirst(strtolower($newStatus));

  // Allowed global statuses
  $allowedStatuses = ['Pending','Processing','Paid','Shipped','Delivered','Cancelled'];
  if (!in_array($newStatus, $allowedStatuses, true)) {
    fail('invalid_status', 422, ['allowed' => $allowedStatuses]);
  }

  // Determine role
  $distributorId = isset($_SESSION['distributor_id']) ? (int)$_SESSION['distributor_id'] : 0;
  $shopkeeperId  = isset($_SESSION['shopkeeper_id'])  ? (int)$_SESSION['shopkeeper_id']  : 0;
  if ($distributorId <= 0 && $shopkeeperId <= 0) {
    fail('unauthorized', 401);
  }

  // Fetch order & ownership
  $stmt = $conn->prepare("
    SELECT o.id, o.shopkeeper_id, o.distributor_id, o.status, o.total_amount, o.created_at
    FROM orders o
    WHERE o.id = :id
    LIMIT 1
  ");
  $stmt->execute([':id' => $orderId]);
  $order = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$order) {
    fail('order_not_found', 404);
  }

  // Ownership checks
  if ($distributorId > 0 && (int)$order['distributor_id'] !== $distributorId) {
    fail('forbidden_not_your_order', 403);
  }
  if ($shopkeeperId > 0 && (int)$order['shopkeeper_id'] !== $shopkeeperId) {
    fail('forbidden_not_your_order', 403);
  }

  $current = $order['status'];

  // Disallow changes from terminal states
  if (in_array($current, ['Delivered','Cancelled'], true)) {
    fail('order_in_terminal_state', 409, ['current_status' => $current]);
  }

  // Transition rules
  $transitions = [
    'Pending'    => ['Processing','Paid','Cancelled'],
    'Processing' => ['Shipped','Paid','Cancelled'],
    'Paid'       => ['Shipped','Delivered','Cancelled'],
    'Shipped'    => ['Delivered','Cancelled'],
    'Delivered'  => [], // terminal
    'Cancelled'  => []  // terminal
  ];

  // Role-based restriction:
  // - Distributor: can apply any allowed transition above.
  // - Shopkeeper: can only set Delivered from Shipped or Paid.
  $allowedNext = $transitions[$current] ?? [];
  if ($distributorId > 0) {
    if (!in_array($newStatus, $allowedNext, true)) {
      fail('invalid_transition', 409, ['from' => $current, 'to' => $newStatus, 'allowed' => $allowedNext]);
    }
  } else {
    // shopkeeper
    $shopkeeperAllowed = ($newStatus === 'Delivered' && in_array($current, ['Shipped','Paid'], true));
    if (!$shopkeeperAllowed) {
      fail('forbidden_transition_for_role', 403, ['role' => 'shopkeeper', 'from' => $current, 'to' => $newStatus]);
    }
  }

  // Start transaction
  $conn->beginTransaction();

  // Optional restock on cancellation (only if not delivered/cancelled already)
  if ($newStatus === 'Cancelled' && $restock && !in_array($current, ['Delivered','Cancelled'], true)) {
    // Return inventory for each order item
    $items = $conn->prepare("
      SELECT oi.product_id, oi.quantity
      FROM order_items oi
      WHERE oi.order_id = :oid
    ");
    $items->execute([':oid' => $orderId]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
      $upd = $conn->prepare("UPDATE inventory SET quantity = quantity + :q WHERE id = :pid");
      foreach ($rows as $r) {
        $upd->execute([
          ':q'   => (int)$r['quantity'],
          ':pid' => (int)$r['product_id']
        ]);
      }
    }
  }

  // Update order status
  $updOrder = $conn->prepare("
    UPDATE orders
    SET status = :status, updated_at = NOW()
    WHERE id = :id
  ");
  $updOrder->execute([':status' => $newStatus, ':id' => $orderId]);

  // Optional: append note in a lightweight audit log table if you have one.
  // If you don’t, this block is harmlessly skipped.
  if ($note !== '') {
    try {
      $insNote = $conn->prepare("
        INSERT INTO order_notes (order_id, note, created_by_role, created_by_id, created_at)
        VALUES (:oid, :note, :role, :uid, NOW())
      ");
      $role = $distributorId ? 'distributor' : 'shopkeeper';
      $uid  = $distributorId ?: $shopkeeperId;
      $insNote->execute([
        ':oid'  => $orderId,
        ':note' => $note,
        ':role' => $role,
        ':uid'  => $uid
      ]);
    } catch (\Throwable $ignored) {
      // Table may not exist; ignore silently
    }
  }

  // Commit
  $conn->commit();

  // Return latest snapshot
  $snap = $conn->prepare("
    SELECT o.id, o.shopkeeper_id, o.distributor_id, o.status, o.total_amount, o.created_at, o.updated_at
    FROM orders o
    WHERE o.id = :id
  ");
  $snap->execute([':id' => $orderId]);
  $updated = $snap->fetch(PDO::FETCH_ASSOC);

  echo json_encode([
    'success' => true,
    'message' => 'status_updated',
    'order' => [
      'id'             => (int)$updated['id'],
      'status'         => $updated['status'],
      'total_amount'   => (float)$updated['total_amount'],
      'created_at'     => $updated['created_at'],
      'updated_at'     => $updated['updated_at'],
      'shopkeeper_id'  => (int)$updated['shopkeeper_id'],
      'distributor_id' => (int)$updated['distributor_id'],
    ]
  ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  if (isset($conn) && $conn->inTransaction()) {
    $conn->rollBack();
  }
  error_log('update_order_status.php error: ' . $e->getMessage());
  fail('server_error', 500);
}
