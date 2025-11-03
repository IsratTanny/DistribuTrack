<?php
declare(strict_types=1);

require __DIR__ . '/db_connection.php';

// Secure session cookie (set before session_start)
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure   = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Respond helper: JSON if requested, else redirect to the given URL with query string.
 */
function respond(bool $ok, string $message, string $redirectOnForm = 'distributor.html', string $redirectOnSuccess = 'distributor-dashboard.html'): void
{
    $isAjax =
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $ok, 'message' => $message], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // For normal form posts, redirect with a compact status
    if ($ok) {
        header("Location: {$redirectOnSuccess}");
    } else {
        $qs = http_build_query(['error' => $message]);
        header("Location: {$redirectOnForm}?{$qs}");
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Method not allowed
    http_response_code(405);
    respond(false, 'method_not_allowed');
}

// Gather & validate inputs
$emailRaw = trim($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');

if ($emailRaw === '' || !filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'invalid_email');
}
if ($password === '') {
    respond(false, 'missing_password');
}

// Normalize email (case-insensitive match by convention)
$email = strtolower($emailRaw);

// Dummy hash to mitigate user enumeration timing attacks
// (bcrypt hash for the string 'dummy_password'; precomputed)
const DUMMY_BCRYPT_HASH = '$2y$10$CwTycUXWue0Thq9StjUM0uJh8b2UxZ7U6q3zO7F8YpG1fWfV1YH5i';

try {
    // Minimal selection for speed
    $stmt = $conn->prepare("SELECT id, password, company_name FROM distributor WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $hash = $row['password'] ?? DUMMY_BCRYPT_HASH;

    // Always run password_verify to keep timing consistent
    $verified = password_verify($password, $hash);

    if ($row && $verified) {
        // Success: set session & redirect/JSON
        session_regenerate_id(true);
        $_SESSION['distributor_id'] = (int)$row['id'];
        $_SESSION['company_name']   = (string)$row['company_name'];

        respond(true, 'login_ok', 'distributor.html', 'distributor-dashboard.html');
    } else {
        // Invalid credentials
        // (Optional small sleep to slow brute force without noticeable impact)
        usleep(150000); // 150ms
        respond(false, 'invalid_credentials');
    }
} catch (Throwable $e) {
    // Log the detailed error for the server, but don’t leak details to the client
    error_log('distributor_login.php error: ' . $e->getMessage());
    respond(false, 'server_error');
}
