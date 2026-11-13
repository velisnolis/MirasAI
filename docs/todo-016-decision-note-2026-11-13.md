# Nota de decisió: contingut no YOOtheme (TODO-016)

2026-11-13. Segona versió, després d'una ronda de revisió creuada (GPT-6.1 Sol
i Grok 4.7, i una segona només amb GPT-6.1 Sol; vegeu «Revisió creuada»). Contrastat amb `main` a `6f314b8`
(MirasAI 0.14.0). No s'ha escrit codi. Les API del nucli s'han provat després
de la primera ronda als dos webs de proves (vegeu «Proves»).

## La pregunta

`TODOS.md`, TODO-016: en una feina real del 13/11 en un WordPress amb YOOtheme i
MirasAI 0.13, refer pàgines va anar per MCP, però tres coses van anar per SSH de
root, CageFS i WP-CLI perquè MirasAI no les cobreix:

- pujar fitxers a la mediateca (imatges amb títol) i, després, posar el text
  alternatiu a 55 imatges;
- canviar l'estat, el pare i el slug d'una pàgina;
- tocar el menú (no consta quina operació).

Proposta apuntada: `media/import`, `content/update` i eines de menú al host,
totes `guarded_write`. Lligat: F-025 de `docs/yootheme-builder-findings.md`
(una pàgina nova no té layout i `template/element-add` no el pot crear).

Què es va fer exactament, segons les notes de la feina (consultades després de
la revisió):

- **Mediateca:** 55 logos en PNG pujats amb el nom de l'entitat com a text
  alternatiu de l'adjunt, corregit després per WP-CLI; abans, cinc fotos i
  tres fitxes en PDF. L'alt era, doncs, el de la mediateca i anava lligat a la
  pujada.
- **Pàgines:** una pàgina moguda a l'arrel (canvi de pare i, per tant, d'URL)
  i una altra passada a esborrany. Un enllaç de la portada cap a la URL antiga
  es va haver d'arreglar a part.
- **Menú:** dos elements convertits en enllaços propis amb àncora i un canvi
  d'ordre. És edició d'elements, no assignació a una posició.

Cal decidir si MirasAI ha de cobrir contingut que no és de YOOtheme i, si ho
fa, on ha de viure cada peça.

## Límits del producte que ja estan decidits

- `docs/wootsup-comparison-proposal-2026-11-09.md`: MirasAI es diferencia per
  verificar i auditar, per les escriptures amb CAS, la compilació d'Style, la
  traducció i l'operació del CMS; no per «la IA et fa la pàgina».
- El «CRUD de mòduls i articles: possible, no prioritari» és a la secció
  **«Descartat»** d'aquell document (l. 179). `content/update` reobre, doncs,
  una línia descartada el 9/11: cal dir-ho i decidir-ho expressament.
- Quatre nivells de risc: lectura, `safe_write`, `guarded_write` (dry-run i
  confirmació; `if_match` a les eines que tenen un estat per comparar;
  `preview_token` opcional) i `dangerous_exec` amb elevació.
  `menu/migrate-theme-to-modules` és `guarded_write` sense `if_match`: no és
  un model a copiar.
- Els dos hosts són miralls: el que es faci a WordPress s'ha de poder dir què
  vol dir a Joomla.
- `AGENTS.md`: l'Style només el compila el router local. És el precedent d'una
  capacitat que només té el router.

## Evidència del codi (0.14.0)

- **F-025 continua igual.** `packages/mirasai-wp/src/Tool/YoothemeWpHelper.php`:
  `resolvePostTarget()` torna `post_layout_missing` (l. 940) quan
  `loadPostLayout()` (l. 336) no troba ni meta ni comentari; `writePostLayout()`
  (l. 370) només sap escriure sobre una font ja detectada. A Joomla,
  `article_layout_missing` a
  `plg_mirasai_yootheme/src/Tool/AbstractTemplateElementWriteTool.php` (l. 405).
- **`content/translate` no té el mateix patró als dos hosts.** A WordPress és
  `RISK_SAFE_WRITE`, amb `dry_run` i `if_match` però sense confirmació
  (`packages/mirasai-wp/src/Tool/ContentTranslateTool.php`, l. 96-102). A Joomla
  és `RISK_GUARDED_WRITE` amb `confirm_guarded_write`
  (`lib_mirasai/src/Tool/ContentTranslateTool.php`, l. 102 i 117).
