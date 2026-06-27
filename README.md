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

## Automatizacion

La automatizacion completa ejecuta:

1. Lee ordenes nuevas de JTL.
2. Aplica los mapeos JTL -> Packiyo customer.
3. Crea las ordenes en Packiyo.
4. Lee fulfillments/tracking desde Packiyo.
5. Agrega el tracking al delivery note de JTL para que el marketplace pueda recibirlo.

Para usarla en un subdominio, el servidor donde corre esta app debe poder conectarse a `JTL_BASE_URL`.
Si JTL-Wawi esta en una PC local, usa una VPN/tunel privado o instala esta app/agente en la misma red. No expongas `:5883` publicamente sin firewall y TLS controlado.

Cron CLI recomendado en el servidor:

```cron
*/5 * * * * php /ruta/al/proyecto/cron/automation.php
```

Alternativa para cron HTTP del hosting:

```bash
curl -fsS -H "X-Automation-Token: $AUTOMATION_TOKEN" https://subdominio.tu-dominio.com/automation/run
```

Variables:

```env
AUTOMATION_TOKEN=<token-largo-random>
AUTOMATION_SYNC_CUSTOMERS=false
AUTOMATION_FULFILLMENT_LIMIT=200
```

El endpoint HTTP queda deshabilitado si `AUTOMATION_TOKEN` esta vacio.

El tracking hacia JTL requiere que la app registrada tenga scopes `deliverynotes.read` y `deliverynotes.write`, y que JTL ya tenga un `Lieferschein`/delivery note para la orden. Si no existe delivery note, la corrida registra el error y no puede marcar tracking.

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

El endpoint `/automation/run` no usa la sesion del navegador; sigue protegido por `AUTOMATION_TOKEN` para que el cron del hosting pueda ejecutarlo.

Cron antiguo, solo ordenes JTL -> Packiyo:

```cron
*/1 * * * * php /ruta/al/proyecto/cron/sync_orders.php
```

## Endpoints

- `GET /` dashboard.
- `GET|POST /login` login del dashboard.
- `GET|POST /logout` cierra sesion.
- `GET|POST /invite` crea usuario usando un token de invitacion.
- `GET|POST /automation/run` ejecuta el ciclo completo protegido por `AUTOMATION_TOKEN`.
- `POST /sync` ejecuta sincronizacion manual.
- `POST /sync/order` sincroniza una sola orden JTL por ID interno o numero de orden.
- `POST /jtl/register` inicia el registro de la app en JTL-Wawi.
- `POST /jtl/register/complete` recupera y guarda el API token.
- `POST /jtl/order-sources/detect` detecta tiendas/canales presentes en las ordenes JTL actuales.
- `POST /packiyo/customers/sync` actualiza el cache de clientes Packiyo.
- `POST /packiyo/customers/activate` activa un cliente cacheado.
- `POST /packiyo/customers/deactivate` desactiva un cliente cacheado.
- `POST /packiyo/customer-mappings` guarda un mapeo JTL -> Packiyo customer.
- `POST /packiyo/customer-mappings/delete` elimina un mapeo.
- `POST /products/import` importa productos seleccionados de Packiyo a JTL.
- `POST /settings` guarda ajustes de `.env` desde la tab Ajustes.
- `POST /users/invite` crea invitaciones de usuario.
- `POST /users/invite/revoke` revoca invitaciones pendientes.
- `GET /health` devuelve estado de configuracion.
