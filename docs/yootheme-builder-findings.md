# YOOtheme Builder — registre de comportaments verificats

Registre acumulatiu i append-only. Cada entrada és un comportament del builder
de YOOtheme que ha costat una iteració fallida en un projecte real, amb
l'evidència que el confirma.

**Per què aquí.** El projecte MirasAI és el punt de posada en comú: és l'eina que
opera sobre sites reals i el lloc on conviuen els coneixements de WordPress i
Joomla. Les regles d'aquí es destil·len després cap a `yootheme-layout-json`
(autoria de JSON) i `yootheme-builder-ops` (operació live via MirasAI).
Vegeu «Destil·lació» al final.

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

### F-013 · MirasAI 0.8.2 substitueix la recepta del navegador de F-010

Publicat el 2026-08-17, poques hores després d'escriure F-010. El router
implementa compilació **headless**: executa el `worker.js` del propi site dins
un `vm` amb un shim de Web Worker, en un procés Node separat amb entorn buit i
Permission Model. És el mateix compilador, la mateixa versió i els mateixos
plugins que faria servir el Customizer.

- Eines: `mirasai/style-preview`, `mirasai/style-update`, `mirasai/style-verify`.
- Les instruccions del router deprecien explícitament les dues vies de F-010:
  «Style CSS writes use `mirasai/style-update` here — never Customizer save()
  and never WP-CLI/SQL config».
- El playbook de `system/diagnose` documenta els anti-patrons amb nom propi,
  inclosos `customizer_save_noop` i `wpcli_or_sql_style_config`, que són
  exactament els que vaig fer servir tot el 17/08.

Requisits: router local amb Node 20+, entrada a `sites.json` amb
`style_worker_sha256` **fixat contra el `worker.js` d'aquell site**, i
credencials. Cal tornar a fixar el hash després de cada actualització de
YOOtheme.

Trampa de CLI verificada: a mcp2cli, `--dry-run` és `store_true` i l'eina
assumeix `dry_run=true` quan el camp s'omet. Una escriptura real necessita JSON
per `--stdin` amb `dry_run=false` i `confirm_guarded_write=true`.

Conseqüència per a F-010: la recepta del navegador passa a ser l'últim recurs,
només quan el router no està disponible. La resta del contingut de F-010 (modes
de fallada silenciosa, verificació pel segell del CSS) segueix sent vàlida i
és el que explica per què calia el router.

### F-014 · Sense child theme, el Custom LESS no sobreviu a una actualització

`mirasai/style-verify` avisa: «No child theme is active. Custom styles and
portable brand Less have nowhere version-controllable to live, and would not
survive a theme update.»

A Agència Nord tot el sistema de disseny viu a `theme_mods_yootheme` del tema
pare. `DESIGN.md` secció 13 ja deia de crear un estil derivat abans de tocar
LESS, i no es va fer. L'eina `template/style-create` crea el child theme.

Ordre correcte: crear el child ABANS d'acumular-hi LESS, perquè després cal
migrar-lo. I compte amb l'anti-loop `child_theme_parent_mods`: amb un child
actiu, la configuració viu a `theme_mods_<child>` i escriure la fila del pare
provoca un avortament per CAS.

### F-015 · La cau de secrets del router mor amb el procés

`packages/mirasai-mcp/src/secrets.mjs`: `const secretCache = new Map()`. És una
cau **dins del procés**. Cada invocació de `mcp2cli --mcp-stdio` arrenca un Node
nou, la cau neix buida i torna a cridar `op read`, amb el prompt d'1Password
corresponent. Amb desenes de crides per sessió, és insuportable.

`secret_ttl_seconds` no ho arregla: `DEFAULT_SECRET_TTL_SECONDS = 3600` ja
s'aplica encara que el camp no hi sigui, i el procés mor molt abans que
l'hora. Pujar el TTL no canvia res.

