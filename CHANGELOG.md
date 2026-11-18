# Registro de cambios

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el versionado, [SemVer](https://semver.org/lang/es/): antes
de la 1.0, un cambio incompatible sube el número menor.

## [Sin publicar]

Regenerado con la API de noviembre de 2026 (versión `2026-11-18`, la de los errores juntos; 149 operaciones y 70 tipos de evento).

- Recursos nuevos: `customers`, `dianDocuments`, `dianSyncs`, `invoiceBatches`, `numberingAssociations`, `numberingRanges`, `numberingRequests`, `paperNumberingRanges`, `products`, `radianStatus`, `reportRuns`, `reports`: lo recibido y el XML de la DIAN, la sincronización, el estado RADIAN, clientes y productos, numeraciones
  (también el modo simulado de las llaves de prueba), lotes de facturas, reportes (también los asíncronos) y la numeración de talonario.
- `Trevol-Version` por defecto: `2026-11-18`.
- Errores tipados para los códigos nuevos (`company_busy`, `confirmation_required`, `duplicate_customer`, `duplicate_product`,
  `numbering_request_in_progress`, `range_protected`, `paper_numbering_limit_reached` → conflicto; `automatic_mode_unavailable`,
  `portal_read_required`, `report_too_large` → precondición).
- Las líneas de factura, notas y documento equivalente aceptan la línea completa o la del catálogo (`product_id`) en un mismo modelo.
- La exportación de documentos (`exportDocuments`) se descarga como archivo (CSV o Excel).
- Las fechas de la consulta (`period_end_from`/`period_end_to` de las nóminas y `issued_from`/`issued_to` de los documentos de la DIAN y los reportes) son texto `AAAA-MM-DD`, como las documenta la API (antes, en algunos lenguajes, un tipo de fecha que podía viajar con hora y zona).
- Los booleanos de la consulta (p. ej. `include_expired`) viajan como `true`/`false` (antes `1`/`0`, que la API rechaza).

## [0.1.0] — sin publicar

Primera versión (API `2026-10-22`).

- Cliente `Trevol\Trevol` con una propiedad por etiqueta de la API (`$invoices`, `$creditNotes`, `$debitNotes`, `$supportDocuments`,
  `$payrolls`, `$radianEvents`, `$webhookEndpoints`, `$events`, `$requests`, `$balance`, `$general`…) y los modelos generados de la
  especificación (`Trevol\Generado\Model`).
- `Idempotency-Key` automática (la misma en los reintentos), reintentos con espera creciente que respetan `Retry-After`, excepciones tipadas
  por `code` (`Trevol\Exception\*`), entorno deducido del prefijo de la llave, `Trevol-Version` fijada.
- `paginate` (generador), descargas de PDF, XML y ZIP como `SplFileObject`, `Webhook::verify` y `Webhook::eventData` (Standard Webhooks).
