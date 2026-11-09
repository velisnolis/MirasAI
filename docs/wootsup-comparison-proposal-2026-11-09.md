# Proposta: què incorporem de MCP for YOOtheme Pro (WootsUp)

2026-11-09. Proposta i pla d'acció. Contrastada amb el checkout de `main` a
`0f824fb` (MirasAI 0.10.4) i amb la documentació pública de WootsUp del mateix
dia (MCP for YOOtheme Pro 3.2, 69 eines a Joomla). No he provat el seu producte:
tot el que se'n diu surt de la documentació, les notes de versió i el blog.

Fonts:

- <https://wootsup.com/products/mcp-for-yootheme-pro/>
- <https://wootsup.com/docs/mcp/yootheme-joomla/tools/>
- <https://wootsup.com/docs/mcp/yootheme-joomla/troubleshooting/>
- <https://wootsup.com/blog/mcp-for-yootheme-pro-3-1-fewer-steps-my-layouts/>
- <https://wootsup.com/blog/mcp-for-yootheme-pro-3-2-sublayouts-fragments/>
- <https://wootsup.com/blog/audit-yootheme-pro-page/>
- <https://wootsup.com/blog/yootheme-pro-mcp-ai-activity-log/>
- <https://wootsup.com/blog/yootheme-pro-5-1-jwc-2026-ai-first/>

## Límits que no canvien

- WootsUp és referència, no font de codi. No se'n porta res.
- Segueixen descartats el dynamize (`page_generate_dynamic`), el mapejador
  d'API (AnySource) i competir en «la IA et fa la pàgina» (decisió de l'agost,
  `AGENTS.md` i GBrain `projects/mirasai`).
- Cap eina nova se salta el model de quatre nivells: lectura, `safe_write`,
  `guarded_write` (dry-run, `if_match`, confirmació) i destructiva amb elevació.

## Context estratègic

A la JWC 2026, YOOtheme va anunciar un «AI coworker» dins del Builder, basat en
MCP: treballa sobre el layout obert, veu els canvis no desats i actualitza la
sessió sense recarregar. Al full de ruta hi ha la integració amb el MCP de
Joomla. No hi ha data.

Si arriba, l'edició d'elements del Builder la donarà YOOtheme mateix. El que
diferencia MirasAI és el que no fan ni ells ni WootsUp: compilar Style,
escriptures amb CAS, traducció i operació del CMS. Per això aquest pla prioritza
**verificar i auditar** per sobre d'afegir més manipulació d'elements.

## Pla d'acció

### Fase 1 · Comprovar el que veu el visitant

Problema verificat: després d'una escriptura al Builder, MirasAI només pot
demostrar que el JSON ha canviat. `docs/agent-routes.md` ho reconeix a la taula
de capacitats: «Purge HTML cache after CSS write: not implemented on the host».

#### 1.1 `site/public-fetch` (lectura, tots dos hosts)

Obre una URL del mateix web com un visitant anònim i informa de:

- `status`, `final_url`, `title`, si hi ha `noindex`;
- capçaleres de memòria cau que ajudin a interpretar el resultat (`age`,
  `cache-control`, `x-cache`, `cf-cache-status`, `x-litespeed-cache` i similars);
- fins a 10 textos esperats (`expect[]`), cadascun amb `found`, el nombre de
  coincidències i la zona on surt (`header`, `nav`, `main`, `footer`, `other`).
  El text es compara normalitzat: sense etiquetes, amb els espais i els salts de
  línia col·lapsats.

Restriccions de seguretat (és una petició que fa el servidor):

- només `http`/`https` i el mateix host que el web (`home_url()` a WordPress,
  `Uri::root()` a Joomla); cap IP literal ni cap altre host;
- les redireccions no se segueixen automàticament: cada salt es valida amb la
  mateixa regla i n'hi ha un màxim;
- sense cookies ni capçaleres d'autenticació; temps màxim i mida màxima de cos;
- la resposta no retorna el cos sencer, només un extracte curt.

