# MovaMiraAI - TODO operatiu detectat a InARTS UB

Data: 2026-05-08

Aquest fitxer recull dos deutes tecnics detectats durant la migracio Joomla
d'InARTS al hosting UB.

## 1. Compatibilitat MCP: `rereplacer/capabilities`

Estat: resolt a `0.4.8`.

### Problema

L'endpoint MCP de MirasAI respon correctament per JSON-RPC directe, pero
`mcp2cli` falla quan llegeix `tools/list`.

S'ha detectat que el tool `rereplacer/capabilities` publica un schema amb:

```php
'properties' => [],
```

Quan PHP codifica aquest array buit a JSON, surt com `[]`, no com `{}`.
Alguns clients MCP validen estrictament JSON Schema i esperen que
`inputSchema.properties` sigui un objecte. Per tant rebutgen tota la llista
d'eines encara que la resta de tools siguin correctes.

### Solucio proposada

Canvi minim:

- A `pkg_mirasai/packages/plg_mirasai_rereplacer/src/Tool/RereplacerCapabilitiesTool.php`,
  canviar `properties => []` per `properties => new \stdClass()` o `(object) []`.

Canvi robust:

- Afegir una normalitzacio central a `AbstractTool::toArray()` o al punt on
  `McpHandler` construeix la resposta `tools/list`.
- Si `inputSchema.type === object` i `properties` existeix pero es un array
  buit, convertir-lo a `new \stdClass()`.
- Revisar tots els tools sense arguments. Ara ja hi ha exemples correctes amb
  `new \stdClass()` o `(object) []`, pero cal evitar regressions quan s'afegeixin
  addons nous.

Validacio:

- `curl` a `tools/list` ha de continuar retornant JSON valid.
- `mcp2cli` ha de poder carregar l'endpoint sense error de schema.
- Afegir un smoke test que recorri tots els tools i comprovi que:
  - `inputSchema.type` existeix.
  - `inputSchema.properties` es objecte quan el schema es de tipus `object`.
  - `required`, si existeix, es array.

## 2. Joomla instal.lat en subdirectori

Estat: cobert a `0.4.8` amb normalitzacio compartida i smoke tests.

### Problema

InARTS a UB viu a:

```text
https://www.ub.edu/a-rts
```

Joomla esta servit sota un subdirectori public (`/a-rts`) i l'API pot entrar
tant per:

```text
/a-rts/api/v1/mirasai/mcp
/a-rts/api/index.php/v1/mirasai/mcp
```

Abans del patch de ruta, l'endpoint `/api/v1/mirasai/mcp` podia donar 404 en
instal.lacions d'aquest tipus. La versio `0.4.7` ja ha incorporat un fallback de
ruta que va funcionar a InARTS, pero convindria deixar-ho cobert amb proves.

### Solucio proposada

- Mantenir `MirasaiSystem::normalizeApiPath()` com a punt unic per normalitzar:
  - base path de Joomla (`Uri::base(true)`)
  - entrada amb `/index.php/`
  - site app: `/api/v1/mirasai/mcp`
  - api app: `/v1/mirasai/mcp`
- Afegir tests o smoke checks amb aquests casos:
  - Joomla a domini arrel: `/api/v1/mirasai/mcp`
  - Joomla a subdirectori: `/a-rts/api/v1/mirasai/mcp`
  - API app amb index.php: `/a-rts/api/index.php/v1/mirasai/mcp`
- Revisar el dashboard:
  - `com_mirasai/admin/src/View/Dashboard/HtmlView.php` construeix l'endpoint
    amb `Uri::root()`, que en subdirectori ha d'incloure `/a-rts/`.
  - Afegir una nota o validacio visual si el path public calculat no coincideix
    amb el path real de la request.

Validacio feta a InARTS:

- `https://www.ub.edu/a-rts/api/v1/mirasai/mcp` respon `tools/list`.
- `https://www.ub.edu/a-rts/api/index.php/v1/mirasai/mcp` respon `tools/list`.
- `system/info` retorna Joomla `5.4.5`, PHP `8.2.29`, MySQL `8.0.45-commercial`.

## Notes operatives

- No desar tokens Joomla/MirasAI en aquest repo.
- UB usa NFS: en canvis remots de fitxers PHP, evitar substitucions amb
  `rename()` sobre fitxers calents si el servidor talla la connexio. Patró que
  ha funcionat: backup amb nom nou, escriptura directa, validacio i comprovacio
  de `.nfs*`.

## 3. Pendents operatius detectats a comunitat.congresbit.cat

Data: 2026-05-25

Estat: obert.

### Context

- Site: `comunitat.congresbit.cat`
- Joomla `5.4.5`, PHP `8.4.21`, YOOtheme `5.0.34`
- MirasAI `0.4.8` instal.lat i validat via endpoint standalone.
- Backup BBDD previ:
  `/home/congresbit/mirasai-backups/20260525-140828/db-before-mirasai.sql.gz`
