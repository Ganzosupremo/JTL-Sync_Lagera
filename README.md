# Lagera JTL Sync

Aplicacion PHP para sync JTL -> Packiyo.

## Incluido en fase 1

- Lectura de pedidos nuevos desde JTL.
- Verificacion de duplicados en `order_mappings`.
- Creacion de pedidos en Packiyo.
- Guardado de relacion JTL -> Packiyo.
- Logs de sincronizacion.
- Dashboard basico y cron de pedidos.

## Instalacion local

Requisitos: PHP 8.3+ con la extension `mysqli` habilitada, MySQL en ejecucion y `curl` u `openssl` para llamadas HTTPS a JTL-Wawi.

1. Copia `.env.example` a `.env`.
2. Completa las credenciales de JTL y Packiyo.
3. Crea la base de datos MySQL:

```sql
CREATE DATABASE jtlsync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

4. Completa las variables `DB_*` en `.env`.
5. Ejecuta:

```bash
php scripts/install.php
```

6. Sirve la carpeta `public/` desde Laragon o ejecuta el servidor embebido:

```bash
php -S localhost:8080 -t public
```

## Registro JTL-Wawi

JTL-Wawi debe tener activa la pantalla `Admin -> App Registrierung` y la API escuchando en el puerto `5883`.

Aunque JTL escuche en `0.0.0.0:5883`, desde esta app local se llama normalmente a `127.0.0.1:5883`. Si JTL-Wawi esta en otra maquina, usa la IP real de esa maquina en `JTL_BASE_URL`.

La app usa por defecto:

```env
JTL_BASE_URL=https://127.0.0.1:5883
JTL_AUTH_TYPE=wawi
JTL_SSL_VERIFY=false
JTL_API_VERSION=1.0
JTL_APP_ID=lagera-jtlsync
JTL_APP_ICON=<base64-png>
JTL_CHALLENGE_CODE=lagera2026
```

Desde el dashboard:

1. Pulsa `Registrar app en JTL`.
2. Aprueba los permisos en JTL-Wawi.
3. Pulsa `Obtener API token`.

El token se guarda en MySQL en `jtl_api_credentials` y las llamadas posteriores usan `Authorization: Wawi <API_KEY>`.

## Packiyo

Packiyo usa JSON:API. Las llamadas se envian con:

```env
PACKIYO_MEDIA_TYPE=application/vnd.api+json
PACKIYO_ORDER_CHANNEL_NAME=JTL-Wawi
PACKIYO_CUSTOMER_ID=
PACKIYO_CUSTOMERS_ENDPOINT=/customers
```

Si Packiyo exige relacionar cada pedido con un cliente concreto, completa `PACKIYO_CUSTOMER_ID` con el ID del customer en Packiyo.

Para varios clientes, usa las tabs `Clientes Packiyo` y `Mapeos` del dashboard.

En `Clientes Packiyo`, pulsa `Actualizar desde Packiyo` para cachear los customers actuales. La primera corrida trae todos; las siguientes usan `filter[updated_at_min]` con el ultimo cambio leido para pedir solo cambios nuevos. Los clientes desactivados se mueven a `Clientes inactivos` y no se usan para enviar pedidos a Packiyo.

En `Mapeos`, cada regla asigna pedidos JTL a un `Packiyo customer ID` por:

- `marketplace`
- `sales_channel`
- `shop`
- `customer_number`
- `customer_id`
- `email`
- `company`
- `default`

Tambien puedes pulsar `Detectar tiendas desde JTL` en `Mapeos` para leer las ordenes actuales y cachear valores de JTL como `shop=Temu EsSo`. Desde esa tabla se puede crear el mapeo directo al customer Packiyo activo, por ejemplo `Temu EsSo` -> `EsSo`.

El payload de Packiyo se envia con:

```json
"relationships": {
  "customer": {
    "data": {
      "type": "customers",
      "id": "PACKIYO_CUSTOMER_ID"
    }
  }
}
```

## Productos Packiyo -> JTL

La tab `Productos` permite importar articulos de Packiyo a JTL por cliente, no de forma masiva.

Flujo:

1. Selecciona un cliente Packiyo activo, por ejemplo `EsSo #46`.
2. Ingresa el `JTL category ID` donde se crearan los articulos.
3. Pulsa `Cargar productos`.
4. Marca solo los productos que quieres importar.
5. Pulsa `Importar seleccionados a JTL`.

