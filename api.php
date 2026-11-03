<?php
declare(strict_types=1);

require __DIR__ . '/app.php';

function zt_login(array $input): void
{
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $query = zt_db()->prepare('SELECT id, contrasena_hash AS password_hash FROM usuarios WHERE correo = ?');
    $query->execute([$email]);
    $account = $query->fetch();
    if (!$account || !password_verify((string) ($input['password'] ?? ''), $account['password_hash'])) {
        zt_json(['error' => 'Correo o contraseña incorrectos.'], 401);
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $account['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    zt_json(['user' => zt_current_user(), 'csrf' => $_SESSION['csrf']]);
}

function zt_register(array $input): void
{
    $name = trim((string) ($input['name'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        zt_json(['error' => 'Escribe un nombre y correo válidos.'], 422);
    }
    if (strlen($password) < 10 || strlen($password) > 200) {
        zt_json(['error' => 'La contraseña debe tener entre 10 y 200 caracteres.'], 422);
    }
    try {
        $query = zt_db()->prepare("INSERT INTO usuarios (nombre, correo, contrasena_hash, rol) VALUES (?, ?, ?, 'cliente')");
        $query->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            zt_json(['error' => 'Ya existe una cuenta con ese correo.'], 409);
        }
        throw $error;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) zt_db()->lastInsertId();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    zt_json(['user' => zt_current_user(), 'csrf' => $_SESSION['csrf']], 201);
}

function zt_auth_site_url(): string
{
    $siteUrl = rtrim((string) (getenv('ZUMBATECH_SITE_URL') ?: ''), '/');
    if ($siteUrl === '' || !filter_var($siteUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($siteUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
        throw new RuntimeException('Configura ZUMBATECH_SITE_URL con la URL local o pública de la tienda.');
    }
    return $siteUrl;
}

function zt_oauth_redirect_uri(string $provider): string
{
    $configured = trim((string) getenv(strtoupper($provider) . '_REDIRECT_URI'));
    return $configured !== '' ? $configured : zt_auth_site_url() . '/api.php?action=oauth_callback&provider=' . rawurlencode($provider);
}

function zt_oauth_client(string $provider): array
{
    if ($provider === 'google') {
        $clientId = trim((string) getenv('GOOGLE_CLIENT_ID'));
        $clientSecret = trim((string) getenv('GOOGLE_CLIENT_SECRET'));
    } elseif ($provider === 'microsoft') {
        $clientId = trim((string) getenv('MICROSOFT_CLIENT_ID'));
        $clientSecret = trim((string) getenv('MICROSOFT_CLIENT_SECRET'));
    } else {
        zt_json(['error' => 'Proveedor de acceso no válido.'], 404);
    }
    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException("Falta configurar las credenciales OAuth de $provider en Apache.");
    }
    return [$clientId, $clientSecret];
}

function zt_http_json(string $url, array $options = []): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('No se pudo iniciar la conexión con el proveedor de identidad.');
    }
    curl_setopt_array($handle, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);
    if (!is_string($response) || $status < 200 || $status >= 300) {
        error_log("ZyP TECH identity provider request failed: HTTP $status; $curlError");
        throw new RuntimeException('No se pudo validar la respuesta del proveedor de identidad.');
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('El proveedor de identidad devolvió una respuesta inválida.');
    }
    return $decoded;
}

function zt_oauth_start(string $provider): never
{
    zt_auth_site_url();
    [$clientId] = zt_oauth_client($provider);
    $state = bin2hex(random_bytes(32));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_provider'] = $provider;
    $_SESSION['oauth_started'] = time();
    if ($provider === 'google') {
        $authorizationEndpoint = 'https://accounts.google.com/o/oauth2/v2/auth';
        $scope = 'openid email profile';
    } else {
        $tenant = trim((string) (getenv('MICROSOFT_TENANT_ID') ?: 'common'));
        $authorizationEndpoint = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/authorize';
        $scope = 'openid profile email User.Read';
    }
    $query = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => zt_oauth_redirect_uri($provider),
        'response_type' => 'code',
        'scope' => $scope,
        'state' => $state,
        'prompt' => 'select_account',
    ]);
    header('Cache-Control: no-store');
    header('Location: ' . $authorizationEndpoint . '?' . $query, true, 302);
    exit;
}

function zt_auth_redirect(string $status, ?string $message = null): never
{
    $query = ['auth' => $status];
    if ($message !== null) {
        $query['auth_message'] = mb_substr($message, 0, 300);
    }
    $siteUrl = trim((string) getenv('ZUMBATECH_SITE_URL'));
    $basePath = $siteUrl !== ''
        ? zt_auth_site_url()
        : rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php'))), '/');
    header('Cache-Control: no-store');
    header('Location: ' . $basePath . '/ZyP_TECH___Tienda_de_tecnolog%C3%ADa.html?' . http_build_query($query), true, 302);
    exit;
}

