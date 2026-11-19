# Código generado

**No edites `generado/`**: `sdks/generar.sh` la borra y la vuelve a escribir entera. La capa propia (cliente,
reintentos, errores, paginación, webhooks) vive fuera de esa carpeta.

| | |
|---|---|
| Generador | OpenAPI Generator 7.26.0 (`php`) |
| Especificación | `especificacion/openapi.sdk.enums-abiertos.json` (variante 3.0.3 de `docs/api/openapi.json`, versión de la API 2026-11-18) |
| sha256 de la especificación | `9e2ec0f878945e0bb2ed5e2850023b57e63dda040164896d23ad1f8a18c3921f` |
| Configuración | `openapi-generator.yaml` |

Regenerar (desde el monorepo de Trevol): `sdks/generar.sh php`. Comprobar que lo del repositorio es lo que sale de la
especificación: `sdks/generar.sh --comprobar php`.
