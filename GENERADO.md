# Código generado

**No edites `generado/`**: `sdks/generar.sh` la borra y la vuelve a escribir entera. La capa propia (cliente,
reintentos, errores, paginación, webhooks) vive fuera de esa carpeta.

| | |
|---|---|
| Generador | OpenAPI Generator 7.26.0 (`php`) |
| Especificación | `especificacion/openapi.sdk.enums-abiertos.json` (variante 3.0.3 de `docs/api/openapi.json`, versión de la API 2026-11-18) |
| sha256 de la especificación | `54eacecb9a4f2ad19da49a05324b8f7cdbab37f1ccb1ff50df6f7071eda432ce` |
| Configuración | `openapi-generator.yaml` |

Regenerar (desde el monorepo de Trevol): `sdks/generar.sh php`. Comprobar que lo del repositorio es lo que sale de la
especificación: `sdks/generar.sh --comprobar php`.
