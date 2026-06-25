# JTL API Application Registration

## Objetivo

Registrar la aplicación **Lagera JTLSync** en JTL-Wawi para obtener un API Key permanente.

---

# Flujo de Registro

```text
1. Iniciar Wizard en JTL-Wawi
        ↓
2. JTL espera Registration Request
        ↓
3. Aplicación PHP envía POST /authentication
        ↓
4. JTL devuelve RegistrationRequestId
        ↓
5. Usuario aprueba en JTL
        ↓
6. Aplicación consulta GET /authentication/{id}
        ↓
7. JTL devuelve API Key
        ↓
8. Guardar API Key en base de datos
```

---

# Requisitos

## JTL

* API License activa.
* API Instance funcionando.
* Puerto 5883 abierto.
* Wizard abierto en:

```text
Admin → App Registrierung
```

* Pantalla "Registrierung beginnen" activa.

---

# Configuración de la Aplicación

Archivo:

```php
config/jtl.php
```

```php
return [

    'base_url' => 'https://0.0.0.0:5883',

    'app_id' => 'lagera-jtlsync',

    'app_version' => '1.0.0',

    'challenge_code' => 'lagera2026'

];
```

---

# Registration Request

Endpoint:

```http
POST /authentication
```

Headers:

```http
x-appid: lagera-jtlsync
x-appversion: 1.0.0
api-version: 2.0
x-challengecode: lagera2026
Content-Type: application/json
```

Body:

```json
{
  "AppId": "lagera-jtlsync",
  "DisplayName": "Lagera JTL Sync",
  "Description": "Synchronization between JTL and Packiyo",
  "Version": "1.0.0",
  "ProviderName": "Lagera 3PL Germany GmbH",
  "ProviderWebsite": "https://3plgermany.com",
  "MandatoryApiScopes": [
    "salesorders.read",
    "salesorders.write",
    "items.read"
  ],
  "OptionalApiScopes": []
}
```

---

# Ejemplo PHP

```php
<?php

$body = [
    "AppId" => "lagera-jtlsync",
    "DisplayName" => "Lagera JTL Sync",
    "Description" => "Synchronization between JTL and Packiyo",
    "Version" => "1.0.0",
    "ProviderName" => "Lagera 3PL Germany GmbH",
    "ProviderWebsite" => "https://3plgermany.com",
    "MandatoryApiScopes" => [
        "salesorders.read",
        "salesorders.write",
        "items.read"
    ],
    "OptionalApiScopes" => []
];

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => "https://0.0.0.0:5883/authentication",
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "x-appid: lagera-jtlsync",
        "x-appversion: 1.0.0",
        "api-version: 2.0",
        "x-challengecode: lagera2026"
    ],
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_SSL_VERIFYPEER => false
]);

$response = curl_exec($ch);

curl_close($ch);

echo $response;
```

---

# Respuesta Esperada

```json
{
  "registrationRequestId": "abc123"
}
```

Guardar:

```php
$registrationRequestId
```

---

# Aprobación en JTL

Cuando la request llegue correctamente:

* El wizard avanzará automáticamente.
* JTL mostrará los datos de la aplicación.
* El usuario aprobará permisos.
* Finalizará el asistente.

---

# Obtener API Key

Endpoint:

```http
GET /authentication/{registrationRequestId}
```

Headers:

```http
x-challengecode: lagera2026
```

Ejemplo:

```php
GET /authentication/abc123
```

---

# Respuesta Esperada

```json
{
  "apiKey": "xxxxxxxxxxxxxxxxxxxxxxxx",
  "grantedScopes": [
    "salesorders.read",
    "salesorders.write",
    "items.read"
  ]
}
```

---

# Guardar API Key

Tabla:

```sql
CREATE TABLE jtl_api_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_key TEXT,
    created_at DATETIME
);
```

Guardar inmediatamente.

Importante:

La API Key sólo puede recuperarse una vez.

---

# Headers para llamadas posteriores

Todas las llamadas futuras deberán incluir:

```http
Authorization: Wawi <API_KEY>

x-appid: lagera-jtlsync

x-appversion: 1.0.0

api-version: 2.0
```

---

# Primer Test

```http
GET /salesOrders
```

Si responde 200:

```text
Aplicación registrada correctamente.
```
