# Registro de cambios

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el versionado, [SemVer](https://semver.org/lang/es/): antes
de la 1.0, un cambio incompatible sube el número menor.

## [0.1.0] — sin publicar

Primera versión (API `2026-10-22`).

- Cliente `Trevol\Trevol` con una propiedad por etiqueta de la API (`$invoices`, `$creditNotes`, `$debitNotes`, `$supportDocuments`,
  `$payrolls`, `$radianEvents`, `$webhookEndpoints`, `$events`, `$requests`, `$balance`, `$general`…) y los modelos generados de la
  especificación (`Trevol\Generado\Model`).
- `Idempotency-Key` automática (la misma en los reintentos), reintentos con espera creciente que respetan `Retry-After`, excepciones tipadas
  por `code` (`Trevol\Exception\*`), entorno deducido del prefijo de la llave, `Trevol-Version` fijada.
- `paginate` (generador), descargas de PDF, XML y ZIP como `SplFileObject`, `Webhook::verify` y `Webhook::eventData` (Standard Webhooks).
