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

Requisitos: PHP 8.3+ con la extension `mysqli` habilitada y MySQL en ejecucion.

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

## Cron

Ejecutar cada minuto:

```cron
*/1 * * * * php /ruta/al/proyecto/cron/sync_orders.php
```

## Endpoints

- `GET /` dashboard.
- `POST /sync` ejecuta sincronizacion manual.
- `GET /health` devuelve estado de configuracion.
