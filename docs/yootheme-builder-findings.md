# YOOtheme Builder — registre de comportaments verificats

Registre acumulatiu i append-only. Cada entrada és un comportament del builder
de YOOtheme que ha costat una iteració fallida en un projecte real, amb
l'evidència que el confirma.

**Per què aquí.** El projecte MirasAI és el punt de posada en comú: és l'eina que
opera sobre sites reals i el lloc on conviuen els coneixements de WordPress i
Joomla. Les regles d'aquí es destil·len després cap a la skill
`yootheme-layout-json`, que és el que es carrega en el moment de generar
layouts. Vegeu «Destil·lació» al final.

**Com afegir una entrada.** Data, versió i CMS on s'ha vist, símptoma observable,
causa verificada (amb la ruta del template o del codi que ho demostra) i la
regla accionable. Sense l'evidència no entra: un comportament suposat és pitjor
que cap nota.

---

## 2026-08-17 · YOOtheme Pro 5.0.40 · WordPress · agencianord.com

Projecte: landing d'Agència Nord, reconstrucció amb elements natius sobre
`post_content` de la pàgina 244. Totes les causes verificades llegint
`wp-content/themes/yootheme/packages/builder/elements/*/templates/`.

### F-001 · Absent no és el mateix que buit

El patró mare. Apareix tres vegades i explica la majoria de les hores perdudes.

**Columnes.** Un node `column` desat amb `props: null` en comptes de `{}` fa que
YOOtheme no pugui resoldre l'amplada, emeti `uk-width-1-1` i marqui la fila amb
`uk-grid-stack`: la fila s'apila sencera.

- Símptoma: files que apareixen apilades tot i tenir un `layout` correcte.
- Evidència: `elements/column/templates/template.php`, línia
  `'uk-width-1-1 {@!width_default} {@!width_small} …'`.
- Origen habitual: helpers que ometen la clau quan el diccionari és buit.
- Regla: emetre sempre `props`, encara que sigui `{}`.

**Color de fons de secció.** El template emet el fons amb la condició
`{@!style}`. Si la clau `style` no hi és, YOOtheme hi posa `default` i el
`background_color` no s'aplica: la secció surt blanca.

- Regla: `'style': ''` explícit sempre que es faci servir `background_color`.

**Regla general del sistema:** escriure la clau amb valor buit, no ometre-la.

### F-002 · Les amplades de columna no venen del `layout` de la fila

El template de `row` només fa servir `layout` per decidir si força
`uk-child-width-1-1`. Les amplades reals surten dels props de la columna:
`width_default`, `width_small`, `width_medium`, `width_large`, `width_xlarge`.

- Símptoma: `layout: '3-5,2-5'` i la fila igualment apilada.
- Regla: una fila 60/40 es fa amb `width_medium: '3-5'` i `'2-5'` a les
  columnes. El `layout` es manté per coherència amb el constructor.
- Complement: `layout` només accepta fraccions simples. `7-12,5-12` no és vàlid.

### F-003 · `list_item` no renderitza el camp `title`

Cap template de `list_item` referencia `title`: només serveix com a etiqueta del
node dins el constructor.

- Regla: si cal títol visible, va dins de `content` amb `<strong>`.
- Complement: els filets entre items venen de `list_style: 'divider'`, no d'una
  clau `divider`, que no existeix en aquest element.

### F-004 · L'element `image` no té rètol

El seu camp `text` no es renderitza com a caption.

- Regla: per etiquetar una fotografia, element `text` a part amb els camps
  natius de posició (`position: 'absolute'`, `position_bottom`,
  `position_left`, `position_z_index`) i `position: relative` al contenidor.

### F-005 · `uk-column-*` pot ser una classe morta

El camp `column` del `list` emet `uk-column-2@s`, però si el component `column`
d'UIkit no entra en la compilació del tema no hi ha cap regla que la casi i
`column-count` es queda a `auto`.

- Símptoma: la classe hi és al DOM i no fa res.
- Regla: verificar sempre que una classe emesa tingui regla que la casi abans de
  donar-la per bona. Les columnes múltiples, al CSS propi.

### F-006 · `.el-content` també porta la classe `uk-panel`

Una regla sobre `.uk-panel` dins una graella s'aplica dues vegades: a la targeta
i al seu contingut, i dibuixa una caixa amb vora dins de cada targeta.

- Regla: el node de la targeta és `.el-item`.

### F-007 · `.el-element` és un àlies només dins el camp `css` d'un element

YOOtheme el reescriu al selector propi de l'element. Al LESS global no existeix.

- Regla: al LESS global, apuntar directament a la classe de l'element.
- Complement: en un element de text, la classe va al mateix node que porta el
  text, no a un fill.

### F-008 · La imatge de fons d'una secció no és a la secció

Va en un `div` fill amb `uk-background-norepeat uk-background-cover`.

- Símptoma: mesurar `backgroundImage` sobre la secció dona sempre `none` i sembla
  que la imatge no carrega.
- Complement: les rutes porten barra inicial, `/wp-content/uploads/...`.

### F-009 · `media_overlay` i `media_overlay_gradient` se sumen

Si es posen tots dos, els dos s'apliquen i la fotografia queda molt més fosca
del que suggereix cada valor per separat.

### F-010 · YOOtheme no compila LESS al servidor

YOOtheme no inclou compilador LESS al servidor. De forma nativa, `less.js`
compila dins el Customizer i el CSS es puja per AJAX;
`packages/styler/src/StyleController.php` l'escriu a
`themes/yootheme/css/theme.<id>.css`. MirasAI sí que pot executar el mateix
`worker.js` sense navegador des del router local Node, sempre amb el SHA-256 del
worker fixat.

