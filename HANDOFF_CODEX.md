# Handoff — MirasAI: estils YOOtheme segurs i versió 0.7.0

Actualitzat: 27 de juliol de 2026
Objectiu de la sessió següent: publicar la 0.7.0 i, si s'aprova, desplegar-la a
Indústria Viva i Auto Vigatana.

## Estat executiu

- Repo: `/Users/alexmiras/Documents/Claude Code Default/MovaMiraAI`
- Branca activa: `codex/style-safety-platform-contract`
- Tot el treball està commitejat: set commits d'estils YOOtheme (runner aïllat,
  pin del worker, contracte d'estils al router, host WordPress, host Joomla,
  `source-types`), dos commits de contracte d'eines (rebuig d'arguments
  desconeguts, inserció posicional a `element-move`) i el bump de versió.
- `main` i `origin/main` continuen a `477e6d1`: la branca no s'ha fusionat ni
  publicat.
- **La 0.6.3 no es publica.** Es va decidir plegar-la amb els canvis de
  contracte d'eines i saltar directament a `0.7.0`. El rebuig d'arguments
  desconeguts és un canvi de comportament —crides que abans passaven en silenci
  ara fallen— i la minor ho senyala.
- Versió declarada a tots els *workspaces* i manifests: `0.7.0`.
- No hi ha cap tag, push ni GitHub Release. Els *feeds* locals apunten a
  `v0.7.0`, però aquestes URLs no seran consumibles fins que es publiqui.
- Auto Vigatana té instal·lat un build preliminar etiquetat `0.6.3`, verificat
  en viu. Aquella versió no existirà mai com a release: cal reinstal·lar-hi la
  0.7.0 quan es publiqui.
- Indústria Viva encara declara MirasAI WP `0.6.2`.

## Context anterior que no cal duplicar

- El punt de partida, l'arquitectura de compilació YOOtheme al navegador i les
  primeres preguntes de revisió són a
  `/Users/alexmiras/Documents/Projectes Feina/ManlleuActiva/HANDOFF_CODEX.md`.
- Arquitectura general i superfície MCP: `README.md`.
- Contracte i ús del router d'estils:
  `packages/mirasai-mcp/README.md`.
- Host WordPress i eines disponibles:
  `packages/mirasai-wp/README.md`.
- Laboratori d'escriptures YOOtheme:
  `docs/handoff-docker-lab-yootheme-write-tests.md`.
- El codi i els tests del *working tree* són la font de veritat dels canvis;
  revisar-los amb `git diff` en lloc de reproduir-los en aquest document.

## Què s'ha implementat

### 1. Compilació YOOtheme aïllada

La primera implementació executava el `worker.js` del site dins un `vm` amb
funcions host exposades. Es va demostrar una evasió amb el constructor d'un
timer. La implementació actual:

- verifica el SHA-256 esperat del worker;
- l'executa en un procés separat, terminable i amb entorn buit;
- aplica límits d'arrencada i de crida;
- talla el procés si hi ha timeout;
- manté separada la compilació LTR/RTL.

Fitxers clau:

- `packages/mirasai-mcp/src/style-compiler.mjs`
- `packages/mirasai-mcp/src/style-worker-runner.mjs`
- `packages/mirasai-mcp/src/style-preview.mjs`
- `packages/mirasai-mcp/test/style-compiler.test.mjs`
- `packages/mirasai-mcp/test/style-preview.test.mjs`

### 2. Eines d'estil per WordPress i Joomla

S'han incorporat `template/style-read`, `template/style-sources` i
`template/style-update` als dos hosts. Les escriptures segueixen:

`read/preview → ETag fresc → dry-run → confirmació → compilar LTR/RTL →
validar assets → snapshot → escriure → netejar cache → rellegir`.

Els helpers també validen tots els `url(...)` relatius del CSS abans
d'escriure. Els fonts localitzats per YOOtheme es resolen des del directori
`css`, habitualment com `../fonts/...`.

Fitxers clau:

