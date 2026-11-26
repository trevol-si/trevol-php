# Código generado

**No edites `generado/`**: `sdks/generar.sh` la borra y la vuelve a escribir entera. La capa propia (cliente,
reintentos, errores, paginación, webhooks) vive fuera de esa carpeta.

| | |
|---|---|
| Generador | OpenAPI Generator 7.26.0 (`php`) |
| Especificación | `especificacion/openapi.sdk.enums-abiertos.json` (variante 3.0.3 de `docs/api/openapi.json`, versión de la API 2026-11-18) |
| sha256 de la especificación | `2c6a9cf11968a1849e708c6c41e981a156404563780ac56bb658f94ff64c4ef2` |
| Configuración | `openapi-generator.yaml` |

Regenerar (desde el monorepo de Trevol): `sdks/generar.sh php`. Comprobar que lo del repositorio es lo que sale de la
especificación: `sdks/generar.sh --comprobar php`.