- **El pare d'una pàgina de WordPress no es llegeix enlloc.**
  `ContentReadTool` no el torna (l. 83-103) i `contentEtag()` (l. 139) no el
  posa al hash. Un canvi de pare fet amb `wp_update_post` sí que invalida
  l'ETag, perquè canvia `post_modified_gmt`.
- **Joomla ja escriu menús** dins de `content/translate`: `create_menu`,
  `menu_id`, `target_menutype` (l. 36-39 i 570-700). La URL pública d'un
  article la fixa l'element de menú, no l'`alias` ni el `catid` sols.
  WordPress no té cap eina que toqui menús.
- **Ja hi ha un cas real de menús anterior: F-028** (projecte del 19/09).
  Sense `wp menu location assign <menu> navbar`, YOOtheme no pinta la
  capçalera. És assignar un menú a una posició del tema, no editar elements.
- **`wp/ability-call` només executa** abilities amb `readonly=true` o d'una
  llista tancada de noms `ai/*`. Bloqueja expressament `ai/editorial-updates`
  i `mcp-adapter/execute-ability`, i tot el que declara `destructive=true`
  (`packages/mirasai-wp/src/Tool/WordPressAbilityPolicy.php`).
- **`site/public-fetch` valida URL amb una regla de mateix host**
  (`PublicPageInspector::resolveUrl()`): només http/https, sense credencials,
  mateix host i port, cada redirecció revalidada. A Joomla sense `live_site`,
  a més, la connexió es fixa a `SERVER_ADDR`. No serveix per baixar un fitxer
  d'un altre host.
- **Cap eina del host accepta fitxers**, tret de `file/write` de Joomla:
  `dangerous_exec`, base64 opcional, i només dins de `media/mirasai/sandbox/`
  (`lib_mirasai/src/Sandbox/PathValidator.php`, `validateWrite()`).
- **El router** parla dos protocols per web (`mirasai` i `mcp`,
  `packages/mirasai-mcp/src/config.mjs`, l. 6), resol la credencial
  (`site-client.mjs`) i només exposa `mirasai/sites-*`, `style-*`, `batch` i
  `host-diagnose` (`mcp-stdio.mjs`). L'agent no veu la credencial.
- **Hi ha tres «alt» diferents.** El que veu el visitant en un layout és
  `props.image_alt` de l'element (`YoothemeLayoutAuditor.php`, l. 151-154), i
  ja s'escriu amb `template/element-update-props`. L'alt de l'adjunt de
  WordPress és una meta de la mediateca. Joomla no té alt per fitxer: el té
  l'article (`image_intro_alt`, `image_fulltext_alt`) o l'element.

## El que ja fan les API del nucli

| | WordPress (REST) | Joomla (Web Services) |
| --- | --- | --- |
| Pujar un fitxer | `POST /wp/v2/media`, binari o multipart | `POST /v1/media/files`, base64 dins del JSON i `path` amb prefix d'adaptador (`local-images:`) |
| Alt d'un fitxer de la mediateca | `POST /wp/v2/media/<id>` (`alt_text`) | no existeix |
| Estat, pare, slug | `POST /wp/v2/pages/<id>` | `PATCH /v1/content/articles/<id>` (`state`, `alias`, `catid`) |
| Elements de menú | `/wp/v2/menus`, `/wp/v2/menu-items` (admet `status=draft` per element) | `/v1/menus/site/items` |
| Assignar un menú a una posició | `/wp/v2/menu-locations`, `locations` del menú | config del tema o `mod_menu` (no és a l'API d'elements) |

«En un web sense SSH no hi hauria hagut cap via» no és exacte, però la via té
condicions:

- **La credencial no sempre serveix.** El host de WordPress accepta la
  contrasenya d'aplicació i també un token propi (`X-MirasAI-Token`,
  `RestController::authorize()`, l. 31). El nucli només reconeix la primera.
  A Joomla, cada ruta depèn del seu connector de Web Services.
- **L'agent no té la credencial**: la resol el router. Per cridar l'API del
  nucli cal un consumidor nou, dins del router o fora.
