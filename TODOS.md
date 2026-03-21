# TODOS — MirasAI

## Pendents

### ~~TODO-001: Investigar API interna YOOtheme Pro 5~~ ✅ DONE
- **Completat:** 2026-03-21. Resultats a `~/.gstack/projects/movamiraai/yootheme5-api-research.md`
- **Findings:** Articles: `#__content.fulltext` (`<!-- JSON -->`). Storage: `#__extensions.custom_data`. Template: `#__template_styles.params`. Builder: `YOOtheme\Builder::load()/render()`.

### TODO-002: Definir MCP capabilities subset
- **What:** Documentar exactament quines parts de l'spec MCP implementem i quines no.
- **Why:** MCP és un protocol ampli. Implementar-lo tot seria un oceà — v1 només necessita tools.
- **Pros:** Evita scope creep, aclareix què suportem als consumidors.
- **Cons:** Cap real — documentació de ~30 min.
- **Context:** Novamira implementa tools + resources + prompts. Per v1: initialize, tools/list, tools/call. NO resources, NO prompts, NO sampling, NO roots.
- **Depends on:** Res.
- **Added:** 2026-03-21 via /plan-eng-review

### TODO-003: Configurar entorn Docker per tests d'integració
- **What:** Crear `docker-compose.yml` amb Joomla 5 + MySQL + YOOtheme Pro.
- **Why:** L'estratègia de test (PHPUnit unit + integració) requereix un Joomla real.
- **Pros:** Tests fiables, CI/CD possible, reproducció consistent entre màquines.
- **Cons:** Setup inicial (~1h amb CC). YOOtheme Pro necessita llicència.
- **Context:** Joomla té imatge Docker oficial (`joomla:5`). YOOtheme Pro es pot instal·lar via volume mount.
- **Depends on:** Llicència YOOtheme Pro.
- **Added:** 2026-03-21 via /plan-eng-review
