# TODOS — MirasAI

## Pendents

### ~~TODO-001: Investigar API interna YOOtheme Pro 5~~ ✅ DONE
- **Completat:** 2026-03-21. Resultats a `~/.gstack/projects/movamiraai/yootheme5-api-research.md`
- **Findings:** Articles: `#__content.fulltext` (`<!-- JSON -->`). Storage: `#__extensions.custom_data`. Template: `#__template_styles.params`. Builder: `YOOtheme\Builder::load()/render()`.

### ~~TODO-002: Definir MCP capabilities subset~~ ✅ DONE
- **Completat:** 2026-03-22. Documentat a `README.md`
- **Findings:** MirasAI implementa `initialize`, `tools/list`, `tools/call` i `ping`. No implementa resources, prompts, sampling ni roots.
- **Extra:** Documentat també el workflow de migració de menús YOOtheme a `mod_menu` multiidioma (`navbar` + `dialog-mobile`).

### ~~TODO-003: Configurar entorn Docker per tests d'integració~~ ✅ DONE
- **What:** Crear `docker-compose.yml` amb Joomla 5 + MySQL + YOOtheme Pro.
- **Why:** L'estratègia de test (PHPUnit unit + integració) requereix un Joomla real.
- **Pros:** Tests fiables, CI/CD possible, reproducció consistent entre màquines.
- **Cons:** Setup inicial (~1h amb CC). YOOtheme Pro necessita llicència.
- **Context:** Joomla té imatge Docker oficial (`joomla:5`). YOOtheme Pro es pot instal·lar via volume mount.
- **Completat:** 2026-03-22. Validat end-to-end en una VM Debian + Docker a Proxmox amb `./docker/bootstrap-lab.sh` i `./docker/smoke.sh`.
- **Findings:** El laboratori funciona amb `mysql:8.4`, `joomla:5-apache`, YOOtheme Pro i el runtime MCP de MirasAI. En entorns ISP espanyols afectats pel bloqueig de Cloudflare R2 pot caldre WARP o equivalent per permetre `docker pull`.
- **Extra:** `com_mirasai` queda com a instal·lació opcional/best effort al bootstrap; el runtime de proves depèn de `lib_mirasai` + plugins MCP.
- **Depends on:** Llicència YOOtheme Pro.
- **Added:** 2026-03-21 via /plan-eng-review

### ~~TODO-004: Suport multiidioma per templates YOOtheme~~ ✅ DONE
- **Completat:** 2026-03-22. Implementat als tools MCP i validat a Boira.
- **Findings:** `template/list`, `template/read` i `template/translate` ja permeten auditar, llegir i duplicar/traduir templates per idioma.
- **Extra:** `content/audit-multilingual` detecta templates amb text fix compartit, variants per idioma faltants i casos dinàmics purs amb `lang=all`.
- **Context:** Els templates es llegeixen de `#__extensions.custom_data.templates`; el filtre d'idioma viu a `query.lang` i el layout a `layout`.

### ~~TODO-006: Phase 4 — Integration tests per theme/extract-to-modules~~ ✅ DONE
- **Completat:** 2026-03-23. Script a `docker/test-extract-to-modules.sh`.
- **Cobertura:** 6 escenaris del pla Codex:
  1. Single YOOtheme style — extracció normal amb traduccions
  2. Múltiples template_styles — resolució per id explícit i fallback a l'actiu
  3. Mòdul preexistent no-MirasAI — detecció de conflicte sense force
  4. Re-run idempotent — reusa mòduls existents (modules_reused >= 2)
  5. dry_run — cap mutació a DB
  6. replace_theme_area=false — crea mòduls però no modifica l'àrea del tema
- **Integració:** `smoke.sh` crida el test suite amb `RUN_INTEGRATION=1`.
- **Depends on:** Docker lab funcionant (bootstrap-lab.sh).
- **Added:** 2026-03-23

### TODO-007: Subprocess isolation per sandbox/execute-php (P2)
- **What:** Implementar execució de codi PHP en un subprocess dedicat (via `proc_open`) en lloc de `eval()` in-process.
- **Why:** L'execució in-process amb `eval()` pot corrompre l'estat de la request actual (sessions, output buffers, connexions DB) en cas de fatal error. A més, `set_time_limit` pot ser bypassat per codi avaluat.
- **Pros:** Aïllament real, timeouts fiables via `pcntl_alarm`, protecció contra OOM i stack overflow.
- **Cons:** Overhead de crear un subprocess per cada execució, requereix `pcntl` extension, complexitat addicional.
- **Context:** v1 utilitza `eval()` + `set_time_limit(30)` + `register_shutdown_function` com a mesura acceptable. Aquest TODO captura la millora per a v2.
- **Depends on:** Ús real que validi la necessitat vs el cost.
- **Added:** 2026-03-23 via /plan-eng-review

