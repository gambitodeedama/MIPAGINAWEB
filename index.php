<?php
declare(strict_types=1);

$matches = glob(__DIR__ . '/ZyP_TECH___Tienda_de_*.html') ?: [];
if ($matches === []) {
    http_response_code(404);
    echo 'Tienda no encontrada.';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
readfile($matches[0]);