- Sense dry-run ni `if_match` (l'última escriptura guanya) ni entrada al
  registre d'accions amb el client MCP.
- El playbook només prohibeix inventar camins «quan una eina llistada ja
  cobreix la feina»; no diu res de la REST. No se sap per què la feina del
  13/11 no la va fer servir.

MCP propis dels CMS: el nucli de WordPress no porta servidor MCP; el MCP
Adapter és un paquet a part que exposa les abilities instal·lades, del nucli i
de connectors. Al web de proves (WordPress 7.1.4), `wp/abilities/list` en torna
tres, totes de lectura: `core/get-site-info`, `core/get-user-info` i
`core/get-environment-info`. Per escriure, avui no hi ha res. El MCP del nucli
de Joomla és en desenvolupament (no comprovat).

## Les peces

| Peça | Cost | Risc | Estalvia |
| --- | --- | --- | --- |
| Layout buit (F-025) | Petit | Baix | El pas de WP-CLI abans d'`element-add` |
| `content/update` | Petit a WordPress si la previsualització es limita a la pàgina i les filles; no ho és amb una auditoria d'enllaços entrants | Mitjà: publicar i canviar una URL es veu de seguida | SSH per publicar, moure o reanomenar |
| Pujar fitxers | Mitjà o gran, segons la via | El més alt | La pujada |
| Alt de la mediateca | Petit | Baix | Els 55 alt del 13/11; va amb la pujada |
| Assignar un menú a una posició del tema | Petit o mitjà | Mitjà: canvia la capçalera de tot el web | F-028 |
| Editar elements de menú | Gran: dos models diferents | Alt | El cas del 13/11 (enllaços propis i ordre) |

Notes:

- `content/check-links` mira els enllaços sortints de `post_content`, amb un
  límit de 500. No dona «qui enllaça aquí».
- A Joomla un article no té pare, i amb workflows de contingut `state` pot no
  ser l'únic commutador de publicació.

## Com entraria el fitxer

1. **Base64 dins del JSON cap al host.** Un model no pot emetre megabytes de
   base64 com a arguments: només funciona si un procés munta la petició. El cos
   pesa un 33% més i PHP el té en memòria diverses vegades. Al servidor de
   proves cap cos no es talla fins a 64 MB, però és lent: uns 2,4 s per MB a
   WordPress (vegeu «Proves»). No dona cap lectura nova del Mac.
2. **URL que baixa el servidor.** Demana una regla nova d'SSRF i no resol el
   cas real, en què els fitxers eren al Mac. **Descartada**: és el pitjor risc
   dels tres (arriba a serveis interns) i no hi ha cap necessitat real.
3. **Ruta local que llegeix el router**, que envia el fitxer a l'API del
   nucli. Sense SSRF i sense passar pel model. Tres límits:
   - la validació del nucli no basta: el WordPress de proves, amb YOOtheme
     actiu, va acceptar un SVG amb un `<script>` a dins. L'eina ha de tenir la
     seva pròpia llista de tipus;
   - a Joomla el nucli també rep base64, de manera que el límit de cos
     continua (`MediaController::checkContent()` compara `CONTENT_LENGTH` amb
     els límits de PHP i de `com_media`);
   - un agent enganyat podria publicar un fitxer del Mac. Comprovar el tipus
     no ho evita: un JPEG o un PDF confidencial hi passa. Només és acceptable
     amb una carpeta permesa per web, on l'operador ha posat el que es vol
     publicar. Sense aquesta gàbia és pitjor que el base64.

Límits proposats: jpg, png, webp, gif i potser pdf, sense SVG; 10 MB per
fitxer; la previsualització diu el límit real del servidor.

## Decisió (Àlex, 2026-11-13)

Criteri: una eina de MirasAI només on la peça és de YOOtheme o on pot ensenyar,
abans d'escriure, una cosa que el CMS no ensenya.

### Paquet A, al host (versió 0.15.0)

1. **Layout buit.** Inicialitzar el layout d'una pàgina (WordPress) o d'un
   article (Joomla) que existeix i és buit. Tanca F-025.
   - «Buit» vol dir sense layout a cap font i sense text: a WordPress, cap de
     les metes de layout i `post_content` buit; a Joomla, `introtext` i
     `fulltext` buits.
   - L'eina no rep contingut: escriu el layout buit canònic i prou, sota CAS
     amb l'ETag de contingut.
   - Un layout buit no té text pla per generar, però la resposta manté el camp
     `fallback_content` de la 0.14.0, i la primera escriptura posterior s'ha de
     verificar a la prova en directe.
2. **`content/update`, `guarded_write`.** Es reobre el «CRUD d'articles»
   descartat el 9/11, **només fins aquí**:
   - WordPress: només `post_type=page`, existent. Camps: estat (`draft` o
     `publish`), pare (una altra pàgina existent, o cap) i slug.
   - Joomla: només l'estat d'un article existent (`0` o `1`).
   - Mai la paperera (`trash`, `-2`) ni l'arxiu, mai crear ni esborrar, mai
     títol ni contingut. Qualsevol altre camp o valor es rebutja. No es
     reaprofita el payload de `content/translate`, que escriu títol i contingut
     i admet `-2`.
   - Entrades, adjunts i tipus propis es rebutgen. Qualsevol ampliació demana
     una decisió nova.
   - La previsualització diu el permalink d'abans i de després de la pàgina i
     de les filles, sense auditoria d'enllaços. `content/read` ha de tornar el
     pare.
3. **Assignar un menú a una posició de YOOtheme** (`navbar`, `dialog-mobile`).
   Eina estreta: no crea menús ni n'edita els elements.
   - WordPress: l'assignació del tema (`nav_menu_locations`). El CAS va amb un
     ETag propi de les assignacions; el de l'Style no hi serveix, perquè no
     canvia.
   - Joomla: vol dir escriure `menu.positions[posició].menu` a la configuració
     de l'estil actiu, que és el que `menu/migrate-theme-to-modules` llegeix i
     buida. Si aquella posició ja la governa un `mod_menu` (web migrat), l'eina
     ho detecta i es nega: no crea mòduls ni desfà una migració. L'estil,
     l'idioma i l'ETag (configuració i mòduls de la posició) s'han de definir
     abans d'escriure-la; es pot publicar després que la de WordPress.

### Paquet B, al router (després que A estigui publicat)

4. **`mirasai/media-upload`.** Puja fitxers d'una carpeta local a la mediateca
   per l'API del nucli. Condicions de disseny, abans de cap codi:
   - **Carpeta.** Una carpeta dedicada per web, declarada per l'operador a la
     configuració, no per l'agent. Contenció comprovada sobre el camí canònic,
     component per component; els enllaços simbòlics, intermedis o finals, es
     rebutgen; només fitxers regulars. Cap comodí: els fitxers es nomenen un
     per un.
   - **Manifest.** La previsualització torna un manifest amb el web, la
     destinació, els noms, les metadades, les mides i els hashes. La
     confirmació ha de portar aquest manifest, i s'envien exactament els bytes
     que es van revisar: si un fitxer canvia, la previsualització caduca.
   - **Tipus.** Comprovat pels bytes i coherent amb l'extensió i el MIME (jpg,
     png, webp, gif, pdf; mai SVG), amb noms de fitxer segurs. Això no és cap
     garantia de confidencialitat: un PDF privat hi passa. El que ho limita és
     la carpeta dedicada.
   - **Autenticació.** La de cada CMS: a WordPress, contrasenya d'aplicació; a
     Joomla, el token que accepten els Web Services amb permís de mediateca. Un
     WordPress que només té token propi de MirasAI rep «no disponible», sense
     cap via alternativa. La destinació és sempre el web autoritzat.
   - **Límits.** Exactes i en bytes, per fitxer, per petició i per lot. A
     Joomla es compta el cos sencer: `4 × ceil(n/3)` més l'embolcall JSON,
     contra el límit de `com_media` (a la prova, 9,87 MB de petició passen i
     10,7 MB no). Una petició per fitxer.
   - **Lots i reintents.** Tot el lot es valida abans d'enviar res. Cada fitxer
     torna el seu resultat (pujat, fallat, pendent o desconegut) i un rebut amb
     l'ID o el camí. Un resultat desconegut es reconcilia abans de repetir; no
     se sobreescriu ni s'esborra res automàticament.
   - **Metadades.** Títol i alt només a WordPress. A Joomla es rebutgen: la
     pujada no escriu articles ni layouts.

