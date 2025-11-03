<?php
declare(strict_types=1);
session_start();

/**
 * add_inventory.php
 * Creates a new inventory item for the logged-in distributor.
 * - Accepts multipart/form-data from inventory-management.html
 * - On normal form post: redirects with success/error query string
 * - On AJAX/JSON request: returns JSON {success, message, data?}
 */

require __DIR__ . '/db_connection.php'; // must define $conn as a PDO instance

// Helper: unified response for HTML vs AJAX callers
function respond(bool $ok, string $message, array $data = []): void {
    $isAjax = (
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    );

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $ok, 'message' => $message, 'data' => $data], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Fallback: redirect for normal form submissions
    $qs = http_build_query(['success' => $ok ? 1 : 0, 'msg' => $message]);
    header("Location: inventory-management.html?{$qs}");
    exit;
}

// Ensure distributor is logged in
if (!isset($_SESSION['distributor_id'])) {
    respond(false, 'Unauthorized access. Please log in as a distributor.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

// ---- Input collection & validation ----
$distributor_id = (int) $_SESSION['distributor_id'];

$product_name = trim($_POST['product_name'] ?? '');
$quantity_raw = $_POST['quantity'] ?? null;
$price_raw    = $_POST['price'] ?? null;
$description  = trim($_POST['description'] ?? '');

if ($product_name === '') {
    respond(false, 'Product name is required.');
}

if (!is_numeric($quantity_raw) || (int)$quantity_raw < 0) {
    respond(false, 'Quantity must be a non-negative integer.');
}
$quantity = (int) $quantity_raw;

if (!is_numeric($price_raw) || (float)$price_raw < 0) {
    respond(false, 'Price must be a non-negative number.');
}
$price = number_format((float)$price_raw, 2, '.', ''); // keep as string "0.00"

// ---- Image upload (optional but recommended by your UI) ----
$image_path = null;

// Only proceed if a file was submitted and uploaded to tmp
if (isset($_FILES['product_image']) && is_array($_FILES['product_image']) && $_FILES['product_image']['error'] !== UPLOAD_ERR_NO_FILE) {

    $err = $_FILES['product_image']['error'];
    if ($err !== UPLOAD_ERR_OK) {
        $phpFileErrors = [
            UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive.',
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
            UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'File upload stopped by extension.',
        ];
        $msg = $phpFileErrors[$err] ?? 'Unknown upload error.';
        respond(false, "Image upload failed: {$msg}");
    }

    // Validate mime type & extension
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['product_image']['tmp_name']) ?: 'application/octet-stream';

    if (!isset($allowed[$mime])) {
        respond(false, 'Invalid image type. Allowed: JPG, PNG, WEBP.');
    }

    // Limit size (e.g., 4 MB)
    $maxBytes = 4 * 1024 * 1024;
    if (($_FILES['product_image']['size'] ?? 0) > $maxBytes) {
        respond(false, 'Image is too large. Max 4 MB.');
    }

    // Ensure uploads directory exists
    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            respond(false, 'Failed to create uploads directory.');
        }
    }

    // Generate a safe unique filename
    $ext      = $allowed[$mime];
    $basename = bin2hex(random_bytes(8)) . '_' . time();
    $filename = $basename . '.' . $ext;

    $destAbs = $uploadDir . '/' . $filename;
    $destRel = 'uploads/' . $filename; // this is what we store in DB

    if (!move_uploaded_file($_FILES['product_image']['tmp_name'], $destAbs)) {
        respond(false, 'Failed to save uploaded image.');
    }

    $image_path = $destRel;
}

// ---- DB insert ----
try {
    // Ensure PDO throws exceptions
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "INSERT INTO inventory 
            (distributor_id, product_name, description, price, quantity, image_path, is_active, created_at) 
            VALUES 
            (:distributor_id, :product_name, :description, :price, :quantity, :image_path, 1, NOW())";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':distributor_id' => $distributor_id,
        ':product_name'   => $product_name,
        ':description'    => $description,
        ':price'          => $price,     // decimal(10,2) as string ok
        ':quantity'       => $quantity,
        ':image_path'     => $image_path,
    ]);

    $newId = (int) $conn->lastInsertId();

    respond(true, 'Product added successfully.', [
        'id'            => $newId,
        'product_name'  => $product_name,
        'quantity'      => $quantity,
        'price'         => $price,
        'image_path'    => $image_path,
    ]);
} catch (Throwable $e) {
    // Optional: unlink image if DB insert failed
    if (!empty($destAbs) && file_exists($destAbs)) {
        @unlink($destAbs);
    }
    // Log server-side, return generic message to client
    error_log('add_inventory.php error: ' . $e->getMessage());
    respond(false, 'An error occurred while adding the product.');
}
