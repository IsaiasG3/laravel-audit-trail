#  Laravel Audit Trail

![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-FF2D20?style=flat-square&logo=laravel&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?style=flat-square&logo=mysql&logoColor=white)
![Testing](https://img.shields.io/badge/Testing-Pest-F22F46?style=flat-square&logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)

Sistema backend de auditoría para aplicaciones Laravel que necesitan mantener una **traza verificable de cambios críticos**, reconstruir estados históricos y detectar manipulaciones en los registros de auditoría.

La implementación utiliza eventos de Eloquent, procesamiento asíncrono, persistencia transaccional y una cadena de hashes para mantener la integridad de la bitácora.

**Objetivo**

Proporcionar un mecanismo de seguridad y cumplimiento normativo (compliance) para sectores altamente regulados. Garantiza que todo cambio en entidades críticas quede registrado de manera asíncrona y consistente, protegiendo la información sin penalizar el tiempo de respuesta de la aplicación principal.
> No es una implementación de Event Sourcing puro. El sistema utiliza un enfoque de auditoría inspirado en algunos de sus principios para permitir la reconstrucción histórica sin introducir un Event Store o una arquitectura CQRS completa.

---

## Caso de Uso Real: Billetera Virtual (Fintech) y Auditoría Normativa

Imagina una aplicación financiera donde los saldos de los usuarios (`Wallets`) cambian constantemente debido a recargas y transferencias. Por normativas de prevención de fraude, el sistema no puede limitarse a sobrescribir el saldo. Este paquete resuelve problemas críticos del negocio:

1. **Detección de Fraude Interno:** Si un administrador con acceso directo a la base de datos (ej. DBA) altera su propio saldo de `$100` a `$10,000` saltándose la aplicación, el sistema detectará que el `hash` criptográfico de la cadena se ha roto, levantando una alerta inmediata de manipulación de registros.
2. **Resolución de Disputas:** Un usuario reclama que el martes a las 10:00 AM tenía `$500` pero su saldo actual es `$200`. Utilizando el endpoint de *snapshot*, soporte técnico puede reconstruir el estado exacto de la billetera en ese timestamp y usar el endpoint *diff* para demostrar que se realizó un retiro legítimo de `$300` a las 10:05 AM.
3. **Rendimiento sin cuellos de botella:** Al ejecutar la auditoría mediante Laravel Queues estrictamente **después** del `COMMIT` exitoso de la transacción, las operaciones masivas de los usuarios no sufren latencia adicional.

---

##  Funcionalidades

* Registro automático de cambios sobre modelos críticos.
* Captura de `old_values` y `new_values`.
* Persistencia asíncrona mediante Laravel Queues.
* Procesamiento de auditoría después del `COMMIT`.
* Reconstrucción del estado de un registro en un momento determinado.
* Comparación entre diferentes puntos históricos mediante `diff`.
* Cadena global de hashes SHA-256.
* Detección de manipulación de registros.
* Idempotencia mediante UUID único por evento.
* Exclusión de información sensible.
* Registros de auditoría inmutables.
* Autorización por recurso.
* Pruebas automatizadas sobre los principales flujos e invariantes.

## Arquitectura

El flujo principal es:

```text
                    HTTP Request
                         │
                         ▼
                WalletController
                         │
                         ▼
                  WalletPolicy
                         │
                         ▼
                    Eloquent
                  Model::update()
                         │
                         ▼
                AuditableObserver
                         │
              captura old/new values
                         │
                         ▼
                   AuditChange
                         │
                         ▼
              StoreAuditLogJob
                         │
                    afterCommit()
                         │
                         ▼
                  Queue: audits
                         │
                         ▼
              AuditChainService
                         │
             ┌───────────┴───────────┐
             │                       │
       DB transaction          lockForUpdate()
             │                       │
             └───────────┬───────────┘
                         ▼
                     AuditLog
                         │
                 previous_hash
                         │
                       hash
```

Cada componente tiene una responsabilidad específica:

| Componente              | Responsabilidad                                                 |
| ----------------------- | --------------------------------------------------------------- |
| `Auditable`             | Marca los modelos que deben generar auditoría                   |
| `AuditableObserver`     | Detecta cambios y captura el estado antes de enviarlo a la cola |
| `AuditChange`           | DTO inmutable que representa el evento                          |
| `AuditPayloadSanitizer` | Filtra información sensible                                     |
| `StoreAuditLogJob`      | Persiste el evento de auditoría                                 |
| `AuditChainService`     | Gestiona la cadena hash y verifica su integridad                |
| `AuditLog`              | Representa el registro inmutable                                |
| `AuditTrailService`     | Reconstruye snapshots y calcula diferencias                     |
| `WalletPolicy`          | Controla el acceso a las Wallets                                |

##  Auditoría de cambios

Un modelo puede habilitar la auditoría utilizando el trait:

```php
use App\Concerns\Auditable;

class Wallet extends Model
{
    use Auditable;
}
```

Actualmente se registran los eventos:

```text
created
updated
deleted
```

Cada actualización almacena los valores afectados en:

```text
old_values
new_values
```

Por ejemplo:

```json
{
    "balance": "250.50",
    "status": "frozen"
}
```

En una actualización, `old_values` se obtiene mediante el estado original de Eloquent, mientras que `new_values` representa el estado actual después de la modificación.

El `AuditChange` se construye dentro del Observer antes de enviar el trabajo a la cola. De esta forma, la información original del modelo no depende del momento en que el worker procese el Job.

##  Consistencia transaccional

La auditoría se procesa mediante `afterCommit()`.

Esto evita registrar una operación que posteriormente sea revertida mediante un `ROLLBACK`.

Por ejemplo:

```php
DB::transaction(function () use ($wallet): void {
    $wallet->update([
        'balance' => 999,
    ]);

    throw new RuntimeException('Rollback');
});
```

El cambio de la Wallet se revierte y el evento de auditoría tampoco se persiste.

El flujo normal es:

```text
UPDATE
  │
  ▼
COMMIT
  │
  ▼
StoreAuditLogJob
  │
  ▼
AuditLog
```

La captura del cambio y su persistencia están separadas intencionalmente:

1. El Observer captura el estado inmediatamente.
2. El Job se ejecuta después del commit.
3. `AuditChainService` persiste el registro dentro de una transacción propia.

##  Integridad mediante Hash Chain

Los registros de auditoría forman una **cadena hash global**.

Cada registro contiene:

```text
previous_hash
hash
```

El hash se calcula a partir del hash anterior y del contenido relevante del evento:

```text
hash = SHA-256(previous_hash + payload)
```

El primer registro utiliza un hash génesis.

La cadena tiene esta estructura:

```text
Genesis
   │
   ▼
Event 1
   │
   ▼
Event 2
   │
   ▼
Event 3
   │
   ▼
Event 4
```

### Cadena global

La cadena no se separa por modelo.

Por ejemplo:

```text
User.created
      │
      ▼
Wallet.created
      │
      ▼
Wallet.updated
      │
      ▼
Wallet.updated
```

Esto permite comprobar la integridad de toda la secuencia de auditoría.

El estado de la cadena se mantiene en:

```text
audit_chain_state
```

y la creación de nuevos eventos utiliza:

```php
lockForUpdate()
```

para serializar el acceso al estado de la cadena cuando existen operaciones concurrentes.

## Inmutabilidad

Los registros de `audit_logs` no deben modificarse después de ser creados.

`AuditLog` bloquea las operaciones de actualización y eliminación a nivel del modelo:

```php
$log->save();
```

y:

```php
$log->delete();
```

sobre registros existentes generan una excepción.

La tabla tampoco utiliza `updated_at`. Los registros solamente mantienen:

```text
created_at
```

como referencia de persistencia.

> Esta protección se encuentra a nivel de aplicación. Para entornos con requerimientos de auditoría más estrictos puede complementarse con permisos de base de datos, almacenamiento WORM o una segunda capa de persistencia.

## Protección de información sensible

Los campos sensibles se excluyen antes de almacenarlos en `old_values` y `new_values`.

Entre los campos protegidos se encuentran:

```text
password
password_confirmation
remember_token
token
access_token
refresh_token
api_token
secret
client_secret
private_key
authorization
cookie
```

Los modelos también pueden definir campos adicionales:

```php
protected array $auditExcluded = [
    'campo_privado',
];
```

La sanitización se centraliza en `AuditPayloadSanitizer` para evitar duplicar esta lógica en cada modelo.

## Reconstrucción histórica

`AuditTrailService` permite reconstruir el estado de un modelo a partir de los eventos registrados.

Por ejemplo:

```text
10:00 → balance = 100
10:01 → balance = 200
10:02 → balance = 300
```

Una consulta al snapshot de las `10:01` devuelve:

```text
balance = 200
```

La reconstrucción se realiza aplicando cronológicamente los cambios almacenados.

Este mecanismo proporciona una forma de consultar estados históricos sin implementar un Event Store completo.

## Diff histórico

También es posible comparar dos estados históricos.

Ejemplo:

```text
Snapshot A
balance = 200

Snapshot B
balance = 300
```

El endpoint devuelve:

```json
{
    "diff": {
        "balance": {
            "from": 200,
            "to": 300
        }
    }
}
```

Endpoint:

```http
GET /api/wallets/{wallet}/diff?from={datetime}&to={datetime}
```

## Autorización

El acceso a las Wallets está protegido mediante `WalletPolicy`.

Un usuario solamente puede modificar o consultar información de una Wallet que le pertenece.

Actualmente se protegen:

```text
update
viewHistory
viewSnapshot
viewDiff
```

La autorización se aplica antes de consultar el historial o modificar el recurso.

## Procesamiento asíncrono

La persistencia de auditoría se realiza mediante:

```text
StoreAuditLogJob
```

utilizando la cola:

```text
audits
```

El Job cuenta con:

```text
tries: 3
backoff: 5 segundos
```

Además, está configurado para ejecutarse después del commit de la transacción.

Esto permite mantener la operación de negocio separada de la persistencia de la bitácora, sin perder los valores originales capturados durante el ciclo de vida del modelo.

## Pruebas

El proyecto incluye pruebas para los principales comportamientos del sistema:

* Encadenamiento de hashes.
* Detección de manipulación.
* Integridad de la cadena.
* Idempotencia mediante `event_uuid`.
* Exclusión de campos sensibles.
* Uso de la cola `audits`.
* Captura correcta de `old_values`.
* Captura correcta de `new_values`.
* Detección de actualizaciones sin cambios reales.
* Persistencia del Job.
* Inmutabilidad de `AuditLog`.
* Reconstrucción de snapshots.
* Rollback transaccional.
* Persistencia posterior al commit.
* Integración HTTP.
* Autorización del propietario.
* Bloqueo de acceso entre usuarios.
* Diff histórico.

Ejecutar las pruebas:

```bash
php artisan test
```

o:

```bash
./vendor/bin/pest
```

Estado actual:

```text
21 tests
51 assertions
0 failures
```

## Estructura

```text
app/
├── Concerns/
│   └── Auditable.php
│
├── DTOs/
│   └── AuditChange.php
│
├── Enums/
│   └── AuditEvent.php
│
├── Http/
│   ├── Controllers/
│   │   └── WalletController.php
│   │
│   └── Requests/
│       └── UpdateWalletBalanceRequest.php
│
├── Jobs/
│   └── StoreAuditLogJob.php
│
├── Models/
│   ├── AuditChainState.php
│   ├── AuditLog.php
│   ├── User.php
│   └── Wallet.php
│
├── Observers/
│   └── AuditableObserver.php
│
├── Policies/
│   └── WalletPolicy.php
│
├── Services/
│   ├── AuditChainService.php
│   └── AuditTrailService.php
│
└── Support/
    └── Audit/
        └── AuditPayloadSanitizer.php
```

## Instalación

### 1. Clonar el repositorio

```bash
git clone https://github.com/IsaiasG3/laravel-audit-trail
cd laravel-audit-trail
```

### 2. Instalar dependencias

```bash
composer install
```

### 3. Configurar el entorno

Copiar el archivo de entorno:

```bash
cp .env.example .env
```

Generar la clave de aplicación:

```bash
php artisan key:generate
```

Configurar las variables `DB_*` y la conexión de cola.

Para desarrollo local:

```env
QUEUE_CONNECTION=database
```

### 4. Ejecutar migraciones

```bash
php artisan migrate
```

### 5. Iniciar el worker

En una terminal:

```bash
php artisan queue:work --queue=audits --tries=3
```

### 6. Iniciar Laravel

En otra terminal:

```bash
php artisan serve
```

La aplicación estará disponible normalmente en:

```text
http://localhost:8000
```

## API

### Actualizar Wallet

```http
PUT /api/wallets/{wallet}
```

Ejemplo:

```bash
curl -X PUT http://localhost:8000/api/wallets/1 \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"balance":500}'
```

### Consultar historial

```http
GET /api/wallets/{wallet}/history
```

### Consultar snapshot

```http
GET /api/wallets/{wallet}/snapshot/{datetime}
```

### Consultar diferencias

```http
GET /api/wallets/{wallet}/diff?from={datetime}&to={datetime}
```

## Decisiones de diseño

| Decisión                         | Alternativa                  | Motivo                                              |
| -------------------------------- | ---------------------------- | --------------------------------------------------- |
| `Auditable` por modelo           | Observer global              | Hace explícito qué modelos requieren auditoría      |
| `AuditChange` como DTO inmutable | Arrays entre capas           | Mantiene un contrato claro y tipado                 |
| Captura en Observer              | Captura dentro del Job       | Permite conservar correctamente el estado original  |
| `afterCommit()`                  | Persistencia inmediata       | Evita auditar operaciones revertidas                |
| Cola dedicada `audits`           | Cola general                 | Aísla el procesamiento de auditoría                 |
| `AuditLog` inmutable             | Modelo Eloquent convencional | Protege la bitácora contra modificaciones           |
| Hash Chain                       | Registros independientes     | Permite detectar modificaciones en la secuencia     |
| Cadena global                    | Cadena por modelo            | Permite verificar la integridad de toda la bitácora |
| Tabla polimórfica                | Tabla por modelo             | Facilita incorporar nuevos modelos auditables       |
| Sanitización centralizada        | Filtrado individual          | Mantiene una política consistente de protección     |
| Policy por recurso               | Autorización en controlador  | Centraliza las reglas de acceso                     |

## Alcance y limitaciones

El sistema está orientado a auditoría y reconstrucción histórica. No pretende sustituir una plataforma completa de Event Sourcing.

Actualmente no incluye:

* Event Store distribuido.
* CQRS.
* Proyecciones distribuidas.
* Replay de eventos de dominio completos.
* Snapshots persistidos.
* Replicación de eventos.
* Kafka o RabbitMQ.
* Almacenamiento WORM.
* Firmas digitales externas.
* Rotación de claves criptográficas.
* Arquitectura multi-región.

La cadena SHA-256 permite **detectar modificaciones en los registros**, pero no constituye por sí sola una protección absoluta frente a un atacante que tenga control completo de la aplicación y de la base de datos.

## Stack técnico

- **Lenguaje:** PHP 8.2+
- **Framework:** Laravel (Eloquent ORM, Queues, Sanctum)
- **Base de datos:** MySQL / PostgreSQL (Transaccional)
- **Testing:** Pest PHP
- **Criptografía:** Algoritmo SHA-256 para integridad de la Hash Chain