Pal·liatiu que funciona avui: sessió persistent de mcp2cli
(`--session-start` i `--session`), que manté viu un sol procés del router.
Mesurat: primera crida 11,1 s amb prompt, següents 2,7 s sense. Compromís: el
dimoni manté la credencial desxifrada en memòria mentre viu.

**Proposta per a MirasAI:** cau de secrets que sobrevisqui entre processos,
amb el mateix patró que els wrappers `~/.config/ai/env.sh` d'aquest entorn:
1Password com a font de veritat i un mirall al Keychain de macOS amb TTL, de
manera que una invocació per crida tampoc no torni a demanar autorització. Això
sí que faria que el TTL fos una palanca real, i llavors tindria sentit
preguntar-lo o configurar-lo per site.

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
| F-018 + workflows live (add / bind / clone+rebind / `stale_etag` / canal Style) | skill `yootheme-builder-ops` | no és autoria de JSON; és el camí MirasAI. Apunta a aquest registre i a `docs/agent-routes.md`; no els duplica |

### Estat de la destil·lació

Aplicada el 2026-08-17. F-001 a F-004 a `core-builder.md`, F-005 a F-009 a
`html-uikit-and-css.md`, F-010 i F-011 a `mirasai-runtime.md`. F-012 a memòria,
perquè no és de YOOtheme. El `SKILL.md` recull la convenció del registre.

Skill d'operació live el 2026-08-18: canònic
`~/.codex/skills/yootheme-builder-ops` (mateixos enllaços que
`yootheme-layout-json`). Recull casos de clone+rebind en aquest fitxer.
Disseny del write atòmic (no implementat): `docs/template-clone-rebind.md`.

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

### F-016

**Data:** 18/08/2026 · **Versió:** YOOtheme Pro 5.0.40, UIkit 3.25.21, WordPress 7.0.4 · **CMS:** WordPress

**Símptoma.** Una barra de modalitats enllaça amb `href="#servicios=ftl"` per obrir
la pestanya corresponent d'un Switcher que viu molt més avall. En clicar-hi, la
pestanya canvia correctament però la pàgina no es mou. Com que la secció de
destinació és a uns 4.300 px, l'usuari no veu absolutament res i conclou que
l'enllaç està trencat.

**Causa verificada.** L'href no és una àncora. El navegador cerca un element amb
l'id sencer `servicios=ftl`, i `document.getElementById('servicios=ftl')` retorna
`null`. No hi ha destinació, per tant no hi ha salt. Un `href` que codifica estat
com a `#ancora=valor` **mai** produeix desplaçament natiu, encara que existeixi un
element amb l'id `ancora`. Mesurat a la pàgina: després del clic, `window.scrollY`
= 46 amb `#servicios` situat a 4314 px.

Codi que ho demostra, `wp-content/mu-plugins/agencia-nord-service-deeplink.php`
v1.3.0: el gestor de clic evitava deliberadament `preventDefault()` amb el comentari
«el salt a #servicios ha de seguir funcionant». La premissa era falsa.

**Per què va passar el QA.** Es va validar carregant l'URL amb el hash ja posat,
que entra pel camí `fromHash()` i sí que desplaça explícitament. El camí que fa
servir l'usuari real —clicar des de la mateixa pàgina— no es va provar mai.

**Regla accionable.** Si es codifica estat al hash amb la forma `#ancora=valor`,
el desplaçament és responsabilitat del script: cridar `preventDefault()`, fer
`history.pushState()` per mantenir l'URL compartible i desplaçar a mà. Fer servir
`pushState` i no assignar `location.hash` evita disparar `hashchange` i que el
salt es faci dues vegades. La navegació enrere continua coberta perquè entre
estats de hash sí que hi ha `hashchange`.

**Regla de verificació, més general.** Provar una interacció des de totes les vies
d'entrada, no només de la que s'acaba d'implementar. En un deep link n'hi ha tres i
són codi diferent: càrrega amb hash, clic amb la pàgina ja oberta, i enrere/endavant.

### F-017

**Data:** 18/08/2026 · **Eina:** navegador integrat (pestanya automatitzada) · **CMS:** indiferent