### Fora

- Editar elements de menú: sense eina. Es reprèn amb un segon cas real.
- Baixar fitxers d'una URL des del servidor, i base64 cap al host: descartats.

Abans del paquet B cal aclarir, als webs de proves: qui habilita l'SVG a
WordPress, per què Joomla rebutja el PDF i per què els cossos JSON són lents.

## Proves (2026-11-13)

Fetes després de la revisió, com a usuari de cada compte dins de CageFS, amb
les credencials de prova que ja eren al servidor i peticions des del servidor
mateix. Abans, un dump de cada base de dades al compte. Tot el que es va crear
es va esborrar, i els dos webs han quedat com estaven.

WordPress de proves (7.1.4, PHP 8.4, YOOtheme 5.0.53, MirasAI 0.13.0), REST del
nucli amb la contrasenya d'aplicació:

| Prova | Resultat |
| --- | --- |
| Pujar un PNG de 3 MB, cos binari | 201 en 1,3 s; WordPress en fa les miniatures |
| Títol i alt en un adjunt existent | 200 |
| Pujar amb títol i alt en una sola crida (multipart) | 201 |
| Pujar un PDF | 201 |
| Text PHP amb nom `.png`, i un `.php` | rebutjats, amb un 500 (`rest_upload_sideload_error`) |
| **SVG amb un `<script>`** | **acceptat (201)**. No s'ha mirat qui l'habilita |
| Pare, estat i slug d'una pàgina | 200; el visitant rep la URL nova |
| `content/read` de MirasAI | no torna el pare; l'ETag sí que canvia en canviar-lo |
| F-025 | `post_layout_missing` en una pàgina nova. Escrit el comentari del layout buit per REST, queda idèntic i `template/read` i `template/element-add` (dry-run) ja funcionen |
| Element de menú amb `status=draft` | 201 |
| Convertir un element de pàgina en enllaç propi amb àncora; reordenar | 200 |
| Assignar el menú a `navbar` | 200; a la portada, `tm-header` passa de 0 a 3 (F-028 reproduït) |
| ETag de l'Style després d'assignar | igual |
| Cos JSON cap a l'endpoint MCP | acceptat fins a 64 MB; 10 MB en 25 s, 32 MB en 107 s, 64 MB en 285 s |

