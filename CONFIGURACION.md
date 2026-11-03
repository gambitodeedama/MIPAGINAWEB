# ZyP TECH: cuentas y comprobantes

## Primer arranque

1. Inicia Apache y MySQL desde el panel de XAMPP.
2. La tienda usa MariaDB/MySQL, la base de datos `zumbatech` y las tablas `usuarios`, `usuarios_identidades`, `verificaciones_telefono`, `comentarios_clientes`, `pedidos`, `detalle_pedido`, `cancelaciones_pedido`, `precios_productos`, `productos_personalizados` y `personalizaciones_productos`. Las tablas de identidad guardan accesos externos y verificaciones de teléfono; los comentarios guardan las opiniones publicadas por clientes; las tablas del catálogo guardan precios y cambios de productos. Si las tablas todavía no existen, importa [base_datos.sql](./base_datos.sql) desde phpMyAdmin. El script usa `CREATE TABLE IF NOT EXISTS` y no borra datos existentes.
3. Comprueba la conexión y las tablas desde PowerShell:

```powershell
Set-Location 'C:\xampp\htdocs\MIPAGINAWEB'
& 'C:\xampp\php\php.exe' 'probar_conexion.php'
```

La conexión usa por defecto `127.0.0.1`, base `zumbatech`, usuario `root` y contraseña vacía, como una instalación local estándar de XAMPP. Si tu instalación usa otros valores, configura `ZUMBATECH_DB_HOST`, `ZUMBATECH_DB_NAME`, `ZUMBATECH_DB_USER` y `ZUMBATECH_DB_PASS` en Apache y en la terminal.

## Acceso a la cuenta

El formulario de cuenta usa correo y contraseña. **Iniciar sesión** y **Crear cuenta** son formularios separados; el acceso de administrador permanece en su pestaña. Google, Microsoft y teléfono no se ofrecen como métodos de acceso. Al iniciar sesión o crear una cuenta, se muestra una confirmación animada antes de abrir el panel correspondiente. La autenticación utiliza sesiones locales de PHP y no requiere credenciales OAuth ni configuración de Twilio. Las tablas heredadas de proveedores y verificaciones pueden permanecer en la base de datos sin afectar el acceso por correo.

4. En PowerShell, crea una cuenta administradora (puedes repetir el comando para crear cuentas adicionales):

```powershell
Set-Location 'C:\xampp\htdocs\MIPAGINAWEB'
& 'C:\xampp\php\php.exe' 'create_admin.php'
```

El comando solicita el correo y la contraseña del administrador. Cada cuenta debe usar un correo distinto. No hay una clave predeterminada ni se puede crear una cuenta administradora desde el formulario público.

