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

### TODO-005: Explorar overrides de llengua per microcopies compartides de templates
- **What:** Avaluar una estratègia alternativa per substituir text fix de templates per claus de llengua Joomla.
- **Why:** Pot reduir manteniment quan una mateixa microcopy es repeteix a múltiples templates.
- **Pros:** Un únic punt de canvi per textos molt repetits.
- **Cons:** Més intrusiu sobre el layout i més acoblament a Joomla language overrides.
- **Context:** Fora de la primera implementació; només com a optimització futura després del flux per duplicació per idioma.
- **Depends on:** TODO-004 resolt i ús real que justifiqui l'optimització.
- **Added:** 2026-03-22