function zt_oauth_callback(string $provider): never
{
    try {
        if (isset($_GET['error'])) {
            throw new RuntimeException('Se canceló o no se pudo completar el acceso externo.');
        }
        $state = (string) ($_GET['state'] ?? '');
        if ($state === '' || !isset($_SESSION['oauth_state'], $_SESSION['oauth_provider'], $_SESSION['oauth_started'])
            || $_SESSION['oauth_provider'] !== $provider
            || time() - (int) $_SESSION['oauth_started'] > 600
            || !hash_equals((string) $_SESSION['oauth_state'], $state)) {
            throw new RuntimeException('La verificación de seguridad del acceso venció. Inténtalo de nuevo.');
        }
        unset($_SESSION['oauth_state'], $_SESSION['oauth_provider'], $_SESSION['oauth_started']);
        $code = (string) ($_GET['code'] ?? '');
        if ($code === '') {
            throw new RuntimeException('El proveedor no entregó un código de acceso.');
        }
        [$clientId, $clientSecret] = zt_oauth_client($provider);
        $tokenEndpoint = $provider === 'google'
            ? 'https://oauth2.googleapis.com/token'
            : 'https://login.microsoftonline.com/' . rawurlencode(trim((string) (getenv('MICROSOFT_TENANT_ID') ?: 'common'))) . '/oauth2/v2.0/token';
        $tokens = zt_http_json($tokenEndpoint, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => zt_oauth_redirect_uri($provider),
            ]),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]);
        $accessToken = (string) ($tokens['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('El proveedor no emitió un token de acceso.');
        }
        $userInfoUrl = $provider === 'google'
            ? 'https://openidconnect.googleapis.com/v1/userinfo'
            : 'https://graph.microsoft.com/oidc/userinfo';
        $profile = zt_http_json($userInfoUrl, [
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $accessToken],
        ]);
        $subject = trim((string) ($profile['sub'] ?? ''));
        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        if ($provider === 'microsoft' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $microsoftProfile = zt_http_json('https://graph.microsoft.com/v1.0/me?$select=displayName,mail,userPrincipalName', [
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $accessToken],
            ]);
            $email = strtolower(trim((string) ($microsoftProfile['mail'] ?? $microsoftProfile['userPrincipalName'] ?? '')));
            if (trim((string) ($profile['name'] ?? '')) === '') {
                $profile['name'] = $microsoftProfile['displayName'] ?? '';
            }
        }
        if ($subject === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || ($provider === 'google' && ($profile['email_verified'] ?? false) !== true)) {
            throw new RuntimeException('La cuenta externa no proporcionó un correo verificado.');
        }
        $db = zt_db();
        $query = $db->prepare('SELECT usuario_id FROM usuarios_identidades WHERE proveedor = ? AND subject_id = ?');
        $query->execute([$provider, $subject]);
        $userId = $query->fetchColumn();
        if ($userId === false) {
            $name = trim((string) ($profile['name'] ?? ''));
            if ($name === '') {
                $name = strstr($email, '@', true) ?: 'Cliente';
            }
            $db->beginTransaction();
            try {
                $query = $db->prepare("INSERT INTO usuarios (nombre, correo, contrasena_hash, rol) VALUES (?, ?, ?, 'cliente')");
                $query->execute([mb_substr($name, 0, 120), $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
                $userId = (int) $db->lastInsertId();
                $query = $db->prepare('INSERT INTO usuarios_identidades (usuario_id, proveedor, subject_id) VALUES (?, ?, ?)');
                $query->execute([$userId, $provider, $subject]);
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($error instanceof PDOException && $error->getCode() === '23000') {
                    throw new RuntimeException('Ese correo ya tiene una cuenta local. Inicia sesión con tu contraseña y usa un correo externo distinto.');
                }
                throw $error;
            }
        } else {
            $userId = (int) $userId;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $userId;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        zt_auth_redirect('success');
    } catch (Throwable $error) {
        error_log('ZyP TECH OAuth callback failed: ' . $error->getMessage());
        zt_auth_redirect('error', $error->getMessage());
    }
}

function zt_send_phone_code(array $input): never
{
    $phone = preg_replace('/[\s().-]+/', '', trim((string) ($input['phone'] ?? ''))) ?? '';
    $name = trim((string) ($input['name'] ?? ''));
    if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) || mb_strlen($name) > 100) {
        zt_json(['error' => 'Ingresa un teléfono internacional válido, por ejemplo +51912345678.'], 422);
    }
    $accountSid = trim((string) getenv('TWILIO_ACCOUNT_SID'));
    $authToken = trim((string) getenv('TWILIO_AUTH_TOKEN'));
    $messagingServiceSid = trim((string) getenv('TWILIO_MESSAGING_SERVICE_SID'));
    $fromNumber = trim((string) getenv('TWILIO_FROM_NUMBER'));
    if ($accountSid === '' || $authToken === '' || ($messagingServiceSid === '' && $fromNumber === '')) {
        zt_json(['error' => 'Configura Twilio en XAMPP antes de solicitar códigos SMS.'], 503);
    }
    $db = zt_db();
    $query = $db->prepare('SELECT enviado_en FROM verificaciones_telefono WHERE telefono = ?');
    $query->execute([$phone]);
    $lastSent = $query->fetchColumn();
    if ($lastSent !== false && strtotime((string) $lastSent) > time() - 60) {
        zt_json(['error' => 'Espera un minuto antes de solicitar otro código.'], 429);
    }
    $code = (string) random_int(100000, 999999);
    $query = $db->prepare('INSERT INTO verificaciones_telefono (telefono, codigo_hash, session_hash, nombre, enviado_en, expira_en, intentos) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 0 SECOND), DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0) ON DUPLICATE KEY UPDATE codigo_hash = VALUES(codigo_hash), session_hash = VALUES(session_hash), nombre = VALUES(nombre), enviado_en = VALUES(enviado_en), expira_en = VALUES(expira_en), intentos = 0');
    $query->execute([$phone, password_hash($code, PASSWORD_DEFAULT), hash('sha256', session_id()), $name]);
    $message = 'Tu código de verificación ZyP TECH es ' . $code . '. Vence en 5 minutos. No lo compartas.';
    $body = ['To' => $phone, 'Body' => $message];
    if ($messagingServiceSid !== '') {
        $body['MessagingServiceSid'] = $messagingServiceSid;
    } else {
        $body['From'] = $fromNumber;
    }
    $handle = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json');
    if ($handle === false) {
        throw new RuntimeException('No se pudo iniciar el servicio SMS.');
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($body),
        CURLOPT_USERPWD => $accountSid . ':' . $authToken,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);
    if (!is_string($response) || $status < 200 || $status >= 300) {
        $db->prepare('DELETE FROM verificaciones_telefono WHERE telefono = ? AND session_hash = ?')->execute([$phone, hash('sha256', session_id())]);
        error_log("ZyP TECH Twilio SMS failed: HTTP $status; $curlError");
        zt_json(['error' => 'Twilio no pudo enviar el SMS. Revisa la configuración y el formato del número.'], 502);
    }
    zt_json(['ok' => true, 'message' => 'Enviamos el código por SMS.']);
}

