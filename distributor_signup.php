<?php
declare(strict_types=1);

require __DIR__ . '/db_connection.php';

// Harden session cookie (must be set before session_start)
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
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
 * Respond helper
 * - If AJAX: JSON { success, message, data? }
 * - If form: redirect with ?error=... or go to dashboard on success
 */
function respond(bool $ok, string $message, array $data = []): void {
    $isAjax = (
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    );

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $ok, 'message' => $message, 'data' => $data], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($ok) {
        header('Location: distributor-dashboard.html');
    } else {
        $qs = http_build_query(['error' => $message]);
        header("Location: distributor-signup.html?{$qs}");
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'method_not_allowed');
}

// ---------- Collect & sanitize ----------
$firstname        = trim((string)($_POST['firstname']        ?? ''));
$lastname         = trim((string)($_POST['lastname']         ?? ''));
$emailRaw         = trim((string)($_POST['email']            ?? ''));
$password         = (string)($_POST['password']              ?? '');
$confirm_password = (string)($_POST['confirm_password']      ?? '');
$company_name     = trim((string)($_POST['company_name']     ?? ''));
$business_license = trim((string)($_POST['business_license'] ?? ''));
$nid              = trim((string)($_POST['nid']              ?? ''));
$phone            = trim((string)($_POST['phone']            ?? ''));
$address          = trim((string)($_POST['address']          ?? ''));

// Normalize email (case-insensitive)
$email = strtolower($emailRaw);

// ---------- Validate ----------
if ($firstname === '' || $lastname === '' || $email === '' || $password === '' || $confirm_password === '' ||
    $company_name === '' || $business_license === '' || $nid === '' || $phone === '' || $address === '') {
    respond(false, 'missing_fields');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'invalid_email');
}

if ($password !== $confirm_password) {
    respond(false, 'password_mismatch');
}

// Basic password strength (tune as needed)
if (strlen($password) < 8) {
    respond(false, 'weak_password'); // at least 8 chars
}

// Length guards to respect DB column sizes
if (strlen($firstname) > 100 || strlen($lastname) > 100)      respond(false, 'name_too_long');
if (strlen($company_name) > 100)                               respond(false, 'company_name_too_long');
if (strlen($business_license) > 100)                           respond(false, 'business_license_too_long');
if (strlen($nid) > 30)                                         respond(false, 'nid_too_long');
if (strlen($phone) > 20)                                       respond(false, 'phone_too_long');
// address is TEXT, generally fine; you can still cap if desired.

// Optional format checks (non-fatal; adjust patterns to your needs)
if (!preg_match('/^[0-9\-\+\s\(\)]{6,20}$/', $phone)) {
    respond(false, 'invalid_phone');
}
if (!preg_match('/^[A-Za-z0-9\-\/]{3,}$/', $business_license)) {
    respond(false, 'invalid_business_license');
}
// NID format varies; at least numeric-ish:
if (!preg_match('/^[0-9A-Za-z\-]{5,}$/', $nid)) {
    respond(false, 'invalid_nid');
}

// ---------- Hash password ----------
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

// ---------- Insert ----------
try {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Pre-check duplicate email for clearer error
    $check = $conn->prepare('SELECT 1 FROM distributor WHERE email = :email LIMIT 1');
    $check->execute([':email' => $email]);
    if ($check->fetch()) {
        respond(false, 'duplicate'); // email already in use
    }

    $sql = "INSERT INTO distributor 
            (first_name, last_name, email, password, company_name, business_license, nid, phone, address, created_at)
            VALUES
            (:firstname, :lastname, :email, :password, :company_name, :business_license, :nid, :phone, :address, NOW())";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':firstname'        => $firstname,
        ':lastname'         => $lastname,
        ':email'            => $email,
        ':password'         => $hashed_password,
        ':company_name'     => $company_name,
        ':business_license' => $business_license,
        ':nid'              => $nid,
        ':phone'            => $phone,
        ':address'          => $address,
    ]);

    $newId = (int)$conn->lastInsertId();

    // Auto-login after signup
    session_regenerate_id(true);
    $_SESSION['distributor_id'] = $newId;
    $_SESSION['company_name']   = $company_name;

    respond(true, 'signup_ok', ['id' => $newId]);

} catch (PDOException $e) {
    // 23000 = integrity constraint violation (e.g., unique email)
    if ($e->getCode() === '23000') {
        respond(false, 'duplicate');
    }
    error_log('distributor_signup.php error: ' . $e->getMessage());
    respond(false, 'server_error');
} catch (Throwable $e) {
    error_log('distributor_signup.php fatal: ' . $e->getMessage());
    respond(false, 'server_error');
}