**Símptoma.** `element.scrollIntoView({behavior:'smooth'})` no desplaça res. Ni a
l'instant ni un segon i mig després. Sembla que el codi no s'executi.

**Causa verificada.** La pestanya reporta `document.visibilityState === 'hidden'`
encara que se la posi en primer pla amb `tabs_select`. Chrome no executa animacions
de desplaçament en un context ocult. Prova discriminant a la mateixa pàgina i el
mateix element: `behavior:'auto'` porta `scrollY` a 3407, i `behavior:'smooth'`
el deixa a 0. La diferència és exclusivament el mode d'animació.

Del mateix origen: `requestAnimationFrame` queda escanyat a uns pocs fotogrames
per segon, de manera que qualsevol mesura de durada feta amb rAF en aquest entorn
és brossa (mesurat: 3 fotogrames en 3.589 ms). És la mateixa causa que fa que un
vídeo amb `autoplay` aparegui pausat, cosa que ja havia produït un fals positiu.

**Regla accionable.** En aquest navegador no es pot verificar res que depengui
d'animació o de temps real: desplaçament suau, transicions, autoplay, durades amb
rAF. Es pot verificar l'estat final amb `behavior:'auto'`, que sí que s'aplica.
Davant d'un «no es mou», fer sempre la prova discriminant auto/smooth abans de
tocar el codi. El que no es pugui separar així, s'ha de mirar amb ulls humans i
dir-ho clarament en lloc de donar-ho per verificat.

**Conseqüència de disseny.** Si una interacció depèn només de `behavior:'smooth'`,
en un context sense animació no passa absolutament res i l'usuari es queda on era.
Convé una xarxa: si passats uns centenars de mil·lisegons `scrollY` és exactament
el d'abans, fer el salt instantani. Comparar amb la posició exacta de partida, i no
amb una franja, evita estirar l'usuari que hagi començat a desplaçar-se pel seu
compte.

---

## 2026-08-18 · YOOtheme Pro 5.0.24 · Joomla 5.4.5 · lab LXC 103

Projecte: experiment Fase 0 (decisor de prioritat post-revisió YT Builder).
Write path MirasAI 0.8.2 (JSON directe, sense `Builder::load(context:save)`).
El Customizer de page templates desa per `TemplateController::saveTemplate`,
que és exactament `Builder::withParams(['context'=>'save'])->load(...)`.
No es va clicar la UI Vue; es va invocar aquest camí PHP, que és el que
persistiria el layout després d'un Save. Diffs crus:
`docker/fixtures/fase0-save-transform/`.

### F-018 · El save transform omple defaults; no treu nodes al nostre flux

**Símptoma esperat (no observat).** WootsUp cita corrupció si s'escriu JSON
sense el transform de `context:save`. Al lab, després d'un write MirasAI,
tornar a passar el layout per aquest transform no perd nodes, no canvia
`type` i no buida `content`.

**Escenari 1 — edició quirúrgica.** Template ja normalitzat pel primer save.
`template/element-update-props` canvia el `content` d'un `headline`. El save
posterior és idempotent: 6 nodes abans i després, zero claus noves.
Evidència: `04-compare-scenario1.json`.

**Escenari 2 — subarbre nou amb props mínims.** `template/element-add` d'un
`headline` amb només `{content:"minimal add"}`. El save afegeix
`image_align`, `image_margin`, `title_element`; el node i el text es queden.
Evidència: `06-compare-scenario2.json`, `06-after-add-then-save.json`.

**Escenari 3 — migració 5.0.24 → 5.0.40.** Instal·lar el zip no muta el JSON.
El save transform de 5.0.40 sobre el layout escrit a 5.0.24 no perd nodes ni
canvia types; només omple els mateixos defaults que 5.0.24 (el `headline`
afegit per MCP amb props mínims). Evidència: `12-compare-scenario3.json`.