function zt_verify_phone_code(array $input): never
{
    $phone = preg_replace('/[\s().-]+/', '', trim((string) ($input['phone'] ?? ''))) ?? '';
    $code = trim((string) ($input['code'] ?? ''));
    if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) || !preg_match('/^[0-9]{6}$/', $code)) {
        zt_json(['error' => 'Ingresa el teléfono y el código de seis dígitos.'], 422);
    }
    $db = zt_db();
    $db->beginTransaction();
    $query = $db->prepare('SELECT codigo_hash, session_hash, nombre, expira_en, intentos FROM verificaciones_telefono WHERE telefono = ? FOR UPDATE');
    $query->execute([$phone]);
    $challenge = $query->fetch();
    $sessionHash = hash('sha256', session_id());
    if (!$challenge || !hash_equals((string) $challenge['session_hash'], $sessionHash)
        || strtotime((string) $challenge['expira_en']) <= time()
        || (int) $challenge['intentos'] >= 5) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        zt_json(['error' => 'El código venció o ya no es válido. Solicita uno nuevo.'], 422);
    }
    if (!password_verify($code, (string) $challenge['codigo_hash'])) {
        $db->prepare('UPDATE verificaciones_telefono SET intentos = intentos + 1 WHERE telefono = ?')->execute([$phone]);
        $db->commit();
        zt_json(['error' => 'El código es incorrecto. Verifica el SMS e inténtalo de nuevo.'], 401);
    }
    $query = $db->prepare('SELECT id FROM usuarios WHERE telefono = ?');
    $query->execute([$phone]);
    $userId = $query->fetchColumn();
    if ($userId === false) {
        $name = trim((string) ($input['name'] ?? '')) ?: trim((string) ($challenge['nombre'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $db->rollBack();
            zt_json(['error' => 'Escribe tu nombre completo para crear la cuenta con teléfono.'], 422);
        }
        $query = $db->prepare("INSERT INTO usuarios (nombre, correo, telefono, contrasena_hash, rol) VALUES (?, NULL, ?, ?, 'cliente')");
        $query->execute([$name, $phone, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        $userId = (int) $db->lastInsertId();
    }
    $db->prepare('DELETE FROM verificaciones_telefono WHERE telefono = ?')->execute([$phone]);
    $db->commit();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $userId;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    zt_json(['user' => zt_current_user(), 'csrf' => $_SESSION['csrf']]);
}

function zt_order_items(array $orders): array
{
    if (!$orders) {
        return [];
    }
    $ids = array_map(static fn(array $order): int => (int) $order['id'], $orders);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $query = zt_db()->prepare("SELECT pedido_id AS order_id, nombre_producto AS product_name, producto_id AS product_id, cantidad AS quantity, precio_unitario AS unit_price FROM detalle_pedido WHERE pedido_id IN ($placeholders) ORDER BY nombre_producto");
    $query->execute($ids);
    $items = [];
    foreach ($query->fetchAll() as $item) {
        $items[(int) $item['order_id']][] = $item;
    }
    return $items;
}

function zt_get_catalog(): never
{
    $products = [];
    foreach (zt_catalog_products() as $id => $product) {
        $products[] = [
            'id' => (int) $id,
            'name' => $product['name'],
            'price' => (float) $product['price'],
            'effective_price' => (float) $product['effective_price'],
            'offer_price' => $product['offer_price'],
            'offer_label' => $product['offer_label'],
            'category' => $product['category'] ?? null,
            'description' => $product['description'] ?? null,
            'features' => $product['features'] ?? null,
            'stock' => (int) $product['stock'],
            'image' => $product['image'] ?? null,
            'video_id' => $product['video_id'] ?? null,
            'custom_image' => !empty($product['custom_image']),
            'custom' => !empty($product['custom']),
        ];
    }
    zt_json(['products' => $products]);
}

function zt_get_customer_comments(): never
{
    $query = zt_db()->query("SELECT c.comentario AS comment, SUBSTRING_INDEX(u.nombre, ' ', 1) AS author, c.creado_en AS created_at
        FROM comentarios_clientes c
        INNER JOIN usuarios u ON u.id = c.usuario_id
        ORDER BY c.creado_en DESC, c.id DESC
        LIMIT 50");
    zt_json(['comments' => $query->fetchAll()]);
}

function zt_create_customer_comment(array $input, array $user): never
{
    if ($user['role'] !== 'customer') {
        zt_json(['error' => 'Solo las cuentas de cliente pueden publicar comentarios.'], 403);
    }
    $comment = trim((string) ($input['comment'] ?? ''));
    $length = mb_strlen($comment);
    if ($length < 3 || $length > 1000) {
        zt_json(['error' => 'El comentario debe tener entre 3 y 1000 caracteres.'], 422);
    }

    $db = zt_db();
    $recent = $db->prepare('SELECT COUNT(*) FROM comentarios_clientes WHERE usuario_id = ? AND creado_en > DATE_SUB(NOW(), INTERVAL 30 SECOND)');
    $recent->execute([(int) $user['id']]);
    if ((int) $recent->fetchColumn() > 0) {
        zt_json(['error' => 'Espera unos segundos antes de publicar otro comentario.'], 429);
    }

    $insert = $db->prepare('INSERT INTO comentarios_clientes (usuario_id, comentario) VALUES (?, ?)');
    $insert->execute([(int) $user['id'], $comment]);
    zt_json(['message' => 'Tu comentario se publicó correctamente.'], 201);
}

function zt_youtube_video_id(string $url): string|false|null
{
    if ($url === '') {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts)
        || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || !in_array(strtolower((string) ($parts['host'] ?? '')), ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtu.be'], true)) {
        return false;
    }
    $videoId = null;
    if (str_ends_with((string) $parts['host'], 'youtu.be')) {
        $videoId = trim((string) ($parts['path'] ?? ''), '/');
    } elseif (preg_match('~^/(?:embed|shorts|live)/([A-Za-z0-9_-]{11})/?$~', (string) ($parts['path'] ?? ''), $matches)) {
        $videoId = $matches[1];
    } else {
        parse_str((string) ($parts['query'] ?? ''), $query);
        $videoId = $query['v'] ?? null;
    }
    return is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) ? $videoId : false;
}

function zt_update_product(array $input): never
{
    $productId = filter_var($input['productId'] ?? null, FILTER_VALIDATE_INT);
    $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $offerPriceInput = trim((string) ($input['offerPrice'] ?? ''));
    $offerPrice = $offerPriceInput === '' ? null : filter_var($offerPriceInput, FILTER_VALIDATE_FLOAT);
    $name = trim((string) ($input['name'] ?? ''));
    $category = trim((string) ($input['category'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $features = trim((string) ($input['features'] ?? ''));
    $offerLabel = trim((string) ($input['offerLabel'] ?? ''));
    $stock = filter_var($input['stock'] ?? null, FILTER_VALIDATE_INT);
    $videoId = zt_youtube_video_id(trim((string) ($input['videoUrl'] ?? '')));
    $products = zt_catalog_products();
    $categories = ['Celulares', 'Laptops', 'Audio', 'Wearables', 'Gaming', 'Accesorios', 'Software'];
    if ($productId === false || !isset($products[$productId])
        || $price === false || $price <= 0 || $price > 99999999.99
        || ($offerPriceInput !== '' && ($offerPrice === false || $offerPrice <= 0 || $offerPrice >= $price))
        || mb_strlen($name) < 2 || mb_strlen($name) > 200
        || !in_array($category, $categories, true)
        || mb_strlen($description) < 2 || mb_strlen($description) > 255
        || mb_strlen($features) < 2 || mb_strlen($features) > 5000
        || mb_strlen($offerLabel) > 40
        || $stock === false || $stock < 0 || $stock > 1000000
        || $videoId === false) {
        zt_json(['error' => 'Revisa los datos del producto, el stock (de 0 a 1,000,000) y la URL de YouTube. El precio de oferta debe ser menor que el precio normal.'], 422);
    }
    $price = round((float) $price, 2);
    $offerPrice = $offerPrice === null ? null : round((float) $offerPrice, 2);
    if ($offerPrice !== null && $offerPrice >= $price) {
        zt_json(['error' => 'El precio de oferta debe ser menor que el precio normal.'], 422);
    }
    $imageName = null;
    $savedPath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK || $_FILES['image']['size'] < 1 || $_FILES['image']['size'] > 5 * 1024 * 1024 || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            zt_json(['error' => 'La imagen debe pesar como máximo 5 MB.'], 422);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['image']['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            zt_json(['error' => 'Usa una imagen JPG, PNG o WebP.'], 422);
        }
        $directory = zt_data_dir() . DIRECTORY_SEPARATOR . 'products';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento de imágenes de productos.');
        }
        $imageName = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        $savedPath = $directory . DIRECTORY_SEPARATOR . $imageName;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $savedPath)) {
            zt_json(['error' => 'No se pudo guardar la imagen del producto.'], 500);
        }
    }
    $db = zt_db();
    try {
        $db->beginTransaction();
        if (!empty($products[$productId]['custom'])) {
            $query = $db->prepare('UPDATE productos_personalizados SET price = ? WHERE id = ?');
            $query->execute([$price, $productId]);
        } else {
            $query = $db->prepare('INSERT INTO precios_productos (product_id, price) VALUES (?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)');
            $query->execute([$productId, $price]);
        }
        $query = $db->prepare('INSERT INTO personalizaciones_productos (product_id, name, category, description, features, offer_price, offer_label, image_filename, video_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), description = VALUES(description), features = VALUES(features), offer_price = VALUES(offer_price), offer_label = VALUES(offer_label), image_filename = COALESCE(VALUES(image_filename), image_filename), video_id = VALUES(video_id)');
        $query->execute([$productId, $name, $category, $description, $features, $offerPrice, $offerLabel, $imageName, $videoId]);
        $query = $db->prepare('INSERT INTO stock_productos (product_id, stock) VALUES (?, ?) ON DUPLICATE KEY UPDATE stock = VALUES(stock)');
        $query->execute([$productId, $stock]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($savedPath !== null) {
            @unlink($savedPath);
        }
        throw $error;
    }
    zt_json(['ok' => true, 'productId' => $productId, 'price' => $price, 'effectivePrice' => $offerPrice ?? $price]);
}

function zt_create_product(array $input): never
{
    $name = trim((string) ($input['name'] ?? ''));
    $category = trim((string) ($input['category'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $features = trim((string) ($input['features'] ?? ''));
    $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $stock = filter_var($input['stock'] ?? null, FILTER_VALIDATE_INT);
    $offerPriceInput = trim((string) ($input['offerPrice'] ?? ''));
    $offerPrice = $offerPriceInput === '' ? null : filter_var($offerPriceInput, FILTER_VALIDATE_FLOAT);
    $offerLabel = trim((string) ($input['offerLabel'] ?? ''));
    $videoId = zt_youtube_video_id(trim((string) ($input['videoUrl'] ?? '')));
    $categories = ['Celulares', 'Laptops', 'Audio', 'Wearables', 'Gaming', 'Accesorios', 'Software'];
    if (mb_strlen($name) < 2 || mb_strlen($name) > 200
        || !in_array($category, $categories, true)
        || $price === false || $price <= 0 || $price > 99999999.99
        || $stock === false || $stock < 0 || $stock > 1000000
        || ($offerPriceInput !== '' && ($offerPrice === false || $offerPrice <= 0 || $offerPrice >= $price))
        || mb_strlen($offerLabel) > 40
        || mb_strlen($description) < 2 || mb_strlen($description) > 255
        || mb_strlen($features) < 2 || mb_strlen($features) > 5000
        || $videoId === false || $videoId === null) {
        zt_json(['error' => 'Revisa el nombre, categoría, precio, stock (de 0 a 1,000,000), descripción, características y URL de video de YouTube del producto.'], 422);
    }
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        zt_json(['error' => 'Selecciona una imagen JPG, PNG o WebP de hasta 5 MB.'], 422);
    }
    $image = $_FILES['image'];
    if ($image['size'] < 1 || $image['size'] > 5 * 1024 * 1024 || !is_uploaded_file($image['tmp_name'])) {
        zt_json(['error' => 'La imagen debe pesar como máximo 5 MB.'], 422);
    }
    $price = round((float) $price, 2);
    $offerPrice = $offerPrice === null ? null : round((float) $offerPrice, 2);
    if ($offerPrice !== null && $offerPrice >= $price) {
        zt_json(['error' => 'El precio de oferta debe ser menor que el precio normal.'], 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($image['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        zt_json(['error' => 'Usa una imagen JPG, PNG o WebP.'], 422);
    }

    $directory = zt_data_dir() . DIRECTORY_SEPARATOR . 'products';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo preparar el almacenamiento de imágenes de productos.');
    }
    $fileName = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
    $filePath = $directory . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($image['tmp_name'], $filePath)) {
        zt_json(['error' => 'No se pudo guardar la imagen del producto.'], 500);
    }
    try {
        $db = zt_db();
        $db->beginTransaction();
        $query = $db->prepare('INSERT INTO productos_personalizados (name, category, price, description, features, image_filename) VALUES (?, ?, ?, ?, ?, ?)');
        $query->execute([$name, $category, round((float) $price, 2), $description, $features, $fileName]);
        $productId = (int) $db->lastInsertId();
        $query = $db->prepare('INSERT INTO personalizaciones_productos (product_id, name, category, description, features, offer_price, offer_label, video_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $query->execute([$productId, $name, $category, $description, $features, $offerPrice === null ? null : round((float) $offerPrice, 2), $offerLabel, $videoId]);
        $query = $db->prepare('INSERT INTO stock_productos (product_id, stock) VALUES (?, ?)');
        $query->execute([$productId, $stock]);
        $db->commit();
    } catch (Throwable $error) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        @unlink($filePath);
        throw $error;
    }
    zt_json(['ok' => true, 'productId' => $productId], 201);
}

function zt_serve_product_image(): never
{
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(404);
        exit;
    }
    $query = zt_db()->prepare('SELECT COALESCE(o.image_filename, c.image_filename) AS image_filename FROM productos_personalizados c LEFT JOIN personalizaciones_productos o ON o.product_id = c.id WHERE c.id = ? UNION ALL SELECT image_filename FROM personalizaciones_productos WHERE product_id = ? AND image_filename IS NOT NULL LIMIT 1');
    $query->execute([$id, $id]);
    $fileName = $query->fetchColumn();
    if (!$fileName || basename((string) $fileName) !== $fileName) {
        http_response_code(404);
        exit;
    }
    $file = zt_data_dir() . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($file)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($file));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

function zt_create_order(array $input, array $user): void
{
    $address = trim((string) ($input['address'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $location = zt_delivery_location($input);
    $paymentMethods = [
        'yape' => 'Yape',
        'plin' => 'Plin',
        'transfer' => 'Transferencia bancaria',
    ];
    $payment = (string) ($input['payment'] ?? '');
    $items = json_decode((string) ($input['items'] ?? ''), true);
    if (mb_strlen($address) < 5 || mb_strlen($address) > 250 || mb_strlen($phone) < 6 || mb_strlen($phone) > 30) {
        zt_json(['error' => 'Revisa tu dirección y número de teléfono.'], 422);
    }
    if (!isset($paymentMethods[$payment])) {
        zt_json(['error' => 'Selecciona un método de pago válido.'], 422);
    }
    $catalog = zt_catalog_products();
    if (!is_array($items) || !$items || count($items) > count($catalog)) {
        zt_json(['error' => 'El carrito está vacío o no es válido.'], 422);
    }
    if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
        zt_json(['error' => 'Adjunta la captura de tu comprobante de pago.'], 422);
    }

    $receipt = $_FILES['receipt'];
    if ($receipt['size'] < 1 || $receipt['size'] > 5 * 1024 * 1024 || !is_uploaded_file($receipt['tmp_name'])) {
        zt_json(['error' => 'La imagen debe pesar como máximo 5 MB.'], 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($receipt['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        zt_json(['error' => 'Usa una captura en formato JPG, PNG o WebP.'], 422);
    }

    $subtotal = 0.0;
    $normalizedItems = [];
    foreach ($items as $productId => $quantity) {
        if (!ctype_digit((string) $productId) || !isset($catalog[(int) $productId]) || !is_numeric($quantity)) {
            zt_json(['error' => 'El carrito contiene un producto no válido.'], 422);
        }
        $quantity = (int) $quantity;
        if ($quantity < 1 || $quantity > 10) {
            zt_json(['error' => 'La cantidad por producto debe ser de 1 a 10.'], 422);
        }
        $product = $catalog[(int) $productId];
        $unitPrice = (float) $product['effective_price'];
        $subtotal = round($subtotal + $unitPrice * $quantity, 2);
        $normalizedItems[] = [(int) $productId, $product['name'], $quantity, $unitPrice];
    }

    $pricing = zt_order_totals($subtotal);
    $discount = $pricing['discount'];
    $shipping = $pricing['shipping'];
    $total = $pricing['total'];
    $directory = zt_data_dir() . DIRECTORY_SEPARATOR . 'receipts';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo preparar el almacenamiento de comprobantes.');
    }
    $fileName = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
    $filePath = $directory . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($receipt['tmp_name'], $filePath)) {
        zt_json(['error' => 'No se pudo guardar la captura. Inténtalo de nuevo.'], 500);
    }

    $db = zt_db();
    try {
        $db->beginTransaction();
        $unavailableProduct = zt_reserve_order_stock($db, $normalizedItems);
        if ($unavailableProduct !== null) {
            $db->rollBack();
            @unlink($filePath);
            zt_json(['error' => "No hay stock suficiente de $unavailableProduct para completar el pedido. Actualiza el carrito e inténtalo de nuevo."], 409);
        }
        $trackingCode = 'ZYP-' . strtoupper(bin2hex(random_bytes(5)));
        $query = $db->prepare("INSERT INTO pedidos (usuario_id, metodo_pago, direccion, telefono, subtotal, costo_envio, total, ruta_comprobante, estado, departamento, provincia, distrito, ubigeo_departamento, ubigeo_provincia, ubigeo_distrito, transportista, codigo_seguimiento, estado_envio) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente_pago')");
        $query->execute([$user['id'], $paymentMethods[$payment], $address, $phone, $subtotal, $shipping, $total, $fileName, $location['departamento'], $location['provincia'], $location['distrito'], $location['ubigeo_departamento'], $location['ubigeo_provincia'], $location['ubigeo_distrito'], $location['transportista'], $trackingCode]);
        $orderId = (int) $db->lastInsertId();
        $trackingEvent = $db->prepare("INSERT INTO seguimiento_pedidos (pedido_id, estado, detalle) VALUES (?, 'pendiente_pago', 'Comprobante recibido; pago pendiente de revisión')");
        $trackingEvent->execute([$orderId]);
        $itemQuery = $db->prepare('INSERT INTO detalle_pedido (pedido_id, producto_id, nombre_producto, cantidad, precio_unitario) VALUES (?, ?, ?, ?, ?)');
        $reservationQuery = $db->prepare('INSERT INTO reservas_stock_pedidos (pedido_id, producto_id, cantidad) VALUES (?, ?, ?)');
        foreach ($normalizedItems as [$productId, $productName, $quantity, $price]) {
            $itemQuery->execute([$orderId, $productId, $productName, $quantity, $price]);
            $reservationQuery->execute([$orderId, $productId, $quantity]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        @unlink($filePath);
        throw $error;
    }

    zt_json(['orderId' => $orderId, 'subtotal' => $subtotal, 'discount' => $discount, 'shipping' => $shipping, 'total' => $total], 201);
}

function zt_resume_order(array $user): never
{
    $orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$orderId) {
        zt_json(['error' => 'Pedido no encontrado.'], 404);
    }
    $query = zt_db()->prepare("SELECT p.id, p.metodo_pago AS payment_method, p.direccion AS address, p.telefono AS phone, p.departamento, p.provincia, p.distrito, p.ubigeo_departamento AS department_code, p.ubigeo_provincia AS province_code, p.ubigeo_distrito AS district_code, p.subtotal, p.costo_envio AS shipping_fee, p.total FROM pedidos p WHERE p.id = ? AND p.usuario_id = ? AND p.estado = 'pendiente' AND NOT EXISTS (SELECT 1 FROM cancelaciones_pedido c WHERE c.pedido_id = p.id)");
    $query->execute([$orderId, $user['id']]);
    $order = $query->fetch();
    if (!$order) {
        zt_json(['error' => 'Este pedido ya no está pendiente o no pertenece a tu cuenta.'], 404);
    }
    $paymentCodes = ['Yape' => 'yape', 'Plin' => 'plin', 'Transferencia bancaria' => 'transfer'];
    if (!isset($paymentCodes[$order['payment_method']])) {
        zt_json(['error' => 'Este pedido usa un método anterior. Contacta a soporte para continuar.'], 409);
    }
    $items = zt_db()->prepare('SELECT producto_id AS product_id, cantidad AS quantity FROM detalle_pedido WHERE pedido_id = ?');
    $items->execute([$orderId]);
    $order['payment_code'] = $paymentCodes[$order['payment_method']];
    $order['items'] = $items->fetchAll();
    zt_json(['order' => $order]);
}

function zt_update_receipt(array $input, array $user): void
{
    $location = zt_delivery_location($input);
    $orderId = filter_var($input['orderId'] ?? null, FILTER_VALIDATE_INT);
    if (!$orderId) {
        zt_json(['error' => 'Pedido no encontrado.'], 404);
    }
    $query = zt_db()->prepare("SELECT p.ruta_comprobante FROM pedidos p WHERE p.id = ? AND p.usuario_id = ? AND p.estado = 'pendiente' AND NOT EXISTS (SELECT 1 FROM cancelaciones_pedido c WHERE c.pedido_id = p.id)");
    $query->execute([$orderId, $user['id']]);
    $previousReceipt = $query->fetchColumn();
    if (!$previousReceipt) {
        zt_json(['error' => 'Este pedido ya no está pendiente o no pertenece a tu cuenta.'], 404);
    }
    if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
        zt_json(['error' => 'Adjunta la nueva captura del comprobante.'], 422);
    }
    $receipt = $_FILES['receipt'];
    if ($receipt['size'] < 1 || $receipt['size'] > 5 * 1024 * 1024 || !is_uploaded_file($receipt['tmp_name'])) {
        zt_json(['error' => 'La imagen debe pesar como máximo 5 MB.'], 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($receipt['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        zt_json(['error' => 'Usa una captura en formato JPG, PNG o WebP.'], 422);
    }
    $directory = zt_data_dir() . DIRECTORY_SEPARATOR . 'receipts';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo preparar el almacenamiento de comprobantes.');
    }
    $fileName = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
    $filePath = $directory . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($receipt['tmp_name'], $filePath)) {
        zt_json(['error' => 'No se pudo guardar la nueva captura. Inténtalo de nuevo.'], 500);
    }
    $db = zt_db();
    try {
        $db->beginTransaction();
        $query = $db->prepare("SELECT p.ruta_comprobante FROM pedidos p WHERE p.id = ? AND p.usuario_id = ? AND p.estado = 'pendiente' AND NOT EXISTS (SELECT 1 FROM cancelaciones_pedido c WHERE c.pedido_id = p.id) FOR UPDATE");
        $query->execute([$orderId, $user['id']]);
        $currentReceipt = $query->fetchColumn();
        if ($currentReceipt === false) {
            $db->rollBack();
            @unlink($filePath);
            zt_json(['error' => 'El pedido cambió de estado y ya no se puede actualizar.'], 409);
        }
        $query = $db->prepare('SELECT producto_id, cantidad FROM reservas_stock_pedidos WHERE pedido_id = ? AND liberado_en IS NULL FOR UPDATE');
        $query->execute([$orderId]);
        $reservations = $query->fetchAll();
        if (!$reservations) {
            $query = $db->prepare('SELECT producto_id, nombre_producto, cantidad FROM detalle_pedido WHERE pedido_id = ? ORDER BY producto_id');
            $query->execute([$orderId]);
            $items = array_map(static fn(array $item): array => [(int) $item['producto_id'], (string) $item['nombre_producto'], (int) $item['cantidad']], $query->fetchAll());
            if (!$items) {
                $db->rollBack();
                @unlink($filePath);
                zt_json(['error' => 'No se encontraron productos para reservar el stock de este pedido. Contacta a soporte.'], 409);
            }
            $unavailableProduct = zt_reserve_order_stock($db, $items);
            if ($unavailableProduct !== null) {
                $db->rollBack();
                @unlink($filePath);
                zt_json(['error' => "No hay stock suficiente de $unavailableProduct para continuar con este pedido."], 409);
            }
            $insertReservation = $db->prepare('INSERT INTO reservas_stock_pedidos (pedido_id, producto_id, cantidad) VALUES (?, ?, ?)');
            foreach ($items as [$productId, , $quantity]) {
                $insertReservation->execute([$orderId, $productId, $quantity]);
            }
        }
        $update = $db->prepare("UPDATE pedidos SET ruta_comprobante = ?, departamento = ?, provincia = ?, distrito = ?, ubigeo_departamento = ?, ubigeo_provincia = ?, ubigeo_distrito = ?, transportista = ?, codigo_seguimiento = COALESCE(codigo_seguimiento, ?) WHERE id = ? AND usuario_id = ? AND estado = 'pendiente' AND NOT EXISTS (SELECT 1 FROM cancelaciones_pedido c WHERE c.pedido_id = pedidos.id)");
        $update->execute([$fileName, $location['departamento'], $location['provincia'], $location['distrito'], $location['ubigeo_departamento'], $location['ubigeo_provincia'], $location['ubigeo_distrito'], $location['transportista'], 'ZYP-' . strtoupper(bin2hex(random_bytes(5))), $orderId, $user['id']]);
        if ($update->rowCount() !== 1) {
            $db->rollBack();
            @unlink($filePath);
            zt_json(['error' => 'El pedido cambió de estado y ya no se puede actualizar.'], 409);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        @unlink($filePath);
        throw $error;
    }
    if (basename((string) $currentReceipt) === $currentReceipt) {
        @unlink($directory . DIRECTORY_SEPARATOR . $currentReceipt);
    }
    zt_json(['ok' => true, 'orderId' => $orderId]);
}

function zt_cancel_pending_order(array $input, array $admin): void
{
    $orderId = filter_var($input['orderId'] ?? null, FILTER_VALIDATE_INT);
    if (!$orderId) {
        zt_json(['error' => 'Pedido no encontrado.'], 422);
    }
    $db = zt_db();
    try {
        $db->beginTransaction();
        $query = $db->prepare("SELECT estado FROM pedidos WHERE id = ? AND NOT EXISTS (SELECT 1 FROM cancelaciones_pedido WHERE pedido_id = pedidos.id) FOR UPDATE");
        $query->execute([$orderId]);
        if ($query->fetchColumn() !== 'pendiente') {
            $db->rollBack();
            zt_json(['error' => 'Solo se pueden cancelar pedidos pendientes que no estén cancelados.'], 409);
        }
        $query = $db->prepare("INSERT INTO cancelaciones_pedido (pedido_id, administrador_id, motivo) VALUES (?, ?, 'Cancelado por administración')");
        $query->execute([$orderId, $admin['id']]);
        zt_release_order_stock($db, $orderId);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    zt_json(['ok' => true, 'orderId' => $orderId]);
}

function zt_update_tracking(array $input, array $admin): void
{
    $orderId = filter_var($input['orderId'] ?? null, FILTER_VALIDATE_INT);
    $nextStatus = (string) ($input['status'] ?? '');
    $deliveryCode = trim((string) ($input['deliveryCode'] ?? ''));
    $detail = trim((string) ($input['detail'] ?? ''));
    $transitions = [
        'preparando' => ['en_transito', 'incidencia'],
        'en_transito' => ['en_reparto', 'incidencia'],
        'en_reparto' => ['entregado', 'incidencia'],
        'incidencia' => ['en_transito', 'en_reparto'],
    ];
    $labels = [
        'en_transito' => 'Pedido enviado',
        'en_reparto' => 'Pedido en reparto',
        'entregado' => 'Pedido entregado',
        'incidencia' => 'Incidencia en la entrega',
    ];
    if (!$orderId || !isset($labels[$nextStatus]) || ($nextStatus === 'incidencia' && ($detail === '' || mb_strlen($detail) > 255))) {
        zt_json(['error' => 'La actualización del seguimiento no es válida. Describe la incidencia si corresponde.'], 422);
    }
    $db = zt_db();
    try {
        $db->beginTransaction();
        $query = $db->prepare("SELECT p.estado, p.estado_envio, p.codigo_entrega FROM pedidos p WHERE p.id = ? AND NOT EXISTS (SELECT 1 FROM cancelaciones_pedido c WHERE c.pedido_id = p.id) FOR UPDATE");
        $query->execute([$orderId]);
        $order = $query->fetch();
        if (!$order || $order['estado'] !== 'aprobado' || !in_array($nextStatus, $transitions[$order['estado_envio']] ?? [], true)) {
            $db->rollBack();
            zt_json(['error' => 'No se puede aplicar ese estado al pedido. Actualiza la lista e inténtalo de nuevo.'], 409);
        }
        if ($nextStatus === 'entregado' && ($order['codigo_entrega'] === null || $deliveryCode === '' || !hash_equals((string) $order['codigo_entrega'], $deliveryCode))) {
            $db->rollBack();
            zt_json(['error' => 'El código de entrega no coincide. Pide al cliente el código que recibió en su cuenta.'], 422);
        }
        if ($nextStatus === 'incidencia') {
            $eventDetail = $detail;
        } else {
            $eventDetail = $labels[$nextStatus];
        }
        if ($nextStatus === 'en_transito' && $order['codigo_entrega'] === null) {
            $update = $db->prepare('UPDATE pedidos SET estado_envio = ?, codigo_entrega = ? WHERE id = ?');
            $update->execute([$nextStatus, (string) random_int(100000, 999999), $orderId]);
        } else {
            $update = $db->prepare('UPDATE pedidos SET estado_envio = ? WHERE id = ?');
            $update->execute([$nextStatus, $orderId]);
        }
        $event = $db->prepare('INSERT INTO seguimiento_pedidos (pedido_id, administrador_id, estado, detalle) VALUES (?, ?, ?, ?)');
        $event->execute([$orderId, $admin['id'], $nextStatus, $eventDetail]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    zt_json(['ok' => true, 'orderId' => $orderId]);
}

function zt_get_orders(array $user): void
{
    $db = zt_db();
    $deliveryCodeSelect = $user['role'] === 'admin' ? 'NULL AS delivery_code' : 'p.codigo_entrega AS delivery_code';
    if ($user['role'] === 'admin') {
        $customers = $db->query("SELECT id, nombre AS name, correo AS email, telefono AS phone FROM usuarios WHERE rol = 'cliente' ORDER BY nombre")->fetchAll();
        $orders = $db->query("SELECT p.id, p.usuario_id AS user_id, p.metodo_pago AS payment_method, p.direccion AS address, p.telefono AS phone, p.departamento, p.provincia, p.distrito, p.transportista, p.codigo_seguimiento AS tracking_code, p.estado_envio AS shipping_status, $deliveryCodeSelect, p.subtotal, p.costo_envio AS shipping_fee, p.total, p.ruta_comprobante AS receipt_path, u.nombre AS customer_name, u.correo AS customer_email, p.creado_en AS created_at, CASE WHEN c.pedido_id IS NOT NULL THEN 'cancelled' WHEN p.estado = 'pendiente' THEN 'pending' WHEN p.estado = 'aprobado' THEN 'approved' WHEN p.estado = 'rechazado' THEN 'rejected' END AS display_status, c.motivo AS cancellation_reason, c.creado_en AS cancelled_at FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id LEFT JOIN cancelaciones_pedido c ON c.pedido_id = p.id ORDER BY p.creado_en DESC, p.id DESC")->fetchAll();
    } else {
        $customers = [];
        $query = $db->prepare("SELECT p.id, p.usuario_id AS user_id, p.metodo_pago AS payment_method, p.direccion AS address, p.telefono AS phone, p.departamento, p.provincia, p.distrito, p.transportista, p.codigo_seguimiento AS tracking_code, p.estado_envio AS shipping_status, $deliveryCodeSelect, p.subtotal, p.costo_envio AS shipping_fee, p.total, p.ruta_comprobante AS receipt_path, u.nombre AS customer_name, u.correo AS customer_email, p.creado_en AS created_at, CASE WHEN c.pedido_id IS NOT NULL THEN 'cancelled' WHEN p.estado = 'pendiente' THEN 'pending' WHEN p.estado = 'aprobado' THEN 'approved' WHEN p.estado = 'rechazado' THEN 'rejected' END AS display_status, c.motivo AS cancellation_reason, c.creado_en AS cancelled_at FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id LEFT JOIN cancelaciones_pedido c ON c.pedido_id = p.id WHERE p.usuario_id = ? ORDER BY p.creado_en DESC, p.id DESC");
        $query->execute([$user['id']]);
        $orders = $query->fetchAll();
    }
    $eventsByOrder = [];
    if ($orders) {
        $orderIds = array_map(static fn(array $order): int => (int) $order['id'], $orders);
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $eventQuery = $db->prepare("SELECT pedido_id, estado, detalle, creado_en AS created_at FROM seguimiento_pedidos WHERE pedido_id IN ($placeholders) ORDER BY creado_en, id");
        $eventQuery->execute($orderIds);
        foreach ($eventQuery->fetchAll() as $event) {
            $eventsByOrder[(int) $event['pedido_id']][] = $event;
        }
    }
    $itemsByOrder = zt_order_items($orders);
    foreach ($orders as &$order) {
        $order['items'] = $itemsByOrder[(int) $order['id']] ?? [];
        $order['tracking_events'] = $eventsByOrder[(int) $order['id']] ?? [];
        $order['has_receipt'] = !empty($order['receipt_path']);
        $order['discount'] = max(0, round((float) $order['subtotal'] + (float) $order['shipping_fee'] - (float) $order['total'], 2));
        $order['status'] = $order['display_status'];
        unset($order['display_status']);
        unset($order['receipt_path']);
    }
    unset($order);

    if ($user['role'] === 'admin') {
        $grouped = [];
        foreach ($customers as $customer) {
            $customer['orders'] = [];
            $grouped[(int) $customer['id']] = $customer;
        }
        foreach ($orders as $order) {
            $grouped[(int) $order['user_id']]['orders'][] = $order;
        }
        zt_json(['customers' => array_values($grouped)]);
    }
    zt_json(['orders' => $orders]);
}

function zt_serve_receipt(array $user): never
{
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(404);
        exit;
    }
    $query = zt_db()->prepare("SELECT p.ruta_comprobante FROM pedidos p WHERE p.id = ? AND (? = 'admin' OR p.usuario_id = ?)");
    $query->execute([$id, $user['role'], $user['id']]);
    $fileName = $query->fetchColumn();
    if (!$fileName || basename((string) $fileName) !== $fileName) {
        http_response_code(404);
        exit;
    }
    $file = zt_data_dir() . DIRECTORY_SEPARATOR . 'receipts' . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($file)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($file));
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: ' . (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline') . '; filename="comprobante-pedido-' . $id . '.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

try {
    $action = (string) ($_GET['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'oauth_start') {
            try {
                zt_oauth_start((string) ($_GET['provider'] ?? ''));
            } catch (Throwable $error) {
                error_log('ZyP TECH OAuth start failed: ' . $error->getMessage());
                try {
                    zt_auth_redirect('error', $error->getMessage());
                } catch (RuntimeException $redirectError) {
                    throw $error;
                }
            }
        }
        if ($action === 'oauth_callback') {
            zt_oauth_callback((string) ($_GET['provider'] ?? ''));
        }
        if ($action === 'session') {
            zt_json(['user' => zt_current_user(), 'csrf' => $_SESSION['csrf']]);
        }
        if ($action === 'catalog') {
            zt_get_catalog();
        }
        if ($action === 'comments') {
            zt_get_customer_comments();
        }
        if ($action === 'product_image') {
            zt_serve_product_image();
        }
        if ($action === 'orders') {
            zt_get_orders(zt_require_user());
        }
        if ($action === 'resume_order') {
            zt_resume_order(zt_require_user());
        }
        if ($action === 'receipt') {
            zt_serve_receipt(zt_require_user());
        }
        zt_json(['error' => 'Solicitud no válida.'], 404);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        zt_json(['error' => 'Método no permitido.'], 405);
    }
    $input = zt_input();
    zt_require_csrf($input);

    if ($action === 'register') {
        zt_register($input);
    }
    if ($action === 'login') {
        zt_login($input);
    }
    if ($action === 'phone_send_code') {
        zt_send_phone_code($input);
    }
    if ($action === 'phone_verify_code') {
        zt_verify_phone_code($input);
    }
    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        zt_json(['user' => null, 'csrf' => $_SESSION['csrf']]);
    }
    if ($action === 'order') {
        zt_create_order($input, zt_require_user());
    }
    if ($action === 'comment') {
        zt_create_customer_comment($input, zt_require_user());
    }
    if ($action === 'update_receipt') {
        zt_update_receipt($input, zt_require_user());
    }
    if ($action === 'review') {
        $admin = zt_require_admin();
        $orderId = filter_var($input['orderId'] ?? null, FILTER_VALIDATE_INT);
        $status = $input['status'] ?? '';
        if (!$orderId || !in_array($status, ['approved', 'rejected'], true)) {
            zt_json(['error' => 'Revisión no válida.'], 422);
        }
        $db = zt_db();
        $db->beginTransaction();
        try {
            $nextOrderStatus = $status === 'approved' ? 'aprobado' : 'rechazado';
            $shippingStatus = $status === 'approved' ? 'preparando' : 'pendiente_pago';
            $query = $db->prepare("UPDATE pedidos SET estado = ?, estado_envio = ? WHERE id = ? AND estado = 'pendiente' AND NOT EXISTS (SELECT 1 FROM cancelaciones_pedido c WHERE c.pedido_id = pedidos.id)");
            $query->execute([$nextOrderStatus, $shippingStatus, $orderId]);
            if (!$query->rowCount()) {
                $db->rollBack();
                zt_json(['error' => 'No se encontró ese pedido.'], 404);
            }
            if ($status === 'approved') {
                $event = $db->prepare("INSERT INTO seguimiento_pedidos (pedido_id, administrador_id, estado, detalle) VALUES (?, ?, 'preparando', 'Pago aprobado; pedido preparando')");
                $event->execute([$orderId, $admin['id']]);
            } else {
                zt_release_order_stock($db, $orderId);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        zt_json(['ok' => true]);
    }
    if ($action === 'cancel_order') {
        zt_cancel_pending_order($input, zt_require_admin());
    }
    if ($action === 'update_tracking') {
        zt_update_tracking($input, zt_require_admin());
    }
    if ($action === 'update_product') {
        zt_require_admin();
        zt_update_product($input);
    }
    if ($action === 'create_product') {
        zt_require_admin();
        zt_create_product($input);
    }
    zt_json(['error' => 'Solicitud no válida.'], 404);
} catch (Throwable $error) {
    error_log('ZUMBATECH API: ' . $error->getMessage());
    zt_json(['error' => 'Ocurrió un error interno. Revisa la configuración del servidor.'], 500);
}