- `packages/mirasai-wp/src/Tool/YoothemeStyleHelper.php`
- `packages/mirasai-wp/src/Tool/TemplateStyleUpdateTool.php`
- `packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/YoothemeStyleHelper.php`
- `packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/TemplateStyleReadTool.php`
- `packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/TemplateStyleSourcesTool.php`
- `packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/TemplateStyleUpdateTool.php`
- `docker/test-wp-yootheme-style-read.php`
- `docker/test-joomla-yootheme-style-read.php`

### 3. Creació d'estil només a WordPress

`template/style-create` existeix només al host WordPress. Crea de manera
guardada l'esquelet d'un child theme i el Less propi, amb dry-run, ETag,
snapshot, rollback i idempotència.

No activa el child theme, no selecciona l'estil i no compila CSS en viu.
La creació equivalent a Joomla no s'ha implementat deliberadament: Joomla
necessita un flux d'instal·lació i registre de template diferent, no una còpia
directa del comportament WordPress.

Fitxer clau:

- `packages/mirasai-wp/src/Tool/TemplateStyleCreateTool.php`

### 4. Introspecció real de YOOtheme Source

`TemplateSourceTypesTool` ja no fa
`class_exists('YOOtheme\\Builder\\Source')`, perquè aquestes classes poden
estar disponibles a través del contenidor sense ser autocarregables. Ara
resol el servei real amb `YOOtheme\app(...)` i comprova la introspecció.

Fitxers clau:

- `packages/mirasai-wp/src/Tool/TemplateSourceTypesTool.php`
- `packages/mirasai-joomla/packages/plg_mirasai_yootheme/src/Tool/TemplateSourceTypesTool.php`
- `docker/test-yootheme-source-runtime.php`

## Evidència verificada

### Suite local

`npm run release:prepare` va completar:

- 58 tests Node;
- tests de contracte, autenticació, MCP, WordPress i Joomla;
- proves d'estils, introspecció Source, base de dades i sandbox;
- lint PHP de tots dos hosts;
- builds Joomla, WordPress i router MCP.

També va passar `git diff --check`.

Comandes de reproducció:

```bash
cd "/Users/alexmiras/Documents/Claude Code Default/MovaMiraAI"
npm run test
git diff --check
npm run release:prepare
```

### Auto Vigatana — Joomla

Estat comprovat el 27 de juliol de 2026:

- Joomla `6.1.2`
- YOOtheme Pro `5.0.37`
- MirasAI `0.7.0`, instal·lat com a canària reversible sobre el build
  preliminar `0.6.3`
- `template/source-types`: `live_introspection`, 27 tipus;
  `Article` amb 23 camps
- el validador 0.7.0 rebutja arguments desconeguts i suggereix el nom correcte
- estil actiu: `nioh-studio:white-blue`, style id `12`
- 18 overrides Less i 84 bytes de Less personalitzat
- ETag final: `18e774e192c245b46663079767d64a62`
- compilació pinada: 272 imports, zero errors, LTR i RTL generats
- `style-update` normal i amb `variation: ""`: només `dry_run`, cap escriptura
- portada i CSS amb cache-busting: HTTP 200 i cos no buit
- els 18 assets relatius del CSS (9 fonts Poppins i 9 SVG): HTTP 200 i cos no buit
- cap avís final de l'eina d'estil

La canària va conservar el cos normalitzat del CSS. El canvi funcional va
corregir els fonts que abans quedaven com `url(poppins-...)` i provocaven 404,
passant-los a `url(../fonts/poppins-...)`.

Backups conservats:

- JetBackup complet del compte.
- Arxiu privat pre-canària:
  `/home/autovigatana/mirasai-backups/deploy-20260726-151308/mirasai-0.6.2-pre-canary.tar.gz`
- Backup privat fresc pre-0.7.0:
  `/home/autovigatana/mirasai-backups/deploy-20260727-183900-pre-0.7.0`
  (fitxers exactes 0.6.3, dump SQL complet, inventaris, checksums i ZIP desplegat).

No es va crear cap backup Akeeba. El paquet 0.7.0 desplegat es conserva amb
SHA-256 verificat dins el directori privat del rollback.

### Indústria Viva — WordPress

Estat comprovat el 27 de juliol de 2026:

