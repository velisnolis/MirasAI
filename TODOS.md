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

### TODO-004: Suport multiidioma per templates YOOtheme
- **What:** Auditar, llegir i duplicar/traduir templates de YOOtheme per idioma via MCP.
- **Why:** Un template amb text fix compartit a tots els idiomes trenca el site multilingüe igual que un footer o un mòdul.
- **Pros:** Cobertura real de templates, gaps accionables i workflow complet sense editar manualment cada variant.
- **Cons:** Cal mantenir variants per idioma quan el template tingui text fix.
- **Context:** Els templates es guarden a `#__extensions.custom_data.templates`; el filtre d'idioma viu a `query.lang` i el layout a `layout`.
- **Depends on:** Res funcional crític; Boira ja té un exemple real per validar el mapping.
- **Added:** 2026-03-22

### TODO-005: Explorar overrides de llengua per microcopies compartides de templates
- **What:** Avaluar una estratègia alternativa per substituir text fix de templates per claus de llengua Joomla.
- **Why:** Pot reduir manteniment quan una mateixa microcopy es repeteix a múltiples templates.
- **Pros:** Un únic punt de canvi per textos molt repetits.
- **Cons:** Més intrusiu sobre el layout i més acoblament a Joomla language overrides.
- **Context:** Fora de la primera implementació; només com a optimització futura després del flux per duplicació per idioma.
- **Depends on:** TODO-004 resolt i ús real que justifiqui l'optimització.
- **Added:** 2026-03-22