Risc conegut: hi ha servidors on el web no es pot cridar a si mateix (loopback
bloquejat). L'eina ho ha de dir amb un codi propi, no fer-ho passar per un 404.

Èxit: proves unitàries de la validació d'URL (host aliè, IP, esquema, redirecció
fora del host) i de l'anàlisi del HTML (zones, normalització, comptatge), més una
crida real al lab Joomla i a un WordPress de prova.

#### 1.2 `site/cache-purge` (`safe_write`, tots dos hosts)

- WordPress: reaprofitar `Checks/CachePurger.php` (WP Rocket, IONOS, memòria
  cau d'objectes, avís de drop-in desconegut). Amb `post_id`, buidar només
  aquella pàgina quan el connector ho permeti.
- Joomla: les escriptures del Builder ja netegen `page`, `com_templates`,
  `plg_system_yootheme`, `yootheme` i `_system`. Al codi de Joomla 6.2,
  `JPATH_CACHE` és `administrator/cache` per al web, l'administració i l'API,
  de manera que la neteja des de l'API toca la mateixa memòria cau que el web.
  Falta comprovar-ho a Joomla 5 al lab. El que no es neteja són les memòries cau
  de tercers (JCH Optimize, per exemple). L'eina ha de dir `unsupported` quan en
  detecti una que no sap buidar.

Èxit: resposta amb el mateix format als dos hosts; `state=unknown` quan hi ha
una memòria cau que no s'ha pogut buidar.

#### 1.3 `template/render-check` (lectura)

Renderitzar al servidor el layout desat i llistar el text visible de cada
element amb el seu path, distingint un element desactivat d'una font buida.
Abans d'implementar-ho cal un spike: renderitzar amb YOOtheme des del context
REST/API sense efectes secundaris. Si el spike no surt net, 1.1 cobreix la major
part del valor.

#### 1.4 Playbook i rutes

Afegir el pas «verificar» al playbook (`system/diagnose`) i a
`docs/agent-routes.md`: escriure → `site/cache-purge` si cal →
`site/public-fetch` amb els textos esperats.

### Fase 1b · Crear plantilles de YOOtheme (cas real, 2026-11-09)

En un projecte, l'agent va haver de crear una plantilla de YOOtheme per
WP-CLI, amb còpia prèvia de la configuració del tema, perquè MirasAI no en pot
crear. Després la va omplir amb MirasAI. Les eines `template/element-*` només
editen plantilles que ja existeixen.

Proposta: `template/create` (`guarded_write`, tots dos hosts):

- crea la plantilla **desactivada**, amb nom, tipus (`single-post`, etc.) i,
  opcionalment, un layout inicial; així cap pàgina canvia fins que algú
  l'activa;
- `dry_run`, `confirm_guarded_write` i `if_match` contra l'ETag de la col·lecció
  de plantilles (l'opció `yootheme` a WordPress, el `custom_data` de l'extensió
  a Joomla), que les escriptures ja retornen com a `collection_etag`;
- torna la `key` nova i el seu ETag, a punt per a `template/element-add`;
- `template/render-check` amb `context_id` serveix per comprovar-la abans
  d'activar-la.

Activar, desactivar i reordenar plantilles (l'ordre decideix quina s'aplica)
és un segon pas: canvia el que veuen els visitants i demana una
previsualització de quines pàgines canvien de plantilla.

### Fase 2 · Registre d'accions

Cada escriptura feta per MCP queda al registre del CMS: el registre d'accions
d'usuari de Joomla i Simple History a WordPress si està actiu. Ha de constar
l'eina, el destí, l'ETag d'abans i el de després, l'usuari i el client MCP
(`clientInfo` de l'`initialize`). Les lectures i els dry-run no hi deixen rastre.

Èxit: una escriptura al lab Joomla surt a Usuaris → Registre d'accions amb el nom
del client.

### Fase 3 · Auditoria i avisos

- `template/audit` (lectura): H1 que falta o repetit, salts de nivell,
  encapçalaments llargs, imatges sense alt (les decoratives poden anar buides),
  elements de llista sense ítems, paths d'imatge inexistents, bindings a fonts o
  camps que ja no existeixen. Cada troballa amb el path i una proposta de solució.
- Avís `unknown_prop_keys` a les escriptures: `YoothemePropsValidator` només
  revisa `select`/`radio` i deixa passar les props que l'element no declara. Avís,
  no rebuig.

### Fase 4 · Comprovacions abans de decidir

- **Sublayouts i fragments.** Al repo no surt mai «sublayout». Cal comprovar al
  lab si `YoothemeElementNavigator` arriba als fills d'un fragment o d'un ítem de
  switcher amb files pròpies. Si no hi arriba, és un error de cobertura.
- **Confirmació lligada als arguments.** Comprovar si un write confirmat pot dur
  arguments diferents dels del dry-run amb el mateix `if_match`. L'ETag protegeix
  l'estat, no la petició.
- **Bindings de multi-items.** Avisar a `template/element-source-preview` quan la
  font és al contenidor i no als ítems.

### Més endavant, si hi ha cas real

- `elements[]` a `template/element-add`, tot o res sota un sol `if_match`.
- De URL a layout (`post_id`, plantilla o widget).
- Duplicar plantilles (crear-les és a la fase 1b).

### Ja cobert

- El `doctor` de WootsUp (Node, proxy, si el web respon i si accepta la clau) ja
  el fa `mirasai/sites-test` al router: DNS/TLS, HTTP, autenticació,
  `initialize`, `tools/list` i `system/diagnose`, amb la classificació de cada
  fallada.

### Descartat

- Dynamize, AnySource, la Layout Library, Unsplash/Pexels i el bastiment de
  pàgines senceres.
- Claus Bearer pròpies i la clau de xifratge fora del webroot: MirasAI fa servir
  els tokens de Joomla i les contrasenyes d'aplicació de WordPress.
- CRUD de mòduls i articles: possible, no prioritari.

## Estat

| Pas | Estat |
| --- | --- |
| 1.1 `site/public-fetch` | fet i provat als dos hosts: proves locals i webs de proves reals (vegeu «Validació») |
| 1.2 `site/cache-purge` | fet i provat als dos hosts (vegeu «Validació») |
| 1.3 `template/render-check` | fet i provat als dos hosts (vegeu «Validació») |
| 1b `template/create` | fet i provat als dos hosts (vegeu «Validació») |
| 1.4 Playbook i rutes | fet per a 1.1 i 1.2: job `verify_visitor_view`, `purge_page_cache` apunta a `site/cache-purge`, files a `agent-routes.md` |
| Fase 2 | feta i provada als dos hosts (vegeu «Validació») |
| Fase 3 | `template/audit` fet i provat als dos hosts; l'avís `unknown_prop_keys` queda ajornat amb dades (vegeu «Validació») |
| Fase 4 | feta i provada als dos hosts (vegeu «Validació») |

## Validació

### 1.1 `site/public-fetch`, 2026-11-09

Proves locals: `docker/test-public-fetch.php`, 127 comprovacions sobre els dos
hosts, dins de `npm test`.

Webs reals, amb el paquet de la branca `feat/visitor-checks` (0.10.4 + canvis):

- `proveswp` (proves-wp.miras.pro): WordPress 7.1.4, PHP 8.4, sense YOOtheme.
- `provesjoomla` (proves-joomla.miras.pro): Joomla 6.2.0, PHP 8.4, sense YOOtheme.

| Cas | WordPress | Joomla |
| --- | --- | --- |
| Portada amb `expect` | 200, textos trobats a `main` | 200 |
| Redirecció real del mateix host (`/?p=1` → permalink, `/administrator` → `/administrator/`) | salt registrat, el client no la segueix | salt registrat, el client no la segueix |
| Redirecció real cap a un altre host, a `127.0.0.1` i després d'un salt intern | `redirect_refused`, no es demana la destinació | (mateix codi compartit) |
| 404 | `status: 404` | `status: 404` |
| URL d'un altre host o d'una IP | `foreign_host` | `foreign_host` |
| `cache_bust` | paràmetre afegit, 200 | — |
| Arguments erronis (`expect` com a text, argument desconegut) | — | rebutjats pel validador abans de la crida |

Les redireccions cap a fora es van provar amb tres regles `Redirect 302`
temporals a l'`.htaccess` de `proveswp`, que després es va restaurar idèntic.
No s'han provat: un servidor que no es pot cridar a si mateix
(`loopback_failed`) ni una resposta de més de 2 MB a Joomla.

Revisió de seguretat posterior (mateix dia). A Joomla sense `live_site`,
`Uri::root()` surt de la capçalera `Host`; on el web és el vhost per defecte,
això permetia triar a quin host es connectava el servidor. Ara:

- amb `live_site`, mana `live_site`;
- sense `live_site`, totes les connexions van a `SERVER_ADDR` (l'adreça que ha
  rebut la petició) amb `CURLOPT_CONNECT_TO` i l'origen buit, de manera que ni
  una IP a la URL ni una lectura diferent del host entre `parse_url` i cURL
  poden triar la destinació. A més, es rebutja amb `site_url_untrusted` un
  `Host` amb un port no estàndard, una IP que no és la del servidor o un nom
  que no sigui DNS net. No es pot comparar amb `SERVER_PORT`:
  Apache amb `UseCanonicalName Off` el copia de `Host` (comprovat a
  `provesjoomla`);
- Joomla fa la petició amb cURL directe, que també talla la resposta a 2 MB.

Provat a `provesjoomla`: portada normal amb la connexió fixada i `Host` amb
`:8080` rebutjat. A cPanel un nom de host fals ja no arriba a Joomla (421).

Risc que queda a WordPress: `home_url()` surt de la base de dades, excepte si
el `wp-config.php` defineix `WP_HOME` a partir de `$_SERVER['HTTP_HOST']`. No ho
fa cap dels webs que conec, però no està cobert.

Notes per tornar a provar en aquests comptes:

- Tots dos tenen l'accés limitat a la IP del servidor (185.254.206.69) amb un
  bloc `Require ip` a l'`.htaccess`. Les crides MCP es fan amb curl des del
  servidor mateix; la petició que el web es fa a si mateix també passa el filtre.
- L'`.htaccess` de `provesjoomla` no té les regles de reescriptura de Joomla:
  el MCP és a `/api/index.php/v1/mirasai/mcp`, no a `/api/v1/mirasai/mcp`.
- Còpies fetes abans d'instal·lar, al servidor: `~proveswp/mirasai-test/` (el
  connector 0.10.4 i l'`.htaccess`) i `~provesjoomla/mirasai-test/` (dump de la
  base de dades).

### 1.2 `site/cache-purge`, 2026-11-09

Proves locals: `docker/test-cache-purge.php`, amb WP Rocket, una memòria cau
d'objectes i un drop-in desconegut simulats.

WordPress (`proveswp`, sense cap memòria cau): `dry_run` sense res a buidar i
purga amb `state=pass`, `page=none`. El camí de WP Rocket només està provat amb
funcions simulades; el codi és el mateix `CachePurger` que ja feien servir les
comprovacions posteriors a l'actualització.

Joomla (`provesjoomla`), el cas complet amb System - Page Cache activat
temporalment:

| Pas | Resultat |
| --- | --- |
| Primera visita a l'article 1 | text trobat; `dry_run` mostra el grup `page` |
| Canvi a l'article directament a la base de dades | — |
| `site/public-fetch` | la marca nova **no** hi és: còpia de la memòria cau |
| `site/public-fetch` amb `cache_bust=true` | la marca hi és |
| `site/cache-purge` | `state=pass`, `page=purged`, 6 grups |
| `site/public-fetch` | la marca hi és |

Després es va restaurar l'article (idèntic per `cmp`) i es va tornar a
desactivar el connector.

La memòria cau de pàgina de JCH Optimize (`jchpagecache`, vist a nordlogway
amb JCH 9.4.3) té emmagatzematge propi i no escolta cap esdeveniment de neteja
de Joomla. L'eina la detecta i torna `unsupported`; no s'ha provat de buidar-la.

### Spike 1.3 `template/render-check`, WordPress, 2026-11-09

A `proveswp`, amb YOOtheme Pro 5.0.53 copiat del tema pare d'`ambiens` (sense el
tema fill ni la configuració). Es queda instal·lat per a les proves.

