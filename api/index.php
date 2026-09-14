<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'projects';

if ($action === 'contact') {
    require_post();
    $name = request_string('name', 160);
    $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $projectType = request_string('projectType', 80);
    $message = request_string('message', 5000);
    if (!$email) json_response(['error' => 'Enter a valid email address.'], 422);
    if (CONTACT_EMAIL === 'replace-with-your-email@example.com') json_response(['error' => 'Set CONTACT_EMAIL in api/config.php first.'], 500);
    $subject = 'New portfolio inquiry from ' . $name;
    $body = "Name: {$name}\nEmail: {$email}\nProject type: {$projectType}\n\n{$message}";
    $headers = "From: " . CONTACT_EMAIL . "\r\nReply-To: {$email}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    if (!mail(CONTACT_EMAIL, $subject, $body, $headers)) json_response(['error' => 'The message could not be sent. Check the server mail settings.'], 500);
    json_response(['sent' => true]);
}

if ($action === 'shop' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = db()->query('SELECT id, sku, title, description, image_url AS image, price, stock FROM shop_products WHERE active = 1 ORDER BY created_at DESC');
    json_response(['products' => $stmt->fetchAll()]);
}

if ($action === 'admin-products' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_auth();
    json_response(['products' => db()->query('SELECT id, sku, title, description, image_url AS image, price, stock, active FROM shop_products ORDER BY created_at DESC')->fetchAll()]);
}

if ($action === 'order') {
    require_post();
    $customerName = request_string('customerName', 160);
    $customerEmail = filter_var(trim((string)($_POST['customerEmail'] ?? '')), FILTER_VALIDATE_EMAIL);
    $customerAddress = request_string('customerAddress', 1000);
    $items = json_decode((string)($_POST['items'] ?? ''), true);
    if (!$customerEmail || !is_array($items) || !$items) json_response(['error' => 'Complete your contact details and cart.'], 422);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $productStmt = $pdo->prepare('SELECT id, price, stock FROM shop_products WHERE id = ? AND active = 1 FOR UPDATE');
        $orderItems = [];
        $total = 0.0;
        foreach ($items as $item) {
            $productId = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId || !$quantity || $quantity < 1) throw new RuntimeException('Invalid cart item.');
            $productStmt->execute([$productId]);
            $product = $productStmt->fetch();
            if (!$product || $quantity > (int)$product['stock']) throw new RuntimeException('One item is out of stock.');
            $total += (float)$product['price'] * $quantity;
            $orderItems[] = [$productId, $quantity, $product['price']];
        }
        $orderStmt = $pdo->prepare('INSERT INTO shop_orders (customer_name, customer_email, customer_address, total) VALUES (?, ?, ?, ?)');
        $orderStmt->execute([$customerName, $customerEmail, $customerAddress, $total]);
        $orderId = $pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO shop_order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)');
        $stockStmt = $pdo->prepare('UPDATE shop_products SET stock = stock - ? WHERE id = ?');
        foreach ($orderItems as [$productId, $quantity, $price]) {
            $itemStmt->execute([$orderId, $productId, $quantity, $price]);
            $stockStmt->execute([$quantity, $productId]);
        }
        $pdo->commit();
        json_response(['order' => ['id' => $orderId, 'total' => number_format($total, 2, '.', '')]], 201);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['error' => $error->getMessage()], 422);
    }
}

if ($action === 'session' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['authenticated' => !empty($_SESSION['admin_id'])]);
}

if ($action === 'projects' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $showHomeOnly = ($_GET['home'] ?? '') === '1';
    $sql = 'SELECT id, title, category, image_url AS image, image_urls, description, show_home AS showHome FROM projects';
    if ($showHomeOnly) $sql .= ' WHERE show_home = 1';
    $sql .= ' ORDER BY created_at DESC';
    $projects = db()->query($sql)->fetchAll();
    foreach ($projects as &$project) {
        $project['images'] = json_decode($project['image_urls'] ?? '', true) ?: [$project['image']];
        unset($project['image_urls']);
    }
    json_response(['projects' => $projects]);
}

if ($action === 'login') {
    require_post();
    $username = request_string('username', 80);
    $password = (string)($_POST['password'] ?? '');
    if (!hash_equals(ADMIN_USERNAME, $username) || !password_verify($password, ADMIN_PASSWORD_HASH)) {
        json_response(['error' => 'That login did not match.'], 422);
    }
    session_regenerate_id(true);
    $_SESSION['admin_id'] = ADMIN_USERNAME;
    json_response(['authenticated' => true]);
}

if ($action === 'logout') {
    require_post();
    $_SESSION = [];
    session_destroy();
    json_response(['authenticated' => false]);
}

