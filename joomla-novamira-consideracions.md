# Consideracions Per Fer Un Plugin Tipus Novamira A Joomla

## Resum Executiu

Sí, és viable construir una solució semblant a Novamira per Joomla, especialment per a entorns `staging` o `dev`. No seria un port directe del plugin de WordPress, sinó una reimplementació del mateix patró:

- exposar eines MCP sobre el runtime real del CMS
- permetre inspecció i execució controlada dins Joomla
- oferir operacions de fitxers, base de dades i adapters d'extensions
- afegir un sandbox amb recuperació de fallades per al codi generat per AI

La viabilitat és bona per a casos com:

- inspecció de YOOtheme JSON
- canvis quirúrgics a layouts o configuració
- consultes a base de dades dins el context real de Joomla
- prototipat ràpid sense SSH
- suport a hostings on només hi ha HTTP + credencials aplicatives

La part menys portable és la capa de registre d'eines. Novamira depèn fortament de dues peces específiques de WordPress:

- `WordPress Abilities API`
- `WordPress MCP Adapter`

A Joomla aquestes peces no existeixen tal qual, així que s'han de recrear.

## Què Fa Realment Novamira A WordPress

Després d'inspeccionar `novamira-1.0.0.zip`, el patró base és aquest:

1. Un plugin principal que:
   - comprova dependències
   - registra pàgines d'admin
   - activa o desactiva el mode AI
   - inicialitza el bridge MCP
2. Un conjunt d'"abilities" amb schema, permisos i callback:
   - `execute-php`
   - `read-file`
   - `write-file`
   - `edit-file`
   - `delete-file`
   - `disable-file`
   - `enable-file`
   - `list-directory`
3. Un `sandbox loader` que carrega fitxers PHP persistents i detecta errors fatals:
   - marcador `.loading`
   - pas a `.crashed`
   - safe mode fins que es resolgui
4. Un bridge MCP que converteix aquestes abilities en:
   - tools
   - resources
   - prompts

## Què És Portable A Joomla

Aquestes idees són directament reutilitzables:

- model de tools amb `input schema`, `output schema`, permissions i handler
- transport MCP per HTTP
- transport local tipus STDIO o CLI
- `execute-php` dins el runtime del CMS
- eines de filesystem sota un directori base
- sandbox de codi persistent
- markers de crash recovery (`.loading`, `.crashed`)
- pàgina d'admin per activar o desactivar el mode AI
- restricció de l'ús a entorns de prova

## Què No És Portable Tal Qual

Aquestes parts depenen massa de WordPress i s'han de reescriure:

- `wp_register_ability()`
- `WP_Ability`
- `permission_callback` amb semàntica WP
- `rest_pre_echo_response`
- integració del `vendor/wordpress/mcp-adapter`
- inicialització de servers MCP via la infraestructura pròpia de WordPress

En altres paraules: el paquet de Novamira no és un bridge MCP genèric amb un plugin WordPress petit a sobre. És una solució molt ancorada a la pila WordPress.

## Arquitectura Recomanada A Joomla

La forma més neta de fer-ho a Joomla seria separar-ho en 3 peces.

### 1. Plugin de sistema

Nom orientatiu:

- `plg_system_ai_runtime`

Responsabilitats:

- carregar el runtime de l'agent quan el mode AI estigui actiu
- carregar fitxers PHP del sandbox
- gestionar `.loading` i `.crashed`
- impedir la càrrega del sandbox si hi ha safe mode
- mostrar avisos a l'administrador
- opcionalment limitar l'activació a dominis o entorns concrets

### 2. Bridge HTTP / API

Opcions:

- `plg_webservices_ai_mcp` amb ruta API pròpia
- o un endpoint de `com_ajax`
- o un component API mínim dedicat

Recomanació pràctica:

- si es prioritza autenticació i estructura API neta: `webservices plugin + component api`
- si es prioritza paritat amb el runtime de frontend i extensions visuals: endpoint del `site application`

Raó:

YOOtheme i algunes extensions poden dependre de context de frontend, serveis carregats o estat de renderitzat que no sempre encaixa igual dins l'`ApiApplication`.

### 3. Biblioteca o component de tools

Nom orientatiu:

- `lib_ai_tools`
- o `com_ai_tools`

Responsabilitats:

- registre intern de tools
- definició de schemas
- validació d'entrada/sortida
- permisos
- dispatch cap als handlers
- conversió del catàleg de tools a format MCP

## MVP Recomanat

Un MVP útil no necessita començar amb tot. Jo el faria així:

### Fase 1: runtime i diagnòstic

