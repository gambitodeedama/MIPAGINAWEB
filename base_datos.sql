CREATE DATABASE IF NOT EXISTS zumbatech
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE zumbatech;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(120) NOT NULL,
    correo VARCHAR(190) NULL,
    telefono VARCHAR(16) NULL,
    contrasena_hash VARCHAR(255) NOT NULL,
    rol ENUM('cliente', 'administrador') NOT NULL DEFAULT 'cliente',
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_correo (correo),
    UNIQUE KEY uq_usuarios_telefono (telefono)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios_identidades (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NOT NULL,
    proveedor VARCHAR(20) NOT NULL,
    subject_id VARCHAR(255) NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_identidad_proveedor_subject (proveedor, subject_id),
    UNIQUE KEY uq_identidad_usuario_proveedor (usuario_id, proveedor),
    CONSTRAINT fk_identidad_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verificaciones_telefono (
    telefono VARCHAR(16) NOT NULL,
    codigo_hash VARCHAR(255) NOT NULL,
    session_hash CHAR(64) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    enviado_en DATETIME NOT NULL,
    expira_en DATETIME NOT NULL,
    intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (telefono)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedidos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NOT NULL,
    metodo_pago VARCHAR(40) NOT NULL,
    direccion VARCHAR(500) NOT NULL,
    telefono VARCHAR(30) NOT NULL,
    subtotal DECIMAL(10, 2) NOT NULL,
    costo_envio INT NOT NULL,
    total DECIMAL(10, 2) NOT NULL,
    ruta_comprobante VARCHAR(255) NOT NULL,
    estado ENUM('pendiente', 'aprobado', 'rechazado') NOT NULL DEFAULT 'pendiente',
    departamento VARCHAR(80) NOT NULL DEFAULT '',
    provincia VARCHAR(80) NOT NULL DEFAULT '',
    distrito VARCHAR(80) NOT NULL DEFAULT '',
    ubigeo_departamento CHAR(2) NOT NULL DEFAULT '',
    ubigeo_provincia CHAR(4) NOT NULL DEFAULT '',
    ubigeo_distrito CHAR(6) NOT NULL DEFAULT '',
    transportista VARCHAR(30) NOT NULL DEFAULT '',
    codigo_seguimiento VARCHAR(20) NULL,
    codigo_entrega VARCHAR(10) NULL,
    estado_envio VARCHAR(20) NOT NULL DEFAULT 'pendiente_pago',
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pedidos_codigo_seguimiento (codigo_seguimiento),
    KEY idx_pedidos_usuario (usuario_id, creado_en),
    KEY idx_pedidos_estado (estado),
    CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seguimiento_pedidos (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS detalle_pedido (
    pedido_id INT UNSIGNED NOT NULL,
    producto_id INT NOT NULL,
    nombre_producto VARCHAR(200) NOT NULL,
    cantidad INT NOT NULL,
    precio_unitario DECIMAL(10, 2) NOT NULL,
    PRIMARY KEY (pedido_id, producto_id),
    CONSTRAINT fk_detalle_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cancelaciones_pedido (
    pedido_id INT UNSIGNED NOT NULL,
    administrador_id INT UNSIGNED NOT NULL,
    motivo VARCHAR(500) NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (pedido_id),
    KEY fk_cancel_admin (administrador_id),
    CONSTRAINT fk_cancel_admin FOREIGN KEY (administrador_id) REFERENCES usuarios (id),
    CONSTRAINT fk_cancel_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS precios_productos (
    product_id INT UNSIGNED NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS productos_personalizados (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(60) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    features TEXT NOT NULL,
    image_filename VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB AUTO_INCREMENT=1000 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personalizaciones_productos (
    product_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(60) NOT NULL,
    description VARCHAR(255) NOT NULL,
    features TEXT NOT NULL,
    offer_price DECIMAL(10, 2) NULL,
    offer_label VARCHAR(40) NOT NULL DEFAULT '',
    image_filename VARCHAR(100) NULL,
    video_id CHAR(11) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_productos (
    product_id INT UNSIGNED NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO stock_productos (product_id, stock) VALUES
    (0, 10), (1, 10), (2, 10), (3, 10), (4, 10), (5, 10), (6, 10),
    (7, 10), (8, 10), (9, 10), (10, 10), (11, 10), (12, 10), (13, 10),
    (14, 10), (15, 10), (16, 10), (17, 10), (18, 10), (19, 10), (20, 10),
    (21, 10), (22, 10), (23, 10), (24, 10), (25, 10);

CREATE TABLE IF NOT EXISTS reservas_stock_pedidos (
    pedido_id INT UNSIGNED NOT NULL,
    producto_id INT UNSIGNED NOT NULL,
    cantidad INT UNSIGNED NOT NULL,
    liberado_en DATETIME NULL,
    PRIMARY KEY (pedido_id, producto_id),
    KEY idx_reservas_stock_producto (producto_id),
    CONSTRAINT fk_reserva_stock_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comentarios_clientes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NOT NULL,
    comentario TEXT NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_comentarios_clientes_fecha (creado_en),
    KEY idx_comentarios_clientes_usuario (usuario_id, creado_en),
    CONSTRAINT fk_comentario_cliente_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