La app usa `GET /products` en Packiyo con `filter[customer]=CUSTOMER_ID`. Antes de crear un articulo en JTL, busca si el SKU ya existe con `GET /items?searchKeyWord=SKU`; si existe, solo guarda el mapeo local. Si no existe, crea el articulo con `POST /items`.

Variables relacionadas:

```env
PACKIYO_PRODUCTS_ENDPOINT=/products
JTL_ITEMS_ENDPOINT=/api/eazybusiness/items
JTL_ITEM_ENDPOINT=/api/eazybusiness/items/{id}
JTL_STOCKS_ENDPOINT=/api/eazybusiness/stocks
JTL_PRODUCT_IMPORT_CATEGORY_ID=
JTL_PRODUCT_IMPORT_WAREHOUSE_ID=
```

Scopes JTL necesarios para productos:

```env
items.read,items.write,item.queryitems,item.createitem,item.updateitem,
inventories.read,inventories.write,stock.querystocksperitem,stock.stockadjustment
```

JTL no guarda stock dentro de `POST /items`. La app crea/relaciona articulos y, si hay `JTL warehouse ID`, ajusta stock con `POST /stocks` usando `quantity_on_hand` de Packiyo. Para evitar duplicados, primero lee el stock actual de JTL y manda solo la diferencia. Si un producto ya aparece como `importado`, puedes seleccionarlo otra vez para actualizar solo su stock.

## Revision y correccion de ordenes

La pestaña `Ordenes JTL` permite abrir `Ver detalles` antes de enviar una orden. Desde ahi se pueden corregir las direcciones, cambiar nombre/SKU/cantidad/precio de los articulos y agregar o quitar lineas. Los cambios se guardan localmente y no modifican la orden original en JTL.

Una linea sin SKU, o con un SKU provisional `JTL-LINE-...`, bloquea solamente esa orden. La automatizacion continua procesando las demas y reporta la orden como `requires_review`. Al guardar la correccion, la orden vuelve a ser elegible para la siguiente corrida.

La app intenta resolver una linea problematica en este orden:

1. Equivalencia de nombre BOL confirmada para el cliente Packiyo.
2. Coincidencia de alta confianza contra los nombres del catalogo Packiyo, considerando tokens compartidos, marcas, modelos y unidades.
3. Seleccion manual desde el detalle de la orden.

En `Mapeos -> Equivalencias de nombres BOL -> Packiyo` se pueden crear y eliminar reglas permanentes por cliente. Al corregir una linea desde una orden, `Recordar nombre BOL` guarda la misma regla automaticamente.

## Automatizacion

La automatizacion completa ejecuta:

1. Lee ordenes nuevas que JTL Worker ya importo a JTL.
2. Aplica los mapeos JTL -> Packiyo customer.
3. Crea las ordenes en Packiyo.
4. Lee fulfillments/tracking desde Packiyo.
5. Agrega el tracking al delivery note de JTL para que el marketplace pueda recibirlo.

Si una orden ya existe en Packiyo pero aun no existe en `order_mappings`, la app la busca por `external_id`/numero de orden, crea el mapeo local y la deja lista para el paso de fulfillment/tracking en vez de fallar por duplicado.

El marketplace abgleich no se dispara desde esta app. Configuralo en JTL-Wawi con JTL Worker 2.0:

1. Abre `Admin -> JTL-Worker-Status`.
2. Activa el abgleich de la tienda/marketplace que corresponda, por ejemplo `Temu EsSo`.
3. Usa un intervalo minimo de 5 minutos.
4. Presiona `Starten` y luego `Speichern`, o dejalo instalado como servicio de Windows.

Para usarla en un subdominio, el servidor donde corre esta app debe poder conectarse a `JTL_BASE_URL`.
Si JTL-Wawi esta en una PC local, usa una VPN/tunel privado o instala esta app/agente en la misma red. No expongas `:5883` publicamente sin firewall y TLS controlado.