Què s'ha comprovat, amb una pàgina de prova (`prova-render-mirasai`) i
`wp eval-file`:

- `YOOtheme\app(Builder::class)->render($json, ['prefix' => 'page', 'post' => $post])`
  dona el mateix text que veu el visitant (comparat amb `site/public-fetch`).
- Funciona igual dins d'una petició REST (mu-plugin temporal, ja esborrat):
  `REST_REQUEST=true`, `is_admin()=false`, 11 ms per a una pàgina petita.
- `Builder::load($json, ['context' => 'render', ...])` torna l'arbre ja
  transformat: sense els elements desactivats i amb les fonts dinàmiques
  resoltes a `props`. Una marca posada a `props` de cada node abans de carregar
  sobreviu, de manera que es pot dir el path de cada element que es mostra i quins
  paths han desaparegut.
- Un element desactivat porta `props.status = "disabled"` (ho mira
  `DisabledTransform`), no `status` al node.
- **El resultat depèn de l'usuari.** La font `site.is_guest` dona 0 com a
  administrador i 1 com a visitant. Per veure el que veu el visitant cal
  renderitzar amb `wp_set_current_user(0)` i restaurar-lo després.
- **Plantilles amb context:** amb `$wp_query`, `$wp_the_query` i `$post`
  apuntant a una entrada real i `prefix => 'template'`, un titular lligat a
  `{"name":"posts","field":{"name":"singlePost"}}` → `title` dona «Hello
  world!». Amb una entrada inexistent surt buit, sense error: l'eina ha de
  comprovar abans que el context existeix.

