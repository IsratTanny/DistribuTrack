<?php
declare(strict_types=1);

/**
 * db_connection.php
 * Provides a single PDO connection as $conn (and via db()).
 * - Loads .env if present (simple key=value parser; ignores comments)
 * - Defaults DB_NAME to 'distributrack' (matches your SQL dump)
 * - Ensures utf8mb4 and safe PDO options
 */

if (!function_exists('db')) {
    function db(): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        // --- Load .env (optional) ---
        $envPath = __DIR__ . '/.env';
        if (is_file($envPath) && is_readable($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                // strip surrounding quotes
                $v = preg_replace('/^([\'"])(.*)\1$/', '$2', $v);
                putenv("$k=$v");
            }
        }

        // --- Environment / Defaults ---
        $host     = getenv('DB_HOST') ?: '127.0.0.1';
        $port     = getenv('DB_PORT') ?: '3306';
        $user     = getenv('DB_USER') ?: 'root';
        $pass     = getenv('DB_PASS') ?: '';
        // IMPORTANT: default to the dump's name (lowercase)
        $dbname   = getenv('DB_NAME') ?: 'distributrack';
        $charset  = 'utf8mb4';

        // --- DSN & Options ---
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // assoc arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                  // native prepares
            PDO::ATTR_PERSISTENT         => false,                  // no persistent conn
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);

            // Optional but recommended: set session time_zone (Asia/Dhaka = +06:00)
            // Comment out if your MySQL server disallows time_zone changes
            $pdo->exec("SET time_zone = '+06:00'");

            // You can also enable strict SQL modes if desired:
            // $pdo->exec(\"SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'\");
        } catch (Throwable $e) {
            error_log('Database Connection Error: ' . $e->getMessage());
            http_response_code(500);
            die('Sorry, there was a problem connecting to the database. Please try again later.');
        }

        return $pdo;
    }
}

// Backward compatibility: many files expect $conn variable
/** @var PDO $conn */
$conn = db();