**Sonda extra — type desconegut.** Un node `mirasai_unknown_probe` sobreviu
al save (ni el treu ni li canvia el type). Això **no** demostra que packs
de tercers (Flart, YOOessentials) siguin igual de inerts: només que un type
inventat no activa un prune. Evidència: `08-unknown-type-after.json`.

**Què sí que fa el primer save** sobre JSON cru (el que escriuria un agent
sense Customizer previ): omple defaults de secció/columna/headline/text
(`style: default`, `title_element: h1`, `width: default`, etc.) i posa
`layout.version` a string buit. Això encaixa amb F-001 (absent ≠ buit): el
Customizer omple el que nosaltres ometem. No és corrupció segons el criteri
acordat (pèrdua de nodes / canvi de type / props que trenquen el render).

**Límits.** Només elements natius. No hi ha round-trip de la UI Vue (ids/names
que el JS pugui afegir abans del POST). `SaveBuilderLayouts` del Customizer
de tema només toca footer/menu, no page templates. Articles (`PageController`)
fan el mateix `load(context:save)` però no s'han exercitat aquí.

**Regla accionable.** Amb el flux habitual (arbre ja normalitzat +
`element-update-props` / `element-add` natiu), el save del Customizer no
corromp el JSON de MirasAI en 5.0.24. El transform continua sent hardening
útil (omple defaults, alinea amb el que el Customizer escriuria), no un
fix urgent de corrupció. Si s'implementa, l'invariant de no-pèrdua-de-nodes
es queda: un type de tercer que sí que es prunegi seria el vector, no la
cura.

### Cas clone-rebind 1 · BIT Vic (comunitat.congresbit.cat)

**Data:** 2026-08-18 · **CMS:** Joomla 6.1.3 · **YOOtheme:** 5.0.40 ·
**MirasAI:** 0.8.2 (MCP `article_id`, sense `mode=` al host live)