Disseny que en surt:

- `template/render-check` (lectura) amb el destí habitual (`key`, `post_id`)
  i, per a plantilles, una entrada o article de context.
- Renderitza com a visitant. Torna els elements amb text visible i el seu path
  (format `root>section[0]>…`), els paths desats que no es mostren
  (desactivats, condicions o font buida) i si el resultat queda buit.
- Accepta `expect[]` com `site/public-fetch` i diu en quins paths surt cada text.
- No llegeix l'estat de publicació: una pàgina en esborrany també es
  renderitza, i això es diu a la resposta.

### 1.3 `template/render-check`, WordPress, 2026-11-09

Proves locals: `docker/test-render-check.php` (marques de path iguals a les de
`template/element-list`, resum, `expect`, branques que no es mostren, elements
repetits, render buit).

A `proveswp`:

- Pàgina `prova-render-mirasai`: el titular, el text i el títol del web
  (font `site`) surten amb el seu path; l'element desactivat surt a
  `not_rendered`; `site.is_guest` val 1 encara que la crida la fa un
  administrador.
- Plantilla `mirasaitest` (`single-post`, desactivada) amb `context_id=1`:
  «Hello world!» i el text de l'entrada als seus paths.
- La mateixa plantilla sense `context_id`: `renders_empty=true` i una nota. Les
  transformacions de YOOtheme eliminen tot el layout quan la font no es resol, i
  `load()` torna `null`; l'eina ho distingeix d'un layout trencat carregant-lo
  sense el context `render`.
