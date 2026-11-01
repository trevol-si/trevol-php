# trevol/trevol-php

SDK de PHP para la **API de Trevol**: facturación electrónica, documento soporte, documento equivalente, nómina electrónica y eventos
RADIAN ante la DIAN (Colombia). Documentación: <https://platform.trevol.si/docs>.

> **In English.** PHP SDK for the Trevol API (Colombian e-invoicing, DIAN). PHP ≥ 8.2, Guzzle 7. Typed models and API clients generated from
> the OpenAPI spec (OpenAPI Generator, namespace `Trevol\Generado`), plus a thin hand-written layer (namespace `Trevol`): automatic
> `Idempotency-Key` (the same one on retries), retries with exponential backoff honouring `Retry-After`, typed exceptions by problem+json `code`,
> environment inferred from the key prefix (`tvl_test_` = DIAN test environment, `tvl_live_` = production), pinned `Trevol-Version`, Standard
> Webhooks signature verification, cursor pagination with generators and PDF/XML/ZIP downloads. Docs are in Spanish like the API.

**Versión 0.1.0** — API `2026-10-22`. Licencia MIT.

## Instalación

```sh
composer require trevol/trevol-php
```

Necesita PHP 8.2 o más nuevo, `ext-curl`, `ext-json` y `ext-mbstring`. Depende de [Guzzle](https://docs.guzzlephp.org) 7 y de la biblioteca
abierta [`standard-webhooks/standard-webhooks`](https://packagist.org/packages/standard-webhooks/standard-webhooks) (MIT).

## Uso

La llave de la API se crea en la consola de desarrolladores (<https://platform.trevol.si/llaves>) y se guarda en el entorno de tu **servidor**:
`TREVOL_API_KEY`. Una llave `tvl_test_…` llama a la habilitación de la DIAN; una `tvl_live_…`, a producción. El SDK deduce el entorno del
prefijo (`$trevol->environment`).

```php
use Trevol\Exception\TrevolException;
use Trevol\Exception\ValidationException;
use Trevol\Trevol;

require __DIR__ . '/vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY')); // tvl_test_… → $trevol->environment === 'test'

try {
    $factura = $trevol->invoices->createInvoice(
        invoice_input: [
            'external_id' => 'VENTA-2026-000123',
            'customer' => ['final_consumer' => true],
            'items' => [['description' => 'Café especial 500 g', 'quantity' => '1', 'unit_price' => '32000', 'taxes' => [['code' => '01', 'rate' => '19']]]],
            'payment' => ['form' => 'cash', 'methods' => [['code' => '10']]],
        ],
        idempotency_key: 'VENTA-2026-000123', // opcional: si falta, el SDK pone una y la repite en los reintentos
    );
    if ($factura->getStatus() === 'processing') {
        // 202: la DIAN no contestó; Trevol lo termina y avisa por webhook. NO crees otro documento para la misma venta.
    }
} catch (ValidationException $e) {
    foreach ($e->getErrors() as $x) {
        error_log($x['pointer'] . ' ' . $x['message']);
    }
} catch (TrevolException $e) {
    error_log($e->getTrevolCode() . ' ' . $e->getRequestId());
}

// Todas las páginas de un listado, perezosamente
foreach ($trevol->paginate(fn (?string $cursor) => $trevol->invoices->listInvoices(limit: 100, starting_after: $cursor)) as $f) {
    echo $f->getId(), ' ', $f->getStatus(), PHP_EOL;
}

// Los archivos llegan como \SplFileObject (un archivo temporal)
$pdf = $trevol->invoices->downloadInvoicePdf(id: 'inv_…');
```

Los nombres de los métodos son los del `operationId` de la [especificación](https://platform.trevol.si/docs/api) (`createInvoice`,
`listInvoices`, `retrieveInvoice`, `correctInvoice`, `sendInvoice`, `downloadInvoicePdf`…). **Usa argumentos con nombre**: el orden posicional
de los parámetros lo decide el generador y puede cambiar si la API suma un filtro opcional; los nombres no. El cuerpo es un arreglo con las
claves **iguales a las de la API** (`external_id`) o el modelo generado (`new \Trevol\Generado\Model\InvoiceInput([...])`); los importes y las
fechas viajan como texto (`'32000.50'`, `'2026-10-31'`). Un objeto JSON vacío se escribe `(object) []`.

## Lo que conviene saber

### Idempotencia: manda `external_id` y tu propia clave

El SDK genera una `Idempotency-Key` por llamada y **la repite en todos los reintentos de esa llamada**. Pero si tu proceso muere y vuelves a
llamar, saldrá otra clave y, si no mandaste `external_id` (es opcional), podría duplicarse el documento. Por eso:

- manda siempre `external_id` (tu id de la venta), y
- pasa una `idempotency_key` derivada de tu venta (`idempotency_key: 'VENTA-2026-000123'`).

Ante un `ConflictException` con `getTrevolCode() === 'external_id_in_use'`, el documento ya existe: usa `$e->getProblem()['existing_id']`. Toda
excepción lleva la clave que se usó (`getIdempotencyKey()`) para reintentar más tarde con la misma. Un `ApiConnectionException` también.

### Respuestas 202 (`processing`, `sending`, `validating`, `contingency`)

No son errores ni se reintentan: se devuelven como el modelo de la respuesta. **No crees otro documento**: espera el webhook o consulta con
`retrieveInvoice`; repetir la misma llamada con la misma `Idempotency-Key` continúa el mismo documento. El PDF y el ZIP llegan después del
`201` (evento `invoice.delivered`, o `delivery.status === 'completed'`).

### Reintentos

Hasta 2 reintentos (3 intentos) con espera creciente (0,5 s, 1 s, 2 s… con tope de 8 s y un poco de azar) ante un error de red, 429, 500,
502, 503, 504, un 409 `request_in_progress` o `previous_event_pending`, y todo problema que diga `retryable: true` (salvo el 402). Respeta
`Retry-After`; si pide más de `maxRetryAfter` (60 s) no espera y lanza la excepción con `getRetryAfter()`. Las operaciones sin
`Idempotency-Key` (crear un endpoint, `…/send`, `DELETE`) solo se reintentan con 429. Los 202 y los errores finales (400, 401, 402, 403, 404,
422 y los 409 `invalid_state`, `external_id_in_use`, `duplicate_document`) **nunca** se reintentan. Las esperas son bloqueantes (`usleep`),
también en los métodos `…Async`.

### La versión de la API

El SDK fija `Trevol-Version: 2026-10-22` en cada petición. **Ojo:** una llave creada con la versión `2026-10-21` que empiece a usar este SDK
pasa a `2026-10-22` (el encabezado manda): recibe `202 processing` donde antes recibía `503`. Si todavía no quieres ese cambio:
`new Trevol($llave, apiVersion: '2026-10-21')`. Más en <https://platform.trevol.si/docs/versiones>.

### Excepciones tipadas

`TrevolException` es la base (`Trevol\Exception\*`); las clases salen del `code` del problem+json: `AuthenticationException`,
`PermissionDeniedException`, `NotFoundException`, `InvalidRequestException`, `IdempotencyException`, `ValidationException` (con `getErrors()`:
`field`, `pointer`, `message`), `ConflictException`, `PreconditionException`, `DianRejectedException` (con `getProblem()['document']` y
`['rules']`), `BalanceException`, `RateLimitException`, `DianUnavailableException` e `InternalServerException`. Un `code` que el SDK todavía no
conoce llega como `TrevolException` con ese `getTrevolCode()`: nunca rompe. Además: `ApiConnectionException` (no hubo respuesta),
`ConfigurationException` (mal uso, antes de llamar) y `WebhookVerificationException` (`getReason()`: firma, fecha, cabeceras o cuerpo).
Cada excepción tiene `getStatus()`, `getTrevolCode()`, `getType()`, `getTitle()`, `getDetail()`, `getRequestId()`, `isRetryable()`,
`getRetryAfter()`, `getErrors()`, `getIdempotencyKey()`, `getProblem()` (el cuerpo completo) y `getResponseHeaders()`. Ninguna incluye tu llave
(el parámetro `$apiKey` va marcado `#[\SensitiveParameter]`: tampoco sale en un rastro de pila).

### Webhooks

```php
use Trevol\Exception\WebhookVerificationException;
use Trevol\Webhook;

$wh = new Webhook(getenv('TREVOL_WEBHOOK_SECRET')); // whsec_…
try {
    $evento = $wh->verify(file_get_contents('php://input'), getallheaders()); // el cuerpo CRUDO (Laravel: $request->getContent())
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}
// Deduplica por $evento->getId() (también llega en la cabecera webhook-id): la verificación no evita repeticiones.
$datos = $wh->eventData($evento); // el modelo según el type (p. ej. DocumentEventData); null si el tipo es nuevo
http_response_code(200);
```

La firma se calcula sobre los **bytes exactos**: no uses el cuerpo ya decodificado. `verify` acepta las cabeceras en cualquier caja
(`getallheaders()`, `$request->headers->all()`); la firma esperada la calcula la biblioteca de Standard Webhooks, y el SDK tolera 5 minutos hacia
atrás y hacia adelante en `webhook-timestamp` y compara en tiempo constante.

### Estado y cabeceras de la respuesta

Cada método tiene una variante `…WithHttpInfo` que devuelve `[modelo, estado, cabeceras]`:
`[$factura, $estado, $cabeceras] = $trevol->invoices->createInvoiceWithHttpInfo(...);` (`$cabeceras['request-id']`, `idempotent-replayed`).

## Opciones

```php
new Trevol(
    apiKey: $llave,
    apiVersion: '2026-10-22',   // Trevol-Version en cada petición
    maxRetries: 2,              // 0 los apaga
    timeout: 65.0,              // segundos por intento
    maxRetryAfter: 60.0,        // segundos
    baseUrl: 'https://api.trevol.si/v1',
    environment: 'test',        // si la llave no coincide: ConfigurationException antes de llamar
    handler: null,              // un manejador de Guzzle propio (proxy, MockHandler…)
    proxy: null,                // p. ej. 'http://proxy.miempresa.co:3128'
);
```

## Cómo está hecho

- `generado/` lo escribe [OpenAPI Generator](https://openapi-generator.tech) 7.26.0 (`php`, Guzzle 7) desde la especificación pública y **no
  se edita** (ver `GENERADO.md`). Para que un valor nuevo de un `enum` en lo que la API devuelve no rompa a quien ya integró, los modelos de
  respuesta se generan sin `enum`.
- Lo propio (`src/`: `Trevol`, `Transporte`, `Errores`, `Paginacion`, `Webhook`, `Exception/`) vive fuera y solo usa el punto de extensión del
  código generado: un middleware de Guzzle que se conecta antes de `http_errors`, lanza la excepción tipada y reintenta.
- Las pruebas: `composer install && vendor/bin/phpunit` (unitarias sin red, con los mismos casos de los demás SDK en `pruebas/datos/`);
  `TREVOL_PRISM_URL=http://127.0.0.1:4010 vendor/bin/phpunit --testsuite prism,fragmentos` contra un servidor simulado
  (`npx @stoplight/prism-cli mock pruebas/datos/openapi.json -p 4010`) que valida cada petición contra la especificación, y que además corre
  como programa cada fragmento de código de la documentación.
- `php pruebas/humo/humo.php` es la prueba de humo **real** (solo con una llave `tvl_test_`; no emite documentos).

## Licencia

MIT © 2026 Trevol.
