<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'dyndel_portfolio';
const DB_USER = 'root';
const DB_PASSWORD = '';
const CONTACT_EMAIL = 'replace-with-your-email@example.com';
const PROJECT_UPLOAD_DIR = __DIR__ . '/../img/projectfolder/';
const PROJECT_UPLOAD_URL = 'img/projectfolder/';
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;
const MAX_PROJECT_IMAGES = 12;

// Change this before deploying. Generate a hash with password_hash('your-password', PASSWORD_DEFAULT).
const ADMIN_USERNAME = 'admin';
const ADMIN_PASSWORD_HASH = '$2y$10$zHwyu0aio9WvBJEhfjJ5kOfMxUv0OSHFws/fnFbUrQtjX0E1In4ZW';

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $column = $pdo->query("SHOW COLUMNS FROM projects LIKE 'image_urls'")->fetch();
        if (!$column) $pdo->exec('ALTER TABLE projects ADD image_urls TEXT NULL AFTER image_url');
        $pdo->exec('CREATE TABLE IF NOT EXISTS shop_products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(80) NOT NULL UNIQUE,
            title VARCHAR(160) NOT NULL,
            description TEXT NOT NULL,
            image_url VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            stock INT UNSIGNED NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS shop_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_name VARCHAR(160) NOT NULL,
            customer_email VARCHAR(190) NOT NULL,
            customer_address TEXT NOT NULL,
            total DECIMAL(10,2) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT "pending",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS shop_order_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES shop_products(id)
        )');
    }
    return $pdo;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'POST required.'], 405);
}

function require_auth(): void
{
    if (empty($_SESSION['admin_id'])) json_response(['error' => 'Authentication required.'], 401);
}

function request_string(string $key, int $maxLength = 500): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '' || strlen($value) > $maxLength) json_response(['error' => "Invalid {$key}."], 422);
    return $value;
}