- Errors: `context_not_found`, `context_only_for_templates`,
  `template_not_found` i `unknown_argument` per a `widget_id`.

### 1b `template/create`, 2026-11-09

Proves locals: `docker/test-template-create.php` (entrada desada, desactivada,
tipus vàlids als dos hosts, layout inicial, noms repetits, permisos).

El flux d'un agent, als dos webs de proves: `template/list` (per al
`collection_etag`) → previsualització → creació → `template/element-add` amb
l'ETag retornat → comprovació.

| | WordPress (`proveswp`) | Joomla (`provesjoomla`, YOOtheme 5.0.18 en aquesta prova) |
| --- | --- | --- |
| Tipus | `single-post` | `com_content.article` |
| Clau generada | `mirasai_…` (`generateStorageKey()` del host) | 8 caràcters (`generateStorageKey()` del host) |
| `if_match` antic després de crear | `stale_etag` | `stale_etag` |
| `template/element-add` sobre la plantilla nova | `updated` | `updated` |
| Comprovació | `template/render-check` amb `context_id=1`: el titular lligat al títol dona «Hello world!» | `template/element-list`: la secció i el titular als seus paths |
| Visitant amb la plantilla desactivada | sense canvis | sense canvis |
| Control: activada uns segons a mà | la plantilla substitueix el contingut de l'entrada | el visitant de l'article 1 veu el titular de la plantilla |