**Flux confirmat (no és un clone a mitges).** Es duplica tota la pàgina de
l'edició anterior. Les files que encara no tenen material es deixen amb
`props.status=disabled` (i el botó d'àncora corresponent també). El Dynamic
Source vell es queda de placeholder; quan hi ha carpeta/CSV/galeria nova,
només es canvia el source.

**Exemple:** article 28 «BIT Vic 2026», etag vist
`373c7c973105472c012de5e8383ecee10dacba3581a334914c6a9064f8dcf481`.

| Path | Source / estat |
|---|---|
| `root>section[0]>row[3]>…>grid_item[0]` | CSV `csv013C3D.records` → `title=nom_i_cognom`, `meta=company` |
| `root>section[0]>row[4]>…>fragment[1]` | DOCman `edicio-2026-vic-pres` |
| `root>section[0]>row[5]>…>gallery_item[0]` | DOCman `edicio-2026-vic-pos` |
| `root>section[0]>row[6]` (`id=visual`) | `status=disabled`; gallery encara `docmansource` `edicio-2024-vic-vt` |
| `root>section[0]>row[2]>…>button_item[3]` | «Visual Thinking» `status=disabled`, `link=#visual` |
| `root>section[0]>row[7]>…>gallery_item[0]` | `files` `images/galeries-de-fotos/congres-bit-vic/edicio-2026-vic/*` |
| `root>section[0]>row[8]` Vídeos | sense list-source YouTube (a 2024/2025 sí `youtubeChannel1C7D1A.videos`) |

**Implicació per `clone-rebind`.** Ha de clonar pàgina (no una sola secció),
preservar `status=disabled` i no «arreglar» sources de nodes disabled. El
`leaf_map` útil és carpeta/CSV/pattern per edició (`edicio-YYYY-vic-*`,
`csv….records`), no un rebind ceg d'un únic `query_path`.

### Cas clone-rebind 2 · Indústria Viva (draft, no la portada)

**Data:** 2026-08-18 · **CMS:** WordPress 7.0.4 · **YOOtheme:** 5.0.40 ·
**MirasAI:** 0.8.2 WP · Flart `fs_grid` / `fs_table`

**Setup.** Portada `post_id=65` (`inici`, `page_on_front`) duplicada amb
WP-CLI `--from-post` a draft **548** `mirasai-lab-clone-portada`. Backup:
`/home/industriaviva/mirasai-backups/20260818-clone-rebind-lab-20260818T182935Z`.
La 65 no s'ha mutat (267215 bytes). REST anònim 503 (`industria_viva_site_locked`);
MCP autenticat 200.

**Prova MCP al draft.** `template/element-clone` de
`root>section[7]` («Cursos · Instal·lacions») → `root>section[8]`. Després
`template/element-source-set` amb `source` cru (query nested + `slice`):
`ivCurss.customIvCurss` `terms: [7]` → `[9]`. Field mappings intactes
(`title`, `iv_ambitString`, `iv_municipiString`). Dry-run → confirm amb
`if_match`. La secció 7 del draft continua a `terms: [7]`.

**Fulles extra.** El text buit `…>text[1]` (`_condition: #index`) no es va
rebindejar: un `clone-rebind` ha d'incloure també les fulles de visibilitat,
no només l'`fs_grid_item` de la llista.

**Implicació.** Segon cas real: clonar **secció** (no pàgina) + un
`query_arguments.terms` de CPT ACF. El write atòmic hauria d'acceptar un
`leaf_map` per arguments de query (terms/carpeta), no només canviar
`source_name`. Contracte: `docs/template-clone-rebind.md`.

### Read-modes desplegats · 2026-08-18

`template/read` i `template/element-list` accepten `mode=full|outline|bindings_only`.
L'etag continua sent del layout complet. `bindings_only` no porta `raw_source`.

| Host | Estat |
|---|---|
| industriaviva.cat (WP, plugin a disc) | desplegat; smoke `post_id=548` outline + bindings_only (64 bindings) |
| lab LXC 103 Joomla | desplegat; smoke `key=mirasai-fase0` outline (tree, sense layout) i bindings_only (0, el fixture no té sources) |
| lets.cat / comunitat.congresbit.cat | **no** desplegat |

Bug de desplegament WP: `bindingsOnlyFromLayout` era `private` al trait usat
pel pare `TemplateReadTool`; `TemplateElementListTool` (fill) no hi podia
cridar. Corregit a `protected` als dos CMS.

`outline` no exposa `props.status`; per veure files disabled cal
`element-list` full o `element-read` fins que clone-rebind existeixi
(`skipped_disabled` al dry-run).

### F-019 · Clonar un element duplicava el seu `props.id` i trencava l'àncora

**Data:** 19/08/2026 · **Versió:** MirasAI 0.8.2 · **CMS:** WordPress i Joomla

**Símptoma.** Clonar una fila que té ID (BIT Vic: `root>section[0]>row[6]`,
`id=visual`) deixava dos elements amb `id="visual"` a la mateixa pàgina. El
botó d'àncora `#visual` continuava saltant a l'original: la còpia era
inabastable per enllaç, i l'usuari ho llegia com «el clone no ha funcionat».

**Causa verificada.** `YoothemeElementNavigator::cloneElement()` inseria
`$source['element']` verbatim, `props` incloses. YOOtheme renderitza
`props.id` com l'atribut `id` de l'HTML, i el navegador resol `#id` al primer
node del document. Mateix defecte a `cloneElementBeside()` i als dos hosts.
És la germana de F-016: allà l'àncora no saltava perquè el destí no existia;
aquí no salta perquè n'hi ha dos i guanya el vell.

**Fix.** El clone reserva ids: qualsevol `props.id` de la còpia que xoqui amb
un id ja present al layout es renombra (`visual` → `visual-2`, mantenint
l'string original sencer perquè un id datat com `edicio-2026` no perdi l'any),
i els props que valen exactament `#idvell` **dins del subarbre copiat**
segueixen el rename. Els enllaços de fora de la còpia no es toquen. Les tools
`template/element-clone` dels dos hosts retornen `renamed_ids` quan hi ha
hagut cap rename, també al dry-run.

Els ids que el layout d'origen ja duplicava es queden duplicats: desduplicar
l'original no és feina del clone.

**Regla accionable.** Qualsevol operació que copiï un subarbre de Builder ha
de tractar `props.id` com un recurs únic del document, no com una prop més.
Després d'un clone, comprovar `renamed_ids` abans d'assumir que un `#àncora`
de la còpia apunta on toca.

### F-020 · `status=disabled` viu al contenidor, no a la fulla amb el binding

**Data:** 19/08/2026 · **CMS:** WordPress i Joomla

**Observació.** Quan al Builder s'apaga un bloc, `props.status=disabled` queda
a l'element que s'ha apagat — típicament la **fila** o la secció — i no baixa
als descendents. A BIT Vic, `root>section[0]>row[6]` porta `status=disabled` i
el `gallery_item` de dins conserva el `docmansource` `edicio-2024-vic-vt` com a
placeholder, sense cap marca pròpia.

**Per què costa una iteració.** `template/element-list mode=bindings_only`
retorna una llista **plana**. Un agent que hi construeixi un mapa de rebind veu
el `gallery_item` amb el seu source i cap senyal que estigui apagat: la relació
d'ascendència, que a l'arbre és òbvia, a la llista plana no existeix. El
resultat és mapar un placeholder que ningú volia tocar.

**Regla accionable.** `bindings_only` reporta `disabled_by` amb el path de
l'avantpassat (o d'ell mateix) que està apagat; si el camp hi és, aquell binding
és un placeholder i no s'ha de rebindejar tret que es demani explícitament.
`outline` reporta `status` per node i l'ascendència es llegeix del niat. Els dos
camps s'ometen quan no diuen res. Regla general: qualsevol vista plana d'un
arbre ha de tornar explícitament el context que la planitud destrueix.

