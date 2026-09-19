# Primera assignació d'estil a Joomla: prova real

Prova feta el 19/09/2026 al Docker `10.0.2.135`, amb Joomla 5.4.5,
PHP 8.3.33 i YOOtheme Pro 5.0.40. S'ha reprès el clon aïllat
`mirasai-translation-20260907`, amb HTTP restringit a loopback (8082).
El laboratori original del port 8080 no s'ha modificat.

## Preparació

Còpia completa del Joomla i dump coherent de la DB abans dels canvis:
`/root/mirasai-style-20260919/{site-before.tar.gz,database-before.sql}`.
SHA-256:

- Site: `a1b05908abef81a10fb3fd42c2ca4cc8dbce254b61bb09a82b337315ab6b86bd`.
- DB: `508f44ce4d23dd121eb43e4eb54ce8b55ded4db022d0ecd8bad2b7c861715aef`.

La còpia tenia Cassiopeia com a plantilla predeterminada. S'ha activat el registre
YOOtheme existent, ID 12, conservant les files anteriors en un fitxer privat.
Els seus params tenien `yootheme`, `uikit3` i `widgetkit`, però no `config`:
cap família, variable ni Less personalitzat.

S'hi ha copiat només el `TemplateStyleUpdateTool.php` del commit `01c95b0` i,
després de reproduir l'error, el helper corregit. No s'ha instal·lat un paquet
complet ni actualitzat cap CMS. Propietari dels fitxers: `www-data`.
El compilador utilitzat és `updateStyle()` del router local, amb transport
HTTP/MCP al clon i el worker contrastat per SSH:
`682b6616666dc30c2629297ea1dac22c6100ac4d05b5a23eb72563d3e1862d99`.
No és una prova de descobriment via stdio/mcp2cli.

## Error trobat i correcció

La preview de `fuse` compilava 274 imports i retornava `initial` sense canvis.
L'aplicació fallava amb `stale_etag`: `Style config changed at the write gate`.
`loadConfig()` interpreta l'absència de `params.config` com `[]`, però el segon
control la interpretava com `null` i la rebutjava sempre.

El control final reconeix ara únicament el camp absent o la cadena buida com
configuració no inicialitzada. Continua rebutjant JSON invàlid, tipus incorrectes
i diferències amb la configuració rellegida. El CAS sobre els params originals
es manté intacte.

La prova d'integració s'ha executat amb el helper anterior: falla amb l'error
original. Amb la correcció passa. També s'ha trobat un requisit del contenidor:
`/var/www/mirasai-backups/style` ha d'existir fora del document root, amb accés
per `www-data`. S'ha preparat amb mode 0700. Abans, `snapshot_failed` impedia
l'escriptura; no s'ha desactivat aquesta protecció.

## Resultats

[Evidència estructurada](qa/style-joomla-2026-09-19/evidence.json):

- Preview inicial sense alterar l'ETag; confirmació exigida abans d'escriure.
- Assignació real de `fuse`, snapshot privat i ETag de relectura coincident.
- CSS LTR i RTL retornats per HTTP 200 amb SHA-256 idèntics als fitxers escrits.
- Portada HTTP 200 que referencia `/templates/yootheme/css/theme.12.css`.
- ETag caducat i canvi a una altra família rebutjats sense canviar l'ETag final.
- `npm test` complet i `git diff --check` correctes.

La prova reproduïble és `docker/test-joomla-style-initial.mjs`. És optativa,
escriu al laboratori i requereix còpia prèvia, YOOtheme actiu i cap estil ni
overrides. Les variables d'entorn són `MIRASAI_STYLE_TEST_URL`,
`MIRASAI_STYLE_TEST_TOKEN`, `MIRASAI_STYLE_TEST_WORKER_SHA256` i
`MIRASAI_STYLE_TEST_ALLOW_WRITE=1`. El token s'injecta només durant l'execució.

No s'ha fet QA visual ni una release. La prova no habilita canvis entre famílies.
Els casos amb variables o Less orfes continuen coberts pels tests locals de la
política compartida, però no s'han reproduït en aquesta execució HTTP.
La frescor de Joomla segueix basada en versió/mtime, no en comparació de config;
aquí la prova de lliurament són els hashes HTTP i la relectura.

El clon queda aturat en acabar, amb `fuse` i la correcció conservats per reproduir
la prova. Còpies i runners auxiliars: `/root/mirasai-style-20260919/`.

## Verificació del paquet 0.10.1

En preparar la release s'ha instal·lat el ZIP complet `pkg_mirasai-0.10.1.zip`
amb l'instal·lador CLI de Joomla, executat com a `www-data`: instal·lació correcta.
`system/diagnose` per HTTP retorna `mirasai_version=0.10.1`. La mateixa prova
d'integració s'ha repetit després d'inicialitzar de nou la fixture aïllada i
passen totes les comprovacions. Vegeu
[l'evidència del paquet](qa/style-joomla-2026-09-19/release-package-evidence.json).