Després del control, les dues plantilles es van tornar a desactivar. A
WordPress, l'emmagatzematge de YOOtheme (`Storage`) la llegeix i el seu
`Builder::load()` carrega el layout.

L'eina no activa plantilles ni hi posa condicions (`query`): això canvia el que
veuen els visitants i queda per a un segon pas, amb previsualització.

### 1.3 `template/render-check`, Joomla, 2026-11-09

`provesjoomla` té ara YOOtheme 5.0.54, instal·lat amb la clau del servidor
(1Password, «YOOtheme API Key (server.miras.pro)»). El paquet es va comprovar amb
el SHA-256 del feed, i la clau queda al lloc d'actualitzacions del web, com a la
resta de Joomla del servidor.

Diferències amb WordPress, comprovades amb un spike (eina temporal, ja retirada):

- **YOOtheme no arrenca a l'aplicació API.** `template_bootstrap.php` només el
  carrega per al client `site` o per a uns quants components. L'eina el carrega
  amb el mateix `bootstrap.php` i el mateix conjunt de paquets. Després, la
  resposta MCP surt igual.
- **Les fonts d'article llegeixen l'article dels paràmetres del render**
  (`article`, `template => com_content.article`), no de globals.
- **Visitant:** `loadIdentity(null)` falla a l'API (`UserFactory not set`); es fa
  amb `loadIdentity(new User())` i es restaura la identitat després.

Proves:

| Cas | Resultat |
| --- | --- |
| Plantilla `com_content.article` amb `context_id=1` | titular fix i titular lligat al títol («Prova SocialMagick: à, è, í i una imatge») als seus paths |
| La mateixa sense context | només el titular fix, amb la nota |
| Article 2 amb layout propi | els dos titulars; el desactivat a `not_rendered`; coincideix amb `site/public-fetch` |
| `context_id` en un article, plantilla inexistent, article sense layout | `context_only_for_templates`, `template_not_found`, `article_layout_missing` |

Material de proves que queda a `provesjoomla`: l'article 2 té un layout de
YOOtheme al `fulltext`, que abans era buit. Per restaurar-lo:
``UPDATE jos_content SET `fulltext`='' WHERE id=2`` (nota a
`~provesjoomla/mirasai-test/article2-restore.txt`).

### Fase 2 · Registre d'accions, 2026-11-09

Què es registra: cada crida MCP d'una eina que no és de lectura i que ha
escrit (ni dry-run, ni previsualització, ni error). Una entrada porta l'eina,
el nivell de risc, el destí (`key`, `post_id`, `article_id`, `path`… mai el
contingut dels arguments), el client i els 12 primers caràcters dels ETags
d'abans i de després. La decisió és a `McpActionRecord`, igual als dos hosts i
provada a `docker/test-action-log.php`.

El client: el router desa el `clientInfo` de l'`initialize` i l'envia al host
com a `X-MCP-Client` (`claude-code/2.1.0 via @miras/mirasai-mcp`); sense
aquesta capçalera, el host fa servir el User-Agent.

