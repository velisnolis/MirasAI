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
- Gestió de plantilles del tema: crear desactivada, duplicar, reordenar,
  activar, amb previsualització de quines pàgines canvien de plantilla.

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
| 1.2 `site/cache-purge` | pendent |
| 1.3 `template/render-check` | pendent de spike |
| 1.4 Playbook i rutes | fet per a 1.1: job `verify_visitor_view` i fila a `agent-routes.md` |
| Fase 2 | pendent |
| Fase 3 | pendent |
| Fase 4 | pendent |

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
