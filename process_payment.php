<?php
declare(strict_types=1);

// file: process_payment.php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/db_connection.php'; // uses $conn (PDO)

/*
POST JSON example:
{
  "order_id": 101,                    // single order
  "amount": 2500.00,                  // required
  "method": "manual",                 // optional (e.g., card, bkash, sslcommerz)
  "reference": "TXN-2025-001",        // optional
}

OR to batch multiple orders:

{
  "orders": [
    { "order_id": 101, "amount": 2000.00 },
    { "order_id": 102, "amount": 1500.00 }
  ],
  "method": "bkash",
  "reference": "TXN-GROUP-11"
}

Response:
{
  "success": true,
  "payments": [
    { "order_id": 101, "payment_id": 7, "amount": 2000, "status": "Success" }
  ],
  "failed": []
}
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

  // Parse JSON body
  $raw = file_get_contents('php://input');
  $payload = json_decode($raw, true);
  if (!is_array($payload)) {
    fail('invalid_json');
  }

  // Identify user context (for security/audit)
  $shopkeeperId  = $_SESSION['shopkeeper_id'] ?? null;
  $distributorId = $_SESSION['distributor_id'] ?? null;

  $method     = $payload['method'] ?? 'manual';
  $reference  = $payload['reference'] ?? null;
  $paymentsIn = [];

  // Accept single order or batch
  if (!empty($payload['order_id'])) {
    $paymentsIn[] = [
      'order_id' => (int)$payload['order_id'],
      'amount'   => (float)($payload['amount'] ?? 0)
    ];
  } elseif (!empty($payload['orders']) && is_array($payload['orders'])) {
    foreach ($payload['orders'] as $o) {
      $oid = (int)($o['order_id'] ?? 0);
      $amt = (float)($o['amount'] ?? 0);
      if ($oid > 0 && $amt > 0) {
        $paymentsIn[] = ['order_id' => $oid, 'amount' => $amt];
      }
    }
  } else {
    fail('missing_order');
  }

  if (empty($paymentsIn)) {
    fail('no_valid_orders');
  }

  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $conn->beginTransaction();

  // Ensure payments table exists (idempotent)
  $conn->exec("
    CREATE TABLE IF NOT EXISTS payments (
      id INT AUTO_INCREMENT PRIMARY KEY,
      order_id INT NOT NULL,
      amount DECIMAL(10,2) NOT NULL,
      method VARCHAR(50) DEFAULT 'manual',
      reference VARCHAR(100) DEFAULT NULL,
      status ENUM('Pending','Success','Failed') DEFAULT 'Success',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (order_id) REFERENCES orders(id)
    )
  ");

  // Prepare reusable statements
  $findOrder = $conn->prepare("SELECT id, shopkeeper_id, distributor_id, total_amount, status FROM orders WHERE id = :oid FOR UPDATE");
  $insertPay = $conn->prepare("
    INSERT INTO payments (order_id, amount, method, reference, status)
    VALUES (:oid, :amount, :method, :reference, 'Success')
  ");
  $markOrder = $conn->prepare("
    UPDATE orders SET status = 'Paid' WHERE id = :oid
  ");

  $processed = [];
  $failed = [];

  foreach ($paymentsIn as $p) {
    $oid = (int)$p['order_id'];
    $amt = (float)$p['amount'];
    if ($oid <= 0 || $amt <= 0) {
      $failed[] = ['order_id' => $oid, 'reason' => 'invalid_input'];
      continue;
    }

    // Verify order exists and belongs to this user context if available
    $findOrder->execute([':oid' => $oid]);
    $order = $findOrder->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
      $failed[] = ['order_id' => $oid, 'reason' => 'not_found'];
      continue;
    }

    // Optional access check: if shopkeeper, must match; if distributor, must own
    if ($shopkeeperId && (int)$order['shopkeeper_id'] !== (int)$shopkeeperId) {
      $failed[] = ['order_id' => $oid, 'reason' => 'unauthorized_shopkeeper'];
      continue;
    }
    if ($distributorId && (int)$order['distributor_id'] !== (int)$distributorId) {
      $failed[] = ['order_id' => $oid, 'reason' => 'unauthorized_distributor'];
      continue;
    }

    // Payment validation
    $due = (float)$order['total_amount'];
    if ($amt < $due) {
      // allow partial? (comment out next 2 lines to allow partial)
      $failed[] = ['order_id' => $oid, 'reason' => 'insufficient_amount', 'due' => $due, 'paid' => $amt];
      continue;
    }

    // Record payment
    $insertPay->execute([
      ':oid'       => $oid,
      ':amount'    => round($amt, 2),
      ':method'    => $method,
      ':reference' => $reference
    ]);
    $pid = (int)$conn->lastInsertId();

    // Mark order paid
    $markOrder->execute([':oid' => $oid]);

    $processed[] = [
      'order_id'   => $oid,
      'payment_id' => $pid,
      'amount'     => round($amt, 2),
      'status'     => 'Success'
    ];
  }

  $conn->commit();

  echo json_encode([
    'success' => true,
    'payments' => $processed,
    'failed'   => $failed
  ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  if ($conn && $conn->inTransaction()) {
    $conn->rollBack();
  }
  error_log('process_payment.php error: ' . $e->getMessage());
  fail('server_error', 500);
}
