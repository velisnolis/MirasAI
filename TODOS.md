# TODOS — MirasAI

## Pendents

### ~~TODO-001: Investigar API interna YOOtheme Pro 5~~ ✅ DONE
- **Completat:** 2026-03-21. Resultats a `~/.gstack/projects/movamiraai/yootheme5-api-research.md`
- **Findings:** Articles: `#__content.fulltext` (`<!-- JSON -->`). Storage: `#__extensions.custom_data`. Template: `#__template_styles.params`. Builder: `YOOtheme\Builder::load()/render()`.

### ~~TODO-002: Definir MCP capabilities subset~~ ✅ DONE
- **Completat:** 2026-03-22. Documentat a `README.md`
- **Findings:** MirasAI implementa `initialize`, `tools/list`, `tools/call` i `ping`. No implementa resources, prompts, sampling ni roots.
- **Extra:** Documentat també el workflow de migració de menús YOOtheme a `mod_menu` multiidioma (`navbar` + `dialog-mobile`).

### TODO-003: Configurar entorn Docker per tests d'integració
- **What:** Crear `docker-compose.yml` amb Joomla 5 + MySQL + YOOtheme Pro.
- **Why:** L'estratègia de test (PHPUnit unit + integració) requereix un Joomla real.
- **Pros:** Tests fiables, CI/CD possible, reproducció consistent entre màquines.
- **Cons:** Setup inicial (~1h amb CC). YOOtheme Pro necessita llicència.
- **Context:** Joomla té imatge Docker oficial (`joomla:5`). YOOtheme Pro es pot instal·lar via volume mount.
- **Depends on:** Llicència YOOtheme Pro.
- **Added:** 2026-03-21 via /plan-eng-review