Cron CLI recomendado en el servidor:

```cron
*/5 * * * * php /ruta/al/proyecto/cron/automation.php
```

Alternativa para cron HTTP del hosting:

```bash
curl -fsS -H "X-Automation-Token: $AUTOMATION_TOKEN" https://subdominio.tu-dominio.com/automation/tick
```

Variables:

```env
AUTOMATION_TOKEN=<token-largo-random>
AUTOMATION_ENABLED=true
AUTOMATION_INTERVAL_MINUTES=360
AUTOMATION_SYNC_CUSTOMERS=false
AUTOMATION_FULFILLMENT_LIMIT=200
```

`/automation/tick` respeta `AUTOMATION_INTERVAL_MINUTES`: puedes ejecutar el cron cada 5 minutos y la app solo correra el flujo completo cuando toque. `/automation/run` fuerza una corrida inmediata con el mismo token. El endpoint HTTP queda deshabilitado si `AUTOMATION_TOKEN` esta vacio.

El tracking hacia JTL requiere que la app registrada tenga scopes `deliverynotes.read` y `deliverynotes.write`, y que JTL ya tenga un `Lieferschein`/delivery note para la orden. Si no existe delivery note, la corrida registra el error y no puede marcar tracking.

JTL no tiene un campo de shipping-method/carrier estructurado en los paquetes del delivery note (`TrackingID`, `ShippedDate` y `Comment` son los unicos campos que la API acepta al crear un paquete; el `shippingMethodId` que JTL devuelve al leer un paquete es de solo lectura y lo asigna JTL internamente). Lo que BOL/el marketplace realmente necesita para ver la orden como fulfilled es que JTL dispare su evento de workflow interno "Shipped" sobre el delivery note (no solo que exista el paquete de tracking). Por eso, despues de mandar el tracking, la app llama automaticamente a `POST .../deliveryNotes/{id}/workflow/{workflowEventId}` con el evento `Shipped` (id fijo `3` en el enum de JTL: `1=Created, 2=Deleted, 3=Shipped`). Esto requiere el scope adicional `deliverynote.triggerdeliverynoteworkflow`. Si un error al marcar "Shipped" no es de conectividad, se registra como advertencia y no bloquea el tracking (que ya se envio). Se puede desactivar con `JTL_MARK_DELIVERY_NOTE_SHIPPED=false` si la instalacion de JTL no soporta este endpoint.

El boton manual "Buscar tracking nuevo ahora" del tab Fulfillment y el cron corren el mismo `FulfillmentSyncService::sync()`, que llama a Packiyo y JTL una orden a la vez. Con muchas ordenes pendientes esto puede tardar mas que el timeout del navegador o del proxy del hosting, asi que la corrida se detiene sola tras `FULFILLMENT_SYNC_TIME_BUDGET_SECONDS` (20s por defecto) y deja el resto para el siguiente click o el proximo tick del cron; el mensaje de resultado avisa cuando se detuvo por este motivo. Subi ese valor si tu servidor tolera peticiones mas largas y prefieres menos corridas parciales.

La tabla del tab Fulfillment solo muestra ordenes que ya pasaron por `fulfillment_syncs` (tracking enviado con exito, o reintentando el evento "Shipped"). Si una orden falla al enviar el tracking a JTL (por ejemplo, no hay un delivery note que le corresponda todavia), esa orden se guarda ahi con estado `failed` y el motivo, en vez de solo quedar en el log: asi no desaparece de la vista aunque siga fallando en cada corrida, y se sigue reintentando automaticamente (un estado `failed` no cuenta como terminado). Si filtras el tab por un cliente Packiyo y ves menos ordenes de las que esperabas, revisa si hay filas en estado `failed` para ese cliente antes de asumir que faltan ordenes por sincronizar.

## Autenticacion

La app puede proteger el dashboard y las acciones manuales con login de sesion. Los usuarios se guardan en MySQL en `app_users` y las contrasenas se guardan siempre con `password_hash`.

Variables:

```env
AUTH_ENABLED=true
AUTH_SESSION_NAME=jtlsync_session
AUTH_INVITATION_TTL_HOURS=72
```

Flujo recomendado:

1. Entra a `Ajustes -> Usuarios`.
2. Crea una invitacion para el email de la persona.
3. Copia el link generado y envialo por un canal privado.
4. La persona abre `/invite?token=...`, define usuario y password, y la app hashea el password automaticamente.
5. Activa `Requerir login` en `Ajustes -> Autenticacion`.

No hay endpoint de registro abierto. El endpoint `/invite` solo funciona con un token valido, no expirado y no revocado.

Como fallback/bootstrap, puedes definir `AUTH_USERNAME` y `AUTH_PASSWORD_HASH` manualmente en `.env`, pero el uso normal debe ser invitaciones en MySQL.

Los endpoints `/automation/tick` y `/automation/run` no usan la sesion del navegador; siguen protegidos por `AUTOMATION_TOKEN` para que el cron del hosting pueda ejecutarlos.

Cron antiguo, solo ordenes JTL -> Packiyo:

```cron
*/1 * * * * php /ruta/al/proyecto/cron/sync_orders.php
```

## Endpoints

- `GET /` dashboard.
- `GET|POST /login` login del dashboard.
- `GET|POST /logout` cierra sesion.
- `GET|POST /invite` crea usuario usando un token de invitacion.
- `GET|POST /automation/tick` ejecuta el ciclo completo solo si ya paso el intervalo configurado.
- `GET|POST /automation/run` fuerza el ciclo completo protegido por `AUTOMATION_TOKEN`.
- `POST /automation/manual` fuerza el ciclo completo desde el dashboard protegido por login.
- `POST /sync` ejecuta sincronizacion manual.
- `POST /sync/order` sincroniza una sola orden JTL por ID interno o numero de orden.
- `POST /jtl/orders/draft` guarda la correccion local de una orden y opcionalmente la envia.
- `POST /jtl/orders/draft/reset` descarta un borrador no enviado.
- `POST /jtl/register` inicia el registro de la app en JTL-Wawi.
- `POST /jtl/register/complete` recupera y guarda el API token.
- `POST /jtl/order-sources/detect` detecta tiendas/canales presentes en las ordenes JTL actuales.
- `POST /packiyo/customers/sync` actualiza el cache de clientes Packiyo.
- `POST /packiyo/customers/activate` activa un cliente cacheado.
- `POST /packiyo/customers/deactivate` desactiva un cliente cacheado.
- `POST /packiyo/customer-mappings` guarda un mapeo JTL -> Packiyo customer.
- `POST /packiyo/customer-mappings/delete` elimina un mapeo.
- `POST /packiyo/product-name-mappings` guarda una equivalencia nombre BOL -> producto Packiyo.
- `POST /packiyo/product-name-mappings/delete` elimina una equivalencia de nombre.
- `POST /products/import` importa productos seleccionados de Packiyo a JTL.
- `POST /settings` guarda ajustes de `.env` desde la tab Ajustes.
- `POST /users/invite` crea invitaciones de usuario.
- `POST /users/invite/revoke` revoca invitaciones pendientes.
- `GET /health` devuelve estado de configuracion.

## Correccion de ordenes Packiyo

La pestaña **Correccion de ordenes** analiza por lotes reanudables las ordenes de Packiyo desde la fecha elegida para localizar lineas `JTL-LINE-*`. Antes de iniciar permite elegir entre los clientes Packiyo activos y conserva la seleccion durante todos los lotes. Relaciona cada orden con JTL cuando esta disponible, identifica las copias locales no actualizadas, limita las sugerencias al catalogo del cliente y exige una asignacion manual individual o por grupo.

El flujo funciona en lectura/simulacion por defecto: vuelve a leer Packiyo antes de generar la previsualizacion y permite exportar un CSV auditable. La escritura remota sigue bloqueada hasta activar `PACKIYO_ORDER_CORRECTION_WRITE_ENABLED`, confirmar `PACKIYO_ORDER_CORRECTION_ATOMIC_CONFIRMED` y configurar un `PACKIYO_ORDER_CORRECTION_ATOMIC_ENDPOINT` probado. Nunca se usa una secuencia separada de anadir y borrar lineas; antes y despues de una escritura se validan el estado, el snapshot, el producto y las lineas no afectadas.