- WordPress `7.0.2`
- MirasAI WP declara `0.6.2`

La canària anterior va validar la correcció de la base de fonts i la
compilació, però el paquet final no s'hi ha instal·lat. Abans de fer-ho,
repetir preflight, JetBackup/còpia privada del plugin, instal·lació amb WP-CLI,
lectura postinstal·lació, ETag, CSS i comprovació de tots els assets.

## Artefactes 0.7.0

Directori: `.release/v0.7.0/`

| Artefacte | SHA-256 |
| --- | --- |
| `pkg_mirasai-0.7.0.zip` | `cd78734531ca787af3fb942d72e61bdc64fe03b9672787d93d88b7016d9274b5` |
| `mirasai-wp-0.7.0.zip` | `7036a0e0bb9cbd5c5c5e4ad0bc5a39fcc845ee70b394e7536a6b914419648096` |
| `miras-mirasai-mcp-0.7.0.tgz` | `ec503bff930bc86bc2a87735e1a678c35b21ab175869c2dbb9d92c2cad81b29f` |

`install-urls.md` i `release-notes.md` del mateix directori són generats per
`scripts/prepare-release.mjs`.

## Decisions i invariants a preservar

- `vm` no és la frontera de seguretat per al worker remot.
- No executar cap worker si el SHA-256 no coincideix amb el fixat.
- No declarar èxit només perquè la configuració guardada és correcta: validar
  CSS servit, ETag i tots els assets relatius.
- No tornar a introduir `class_exists()` com a prova de disponibilitat de
  serveis registrats al contenidor YOOtheme.
- Preservar `wp_slash()` en escriptures WordPress de JSON serialitzat.
- `style-create` és WP-only fins que es dissenyi el cicle natiu de Joomla.
- No activar automàticament un child theme creat.
- No desplegar a Indústria Viva ni publicar la release sense aprovació
  explícita.
- Conservar els backups de producció fins que la versió estigui consolidada.

## Deute i riscos pendents

1. **Revisió final del diff.** Feta el 27-07-2026 abans de commitejar. Dues
   correccions aplicades: el rebuig del `ready` del runner podia matar el
   router amb una *unhandled rejection* si el fill fallava abans de la primera
   crida, i el README del router documentava `confirm: true` en comptes de
   `confirm_guarded_write: true`. No es va fer una auditoria exhaustiva línia a
   línia dels dos helpers d'estil (1.200 i 700 línies): es confia en la suite i
   en la verificació a Auto Vigatana.
2. ~~**Working tree gran i sense commit.**~~ Resolt.
3. **Publicació pendent.** Merge a `main`, tag `v0.7.0`, push i GitHub Release
   amb els tres artefactes. Verificar els feeds després de publicar.
4. **Indústria Viva pendent.** Auto Vigatana ja executa la canària 0.7.0 i té
   rollback privat verificat. Conservar-lo fins que la versió estigui
   consolidada. Instal·lar 0.7.0 a Indústria Viva només amb autorització i flux
   reversible.
5. **Joomla `style-create`.** Només abordar-lo amb un disseny específic del
   lifecycle Joomla; no és un port mecànic.

## Ordre recomanat per reprendre

1. Llegir aquest document i el handoff inicial de ManlleuActiva.
2. Executar `git status --short`, `git diff --check` i revisar el diff per
   blocs.
3. Executar `npm run test`.
4. Resoldre troballes de revisió abans de commitejar.
5. Preparar commits i tornar a executar `npm run release:prepare`.
6. Demanar aprovació abans de merge/tag/push/release o desplegament a
   Indústria Viva.

## Suggested skills

- `cms-remote-ops`: qualsevol preflight, backup, desplegament o rollback en
  WordPress/Joomla de producció.
- `/careful`: revisió del diff i preparació de canvis amb superfície de risc.
- `/investigate`: si una prova, ETag, worker o asset divergeix.
- `/qa`: verificació visual del CSS final i del *Preview all UI components*.
- `/ship`: només després d'autorització explícita per fer commits, tag, push i
  GitHub Release.
- `handoff`: actualitzar aquest document quan canviï l'estat de publicació o
  desplegament.
