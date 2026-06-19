# Lagera JTL ↔ Packiyo Sync

## Objetivo

Reemplazar la sincronización temporal basada en SQL + n8n por una integración propia utilizando la API oficial de JTL y la API de Packiyo.

La aplicación deberá:

* Leer pedidos nuevos desde JTL.
* Crear pedidos en Packiyo.
* Guardar la relación JTL ↔ Packiyo.
* Evitar duplicados.
* Permitir futuras sincronizaciones de:

  * Tracking Numbers
  * Estados de pedidos
  * Stock
  * Devoluciones

---

# Arquitectura

```text
JTL-Wawi API
        |
        v
+------------------+
|  Lagera JTLSync  |
|      PHP         |
+------------------+
        |
        +----------------+
        |                |
        v                v

Packiyo API      MySQL
                     |
                     v
              Order Mapping
```

---

# Fase 1 (MVP)

## Funcionalidad

Sincronización unidireccional:

```text
JTL
  ↓
Packiyo
```

Proceso:

1. Leer pedidos nuevos desde JTL.
2. Verificar si ya existe sincronización.
3. Crear pedido en Packiyo.
4. Guardar IDs en base de datos.
5. Registrar logs.

---

# Estructura del Proyecto

```text
jtlsync/

├── app/
│
├── app/Clients/
│   ├── JtlClient.php
│   └── PackiyoClient.php
│
├── app/Services/
│   ├── OrderSyncService.php
│   └── MappingService.php
│
├── app/Models/
│   ├── OrderMapping.php
│   └── SyncLog.php
│
├── app/Controllers/
│   ├── DashboardController.php
│   └── SyncController.php
│
├── storage/
│   └── logs/
│
├── config/
│   ├── jtl.php
│   ├── packiyo.php
│   └── database.php
│
├── public/
│   └── index.php
│
└── cron/
    └── sync_orders.php
```

---

# Base de Datos

## order_mappings

```sql
CREATE TABLE order_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,

    jtl_order_id VARCHAR(100) NOT NULL,
    jtl_order_number VARCHAR(100),

    packiyo_order_id VARCHAR(100) NOT NULL,
    packiyo_order_number VARCHAR(100),

    synced_at DATETIME NOT NULL,

    UNIQUE(jtl_order_id)
);
```

---

## sync_logs

```sql
CREATE TABLE sync_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,

    created_at DATETIME NOT NULL,

    level VARCHAR(20),

    source VARCHAR(50),

    message TEXT
);
```

---

# JtlClient

Responsabilidades:

* Autenticación API.
* Obtener pedidos.
* Obtener artículos.
* Obtener tracking.
* Obtener inventario.

Métodos iniciales:

```php
getOrders()
getOrder($id)
getOrderItems($id)
```

---

# PackiyoClient

Responsabilidades:

* Crear pedidos.
* Consultar pedidos.
* Consultar productos.
* Consultar tracking.

Métodos iniciales:

```php
createOrder()
getOrder()
findOrder()
```

---

# OrderSyncService

Proceso:

```text
Obtener pedidos JTL
       ↓
Buscar en order_mappings
       ↓
¿Existe?
 ├─ Sí → Ignorar
 └─ No
       ↓
Crear pedido Packiyo
       ↓
Guardar mapping
       ↓
Registrar log
```

---

# Dashboard

URL:

```text
https://jtlsync.lagera.com
```

Funciones:

## Resumen

* Última sincronización.
* Pedidos sincronizados hoy.
* Errores hoy.
* Estado API JTL.
* Estado API Packiyo.

## Pedidos

Tabla:

```text
JTL Order
Packiyo Order
Fecha
Estado
```

## Logs

Tabla:

```text
Fecha
Nivel
Mensaje
```

---

# Cron Jobs

## Sync Orders

Cada minuto.

```text
*/1 * * * *
```

Ejecuta:

```php
OrderSyncService::sync();
```

---

# Fase 2

Tracking:

```text
Packiyo
  ↓
Tracking Number
  ↓
JTL
```

---

# Fase 3

Inventario:

```text
Packiyo
  ↓
Stock
  ↓
JTL
```

---

# Fase 4

OMS Ligero Lagera

Agregar:

* Clientes
* Almacenes
* Dashboard de operaciones
* Alertas
* Métricas

---

# Hosting

## Desarrollo

```text
Lageron local
PHP
MySQL
```

## Producción

Subdominio:

```text
jtlsync.lagera.com
```

Servidor:

```text
PHP 8.3
MySQL
HTTPS
```

---
