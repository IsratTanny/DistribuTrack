<?php
declare(strict_types=1);

// file: fetch_cart.php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/db_connection.php'; // exposes $conn (PDO)

/**
 * Request:
 *  - Prefer session: $_SESSION['shopkeeper_id']
 *  - Or JSON POST: { "shopkeeper_id": number }
 *
 * Response:
 * {
 *   "success": true,
 *   "data": [
 *     {
 *       "cart_id": 12,
 *       "product_id": 5,
 *       "product_name": "Rice 5kg",
 *       "description": "Premium...",
 *       "price": 550.00,
 *       "quantity": 2,
 *       "line_total": 1100.00,
 *       "stock": 18,
 *       "image_path": "uploads/abc.jpg",
 *       "distributor_id": 3,
 *       "distributor": "Acme Distributors"
 *     },
 *     ...
 *   ],
 *   "summary": {
 *     "items": 3,
 *     "subtotal": 2430.00
 *   }
 * }
 */

function fail(string $msg, int $code = 400, array $extra = []): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg, 'data' => $extra], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // Accept only POST or GET (GET allowed for easy debugging; remove if undesired)
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'GET'], true)) {
        fail('method_not_allowed', 405);
    }

    // Parse JSON body for POST
    $payload = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            try {
                $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                // ignore; we also allow form-encoded or session
                $payload = [];
            }
        } else {
            // also allow application/x-www-form-urlencoded posts
            $payload = $_POST;
        }
    } else {
        // GET: allow query param for quick checks, e.g. ?shopkeeper_id=1
        $payload = $_GET;
    }

    // Use session shopkeeper if present; else accept explicit id
    $shopkeeperId = isset($_SESSION['shopkeeper_id']) ? (int)$_SESSION['shopkeeper_id'] : (int)($payload['shopkeeper_id'] ?? 0);
    if ($shopkeeperId <= 0) {
        fail('missing_or_invalid_shopkeeper_id', 401);
    }

    // Query cart joined with inventory & distributor
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "
        SELECT
            c.id            AS cart_id,
            c.product_id    AS product_id,
            c.quantity      AS quantity,
            i.product_name  AS product_name,
            i.description   AS description,
            i.price         AS price,
            i.quantity      AS stock,
            i.image_path    AS image_path,
            i.distributor_id AS distributor_id,
            d.company_name  AS distributor_name
        FROM cart c
        JOIN inventory i      ON i.id = c.product_id
        LEFT JOIN distributor d ON d.id = i.distributor_id
        WHERE c.shopkeeper_id = :sid
        ORDER BY c.id DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':sid' => $shopkeeperId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    $subtotal = 0.0;

    foreach ($rows as $r) {
        $price   = (float)($r['price'] ?? 0);
        $qty     = (int)($r['quantity'] ?? 0);
        $line    = $price * $qty;
        $subtotal += $line;

        $items[] = [
            'cart_id'        => (int)$r['cart_id'],
            'product_id'     => (int)$r['product_id'],
            'product_name'   => (string)($r['product_name'] ?? 'Unnamed'),
            'description'    => (string)($r['description'] ?? ''),
            'price'          => round($price, 2),
            'quantity'       => $qty,
            'line_total'     => round($line, 2),
            'stock'          => (int)($r['stock'] ?? 0),
            'image_path'     => $r['image_path'] ?? null,
            'distributor_id' => isset($r['distributor_id']) ? (int)$r['distributor_id'] : null,
            'distributor'    => $r['distributor_name'] ?? ($r['distributor_id'] ? ('Distributor #' . (int)$r['distributor_id']) : '—'),
        ];
    }

    echo json_encode([
        'success' => true,
        'data'    => $items,
        'summary' => [
            'items'    => count($items),
            'subtotal' => round($subtotal, 2),
        ],
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('fetch_cart.php error: ' . $e->getMessage());
    fail('server_error', 500);
}