5. Abre la tienda en `http://localhost/MIPAGINAWEB/ZyP_TECH___Tienda_de_tecnolog%C3%ADa.html`, pulsa el icono de cuenta e inicia sesión con el correo y la contraseña elegidos al ejecutar el comando. Las cuentas administradoras acceden al panel de administración desde el mismo formulario de ingreso; la opción **Registrarse** solo crea cuentas de cliente.
6. Los clientes crean sus propias cuentas desde **Crear cuenta**. Para pedir, deben iniciar sesión, indicar teléfono y dirección, seleccionar departamento, provincia y distrito, elegir Yape/Plin o transferencia y adjuntar la captura. La lista de ubicaciones se sirve localmente desde `ubigeos_peru.json`; proviene de [RitchieRD/ubigeos-peru-data](https://github.com/RitchieRD/ubigeos-peru-data), licencia CC0, commit `23cd3666b19f32bccdf36d90243c2a4462d6202c`. Al registrar un pedido se muestra el resumen del cliente, ubicación, productos, método de pago e importe, con opción de descargarlo en PDF.
7. El comprobante descargable tiene el logo y la identidad de ZyP TECH S.A.C., datos de contacto, cliente, productos, cantidades, precios, total y estado del pago. **Descargar PDF** guarda el archivo directamente; **Imprimir** abre la vista de impresión por separado. Es un documento informativo de pedido y pago, no una boleta fiscal ni una confirmación automática del pago.
8. Tras iniciar sesión como administrador, el **Dashboard** muestra las unidades y productos más vendidos a partir de pedidos aprobados, y resume los pedidos cancelados y rechazados. El menú **Ver pedidos** permite revisar comprobantes, aprobar u observar pagos y cancelar pedidos pendientes. Al aprobar el pago, el pedido pasa a **Preparando pedido**. Para Lima Metropolitana (provincia de Lima) se asigna motorizado; para los demás destinos, incluido Callao, se asigna Shalom. En **Ver pedidos**, el administrador actualiza manualmente el seguimiento a **En tránsito**, **En reparto**, **Entregado** o registra una incidencia. Al marcar el pedido en tránsito, se genera un código de entrega de seis dígitos que el cliente consulta en **Mi cuenta → Mis pedidos** y debe comunicar al repartidor. Para confirmar **Entregado**, el administrador solicita y valida ese código. El cliente ve el código de envío y el historial; con la ventana de cuenta abierta, los estados se consultan automáticamente cada 15 segundos. Es seguimiento actualizado por la tienda, no una conexión en tiempo real con los sistemas de Shalom. Las columnas nuevas y la tabla `seguimiento_pedidos` se migran sin borrar pedidos anteriores al abrir la aplicación. En la tienda, el botón **Modificar** abre la ficha editable del producto. El menú **Productos** permite cambiar nombre, categoría, precio, descripción, características, video de YouTube e imagen, o añadir un precio y etiqueta de oferta. La oferta debe ser menor al precio normal y se aplica al carrito y al pedido. También se pueden subir productos nuevos indicando una URL de video de YouTube; las modificaciones se guardan en MariaDB y las imágenes fuera de `htdocs`.
9. En **Comprobantes de pago** se muestran las imágenes enviadas, agrupadas por cliente, con opciones para abrirlas o descargarlas. **Pedidos no pagados** y **Pedidos cancelados** también aparecen separados por cliente. Solo se pueden cancelar pedidos pendientes; la ficha conserva la fecha y el motivo, y ya no permite reanudar el pago. En **Todos los pedidos por cliente** se puede guardar el resumen del pedido como PDF.

## Métodos de pago

El checkout tiene dos pasos: datos del pedido, luego instrucciones de pago y captura. La transferencia muestra la cuenta BCP y el CCI configurados en el checkout. Yape usa el archivo `yape_qr.jpeg` y Plin usa `plinqr.jpeg`, ambos ubicados en la carpeta del proyecto. Mientras falte un QR, ese método no permite finalizar el pedido.

La captura queda pendiente de revisión administrativa. La página todavía no recibe confirmaciones automáticas de Niubiz; no marca una transferencia como pagada por la sola captura.

## Inventario

Al habilitar el inventario, cada producto existente inicia con 10 unidades si todavía no tenía un stock registrado. Los productos nuevos permiten definir sus existencias desde el formulario de administración. El catálogo muestra las unidades disponibles y no permite agregar al carrito más unidades que las existentes. Al registrar un pedido, el servidor reserva el stock dentro de la misma transacción que crea el pedido; si no alcanza, no registra el pedido. Al rechazar o cancelar un pedido pendiente, las unidades reservadas se devuelven una sola vez. Al aprobarlo, la reserva queda consumida. El administrador puede ajustar las unidades disponibles desde **Administrar productos**.

## Productos nuevos

Se añadieron iPhone 18 Pro, Samsung Galaxy S26 Ultra, Xiaomi Redmi Note 15 Pro+ 5G, HONOR 600 Pro y ASUS ROG Strix G16 (2026). Los precios iniciales son referencias editables desde **Administración → Productos**, no cotizaciones ni confirmaciones de disponibilidad. Revisa el almacenamiento, color y SKU/configuración regional de cada equipo antes de aceptar pedidos.

## Datos y seguridad

Las cuentas y pedidos se guardan en MariaDB/MySQL, en la base de datos `zumbatech`. Las capturas quedan en `C:\xampp\zumbatech-data\receipts`, fuera de `htdocs`. Las capturas admitidas son JPG, PNG y WebP de hasta 5 MB. Las contraseñas se guardan cifradas con `password_hash`, y solo una sesión administradora puede abrir los comprobantes.

El sitio registra y revisa comprobantes, pero no procesa pagos ni confirma transacciones con Yape, Plin o bancos. No subas imágenes con números completos de tarjetas, claves, códigos de seguridad ni otros datos secretos.

Esta configuración es para desarrollo local con XAMPP. Antes de publicarla en Internet se necesita HTTPS, protección contra intentos repetidos de inicio de sesión, política de privacidad y copias de seguridad protegidas.