<?php
declare(strict_types=1);

// file: remove_product.php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/db_connection.php'; // provides $conn (PDO)

function fail(string $msg, int $code = 400, array $extra = []): never {
  http_response_code($code);
  echo json_encode(['success' => false, 'error' => $msg, 'data' => $extra], JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  if (!isset($_SESSION['distributor_id'])) {
    fail('unauthorized', 401);
  }
  $distributorId = (int)$_SESSION['distributor_id'];

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

  $productId = isset($payload['id']) ? (int)$payload['id'] : 0;
  $hard = isset($payload['hard'])
    ? filter_var($payload['hard'], FILTER_VALIDATE_BOOL)
    : (isset($_GET['hard']) ? (bool)$_GET['hard'] : false);

  if ($productId <= 0) {
    fail('missing_or_invalid_product_id');
  }

  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Ensure the product exists and belongs to this distributor
  $find = $conn->prepare("
    SELECT id, distributor_id, image_path, COALESCE(is_active, 1) AS is_active
    FROM inventory
    WHERE id = :id AND distributor_id = :did
    LIMIT 1
  ");
  $find->execute([':id' => $productId, ':did' => $distributorId]);
  $product = $find->fetch(PDO::FETCH_ASSOC);

  if (!$product) {
    fail('not_found_or_not_owned', 404);
  }

  // If not hard delete, soft-delete by default: set is_active = 0
  if (!$hard) {
    $upd = $conn->prepare("UPDATE inventory SET is_active = 0 WHERE id = :id AND distributor_id = :did");
    $upd->execute([':id' => $productId, ':did' => $distributorId]);
    echo json_encode([
      'success' => true,
      'action'  => 'soft_deleted',
      'message' => 'Product archived (soft-deleted).',
      'product' => ['id' => (int)$productId]
    ], JSON_UNESCAPED_SLASHES);
    exit;
  }

  // Hard delete path: make sure there are no dependent rows
  $conn->beginTransaction();

  // Check references in order_items / cart to avoid FK failures
  $refCounts = [
    'order_items' => 0,
    'cart'        => 0
  ];

  $chk1 = $conn->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = :pid");
  $chk1->execute([':pid' => $productId]);
  $refCounts['order_items'] = (int)$chk1->fetchColumn();

  $chk2 = $conn->prepare("SELECT COUNT(*) FROM cart WHERE product_id = :pid");
  $chk2->execute([':pid' => $productId]);
  $refCounts['cart'] = (int)$chk2->fetchColumn();

  if ($refCounts['order_items'] > 0) {
    $conn->rollBack();
    echo json_encode([
      'success' => false,
      'error'   => 'has_references',
      'message' => 'Product has existing order items; soft-delete instead.',
      'refs'    => $refCounts
    ], JSON_UNESCAPED_SLASHES);
    exit;
  }

  // Clean up any cart rows for this product (safe to remove)
  if ($refCounts['cart'] > 0) {
    $delCart = $conn->prepare("DELETE FROM cart WHERE product_id = :pid");
    $delCart->execute([':pid' => $productId]);
  }

  // Attempt deletion
  $del = $conn->prepare("DELETE FROM inventory WHERE id = :id AND distributor_id = :did");
  $del->execute([':id' => $productId, ':did' => $distributorId]);

  if ($del->rowCount() !== 1) {
    $conn->rollBack();
    fail('delete_failed', 409);
  }

  // Remove image file if present (best-effort; ignore errors)
  $imgRemoved = false;
  if (!empty($product['image_path'])) {
    $imgPath = $product['image_path'];
    // Prevent directory traversal: only allow paths under ./uploads/
    $real = realpath(__DIR__ . '/' . ltrim($imgPath, '/'));
    $uploads = realpath(__DIR__ . '/uploads');
    if ($real && $uploads && str_starts_with($real, $uploads) && is_file($real)) {
      $imgRemoved = @unlink($real);
    }
  }

  $conn->commit();

  echo json_encode([
    'success'     => true,
    'action'      => 'hard_deleted',
    'message'     => 'Product permanently deleted.',
    'product'     => ['id' => (int)$productId],
    'image_removed' => $imgRemoved
  ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  if (isset($conn) && $conn->inTransaction()) {
    $conn->rollBack();
  }
  error_log('remove_product.php error: ' . $e->getMessage());
  fail('server_error', 500);
}
