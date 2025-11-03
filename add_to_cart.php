<?php
declare(strict_types=1);

// file: add_to_cart.php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/db_connection.php'; // provides $conn as PDO

/**
 * Expected JSON body:
 * {
 *   "shopkeeper_id": 1,   // optional if using session
 *   "product_id": 6,
 *   "quantity": 2
 * }
 *
 * Response JSON:
 * { "success": true, "message": "...", "data": { "cart_item_id": 123, "new_quantity": 3, "cart_total_qty": 7 } }
 */

function fail(string $msg, int $code = 400, array $extra = []): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg, 'data' => $extra], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // Parse JSON
    $raw = file_get_contents('php://input');
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];

    // Accept session shopkeeper if available
    $shopkeeperId = isset($_SESSION['shopkeeper_id']) ? (int)$_SESSION['shopkeeper_id'] : (int)($data['shopkeeper_id'] ?? 0);
    $productId    = (int)($data['product_id']  ?? 0);
    $qtyRequested = (int)($data['quantity']    ?? 0);

    if ($shopkeeperId <= 0) fail('Missing or invalid shopkeeper_id.');
    if ($productId   <= 0) fail('Missing or invalid product_id.');
    if ($qtyRequested < 1) fail('Quantity must be at least 1.');

    // Use transactions to ensure consistent stock checks
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->beginTransaction();

    // 1) Validate product exists, active, and get available stock (lock row to avoid race)
    // If your DB engine supports it (InnoDB), FOR UPDATE helps prevent oversell.
    $stmt = $conn->prepare("SELECT id, quantity, is_active FROM inventory WHERE id = :pid FOR UPDATE");
    $stmt->execute([':pid' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        $conn->rollBack();
        fail('Product not found.', 404);
    }
    if ((int)$product['is_active'] !== 1) {
        $conn->rollBack();
        fail('Product is not available.');
    }

    $stockAvailable = (int)$product['quantity'];

    // 2) Check if item already in cart
    $check = $conn->prepare("SELECT id, quantity FROM cart WHERE shopkeeper_id = :sid AND product_id = :pid");
    $check->execute([':sid' => $shopkeeperId, ':pid' => $productId]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    $newQty = $qtyRequested;
    if ($existing) {
        $newQty = (int)$existing['quantity'] + $qtyRequested;
    }

    // 3) Do not allow cart quantity to exceed available stock
    if ($newQty > $stockAvailable) {
        $conn->rollBack();
        fail('Insufficient stock available for this product.', 409, [
            'requested_total' => $newQty,
            'available' => $stockAvailable
        ]);
    }

    // 4) Upsert into cart
    if ($existing) {
        $upd = $conn->prepare("UPDATE cart SET quantity = :q WHERE id = :id");
        $upd->execute([':q' => $newQty, ':id' => (int)$existing['id']]);
        $cartItemId = (int)$existing['id'];
    } else {
        $ins = $conn->prepare("INSERT INTO cart (shopkeeper_id, product_id, quantity) VALUES (:sid, :pid, :q)");
        $ins->execute([':sid' => $shopkeeperId, ':pid' => $productId, ':q' => $qtyRequested]);
        $cartItemId = (int)$conn->lastInsertId();
    }

    // 5) (Optional) Return total quantity in cart for badge updates
    $sum = $conn->prepare("SELECT COALESCE(SUM(quantity),0) AS total_qty FROM cart WHERE shopkeeper_id = :sid");
    $sum->execute([':sid' => $shopkeeperId]);
    $cartTotalQty = (int)$sum->fetchColumn();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Item added to cart.',
        'data' => [
            'cart_item_id'  => $cartItemId,
            'new_quantity'  => $newQty,
            'cart_total_qty'=> $cartTotalQty
        ]
    ], JSON_UNESCAPED_SLASHES);

} catch (JsonException $je) {
    fail('Invalid JSON payload.');
} catch (Throwable $e) {
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('add_to_cart.php error: ' . $e->getMessage());
    fail('An unexpected error occurred.', 500);
}