### ~~TODO-008: Extension adapter API (P2)~~ ✅ DONE (v0.4.0)
- **What:** Permetre que extensions de tercers registrin eines MCP pròpies a través d'una API d'adaptadors.
- **Implemented:** `ToolProviderInterface` + `MirasaiCollectToolsEvent` + `plg_mirasai_yootheme` com a primera implementació de referència.
- **Done:** 2026-03-25 via plugin architecture refactor

### TODO-009: MCP resources (P3)
- **What:** Exposar estat de Joomla com a recursos subscriptibles del protocol MCP (resources/list, resources/read).
- **Why:** Permet als agents subscriure's a canvis en lloc de fer polling.
- **Pros:** Eficiència, UX millor per a agents que monitoritzen estat.
- **Cons:** Complexitat de protocol, SSE/streaming requerit.
- **Context:** Decidit al CEO review com a DEFERRED — tools cobreixen el 80/20, resources és follow-up.
- **Depends on:** MCP v1 estable amb les 23 eines actuals.
- **Added:** 2026-03-23 via /plan-ceo-review

### TODO-010: Per-tool risk tiers per elevation (P3)
- **What:** Implementar nivells de risc diferenciats per a cada eina destructiva dins del sistema d'elevació.
- **Why:** `sandbox/execute-php` (eval()) pot corrompre estat del procés, connexions DB, i sessions — risc molt més alt que `file/write` que opera dins del sandbox. El gate uniforme actual tracta tots els tools destructius igual.
- **Pros:** Controls més granulars (e.g., eval podria requerir confirmació addicional o durada més curta), millor model de seguretat.
- **Cons:** Complexitat addicional al UX d'activació, més opcions per l'admin.
- **Context:** Identificat pel review independent de Codex durant l'eng review de Smart Sudo. v1 usa un gate uniforme — acceptable per single-admin. v2 podria afegir risk tiers amb controls específics per nivell.
- **Depends on:** Smart Sudo v1 implementat i ús real que validi la necessitat.
- **Added:** 2026-03-24 via /plan-eng-review (Codex finding)

### TODO-011: Crear DESIGN.md via /design-consultation (P3)
- **What:** Definir un design system compartit per tots els admin views de MirasAI (dashboard, elevation, futurs).
- **Why:** El dashboard i l'elevation view usen convencions implícites de Bootstrap 5. Un DESIGN.md formal unificaria tokens de color, tipografia, espaiat i vocabulari de components.
- **Pros:** Consistència visual entre views, referència per a implementadors, base per a /design-review.
- **Cons:** Esforç de creació (~30 min amb /design-consultation). Pot ser prematur amb només 2 views.
- **Context:** Detectat al design review de Smart Sudo. L'elevation view defineix tokens inline (`--mirasai-elevation-*`) que podrien promoure's a sistema compartit.
- **Depends on:** Smart Sudo v1 implementat (2 admin views existents com a base).
- **Added:** 2026-03-24 via /plan-design-review