### F-021 · Un rebind oblidat no dona cap error: filtra malament i calla

**Data:** 19/08/2026 · **CMS:** WordPress · **YOOtheme:** 5.0.40 · **Site:**
industriaviva (compte de desenvolupament, draft 548)

**Observació.** El clone del 18/08 va quedar a mitges i hi va **seguir** un dia
sencer sense que res ho denunciés. A `root>section[8]`:

| Fulla | `terms` |
|---|---|
| `…>fs_grid[0]>fs_grid_item[0]` | `[9]` (rebindejada) |
| `…>text[1]` (`_condition`) | `[7]` (oblidada) |

Totes les altres seccions de cursos quadraven: 5→2/2, 7→7/7, 10→9/9, 12→12/12,
14→16/16. Només la clonada estava desparellada.

**Per què no es veu.** Un binding amb l'argument equivocat és un binding
perfectament vàlid. YOOtheme no valida que dues fulles germanes que consulten la
mateixa source hi vagin amb els mateixos arguments, i la condició de visibilitat
d'una llista buida no crida l'atenció de ningú: la secció simplement es comporta
com si el filtre fos un altre. No hi ha error, ni log, ni res al Customizer.

**Com es detecta.** `template/element-list mode=bindings_only` i comparar les
fulles que comparteixen `query_path` dins d'una mateixa secció. Les que consulten
la mateixa source i tenen arguments diferents són sospitoses. La forma `leaves[]`
d'`element-source-set` ho fa explícit: el dry-run de la crida incompleta reporta
la germana com a `untouched` amb el mateix `query_path`, que és exactament el
senyal que faltava el 18/08.

**Verificat.** Corregit al draft 548 amb un sol write (`kept` la graella,
`rebound` el text de `[7]` a `[9]`); diff dels 64 bindings abans i després: un
sol canvi, i només els seus `terms`.

**Regla accionable.** Després de clonar i rebindejar, no et refiïs de mirar la
pàgina: compara els arguments de totes les fulles que comparteixen `query_path`
a la secció copiada. Un rebind a mitges no es queixa mai.