En esborrar el menú, WordPress deixa `nav_menu_locations` com a `{"navbar":0}`
en lloc de buit; es va tornar a deixar buit a mà.

Joomla de proves (6.2.0, PHP 8.4), Web Services amb el token:

| Prova | Resultat |
| --- | --- |
| Pujar PNG en base64 | 200 fins a un cos de 9,87 MB (fitxer de 7,4 MB, 12 s); amb un cos de 10,7 MB, 500 sense missatge. Quadra amb `upload_maxsize` = 10 aplicat a la mida de la petició |
| Pujar un PDF | 400 «file type not allowed» a `local-images` i a `local-files`, tot i que `pdf` és a la llista d'extensions. Causa sense trobar; pot ser el PDF mínim de la prova |
| SVG i `.php` | rebutjats (400); text PHP amb nom `.png`, 500 |
| Esborrar un fitxer | el camí ha de portar el prefix de l'adaptador (`local-images:/…`) |
| Crear un article despublicat, publicar-lo només amb `state`, canviar l'`alias` | 200 |
| Mirall de F-025 | `article_layout_missing` en un article nou |
| Crear un element de menú d'enllaç, despublicat | 200 |
| Editar-lo enviant només els camps que canvien | 500, i l'element queda bloquejat (`checked_out`). Enviant tots els camps, 200 |
| Cos JSON cap a l'endpoint MCP | acceptat fins a 32 MB (63 s); no s'ha provat més |

Què en surt:

- La ruta de l'API del nucli cobreix a WordPress tot el que es va fer el 13/11.
  A Joomla cobreix l'estat i l'àlies, les imatges fins a uns 7 MB i els
  elements de menú si s'envien sencers; el PDF queda obert.
