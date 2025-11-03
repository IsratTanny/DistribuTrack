<?php
declare(strict_types=1);

// file: shopkeeper_login.php
require_once __DIR__ . '/db_connection.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        header('Location: shopkeeper.html?error=missing_fields');
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT id, shop_name, email, password FROM shopkeeper WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $shopkeeper = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($shopkeeper && password_verify($password, $shopkeeper['password'])) {
            // Regenerate session to prevent fixation
            session_regenerate_id(true);

            $_SESSION['shopkeeper_id'] = (int)$shopkeeper['id'];
            $_SESSION['shop_name'] = $shopkeeper['shop_name'] ?? 'Shopkeeper';

            header('Location: shopkeeper-dashboard.html');
            exit;
        } else {
            header('Location: shopkeeper.html?error=invalid');
            exit;
        }

    } catch (PDOException $e) {
        error_log('Shopkeeper login error: ' . $e->getMessage());
        header('Location: shopkeeper.html?error=server');
        exit;
    }
} else {
    header('Location: shopkeeper.html');
    exit;
}