### ~~TODO-012: Dashboard admin — redisseny complet amb decisions de design review (P1)~~ ✅ DONE
- **What:** Redissenyar el dashboard de `com_mirasai` per reflectir l'estat real del sistema amb jerarquia visual intencionada.
- **Why:** Ara mateix mostra v0.1.0 (hauria de ser v0.4.0), només 10 tools hardcodejades (hauria de ser 25), i les descripcions són en català i estàtiques. El layout és 5 cards apilades sense jerarquia — triga 3 hard rejections de disseny (card grid genèric, cards sense interacció, stacked cards).
- **Subtasques:**
  1. **Versió centralitzada:** Definir `MIRASAI_VERSION` com a constant PHP a `lib_mirasai`. Dashboard i `McpHandler::handleInitialize()` la llegeixen d'allà.
  2. **Tools dinàmiques:** El dashboard crida `ToolRegistry::buildDefault()` per llistar totes les tools amb les seves descripcions MCP reals (truncades a ~100 chars). Zero manteniment de descripcions duplicades.
  3. **Diferenciar core vs addon:** Columna/badge que indica l'origen de cada tool (requereix TODO-013).
  4. **Secció Addons:** Llista de plugins del grup `mirasai` amb toggle, tool count, i link a gestió de plugins Joomla (TODO-014).
  5. **Explicació `*` a traduccions:** Tooltip/nota per `language = '*'` (articles sense idioma assignat).
  6. **Status badge core-only:** El badge ACTIU/INACTIU comprova només core extensions (lib_mirasai, plg_system_mirasai, plg_webservices_mirasai). Addons es mostren a la seva secció.
  7. **i18n:** Totes les strings amb `Text::_('COM_MIRASAI_...')`. Dashboard ha de ser traduïble. Distribuir només `en-GB.com_mirasai.ini`. La comunitat pot traduir via Joomla language overrides.
  8. **Fix extensions query:** La query actual (`element = 'mirasai' OR element = 'com_mirasai'`) no troba les extensions correctes. Cal filtrar per `(type='library' AND element='mirasai') OR (type='plugin' AND folder='system' AND element='mirasai') OR (type='plugin' AND folder='webservices' AND element='mirasai') OR (type='component' AND element='com_mirasai')` per core. Addons: `type='plugin' AND folder='mirasai'`.
  9. **toToolSummaryList():** Usar el nou mètode de TODO-013 per obtenir la llista de tools sense instanciar-les.
- **Design Decisions (review 2026-03-27):**
  - **Layout:** Banner d'estat full-width a dalt (no card) → Sistema+Traduccions en 2 columnes → Tools agrupades per domini → Addons com a secció separada.
  - **Banner d'estat:** Full-width, prominent. Mostra: versió, badge ACTIU/INACTIU, endpoint amb copy, resum (N tools · N idiomes · Elevation: ON/OFF).
  - **Tools agrupades:** Per domini (`content/*`, `template/*`, `theme/*`, `menu/*`, `system/*`, `sandbox/*`, `file/*`, `db/*`, `elevation/*`). Cada grup mostra: nom tool en `<code>`, descripció MCP truncada, badge core/addon, indicador 🔴 si destructiu.
  - **Empty states:** Cada secció té un empty state amb missatge càlid i CTA (ex: "No articles yet. Create content →", "No addons installed. Browse addons →").
  - **Onboarding:** Bloc col·lapsable a la primera visita: 3 passos (habilitar plugins, copiar endpoint, crear API token). Es detecta amb `localStorage` o paràmetre de sessió.
  - **Curl example:** Dins `<details>` col·lapsable sota les tools. Visible per qui el necessiti, ocult per defecte.
  - **Anti-slop rules:** Màxim 3 colors de badge (success=actiu, secondary=inactiu, warning=alerta). Sense icones decoratives. Copy d'utilitat (no aspiracional). Cards només per addons (perquè són interactius). Estil nadiu Joomla Bootstrap 5.
  - **Responsive:** Bootstrap responsive per defecte. Mobile: banner compacte, columnes stacked, tools taula responsiva.
  - **A11y:** ARIA landmarks (banner=status, main=tools, complementary=addons). Copy button accessible. Addon toggles focusables.
  - **CSS tokens:** Reusar els tokens de l'Elevation view com a base compartida, promoure a namespace `--mirasai-*`.
  - **Mockup ASCII del layout objectiu:**
    ```
    ┌──────────────────────────────────────────────────────┐
    │  ⚡ MirasAI v0.4.0                      ACTIU  🟢   │
    │  Endpoint: https://...api/v1/mirasai/mcp    [Copy]  │
    │  25 tools · 3 idiomes · Elevation: OFF              │
    └──────────────────────────────────────────────────────┘
    ┌──────────────┐  ┌──────────────────────────────────┐
    │  SISTEMA     │  │  TRADUCCIONS                     │
    │  Joomla 6.0  │  │  ca-ES ████ 9 (8 YT)            │
    │  PHP 8.4     │  │  en-GB ████ 9 (8 YT)            │
    │  YOOtheme 5  │  │  es-ES ████ 9 (8 YT)            │
    └──────────────┘  └──────────────────────────────────┘
    ┌──────────────────────────────────────────────────────┐
    │  TOOLS (25)                   [Core ▾] [Addon ▾]    │
    │  CONTENT (6) ─────────────────────────────── Core   │
    │  ├ content/list     Lists articles with...          │
    │  ├ content/read     Reads a single article...       │
    │  ├ content/translate Creates or updates...    🔴    │
    │  └ ...                                              │
    │  YOOTHEME (5) ───────────── plg_mirasai_yootheme   │
    │  ├ theme/extract    Extracts a YOOtheme...   🔴    │
    │  └ ...                                              │
    └──────────────────────────────────────────────────────┘
    ┌──────────────────────────────────────────────────────┐
    │  ADDONS                    [Manage plugins →]       │
    │  ┌────────────┐  ┌────────────┐                     │
    │  │ YOOtheme 🟢│  │ Example 🟢 │                     │
    │  │ 5 tools    │  │ 1 tool     │                     │
    │  └────────────┘  └────────────┘                     │
    └──────────────────────────────────────────────────────┘
    ```