- Conseqüència: editar `theme_mods_yootheme` per WP-CLI **no** regenera el CSS.
  El site continua servint el CSS antic indefinidament. Trampa silenciosa.
- Via principal verificada: `system/diagnose` → comprovar que `tools/list` inclou
  `mirasai/style-preview` → `mirasai/style-update` amb `site_id`, dry-run, ETag i
  confirmació. La prova live d'Indústria Viva va recompilar YOOtheme 5.0.40 i va
  escriure el `theme_mods` del child sense obrir el Customizer.
- Últim recurs quan el router no està disponible: obrir el Customizer amb
  `&site=<URL>`, esperar l'iframe,
  **canviar un control de debò i desfer-lo** (només això desperta el worker), i
  després `await window.yootheme.store.useConfigStore().save()`.
- Modes de fallada silenciosa: `save()` amb `dirty === false` és un no-op;
  `change(clau, valor)` no assigna valor, només marca `dirty`; recarregar el
  Customizer després d'editar la BD no posa `dirty` a `true`.
- Verificació: la primera línia del CSS porta
  `/* YOOtheme Pro v… compiled on <ISO8601> */`.
- Complement: el CSS surt minificat en poques línies. Comptar ocurrències amb
  `grep -o … | wc -l`, mai amb `grep -c`. Els decimals es minifiquen
  (`-0.03em` → `-.03em`) i les `url()` absolutes es reescriuen a relatives.

### F-011 · Escriure només el JSON de `post_content` funciona, però deixa rastre

A WordPress, `post_content` conté el comentari amb el JSON del layout **i** una
càrrega HTML antiga renderitzada. Substituint només el JSON, YOOtheme renderitza
correctament des del JSON, però l'HTML previ queda ranci al camp.

- Estat: acceptable per a una pàgina en esborrany sota revisió; no verificat per
  a pàgines publicades ni per a rendiment de cerca interna.
- Nota: `references/mirasai-runtime.md` de la skill ja adverteix que «replacing
  only the JSON comment is not enough». Aquesta entrada matisa que a la pràctica
  renderitza, però confirma que la via robusta és reproduir el pipeline de desat
  del Builder.
- Pendent: decidir si MirasAI ha d'oferir una escriptura que refresqui les dues
  parts.

### F-012 · Límits del navegador automatitzat quan es verifica

No és de YOOtheme, però contamina qualsevol verificació.

- El navegador integrat reporta `document.visibilityState: "hidden"`. Tot el que
  depengui de visibilitat o d'`IntersectionObserver` es mesura com a inactiu
  encara que funcioni bé: vídeos de fons (UIkit els pausa correctament i sembla
  un defecte d'autoplay), lazy-load (`naturalWidth` a 0, `currentSrc` buit),
  scrollspy i sliders amb autoplay.
- Retorna captures en blanc després de fer scroll. Via fiable:
  `transform: translateY(-Npx)` sobre `.tm-page` amb `scrollY` a zero.
- Regla: abans de reportar un defecte de reproducció, càrrega o animació,
  comprovar `visibilityState` i provar `play()` a mà.

---

## Destil·lació cap a les skills

El repartiment proposat, seguint la separació que ja tenen les referències de
`yootheme-layout-json`:

| Entrades | Destí | Per què |
|---|---|---|
| F-001, F-002, F-003, F-004 | `references/core-builder.md` | són semàntica d'autoria del JSON: es necessiten en el moment de generar-lo |
| F-005, F-006, F-007, F-008, F-009 | `references/html-uikit-and-css.md` | són fets de render i de CSS contra la sortida de YOOtheme |
| F-010, F-011 | `references/mirasai-runtime.md` | són operació sobre un site real, que és el que cobreix aquesta referència |
| F-012 | guia de `browse`/`qa` i memòria | no és de YOOtheme; és límit de l'eina de verificació |

### Estat de la destil·lació

Aplicada el 2026-08-17. F-001 a F-004 a `core-builder.md`, F-005 a F-009 a
`html-uikit-and-css.md`, F-010 i F-011 a `mirasai-runtime.md`. F-012 a memòria,
perquè no és de YOOtheme. El `SKILL.md` recull la convenció del registre.

### Unificació de la skill — RESOLTA el 2026-08-17

Hi havia quatre camins cap a `yootheme-layout-json`, dos dels quals eren forks
vells de març que ombregaven el canònic quan es treballava dins el projecte
«Claude Code Default». L'efecte pràctic: allà dins els agents perdien
`references/mirasai-runtime.md` i la meitat del `SKILL.md`, inclosa tota la
secció de persistència real i el procediment segur per actualitzar templates
live. És a dir, precisament el coneixement que hauria evitat l'error de F-011.

Comprovat abans de tocar res: les dues còpies del projecte tenien **zero línies
úniques** respecte del canònic, i el canònic tenia un fitxer més. Superconjunt
estricte, per tant unificar no podia perdre res.

Estat final, una sola font:

```
~/.codex/skills/yootheme-layout-json                              directori real
~/.claude/skills/yootheme-layout-json                             → enllaç
~/Documents/Claude Code Default/.claude/skills/yootheme-layout-json → enllaç
~/Documents/Claude Code Default/.codex/skills/yootheme-layout-json  → enllaç
```

Els tres enllaços resolen els 11 fitxers i llegeixen les troballes noves.
Còpia de seguretat dels dos directoris substituïts a
`~/.agent-system/backups/20260817-1806-yootheme-skill-unify/`, verificada
idèntica als originals abans d'esborrar-los.

Les altres dues skills de yootheme (`yootheme-joomla-translation` i
`yootheme-wpml-translation`) ja seguien aquesta convenció des del principi.