- «Valida el nucli» no és prou garantia (l'SVG).
- Contra el base64 cap al host, l'argument no és que el servidor el talli sinó
  que és lent i que un model no el pot emetre.
- La lentitud dels cossos JSON no s'ha investigat; un PNG binari de la mateixa
  mida puja unes vint vegades més de pressa.

## Revisió creuada (ronda 1, 2026-11-13)

Revisors: GPT-6.1 Sol (Codex) i Grok 4.7 (via Cursor), esforç alt, només
lectura. Tots dos: CHANGES. Les troballes contrastables amb el codi s'han
comprovat una per una.

| Troballa | Qui | Resultat |
| --- | --- | --- |
| La credencial del host no sempre serveix per al nucli (`X-MirasAI-Token`) | tots dos | Acceptada; comprovada a `RestController.php` |
| F-028 ja és un cas real de menús, i és assignar una posició | tots dos | Acceptada; comprovada a les troballes |
| La previsualització amb enllaços entrants no és petita | tots dos | Acceptada; `content/check-links` no ho fa |
| A Joomla la URL la fixa l'element de menú; `content/update` hi és només `state` | Grok | Acceptada |
| El CRUD d'articles és a «Descartat», no a «Límits» | Grok | Acceptada; l. 179 |
| Tres «alt» diferents; el del Builder ja es pot escriure | Grok | Acceptada; comprovada a l'auditor |
| Els 55 alt són metadades, no transport: es poden decidir a part | Codex | Acceptada |
| El router no treu el límit de cos a Joomla | Codex | Acceptada; confirmada a la prova (500 per sobre de 10 MB de cos) |
| `menu-items` admet `status=draft` | Codex | Acceptada; confirmada a la prova |
| Política d'abilities i estat de la 7.1 mal resumits | tots dos | Acceptada; l'inventari real queda per mirar al web de proves |
| La via de la URL és la pitjor i s'ha de descartar | tots dos | Acceptada |
| `SERVER_ADDR` només sense `live_site` | Codex | Acceptada |
| `guarded_write` no implica sempre `if_match` ni `preview_token` | Grok | Acceptada |
| L'ETag «no cobreix» el pare | Grok | Acceptada a mitges: el pare no es llegeix, però el canvi sí que invalida l'ETag |

Desacords entre revisors, per decidir:

- **Pujada de fitxers.** Grok: una ruta documentada no serveix a l'agent, que
  no té la credencial; ha de ser una eina del router amb carpeta permesa.
  Codex: ruta documentada de l'API del nucli primer, i el router només amb una
  necessitat demostrada i fitxers triats expressament per publicar.
- **Menús.** Grok: eina estreta del host per a la posició de YOOtheme.
  Codex: ruta documentada del nucli, assignació inclosa.

## Revisió creuada (ronda 2, 2026-11-13)

Revisor: GPT-6.1 Sol, sobre la decisió escrita i les proves. CHANGES, amb
quatre blockers; totes les troballes s'han acceptat i són al text de la
decisió. Les contrastables amb el codi s'han comprovat.

| Troballa | Gravetat | On s'ha resolt |
| --- | --- | --- |
| «Carpeta permesa» no definia la contenció (`../`, enllaços simbòlics) | blocker | Paquet B, «Carpeta» |
| Mida i hash no obliguen a publicar el que s'ha revisat | blocker | Paquet B, «Manifest» |
| «Pàgina o entrada» eixamplava `content/update` | blocker | Només `post_type=page` |
| «L'estat» sense enumerar deixava entrar la paperera (`content/translate` de Joomla admet `-2`) | blocker | Estats enumerats |
| «Només contrasenya d'aplicació» excloïa Joomla | should | Paquet B, «Autenticació» |
| «Uns 7 MB» no és un límit executable | should | Paquet B, «Límits» |
| Lots parcials i reintents sense contracte | should | Paquet B, «Lots i reintents» |
| Títol i alt promesos a Joomla | should | Paquet B, «Metadades» |
| L'assignació de menú no tenia sentit únic a Joomla (`menu/migrate-theme-to-modules` buida la del tema) | should | Peça 3, Joomla |
| Faltava l'ETag de l'assignació de menú | should | Peça 3, WordPress |
| «Buit» sense concretar; `fallback_content` | should | Peça 1 |

## Sense resoldre

- Per què Joomla rebutja el PDF i per què els cossos JSON són tan lents.
- Qui habilita l'SVG al WordPress de proves, i si passa igual sense YOOtheme.
- Les proves s'han fet amb MirasAI 0.13.0 als dos webs, no amb la 0.14.0.
- No s'ha provat cap web amb token propi de MirasAI ni cap servidor que no
  sigui el de proves.
