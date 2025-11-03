<?php
declare(strict_types=1);

// file: place_order.php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/db_connection.php'; // provides $conn (PDO)

/*
POST (JSON or form-encoded) — all optional:
{
  "shopkeeper_id": 123,      // if not using session
  "items": [                 // optional: to order a subset of cart (else, all items in cart)
    { "product_id": 5, "quantity": 2 },
    { "product_id": 9 }      // quantity omitted -> uses quantity from cart
  ],
  "note": "Deliver ASAP"     // ignored by schema unless you add a note column to orders
}

Response:
{
  "success": true,
  "orders": [
    {
      "order_id": 42,
      "distributor_id": 3,
      "total_amount": 2150.00,
      "items_count": 4,
      "created_at": "2025-11-01 12:34:56"
    }
  ],
  "skipped": [
    { "product_id": 7, "reason": "out_of_stock", "requested": 3, "available": 1 }
  ]
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

    // Parse payload (accept JSON or x-www-form-urlencoded)
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

    // Optional subset of items
    $requestedItems = [];
    if (!empty($payload['items']) && is_array($payload['items'])) {
        foreach ($payload['items'] as $it) {
            $pid = isset($it['product_id']) ? (int)$it['product_id'] : 0;
            $qty = isset($it['quantity']) ? (int)$it['quantity'] : null; // null -> use cart qty
            if ($pid > 0) $requestedItems[$pid] = ($qty !== null && $qty > 0) ? $qty : null;
        }
    }

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1) Load cart for this shopkeeper (optionally filter by requested product_ids)
    $params = [':sid' => $shopkeeperId];
    $filterSql = '';
    if (!empty($requestedItems)) {
        $in = implode(',', array_fill(0, count($requestedItems), '?'));
        $filterSql = " AND c.product_id IN ($in)";
    }

    $sqlCart = "
        SELECT c.id AS cart_id, c.product_id, c.quantity AS cart_qty
        FROM cart c
        WHERE c.shopkeeper_id = :sid
        $filterSql
        ORDER BY c.id
    ";
    $stmt = $conn->prepare($sqlCart);
    $bindIndex = 1;
    $stmt->bindValue(':sid', $shopkeeperId, PDO::PARAM_INT);
    if (!empty($requestedItems)) {
        foreach (array_keys($requestedItems) as $pid) {
            $stmt->bindValue($bindIndex, $pid, PDO::PARAM_INT);
            $bindIndex++;
        }
    }
    $stmt->execute();
    $cartRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$cartRows) {
        fail('cart_empty', 400);
    }

    // 2) Join with inventory to know distributor, stock, price; build worklist
    // We'll collect product_ids from cart first
    $productIds = array_map(fn($r) => (int)$r['product_id'], $cartRows);
    $in = implode(',', array_fill(0, count($productIds), '?'));
    $sqlInv = "
        SELECT i.id AS product_id, i.distributor_id, i.price, i.quantity AS stock
        FROM inventory i
        WHERE i.id IN ($in)
        FOR UPDATE
    ";
    // FOR UPDATE: we will place all inventory rows in a single encompassing transaction shortly.
    // To ensure the locks are taken inside a transaction, we’ll begin a transaction first.

    // But first, organize desired cart quantities (possibly overridden by 'items')
    $desired = []; // product_id => desired_qty
    foreach ($cartRows as $r) {
        $pid = (int)$r['product_id'];
        $cartQty = (int)$r['cart_qty'];
        if ($cartQty < 1) { $cartQty = 1; }
        if (array_key_exists($pid, $requestedItems)) {
            $override = $requestedItems[$pid];
            $desired[$pid] = ($override !== null && $override > 0) ? $override : $cartQty;
        } else {
            $desired[$pid] = $cartQty;
        }
    }

    // Begin a large transaction to lock inventory and ensure atomic split-orders creation
    $conn->beginTransaction();

    $invStmt = $conn->prepare($sqlInv);
    $bind = 1;
    foreach ($productIds as $pid) {
        $invStmt->bindValue($bind, $pid, PDO::PARAM_INT);
        $bind++;
    }
    $invStmt->execute();
    $invRows = $invStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$invRows) {
        $conn->rollBack();
        fail('inventory_not_found_for_cart_items');
    }

    // Build map: product_id -> { distributor_id, price, stock }
    $invMap = [];
    foreach ($invRows as $r) {
        $invMap[(int)$r['product_id']] = [
            'distributor_id' => (int)$r['distributor_id'],
            'price'          => (float)$r['price'],
            'stock'          => (int)$r['stock'],
        ];
    }

    // 3) Split by distributor, check stock, collect successes and skips
    $byDistributor = []; // distributor_id => [ [product_id, qty, price] ... ]
    $skipped = [];       // list of insufficient stock etc.

    foreach ($desired as $pid => $qty) {
        if (!isset($invMap[$pid])) {
            $skipped[] = ['product_id' => (int)$pid, 'reason' => 'not_found'];
            continue;
        }
        $meta = $invMap[$pid];
        $available = $meta['stock'];
        if ($available < 1) {
            $skipped[] = ['product_id' => (int)$pid, 'reason' => 'out_of_stock', 'requested' => (int)$qty, 'available' => (int)$available];
            continue;
        }
        $finalQty = min((int)$qty, (int)$available);
        if ($finalQty < (int)$qty) {
            // Not enough stock for full quantity; you can choose to partially fulfill or skip entirely.
            // Here we partially fulfill and report the shortfall.
            $skipped[] = ['product_id' => (int)$pid, 'reason' => 'partial_fill', 'requested' => (int)$qty, 'available' => (int)$available, 'fulfilled' => $finalQty];
        }
        $did = $meta['distributor_id'];
        $price = $meta['price']; // use current inventory price
        $byDistributor[$did][] = [
            'product_id' => (int)$pid,
            'quantity'   => (int)$finalQty,
            'price'      => (float)$price,
        ];
        // Reserve stock now by decrementing inventory to prevent double-sell within this transaction
        $dec = $conn->prepare("UPDATE inventory SET quantity = quantity - :q WHERE id = :pid AND quantity >= :q");
        $dec->execute([':q' => $finalQty, ':pid' => $pid]);
        if ($dec->rowCount() !== 1) {
            // Someone else might have taken stock; mark as skipped
            $skipped[] = ['product_id' => (int)$pid, 'reason' => 'race_condition', 'requested' => (int)$qty, 'available' => 0];
            // undo any partial reserved? We’re in single transaction; easiest is to fail entire op,
            // but we’ll continue and not include this item. Put back reservation we tried for this pid:
            $undo = $conn->prepare("UPDATE inventory SET quantity = quantity + :q WHERE id = :pid");
            $undo->execute([':q' => $finalQty, ':pid' => $pid]);
            // and do not add to byDistributor
            array_pop($byDistributor[$did]);
            if (empty($byDistributor[$did])) unset($byDistributor[$did]);
        }
    }

    if (empty($byDistributor)) {
        // Nothing to place (all skipped or no stock)
        $conn->rollBack();
        echo json_encode([
            'success' => false,
            'error'   => 'no_items_to_order',
            'skipped' => $skipped
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // 4) Create orders per distributor with items
    $createdOrders = [];

    $insertOrder = $conn->prepare("
        INSERT INTO orders (shopkeeper_id, distributor_id, total_amount, status, created_at)
        VALUES (:sid, :did, :total, :status, NOW())
    ");

    $insertItem = $conn->prepare("
        INSERT INTO order_items (order_id, product_id, quantity, price)
        VALUES (:oid, :pid, :qty, :price)
    ");

    foreach ($byDistributor as $did => $items) {
        if (empty($items)) continue;

        // Compute total
        $total = 0.0;
        foreach ($items as $it) {
            $total += ((float)$it['price']) * ((int)$it['quantity']);
        }

        // Create order
        $insertOrder->execute([
            ':sid'    => $shopkeeperId,
            ':did'    => (int)$did,
            ':total'  => round($total, 2),
            ':status' => 'Pending',
        ]);
        $orderId = (int)$conn->lastInsertId();

        // Insert items
        $count = 0;
        foreach ($items as $it) {
            if ($it['quantity'] < 1) continue;
            $insertItem->execute([
                ':oid'   => $orderId,
                ':pid'   => (int)$it['product_id'],
                ':qty'   => (int)$it['quantity'],
                ':price' => (float)$it['price'],
            ]);
            $count++;
        }

        // Record created order for response
        $createdOrders[] = [
            'order_id'       => $orderId,
            'distributor_id' => (int)$did,
            'total_amount'   => round($total, 2),
            'items_count'    => $count,
            'created_at'     => date('Y-m-d H:i:s'),
        ];
    }

    // 5) Clear purchased items from cart (only those successfully ordered)
    // Collect product_ids that were actually inserted
    $orderedProductIds = [];
    foreach ($byDistributor as $items) {
        foreach ($items as $it) {
            if (($it['quantity'] ?? 0) > 0) $orderedProductIds[] = (int)$it['product_id'];
        }
    }
    $orderedProductIds = array_values(array_unique($orderedProductIds));
    if ($orderedProductIds) {
        $in = implode(',', array_fill(0, count($orderedProductIds), '?'));
        $del = $conn->prepare("DELETE FROM cart WHERE shopkeeper_id = ? AND product_id IN ($in)");
        // bind shopkeeper_id + product_ids
        $bind = 1;
        $del->bindValue($bind++, $shopkeeperId, PDO::PARAM_INT);
        foreach ($orderedProductIds as $pid) {
            $del->bindValue($bind++, $pid, PDO::PARAM_INT);
        }
        $del->execute();
    }

    // Commit the whole thing
    $conn->commit();

    echo json_encode([
        'success' => true,
        'orders'  => $createdOrders,
        'skipped' => $skipped,
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    // If anything fails, rollback safely
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('place_order.php error: ' . $e->getMessage());
    fail('server_error', 500);
}
