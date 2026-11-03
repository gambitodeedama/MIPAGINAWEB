<?php
declare(strict_types=1);

const ZT_PRODUCTS = [
    0 => ['name' => 'Apple iPhone 16e · 128 GB', 'price' => 219.9],
    1 => ['name' => 'Samsung Galaxy A56 5G · 256 GB', 'price' => 151.9],
    2 => ['name' => 'ASUS Vivobook Go 15 E1504FA', 'price' => 159.9],
    3 => ['name' => 'Lenovo LOQ 15ARP9 RTX 4060', 'price' => 449.9],
    4 => ['name' => 'Sony WH-CH520', 'price' => 11.9],
    5 => ['name' => 'JBL Go 4', 'price' => 18.9],
    6 => ['name' => 'Samsung Galaxy Fit3', 'price' => 15.5],
    7 => ['name' => 'Logitech Gamepad F310', 'price' => 11.9],
    8 => ['name' => "LG UltraGear 24GS60F-B · 24''", 'price' => 64.9],
    9 => ['name' => 'Logitech G213 Prodigy RGB', 'price' => 21.9],
    10 => ['name' => 'Logitech C920s HD Pro', 'price' => 26.9],
    11 => ['name' => 'Anker PowerCore Essential · 20,000 mAh', 'price' => 16.9],
    12 => ['name' => 'Microsoft 365 Personal · 12 meses', 'price' => 29.9],
    13 => ['name' => 'Windows 11 Home · licencia digital', 'price' => 49.9],
    14 => ['name' => 'Norton 360 Deluxe · 5 dispositivos', 'price' => 17.9],
    15 => ['name' => 'Kaspersky Standard · 1 dispositivo', 'price' => 8.9],
    16 => ['name' => 'NordVPN Basic · 12 meses', 'price' => 24.9],
    17 => ['name' => 'Bitdefender Total Security · 5 dispositivos', 'price' => 15.9],
    18 => ['name' => 'Apple iPhone 16 · 128 GB', 'price' => 399.9],
    19 => ['name' => 'Samsung Galaxy A36 5G · 128 GB', 'price' => 109.9],
    20 => ['name' => 'Xiaomi Redmi Note 14 Pro 5G · 256 GB', 'price' => 109.9],
    21 => ['name' => 'Apple iPhone 18 Pro · 256 GB', 'price' => 6499.0],
    22 => ['name' => 'Samsung Galaxy S26 Ultra · 512 GB', 'price' => 6499.0],
    23 => ['name' => 'Xiaomi Redmi Note 15 Pro+ 5G · 512 GB', 'price' => 2199.0],
    24 => ['name' => 'HONOR 600 Pro · 512 GB', 'price' => 3999.0],
    25 => ['name' => 'ASUS ROG Strix G16 (2026)', 'price' => 10999.0],
];

function zt_order_totals(float $subtotal): array
{
    $subtotal = round($subtotal, 2);
    $discount = $subtotal > 299 ? round($subtotal * 0.30, 2) : 0;
    $shipping = $subtotal === 0 || $subtotal >= 299 ? 0 : 15;
    return ['discount' => $discount, 'shipping' => $shipping, 'total' => round($subtotal - $discount + $shipping, 2)];
}

function zt_data_dir(): string
{
    $configured = getenv('ZUMBATECH_DATA_DIR');
    $directory = $configured ?: dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'zumbatech-data';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear la carpeta privada de datos.');
    }
    return $directory;
}

