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