- **Context:** El dashboard és la "part humana" de MirasAI. Ara que les descripcions MCP estan polides per agents, el dashboard hauria de ser igual de clar per humans.
- **Depends on:** TODO-013 (per core vs addon). TODO-014 deferred — addons read-only + link a plugin manager.
- **Added:** 2026-03-25
- **Updated:** 2026-03-27 via /plan-design-review
- **Completat:** 2026-03-27. Implementat:
  - Versió centralitzada via `Mirasai::VERSION`, tots els XML manifests a 0.4.0.
  - Tools dinàmiques via `ToolRegistry::buildDefault()->toToolSummaryList()`.
  - Core vs addon badges a cada tool.
  - Fix extensions query: filtra per type+folder+element (core) i folder=mirasai (addons).
  - i18n complet: `en-GB.com_mirasai.ini` amb 40+ strings via `Text::_()`.
  - Status banner full-width amb badge, endpoint copy, resum.
  - Tools agrupades per domini amb indicador destructiu.
  - Addons read-only amb link a plugin manager.
  - Onboarding localStorage-driven.
  - Curl example col·lapsable.
  - Traduccions amb tooltip per `language = '*'`.
  - Empty states per traduccions, tools i addons.

### ~~TODO-013: ToolRegistry — exposar origen i resum de cada tool (P1)~~ ✅ DONE
- **Completat:** 2026-03-27.
- **Implementat:**
  - `registerLazy(name, class, provider='core')` — tercer paràmetre per origen.
  - Array paral·lel `private array $providers = []` amb `$providers[$name] = $provider`.
  - `collectProviders()` passa `$provider->getId()` explícitament a `register()`.
  - `getProvider(string $name): string` al registry.
  - `toToolSummaryList(): array` — retorna [{name, description, provider, destructive}].
  - `register(ToolInterface, provider='core')` — segon paràmetre per origen.
- **Extra:** Versió centralitzada a `Mirasai::VERSION` (0.4.0). `McpHandler`, `SystemInfoTool`, i XML manifests actualitzats.
- **Added:** 2026-03-25
- **Updated:** 2026-03-27 — implementat

### TODO-014: Gestió d'addons des del dashboard (P2)
- **What:** Permetre publicar/despublicar plugins del grup `mirasai` directament des del dashboard de MirasAI.
- **Why:** L'admin hauria de poder activar/desactivar addons (com plg_mirasai_yootheme) sense navegar a Extensions → Plugins i buscar manualment.
- **Implementació proposada:**
  - Secció "Addons" al dashboard amb llista de plugins del grup `mirasai`.
  - Toggle per habilitar/deshabilitar cada addon (crida a l'API de Joomla o update directe a `#__extensions`).
  - Mostrar les tools que cada addon aporta (usant TODO-013).
  - Estat: habilitat/deshabilitat/no instal·lat.
- **Pros:** UX integrada, l'admin no ha de conèixer la gestió de plugins de Joomla.
- **Cons:** Requereix permisos d'admin i validació de seguretat.
- **Depends on:** TODO-013.
- **Added:** 2026-03-25

### TODO-005: Explorar overrides de llengua per microcopies compartides de templates
- **What:** Avaluar una estratègia alternativa per substituir text fix de templates per claus de llengua Joomla.
- **Why:** Pot reduir manteniment quan una mateixa microcopy es repeteix a múltiples templates.
- **Pros:** Un únic punt de canvi per textos molt repetits.
- **Cons:** Més intrusiu sobre el layout i més acoblament a Joomla language overrides.
- **Context:** Fora de la primera implementació; només com a optimització futura després del flux per duplicació per idioma.
- **Depends on:** TODO-004 resolt i ús real que justifiqui l'optimització.
- **Added:** 2026-03-22
