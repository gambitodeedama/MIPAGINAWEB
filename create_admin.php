<?php
declare(strict_types=1);
 
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
 
require __DIR__ . '/app.php';
 
$email = strtolower(trim((string) readline('Correo del administrador: ')));
$password = (string) readline('Contraseña (mínimo 12 caracteres): ');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($password) > 200) {
    fwrite(STDERR, "Correo no válido o contraseña fuera del rango permitido.\n");
    exit(1);
}
 
$query = zt_db()->prepare("INSERT INTO usuarios (nombre, correo, contrasena_hash, rol) VALUES ('Administración', ?, ?, 'administrador')");
try {
    $query->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
} catch (PDOException $error) {
    if ($error->getCode() === '23000') {
        fwrite(STDERR, "Ya existe una cuenta con ese correo. Usa otro correo.\n");
        exit(1);
    }
    throw $error;
}
fwrite(STDOUT, "Cuenta administradora creada. Inicia sesión desde la página.\n");