- **WordPress:** una entrada de Simple History, si està actiu
  (`do_action('simple_history_log', …)`). Simple History 5.36 ja reconeix
  `X-MCP-Client` i marca l'entrada com a feta per una IA
  (`_initiator_ai_agent`). A més, MirasAI posa el filtre
  `simple_history/ai_initiator_origin` abans d'executar l'eina, perquè el que
  Simple History registri pel seu compte en aquella petició també quedi
  atribuït al client.
- **Joomla:** una fila a `#__action_logs` (Usuaris → Registre d'accions) amb
  `COM_MIRASAI_ACTIONLOG_MCP_WRITE`, inserida com ho fa `addLog()` però sense
  arrencar `com_actionlogs` dins de l'API ni enviar correus. Els connectors
  passen al registre el Super User del token (`McpActionLog::setUser`) en
  lloc de carregar-lo com a identitat de l'aplicació, que canviaria com
  s'executen totes les eines.

Proves reals:

- `proveswp`, amb Simple History 5.36 instal·lat per a la prova:
  `site/cache-purge` → «MirasAI ran site/cache-purge on - for
  claude-code/2.1.0 via @miras/mirasai-mcp», amb l'usuari de la contrasenya
  d'aplicació i `_initiator_ai_agent` per capçalera.
- `provesjoomla`: `template/list` i `site/cache-purge` amb `dry_run` no deixen
  cap fila; `site/cache-purge` i `template/element-update-props` en deixen una
  cadascuna, amb el destí, el client i els ETags. Després de la correcció de
  l'usuari, la fila porta `user_id 658` (`alex-proves`).
- El missatge de Joomla s'ha comprovat llegint el fitxer d'idioma i fent la
  substitució de `getHumanReadableLogMessage()`; no s'ha vist a la pantalla de
  l'administració (el web de proves només és accessible des del servidor).

### Fase 3 · Auditoria, 2026-11-09

`template/audit` (lectura, tots dos hosts; `key`/`post_id`/`widget_id` a
WordPress, `key`/`article_id` a Joomla). Llegeix el layout desat i no jutja
els valors que surten de fonts dinàmiques; per a això hi ha
`template/render-check`.

| Gravetat | Codi | Què vol dir |
| --- | --- | --- |
| error | `image_file_missing` | la imatge apunta a un fitxer local que no hi és |
| error | `empty_container` | una llista (grid, slideshow, switcher…) sense ítems ni font: no surt res |
| warning | `no_h1`, `multiple_h1`, `heading_level_skip` | esquema de títols (`headline` i títols d'elements amb `title_element`; els ítems d'una llista fan servir el nivell del pare) |
| notice | `missing_alt` | imatge estàtica sense `image_alt` (una decorativa pot anar buida) |
| notice | `long_heading` | títol de més de 70 caràcters |

Els nivells per defecte surten de la definició de l'element (`headline` és
`h1`; `grid`, `panel`, sliders, `h3`), llegits a YOOtheme 5.0.53. Els elements
desactivats, i el que tenen a dins, no s'auditen; només es compten.

