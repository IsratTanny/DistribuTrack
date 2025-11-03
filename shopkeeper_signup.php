<?php
declare(strict_types=1);

// file: shopkeeper_signup.php
require_once __DIR__ . '/db_connection.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $firstname    = trim($_POST['firstname'] ?? '');
    $lastname     = trim($_POST['lastname'] ?? '');
    $email        = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password     = $_POST['password'] ?? '';
    $confirmPass  = $_POST['confirm_password'] ?? '';
    $nid          = trim($_POST['nid'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $shop_name    = trim($_POST['shop_name'] ?? '');
    $shop_address = trim($_POST['shop_address'] ?? '');

    // Basic validation
    if (!$firstname || !$lastname || !$email || !$password || !$confirmPass || !$nid || !$phone || !$shop_name || !$shop_address) {
        header('Location: shopkeeper-signup.html?error=missing_fields');
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: shopkeeper-signup.html?error=invalid_email');
        exit;
    }

    if ($password !== $confirmPass) {
        header('Location: shopkeeper-signup.html?error=password_mismatch');
        exit;
    }

    // Optional: enforce a minimal password policy
    if (strlen($password) < 8) {
        header('Location: shopkeeper-signup.html?error=weak_password');
        exit;
    }

    // Hash the password securely
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    try {
        // Check for duplicate email or NID before insert
        $check = $conn->prepare("SELECT COUNT(*) FROM shopkeeper WHERE email = :email OR nid = :nid");
        $check->execute([':email' => $email, ':nid' => $nid]);
        if ((int)$check->fetchColumn() > 0) {
            header('Location: shopkeeper-signup.html?error=duplicate');
            exit;
        }

        // Insert new shopkeeper
        $sql = "
            INSERT INTO shopkeeper (
                first_name, last_name, email, password, nid, phone, shop_name, shop_address, created_at
            ) VALUES (
                :firstname, :lastname, :email, :password, :nid, :phone, :shop_name, :shop_address, NOW()
            )
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':firstname'    => $firstname,
            ':lastname'     => $lastname,
            ':email'        => $email,
            ':password'     => $hashed_password,
            ':nid'          => $nid,
            ':phone'        => $phone,
            ':shop_name'    => $shop_name,
            ':shop_address' => $shop_address,
        ]);

        // Create session for the new shopkeeper
        session_regenerate_id(true);
        $_SESSION['shopkeeper_id'] = (int)$conn->lastInsertId();
        $_SESSION['shop_name'] = $shop_name;

        header('Location: shopkeeper-dashboard.html');
        exit;

    } catch (PDOException $e) {
        error_log('Shopkeeper signup error: ' . $e->getMessage());

        if ($e->getCode() === '23000') {
            header('Location: shopkeeper-signup.html?error=duplicate');
        } else {
            header('Location: shopkeeper-signup.html?error=server_error');
        }
        exit;
    }
} else {
    header('Location: shopkeeper-signup.html');
    exit;
}