- Endpoint standalone funcional:
  `https://comunitat.congresbit.cat/mcp-endpoint.php`
- Token de prova local al compte: `/home/congresbit/.mirasai-comunitat-token`
  (no copiar al repo).

### TODO-015: Component MirasAI no visible al menu Components

Estat: resolt el 2026-05-25.

Problema:

- `com_mirasai` consta instal.lat i actiu a `#__extensions`.
- Hi ha entrades admin a `#__menu`:
  - `index.php?option=com_mirasai`
  - `index.php?option=com_mirasai&view=dashboard`
  - `index.php?option=com_mirasai&view=elevation`
- A la UI de Joomla no apareix sota `Components`.

Validacio feta:

- DB indica component i menu presents:
  - component id `385`
  - menu ids `681`, `682`, `683`
- L'usuari esborra cache i confirma que el menu ja surt a la UI de Joomla.

Causa probable:

- Cache de Joomla/menu admin no refrescada just despres de la instal.lacio.

Notes de diagnosi:

- No s'ha modificat DB ni fitxers.
- `#__extensions` tenia `com_mirasai` instal.lat i actiu.
- `#__menu` tenia les files admin correctes:
  - `client_id = 1`
  - `menutype = main`
  - `published = 1`
  - `parent_id = 1` per l'arrel `MirasAI`
  - submenus `Dashboard` i `Elevation`
- `#__assets` tenia asset `com_mirasai`.

### TODO-016: Ruta API nativa continua a 404

Estat: resolt el 2026-05-25.

Problema:

- L'endpoint standalone funciona.
- `/api/v1/mirasai/mcp` i `/api/index.php/v1/mirasai/mcp` retornen 404.
- `plg_webservices_mirasai` esta instal.lat i actiu, pero la instrumentacio
  temporal no va mostrar que Joomla carregues el provider ni `onBeforeApiRoute`.

Canvis ja fets al repo:

- `plg_webservices_mirasai` registra rutes `v1/mirasai/mcp` amb
  `Joomla\Router\Route`.
- `McpController` reforca `core.admin`.

Pendent:

- Cap pendent immediat al site.

Validacio feta:

- Despres d'esborrar cache, les rutes natives funcionen:
  - `POST /api/index.php/v1/mirasai/mcp`
  - `POST /api/v1/mirasai/mcp`
- Amb `X-Joomla-Token`, `tools/list` retorna `200 application/json` i 47 tools.
- `GET /api/index.php/v1/mirasai/mcp` respon `200 text/event-stream` i obre SSE.
- Les rutes core de Joomla tambe funcionen amb el mateix token:
  - `/api/index.php/v1/content/articles`
  - `/api/index.php/v1/config/application`

Causa probable:

- Cache Joomla post-instal.lacio no refrescada, igual que en el cas del menu
  admin de `TODO-015`.

Canvi preventiu al paquet:

- `pkg_mirasai/script.php` ara neteja caches best-effort en `postflight` despres
  d'activar plugins i migrar l'update site.
- Grups netejats: `_system`, `com_installer`, `com_menus`, `com_modules`,
  `com_plugins`, `mod_menu`.

### TODO-017: Dynamic Source writes

Estat: resolt el 2026-05-25.

Context:

- `template/source-types` ja retorna `live_introspection` per `Article` al
  standalone.
- `template/element-source-read` detecta bindings reals.
- Exemple validat:
  - template `Galeries de fotos`
  - path `root>section[0]>row[1]>column[0]>panel[0]`
  - source `article`
  - mappings `image`, `hover_video`, `link`, `content`

Implementat:

- `template/element-source-preview`
- `template/element-source-set`
- `template/element-source-delete`
- `if_match`, `dry_run`, `confirm_guarded_write` i invalidacio cache post-write.
- Suport per storage de template, article YOOtheme i `mod_yootheme_builder`.
- Validat amb escriptura/restauracio a l'article despublicat `Videos` (`id=1`) a `comunitat.congresbit.cat`.
- Validat al Docker lab CT `103` amb `docker/test-template-etag.sh`.

### TODO-018: Schema runtime YOOtheme ampliat

Estat: resolt el 2026-05-25.

Implementat:

- `template/element-schema` resol refs `${builder.*}` contra el config runtime instal.lat de YOOtheme.
- Retorna `props_schema`, `element_schema` i `source_binding_schema`.
- Manté la definicio PHP de YOOtheme com a font de veritat i marca que `enable/show` no s'avaluen.
- Validat a `comunitat.congresbit.cat`: `headline` resol `builder.link`, exposa `props_schema.properties.content` i `source_field_count`.