- `system/info`
- `execute/php`
- `db/query-readonly`
- `files/read`
- `files/list`

### Fase 2: edició segura

- `files/write`
- `files/edit`
- `files/delete`
- `sandbox/disable`
- `sandbox/enable`

### Fase 3: adapters d'extensions

- `yootheme/layout-read`
- `yootheme/layout-write`
- `yootheme/layout-validate`
- `joomla/article-read`
- `joomla/article-update`

## Cas D'Ús Fort Per Vosaltres: YOOtheme

Aquest és probablement el millor argument per fer-ho a Joomla.

Per què:

- YOOtheme guarda molta lògica en JSON
- els canvis quirúrgics a layouts es poden beneficiar molt d'un agent amb context del JSON existent
- la inspecció directa del layout viu és molt més còmoda des del runtime del CMS

Però hi ha una regla crítica:

- no s'ha de reconstruir un layout sencer si no cal
- s'ha de partir sempre del JSON existent
- s'han de preservar tipus desconeguts, props privades i integracions de tercers
- s'ha de respectar el pipeline de guardat de YOOtheme

Dit d'una altra manera: el valor no és només "editar JSON", sinó "editar el JSON correcte i aplicar-lo de manera compatible amb YOOtheme".

## Seguretat I Operació

Una solució així té els mateixos riscos conceptuals que Novamira:

- `eval()` dins el runtime del CMS
- accés a fitxers
- accés a la base de dades
- superfície d'atac HTTP més gran
- risc de prompt injection o d'execució no desitjada

Per tant, les regles haurien de ser estrictes:

- només `staging` o `dev`
- només usuaris admins dedicats
- autenticació forta per token
- opcionalment allowlist d'IP
- ability flags `readonly`, `destructive`, `idempotent`
- mode readonly global com a estat inicial
- sandbox sota un directori propi
- base dir restringit per a filesystem
- límit de temps per a `execute/php`
- logs i audit trail

## Diferència Entre Fer-ho "Semblant" I Fer-ho "Igual"

Fer una cosa similar: sí.

Fer-la igual: no exactament, perquè Joomla no té una `Abilities API` equivalent ja preparada.

La versió Joomla hauria de ser més aviat:

- un registre propi de tools
- un bridge MCP propi
- un sandbox de codi
- adapters d'extensions específiques com YOOtheme

## Llicència I Reutilització

El ZIP de Novamira està sota `AGPL-3.0-or-later`.

Implicacions pràctiques:

- es poden estudiar patrons i arquitectura
- copiar codi directament obliga a revisar bé les obligacions de distribució i compatibilitat
- per una implementació pròpia, és més net usar-lo com a referència conceptual i reescriure el codi

Si l'objectiu és producte propi o ús comercial controlat, jo evitaria una dependència forta del codi original i faria una implementació nativa per Joomla.

## Valor Real Respecte A SSH O Scripts

Per Joomla, una eina així guanyaria sobretot en:

- inspecció del runtime real
- treball sobre YOOtheme
- canvis quirúrgics
- diagnòstic d'extensions custom
- entorns sense shell

Continuaria perdent davant SSH/scripts en:

- operacions massives
- migracions grans
- backups
- tasques de sistema
- processos repetibles i llargs

La millor estratègia també seria híbrida:

- MCP per entendre, inspeccionar i fer canvis precisos
- scripts/CLI per aplicar canvis grans o operatius

## Recomanació Final

Sí, val la pena estudiar un "Novamira per Joomla" si el focus és:

- YOOtheme
- staging
- debugging i exploració ràpida
- canvis quirúrgics sobre layouts i contingut estructurat

No el plantejaria com un clon 1:1 del plugin WordPress, sinó com un producte Joomla-native amb aquest abast inicial:

1. runtime inspector
2. filesystem + DB tools
3. sandbox amb crash recovery
4. transport MCP HTTP
5. adapters específics per YOOtheme

## Proposta De Nom De Mòduls

Una estructura tècnica raonable podria ser:

- `plg_system_ai_runtime`
- `plg_webservices_ai_mcp`
- `libraries/ai-tools`
- `administrator/components/com_ai_mcp`

## Següent Pas Recomanat

Si es vol validar de veritat la idea, el millor següent pas no és programar-ho tot, sinó definir un spike de 2-3 dies amb aquest objectiu:

- exposar una ruta HTTP autenticada
- registrar 3 tools reals
- executar PHP dins Joomla
- llegir un layout YOOtheme
- provar un cicle de sandbox amb safe mode

Si aquest spike surt bé, la resta del producte és principalment feina d'arquitectura, seguretat i UX d'admin.
