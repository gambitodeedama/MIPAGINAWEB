<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/app.php';

try {
    $db = zt_db();
    fwrite(STDOUT, "Conexión a MariaDB correcta: " . $db->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL);

    foreach (['usuarios', 'usuarios_identidades', 'verificaciones_telefono', 'pedidos', 'detalle_pedido', 'cancelaciones_pedido', 'seguimiento_pedidos', 'precios_productos', 'productos_personalizados', 'personalizaciones_productos'] as $table) {
        $count = $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        fwrite(STDOUT, "Tabla $table: $count registro(s)" . PHP_EOL);
    }

    $legacyTable = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    foreach (['zt_product_prices', 'zt_custom_products', 'zt_product_overrides'] as $table) {
        $legacyTable->execute([$table]);
        if ((int) $legacyTable->fetchColumn() > 0) {
            throw new RuntimeException("La tabla antigua $table todavía existe.");
        }
    }

    $admins = (int) $db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'administrador'")->fetchColumn();
    fwrite(STDOUT, $admins > 0
        ? "Existe una cuenta administradora." . PHP_EOL
        : "Falta crear el administrador: ejecuta php create_admin.php." . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, "Error de conexión o esquema de MariaDB: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