if ($action === 'project') {
    require_post();
    require_auth();
    $title = request_string('title', 160);
    $category = request_string('category', 80);
    $description = request_string('description', 2000);
    $showHome = ($_POST['showHome'] ?? '') === '1' ? 1 : 0;
    $projectId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    $folders = [
        'Illustration' => 'illustration',
        'Graphic Design' => 'graphic-design',
        'Portraits' => 'portraits',
        'Logos' => 'logos'
    ];
    if (!isset($folders[$category])) json_response(['error' => 'Invalid category.'], 422);
    $files = $_FILES['imageFiles'] ?? null;
    if (!$files || !isset($files['name'])) {
        if (!$projectId) json_response(['error' => 'Choose at least one image file.'], 422);
        $files = ['name' => []];
    } elseif (!is_array($files['name'])) {
        foreach ($files as $key => $value) $files[$key] = [$value];
    }
    $uploadIndexes = [];
    foreach ($files['name'] as $index => $name) {
        if ($files['error'][$index] !== UPLOAD_ERR_NO_FILE && $name !== '') $uploadIndexes[] = $index;
    }
    if (!$uploadIndexes && !$projectId) json_response(['error' => 'Choose at least one image file.'], 422);
    $fileCount = count($uploadIndexes);
    if ($fileCount > MAX_PROJECT_IMAGES) json_response(['error' => 'A project can have up to 12 images.'], 422);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $imageUrls = [];
    $folder = PROJECT_UPLOAD_DIR . $folders[$category] . '/';
    if ($fileCount && !is_dir($folder) && !mkdir($folder, 0755, true)) json_response(['error' => 'Upload directory is unavailable.'], 500);
    foreach ($uploadIndexes as $index) {
        if ($files['error'][$index] !== UPLOAD_ERR_OK) json_response(['error' => 'One of the selected images could not be uploaded.'], 422);
        if ($files['size'][$index] > MAX_UPLOAD_BYTES) json_response(['error' => 'Each image must be 8MB or smaller.'], 422);
        $mime = $finfo->file($files['tmp_name'][$index]);
        if (!isset($allowed[$mime])) json_response(['error' => 'Only JPG, PNG, GIF, or WebP images are allowed.'], 422);
        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($files['tmp_name'][$index], $folder . $filename)) json_response(['error' => 'Could not save image.'], 500);
        $imageUrls[] = PROJECT_UPLOAD_URL . $folders[$category] . '/' . $filename;
    }
    if ($projectId) {
        if (!$imageUrls) {
            $existing = db()->prepare('SELECT image_url, image_urls FROM projects WHERE id = ?');
            $existing->execute([$projectId]);
            $current = $existing->fetch();
            if (!$current) json_response(['error' => 'Project not found.'], 404);
            $imageUrls = json_decode($current['image_urls'] ?? '', true) ?: [$current['image_url']];
        }
        $stmt = db()->prepare('UPDATE projects SET title = ?, category = ?, image_url = ?, image_urls = ?, description = ?, show_home = ? WHERE id = ?');
        $stmt->execute([$title, $category, $imageUrls[0], json_encode($imageUrls), $description, $showHome, $projectId]);
        json_response(['updated' => true]);
    }
    $stmt = db()->prepare('INSERT INTO projects (title, category, image_url, image_urls, description, show_home) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $category, $imageUrls[0], json_encode($imageUrls), $description, $showHome]);
    json_response(['project' => ['id' => db()->lastInsertId(), 'title' => $title, 'category' => $category, 'image' => $imageUrls[0], 'images' => $imageUrls, 'description' => $description, 'showHome' => (bool)$showHome]], 201);
}

if ($action === 'delete-project') {
    require_post();
    require_auth();
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) json_response(['error' => 'Invalid project.'], 422);
    $stmt = db()->prepare('DELETE FROM projects WHERE id = ?');
    $stmt->execute([$id]);
    json_response(['deleted' => $stmt->rowCount() > 0]);
}

if ($action === 'product') {
    require_post();
    require_auth();
    $title = request_string('title', 160);
    $sku = request_string('sku', 80);
    $description = request_string('description', 2000);
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
    if ($price === false || $price < 0 || $stock === false || $stock < 0) json_response(['error' => 'Enter a valid price and stock quantity.'], 422);
    $imageUrl = trim((string)($_POST['image'] ?? ''));
    if (isset($_FILES['imageFile']) && $_FILES['imageFile']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['imageFile'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if ($file['size'] > MAX_UPLOAD_BYTES || !isset($allowed[$mime])) json_response(['error' => 'Product image must be a JPG, PNG, GIF, or WebP under 8MB.'], 422);
        $folder = PROJECT_UPLOAD_DIR . 'shop/';
        if (!is_dir($folder) && !mkdir($folder, 0755, true)) json_response(['error' => 'Shop upload directory is unavailable.'], 500);
        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $folder . $filename)) json_response(['error' => 'Could not save product image.'], 500);
        $imageUrl = PROJECT_UPLOAD_URL . 'shop/' . $filename;
    }
    if ($imageUrl === '') json_response(['error' => 'Add a product image.'], 422);
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = db()->prepare('UPDATE shop_products SET sku = ?, title = ?, description = ?, image_url = ?, price = ?, stock = ?, active = ? WHERE id = ?');
        $stmt->execute([$sku, $title, $description, $imageUrl, $price, $stock, ($_POST['active'] ?? '1') === '1' ? 1 : 0, $id]);
        json_response(['updated' => true]);
    }
    $stmt = db()->prepare('INSERT INTO shop_products (sku, title, description, image_url, price, stock, active) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$sku, $title, $description, $imageUrl, $price, $stock, 1]);
    json_response(['id' => db()->lastInsertId()], 201);
}

if ($action === 'delete-product') {
    require_post();
    require_auth();
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) json_response(['error' => 'Invalid product.'], 422);
    $stmt = db()->prepare('DELETE FROM shop_products WHERE id = ?');
    $stmt->execute([$id]);
    json_response(['deleted' => $stmt->rowCount() > 0]);
}

if ($action === 'visibility') {
    require_post();
    require_auth();
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) json_response(['error' => 'Invalid project.'], 422);
    $stmt = db()->prepare('UPDATE projects SET show_home = ? WHERE id = ?');
    $stmt->execute([($_POST['showHome'] ?? '') === '1' ? 1 : 0, $id]);
    json_response(['updated' => true]);
}

json_response(['error' => 'Unknown action.'], 404);
