# Código generado

**No edites `generado/`**: `sdks/generar.sh` la borra y la vuelve a escribir entera. La capa propia (cliente,
reintentos, errores, paginación, webhooks) vive fuera de esa carpeta.

| | |
|---|---|
| Generador | OpenAPI Generator 7.26.0 (`php`) |
| Especificación | `especificacion/openapi.sdk.enums-abiertos.json` (variante 3.0.3 de `docs/api/openapi.json`, versión de la API 2026-10-22) |
| sha256 de la especificación | `2a43e25b5bc8b2a735886cf4e77403cac46f4f05ff68528b37101c2ba0ef82d3` |
| Configuración | `openapi-generator.yaml` |

Regenerar (desde el monorepo de Trevol): `sdks/generar.sh php`. Comprobar que lo del repositorio es lo que sale de la
especificación: `sdks/generar.sh --comprobar php`.