function zt_db(): PDO
{
    static $db;
    if ($db instanceof PDO) {
        return $db;
    }

    $host = getenv('ZUMBATECH_DB_HOST') ?: '127.0.0.1';
    $name = getenv('ZUMBATECH_DB_NAME') ?: 'zumbatech';
    $user = getenv('ZUMBATECH_DB_USER') ?: 'root';
    $pass = getenv('ZUMBATECH_DB_PASS') ?: '';
    $db = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec("SET time_zone = '-05:00'");
    $renames = [
        'zt_product_prices' => 'precios_productos',
        'zt_custom_products' => 'productos_personalizados',
        'zt_product_overrides' => 'personalizaciones_productos',
    ];
    $tableExists = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    foreach ($renames as $oldName => $newName) {
        $tableExists->execute([$oldName]);
        $oldExists = (int) $tableExists->fetchColumn() > 0;
        $tableExists->execute([$newName]);
        $newExists = (int) $tableExists->fetchColumn() > 0;
        if ($oldExists && $newExists) {
            throw new RuntimeException("No se puede migrar la tabla $oldName porque también existe $newName.");
        }
        if ($oldExists) {
            $db->exec("RENAME TABLE `$oldName` TO `$newName`");
        }
    }
    $db->exec("CREATE TABLE IF NOT EXISTS precios_productos (
        product_id INT UNSIGNED NOT NULL,
        price DECIMAL(10, 2) NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS productos_personalizados (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(200) NOT NULL,
        category VARCHAR(60) NOT NULL,
        price DECIMAL(10, 2) NOT NULL,
        description VARCHAR(255) NOT NULL,
        features TEXT NOT NULL,
        image_filename VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB AUTO_INCREMENT=1000 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS personalizaciones_productos (
        product_id INT UNSIGNED NOT NULL,
        name VARCHAR(200) NOT NULL,
        category VARCHAR(60) NOT NULL,
        description VARCHAR(255) NOT NULL,
        features TEXT NOT NULL,
        offer_price DECIMAL(10, 2) NULL,
        offer_label VARCHAR(40) NOT NULL DEFAULT '',
        image_filename VARCHAR(100) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS stock_productos (
        product_id INT UNSIGNED NOT NULL,
        stock INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $initialStock = array_map(static fn(int $productId): string => "($productId, 10)", range(0, count(ZT_PRODUCTS) - 1));
    $db->exec('INSERT IGNORE INTO stock_productos (product_id, stock) VALUES ' . implode(', ', $initialStock));
    $db->exec('INSERT IGNORE INTO stock_productos (product_id, stock) SELECT id, 10 FROM productos_personalizados');
    $db->exec("CREATE TABLE IF NOT EXISTS reservas_stock_pedidos (
        pedido_id INT UNSIGNED NOT NULL,
        producto_id INT UNSIGNED NOT NULL,
        cantidad INT UNSIGNED NOT NULL,
        liberado_en DATETIME NULL,
        PRIMARY KEY (pedido_id, producto_id),
        KEY idx_reservas_stock_producto (producto_id),
        CONSTRAINT fk_reserva_stock_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $productVideoColumn = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $productVideoColumn->execute(['personalizaciones_productos', 'video_id']);
    if ((int) $productVideoColumn->fetchColumn() === 0) {
        $db->exec('ALTER TABLE personalizaciones_productos ADD COLUMN video_id CHAR(11) NULL');
    }
    $userColumnExists = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $userColumnExists->execute(['usuarios', 'telefono']);
    if ((int) $userColumnExists->fetchColumn() === 0) {
        $db->exec('ALTER TABLE usuarios ADD COLUMN telefono VARCHAR(16) NULL');
    }
    $userColumnExists->execute(['usuarios', 'correo']);
    if ((int) $userColumnExists->fetchColumn() > 0) {
        $emailColumn = $db->query("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'correo'")->fetchColumn();
        if ($emailColumn === 'NO') {
            $db->exec('ALTER TABLE usuarios MODIFY correo VARCHAR(190) NULL');
        }
    }
    $phoneIndex = $db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND index_name = 'uq_usuarios_telefono'")->fetchColumn();
    if ((int) $phoneIndex === 0) {
        $db->exec('ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_telefono (telefono)');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS usuarios_identidades (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        usuario_id INT UNSIGNED NOT NULL,
        proveedor VARCHAR(20) NOT NULL,
        subject_id VARCHAR(255) NOT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_identidad_proveedor_subject (proveedor, subject_id),
        UNIQUE KEY uq_identidad_usuario_proveedor (usuario_id, proveedor),
        CONSTRAINT fk_identidad_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS verificaciones_telefono (
        telefono VARCHAR(16) NOT NULL,
        codigo_hash VARCHAR(255) NOT NULL,
        session_hash CHAR(64) NOT NULL,
        nombre VARCHAR(100) NOT NULL,
        enviado_en DATETIME NOT NULL,
        expira_en DATETIME NOT NULL,
        intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (telefono)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS comentarios_clientes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        usuario_id INT UNSIGNED NOT NULL,
        comentario TEXT NOT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_comentarios_clientes_fecha (creado_en),
        KEY idx_comentarios_clientes_usuario (usuario_id, creado_en),
        CONSTRAINT fk_comentario_cliente_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $orderColumns = [
        'departamento' => "VARCHAR(80) NOT NULL DEFAULT ''",
        'provincia' => "VARCHAR(80) NOT NULL DEFAULT ''",
        'distrito' => "VARCHAR(80) NOT NULL DEFAULT ''",
        'ubigeo_departamento' => "CHAR(2) NOT NULL DEFAULT ''",
        'ubigeo_provincia' => "CHAR(4) NOT NULL DEFAULT ''",
        'ubigeo_distrito' => "CHAR(6) NOT NULL DEFAULT ''",
        'transportista' => "VARCHAR(30) NOT NULL DEFAULT ''",
        'codigo_seguimiento' => 'VARCHAR(20) NULL',
        'codigo_entrega' => 'VARCHAR(10) NULL',
        'estado_envio' => "VARCHAR(20) NOT NULL DEFAULT 'pendiente_pago'",
    ];
    $columnExists = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    foreach ($orderColumns as $column => $definition) {
        $columnExists->execute(['pedidos', $column]);
        if ((int) $columnExists->fetchColumn() === 0) {
            $db->exec("ALTER TABLE pedidos ADD COLUMN `$column` $definition");
        }
    }
    $trackingIndex = $db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'pedidos' AND index_name = 'uq_pedidos_codigo_seguimiento'")->fetchColumn();
    if ((int) $trackingIndex === 0) {
        $db->exec('ALTER TABLE pedidos ADD UNIQUE KEY uq_pedidos_codigo_seguimiento (codigo_seguimiento)');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS seguimiento_pedidos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        pedido_id INT UNSIGNED NOT NULL,
        administrador_id INT UNSIGNED NULL,
        estado VARCHAR(20) NOT NULL,
        detalle VARCHAR(255) NOT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_seguimiento_pedido (pedido_id, creado_en),
        CONSTRAINT fk_seguimiento_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id) ON DELETE CASCADE,
        CONSTRAINT fk_seguimiento_admin FOREIGN KEY (administrador_id) REFERENCES usuarios (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("UPDATE pedidos SET estado_envio = 'preparando' WHERE estado = 'aprobado' AND estado_envio = 'pendiente_pago'");
    $db->exec("INSERT INTO seguimiento_pedidos (pedido_id, administrador_id, estado, detalle)
        SELECT p.id, NULL, 'preparando', 'Pago aprobado; pedido preparando'
        FROM pedidos p
        WHERE p.estado = 'aprobado'
          AND NOT EXISTS (SELECT 1 FROM seguimiento_pedidos s WHERE s.pedido_id = p.id)");
    return $db;
}

function zt_ubigeo_data(): array
{
    static $data;
    if (is_array($data)) {
        return $data;
    }
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'ubigeos_peru.json';
    $contents = is_file($path) ? file_get_contents($path) : false;
    $decoded = $contents === false ? null : json_decode($contents, true);
    if (!is_array($decoded) || !isset($decoded['departamentos'], $decoded['provincias'], $decoded['distritos'])) {
        throw new RuntimeException('No se pudo cargar el catálogo local de ubicaciones del Perú.');
    }
    $data = $decoded;
    return $data;
}

function zt_delivery_location(array $input): array
{
    $departmentCode = trim((string) ($input['department'] ?? ''));
    $provinceCode = trim((string) ($input['province'] ?? ''));
    $districtCode = trim((string) ($input['district'] ?? ''));
    $data = zt_ubigeo_data();
    $department = null;
    foreach ($data['departamentos'] as $entry) {
        if ($entry['codigo'] === $departmentCode) {
            $department = $entry;
            break;
        }
    }
    $province = null;
    foreach ($data['provincias'] as $entry) {
        if ($entry['codigo'] === $provinceCode && $entry['departamento'] === $departmentCode) {
            $province = $entry;
            break;
        }
    }
    $district = null;
    foreach ($data['distritos'] as $entry) {
        if ($entry['codigo'] === $districtCode && $entry['departamento'] === $departmentCode && $entry['provincia'] === $provinceCode) {
            $district = $entry;
            break;
        }
    }
    if ($department === null || $province === null || $district === null) {
        zt_json(['error' => 'Selecciona un departamento, provincia y distrito válidos.'], 422);
    }
    return [
        'departamento' => $department['nombre'],
        'provincia' => $province['nombre'],
        'distrito' => $district['nombre'],
        'ubigeo_departamento' => $departmentCode,
        'ubigeo_provincia' => $provinceCode,
        'ubigeo_distrito' => $districtCode,
        'transportista' => $departmentCode === '15' && $provinceCode === '1501' ? 'Motorizado' : 'Shalom',
    ];
}

function zt_catalog_products(): array
{
    $products = ZT_PRODUCTS;
    $db = zt_db();
    foreach ($db->query('SELECT product_id, price FROM precios_productos')->fetchAll() as $price) {
        $id = (int) $price['product_id'];
        if (isset($products[$id])) {
            $products[$id]['price'] = (float) $price['price'];
        }
    }
    foreach ($db->query('SELECT id, name, category, price, description, features FROM productos_personalizados ORDER BY id')->fetchAll() as $product) {
        $product['id'] = (int) $product['id'];
        $product['price'] = (float) $product['price'];
        $product['features'] = preg_split('/\R/u', (string) $product['features'], -1, PREG_SPLIT_NO_EMPTY);
        $product['image'] = 'api.php?action=product_image&id=' . $product['id'];
        $product['custom_image'] = true;
        $product['custom'] = true;
        $products[$product['id']] = $product;
    }
    foreach ($db->query('SELECT product_id, name, category, description, features, offer_price, offer_label, image_filename, video_id FROM personalizaciones_productos')->fetchAll() as $override) {
        $id = (int) $override['product_id'];
        if (!isset($products[$id])) {
            continue;
        }
        foreach (['name', 'category', 'description', 'offer_label'] as $field) {
            $products[$id][$field] = $override[$field];
        }
        $products[$id]['features'] = preg_split('/\R/u', (string) $override['features'], -1, PREG_SPLIT_NO_EMPTY);
        $products[$id]['offer_price'] = $override['offer_price'] === null ? null : (float) $override['offer_price'];
        $products[$id]['video_id'] = $override['video_id'];
        if (!empty($override['image_filename'])) {
            $products[$id]['image'] = 'api.php?action=product_image&id=' . $id;
            $products[$id]['custom_image'] = true;
        }
    }
    foreach ($db->query('SELECT product_id, stock FROM stock_productos')->fetchAll() as $inventory) {
        $id = (int) $inventory['product_id'];
        if (isset($products[$id])) {
            $products[$id]['stock'] = (int) $inventory['stock'];
        }
    }
    foreach ($products as &$product) {
        $product['stock'] = $product['stock'] ?? 0;
        $product['offer_price'] = $product['offer_price'] ?? null;
        $product['offer_label'] = $product['offer_label'] ?? '';
        $product['effective_price'] = $product['offer_price'] !== null && $product['offer_price'] < $product['price']
            ? $product['offer_price']
            : $product['price'];
    }
    unset($product);
    return $products;
}

function zt_reserve_order_stock(PDO $db, array $items): ?string
{
    usort($items, static fn(array $first, array $second): int => (int) $first[0] <=> (int) $second[0]);
    $reserve = $db->prepare('UPDATE stock_productos SET stock = stock - ? WHERE product_id = ? AND stock >= ?');
    foreach ($items as [$productId, $productName, $quantity]) {
        $reserve->execute([(int) $quantity, (int) $productId, (int) $quantity]);
        if ($reserve->rowCount() !== 1) {
            return (string) $productName;
        }
    }
    return null;
}

function zt_release_order_stock(PDO $db, int $orderId): void
{
    if (!$db->inTransaction()) {
        throw new LogicException('Las reservas de stock solo se pueden liberar dentro de una transacción.');
    }
    $query = $db->prepare('SELECT producto_id, cantidad FROM reservas_stock_pedidos WHERE pedido_id = ? AND liberado_en IS NULL FOR UPDATE');
    $query->execute([$orderId]);
    $reservations = $query->fetchAll();
    $restore = $db->prepare('UPDATE stock_productos SET stock = stock + ? WHERE product_id = ?');
    $release = $db->prepare('UPDATE reservas_stock_pedidos SET liberado_en = NOW() WHERE pedido_id = ? AND producto_id = ? AND liberado_en IS NULL');
    foreach ($reservations as $reservation) {
        $restore->execute([(int) $reservation['cantidad'], (int) $reservation['producto_id']]);
        if ($restore->rowCount() !== 1) {
            throw new RuntimeException('No se pudo devolver al inventario el stock reservado del pedido.');
        }
        $release->execute([$orderId, (int) $reservation['producto_id']]);
    }
}

function zt_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function zt_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function zt_input(): array
{
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function zt_require_csrf(array $input): void
{
    $token = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        zt_json(['error' => 'La sesión venció. Recarga la página e inténtalo de nuevo.'], 419);
    }
}

function zt_current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $query = zt_db()->prepare("SELECT id, nombre AS name, COALESCE(correo, '') AS email, COALESCE(telefono, '') AS phone, CASE rol WHEN 'administrador' THEN 'admin' ELSE 'customer' END AS role FROM usuarios WHERE id = ?");
    $query->execute([$_SESSION['user_id']]);
    $user = $query->fetch();
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function zt_require_user(): array
{
    $user = zt_current_user();
    if (!$user) {
        zt_json(['error' => 'Inicia sesión para continuar.'], 401);
    }
    return $user;
}

function zt_require_admin(): array
{
    $user = zt_require_user();
    if ($user['role'] !== 'admin') {
        zt_json(['error' => 'No tienes permiso para ver esta sección.'], 403);
    }
    return $user;
}

zt_start_session();