Proves: `docker/test-template-audit.php` (27 comprovacions, les dues còpies de
l'auditor idèntiques). A `proveswp`, la pàgina de prova dona
`image_file_missing` i `missing_alt` per a una imatge que de debò no existeix
al servidor, i l'esquema h1 → h2 (dinàmic). A `provesjoomla`, l'article 2 i la
plantilla creada surten nets, amb el seu esquema.

**Ajornat: l'avís `unknown_prop_keys` a les escriptures.** Un recompte de només
lectura sobre layouts reals mostra que la definició de l'element no basta:

- ambiens (WordPress, 12 layouts, 416 nodes): una sola clau desconeguda,
  `column.width_medium` (l'amplada que desa la fila), 96 vegades;
- nordlogway (Joomla): unes 30 claus distintes, centenars de vegades
  (`headline.parallax_y`, `section.padding`, `text.margin`…). Els camps de
  paral·laxi viuen en un panell compartit del personalitzador
  (`builder-parallax`) al qual l'element remet amb un botó, i d'altres són noms
  de versions antigues que YOOtheme ignora.

Els camps del tipus carregat per YOOtheme són els mateixos que els de la
definició estàtica (comprovat a `proveswp`), així que l'avís demana resoldre
aquests panells i limitar-se a les claus que escriu la crida. Sense això seria
soroll.

### Fase 4 · Comprovacions, 2026-11-09

**Sublayouts i fragments: ja funcionava.** A YOOtheme 5.0.53 només `fragment`
té sublayout, i el desa a `children` (la seva plantilla fa
`$builder->render($children)`). El navegador de MirasAI recorre `children`
sense mirar el tipus. Prova real als dos hosts: un fragment amb fila, columna i
titular; `template/element-list` dona el path de dins
(`…>fragment[2]>row[0]>column[0]>headline[0]`),
`template/element-update-props` el modifica i `template/render-check` troba el
text nou en aquest path. `template/audit` no compta un fragment buit com a
llista buida.

**Confirmació lligada als arguments: no ho estava; ara sí, de manera opcional.**
Una confirmació podia portar arguments diferents dels de la previsualització
amb el mateix `if_match`. Ara el `McpHandler` de cada host afegeix un
`preview_token` (SHA-256 de l'eina i dels arguments, sense `dry_run` ni
`confirm_guarded_write`, amb les claus ordenades) a la resposta de cada dry-run
d'una eina `guarded_write`. Si la confirmació torna aquest token i els
arguments no coincideixen, es rebutja amb `preview_mismatch` abans d'executar
l'eina. Sense token, tot funciona com abans: els clients actuals no es trenquen.
El playbook diu als agents que l'enviïn. Proves: `docker/test-preview-token.php`
(19 comprovacions, els dos handlers) i, en real, `template/element-update-props`
als dos hosts (altres `props` amb el token → `preview_mismatch`; les mateixes →
`updated`).

**Bindings de diversos ítems: avís nou.** A les plantilles de nordlogway, la
consulta de llista (`articles`) és al `grid_item`, no al `grid`.
`template/element-source-set` (forma d'un sol path) torna ara
`warnings: [binding_on_list_container]` quan es lliga una font a un element de
llista (contenidor amb ítems, que no sigui un `fragment`), amb el path del
primer ítem. No rebutja res: un objecte únic lligat al contenidor és vàlid.
Provat als dos hosts: avís al `grid`, cap avís al `grid_item`.

## Després de la 0.11.0

Correccions de la revisió de Codex a la PR #11 (redireccions amb només query;
`renders_empty` amb només imatges o vídeo), pendents de publicar.

### Auditoria de tot el web

`template/audit` amb `scope: "site"`: totes les plantilles i tots els layouts
(entrades i widgets a WordPress; articles fora de la paperera a Joomla). Per a
cada layout amb troballes, els recomptes i com a molt `max_per_layout`
troballes, de pitjor a millor; els nets només es compten. Provat a `proveswp`
(3 layouts, 1 net, la imatge que falta i un salt h1 → h3 real a la plantilla de
prova) i `provesjoomla` (2 layouts nets).

### Activar i ordenar plantilles

`template/set-status` i `template/move` (`guarded_write`, `collection_etag`).
YOOtheme aplica la primera plantilla activada, en l'ordre desat, que té el tipus
de la pàgina i unes condicions que hi encaixen (`TemplateHelper::match()`); una
plantilla sense condicions les encaixa totes. La previsualització diu, abans i
després, l'ordre de les plantilles d'aquell tipus, si una anterior sense
condicions la tapa (`shadowed_by`) i de quines posteriors prendria pàgines.
`template/list` mostra ara `status`.

Proves: `docker/test-template-order.php` (29 comprovacions, les dues còpies
idèntiques). En real a `proveswp`: activar la plantilla creada → el visitant de
l'entrada 1 la veu; activar-ne dues → guanya la primera i la previsualització
diu que l'altra queda tapada; moure la segona al davant → el visitant passa a
veure-la; després, ordre original i totes dues desactivades. A `provesjoomla`:
activar → el visitant de l'article 1 veu la plantilla; desactivar → torna
l'article.
