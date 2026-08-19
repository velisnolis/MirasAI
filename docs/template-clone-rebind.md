# Disseny — `template/clone-rebind`

Estat: **ajornat**. No implementat. No existeix al `tools/list`.
Data: 2026-08-18. Revisat el 2026-08-19.
Casos reals: BIT Vic (Joomla) i Indústria Viva draft 548 (WP).

Nom de producte: **clone-rebind**. Mai «dynamize».

## Revisió 2026-08-19 — tres retalls

La revisió va treure tres peces. Res del que queda s'ha reescrit; només s'ha
esborrat el que la revisió va invalidar.

1. **Fora els dos `scope`.** La justificació era l'atomicitat («cada pas obre
   una finestra `stale_etag`»), però el que va fallar el 18/08 va ser oblidar
   la fulla `text[1]`: un problema de **completesa**, no de cursa. I
   `scope=layout` sobrava al cas que el motivava: els layouts d'article Joomla
   viuen a `#__content.fulltext`, o sigui que un duplicat natiu d'article ja
   arrossega el layout i el destí no necessita cap còpia.
2. **Fora el deep-copy per JSON.** Innecessari: `json_decode($raw, true)`
   deixa tot en arrays i PHP ja copia arrays per valor, sense cap referència
   sobre els payloads d'element. Un round-trip `json_encode`/`json_decode`
   només afegeix cost, i com a hàbit xoca amb F-001 (absent ≠ buit).
3. **Fora la regeneració de `props.id`.** Ja no és feina d'aquest disseny:
   `template/element-clone` renombra els ids que xocarien i segueix els
   `#àncora` de dins de la còpia (`renamed_ids` a la resposta). Era un bug viu
   de 0.8.2, no un ítem pendent.

## Què el substitueix

En lloc d'un tool nou amb semàntica de clone, la direcció és una **forma batch
fail-closed a `template/element-source-set`**: `leaves[]`, un sol `if_match`,
i un dry-run que llista **totes** les fulles amb binding de l'abast amb el seu
estat (`rebound` / `kept` / `untouched`), de manera que la completesa es
verifica a la resposta i no a la memòria de l'agent.

- BIT Vic: l'humà duplica l'article (natiu) → batch-rebind in-place. Un write.
- Indústria Viva: `template/element-clone` (ja existeix) → batch-rebind sobre
  la còpia. Dos writes; si el segon falla queda una secció duplicada amb
  bindings vells: visible i recuperable, no corrupció.
- Cobreix a més el cas que aquest document no contemplava: rebindejar una
  pàgina existent **sense** clonar, que és el flux recurrent de BIT Vic quan
  arriba carpeta o CSV nou.

### Estat: implementat el 19/08

`template/element-source-set` accepta `leaves[]` i `rebind_disabled`. El que ha
aterrat del `leaf_map` de sota:

| Del disseny | A la tool |
|---|---|
| `match.rel_path` | `match.path` (absolut; l'agent ve de `bindings_only`, que dona paths absoluts) |
| `match.query_path` | igual, i ha de resoldre **exactament un** node |
| `match.source_name` / `type` / `prop` | no calen: el path ja els discrimina |
| `set.query_arguments` / `field_mappings` / `source` / `keep` | igual |
| `set.query_path` | com a `set.source_name`, que és el que canvia de debò |
| Regles 1–5 (fail-closed, un node una entrada, disabled, no inventar bindings, visibilitat) | totes |
| `rewrites` | **no**. Cap cas real l'ha necessitat encara; quan n'hi hagi un, es dissenya amb ell al davant |

Dues coses que el disseny no deia i la implementació sí:

- El dry-run reporta **tots** els nodes amb binding del layout, no només els
  anomenats, amb `state` `rebound` / `kept` / `untouched` / `skipped_disabled`.
  És el que converteix «no oblidis cap fulla» en una comprovació sobre la
  resposta en lloc d'un recordatori a l'agent.
- Els `set` són **deltes**: el que no s'anomena sobreviu. Canviar
  `query_arguments` no s'endú els `field_mappings` del costat, i els arguments
  s'escriuen al mateix carrier (niat o puntejat) d'on els llegeix
  `summarizeBinding`, perquè si no la crida sembla aplicada i el Builder
  continua amb els vells.

El tractament de `disabled` de sota segueix sent el contracte. La resta és
història del disseny descartat.

## Per què existeix

Avui el camí és `template/element-clone` + N `template/element-source-set`.
Al cas IV van caldre dos writes per la llista i encara va faltar la fulla
`_condition`. Al cas BIT Vic el clone ni tan sols és Builder: es duplica
l'article i es deixen files `status=disabled` amb el source de l'any passat
com a placeholder.

El valor és obligar el contracte a modelar el que els agents s'equivoquen:
arguments de query, visibilitat, i nodes disabled que **no** s'han de
«arreglar». L'argument de l'atomicitat va caure a la revisió del 19/08: la
finestra `stale_etag` no ha mossegat mai; la fulla oblidada, sí.

## El que ja cobreixen les tools

| Necessitat | Tool actual | Forat |
|---|---|---|
| Veure l'arbre | `template/read` `mode=outline` | — |
| Veure bindings | `template/read` / `element-list` `mode=bindings_only` | arguments a `binding.query_arguments` (nested sota `binding`, no al top-level) |
| Clonar un subarbre al mateix layout | `template/element-clone` | no rebind (ids i `#àncora` ja resolts des del fix del 19/08) |
| Rebindejar una fulla | `template/element-source-set` | un path; no sap de disabled; no fa pattern rewrite |
| Duplicar article/pàgina CMS | `content/*` o WP-CLI / Joomla duplicate | fora d'aquest write |

`clone-rebind` **no** crea posts ni articles. El destí CMS ha d'existir.

## Els dos casos

### Cas A — Indústria Viva (clonar una secció i rebindejar-la)

`template/element-clone` deixa la còpia com a germà següent (o a
`before_path` / `after_path`). El rebind s'aplica **només** sobre la còpia.

Exemple (draft 548, no la portada 65):

```json
{
  "post_id": 548,
  "if_match": "<etag del 548>",
  "path": "root>section[8]",
  "leaf_map": [
    {
      "match": {
        "rel_path": "row[0]>column[0]>fs_grid[0]>fs_grid_item[0]",
        "query_path": "ivCurss.customIvCurss"
      },
      "set": { "query_arguments": { "terms": [9] } }
    },
    {
      "match": {
        "rel_path": "row[0]>column[0]>text[1]",
        "prop": "_condition"
      },
      "set": { "keep": true }
    }
  ],
  "dry_run": true
}
```

`keep: true` vol dir «aquesta fulla ha de ser a la còpia; no canviïs el
binding». Serveix de checklist: si `text[1]` no existeix, la transacció
avorta. El bug del 2026-08-18 va ser exactament oblidar aquesta fulla.

Per al canvi de terms, `set.query_arguments` és un **replace** de
`query.arguments` / `query.field.arguments` (la mateixa normalització que
`element-source-set`), no un merge profund ceg. Field mappings (`title`,
`iv_ambitString`, …) es queden si `set` no porta `field_mappings`.

### Cas B — BIT Vic (rebind in-place del destí ja duplicat)

L'humà duplica l'article; el layout hi arriba dins de `fulltext`. Aquí no hi
ha cap còpia a fer: només `rewrites` / `disable` / `leaf_map` sobre el destí,
amb el seu `if_match`.

```json
{
  "article_id": 99,
  "if_match": "<etag de l'article destí>",
  "rewrites": [
    {
      "in": ["query_path", "query_arguments"],
      "replace": "edicio-2025-vic-",
      "with": "edicio-2026-vic-"
    }
  ],
  "disable_rel_paths": [
    "section[0]>row[6]",
    "section[0]>row[2]>column[0]>button[0]>button_item[3]"
  ],
  "dry_run": true
}
```

`disable_rel_paths` posa `props.status=disabled` al destí. No toca el source
d'aquests nodes. Un source `edicio-2024-vic-vt` dins d'un disabled **no és
un error**.

Si un `rewrite` coincidiria amb un node disabled, s'aplica igualment només
si `rebind_disabled=true` (default `false`). Default: el placeholder es queda.

## `leaf_map`

Llista ordenada. Cada entrada:

```
match:
  rel_path?     path relatiu a l'arrel de l'abast (la còpia, o root)
  query_path?   igualtat exacta del query_path canònic
  source_name?
  type?         type YOOtheme (fs_grid_item, gallery_item, text, …)
  prop?         mapping de visibilitat / camp (`_condition`, `content`, …)
set:
  query_arguments?   objecte; replace
  query_path?        canvia name+field (rara; preferir rewrites de string)
  field_mappings?    replace dels props de source indicats; la resta es queda
  source?            payload cru, com element-source-set (escape hatch)
  keep?              true → exigeix el match, zero mutació de binding
```

Cal almenys un camp a `match`. `rel_path` és el preferit quan l'agent ve de
`mode=bindings_only`.

Regles:

1. **Fail-closed.** Si un `match` no resol exactament un node a la còpia,
   error `leaf_unmatched` i no s'escriu res.
2. **Un node, una entrada.** Dos matches al mateix path → `leaf_conflict`.
3. **Disabled.** Nodes amb `props.status=disabled` s'ometen del rebind
   (i dels `rewrites`) tret de `rebind_disabled=true`.
4. **No inventar bindings.** `clone-rebind` no afegeix `source` on no n'hi
   havia. Per això serveix `element-source-set`.
5. **Visibilitat.** Un mapping `_condition` és una fulla com qualsevol altra.
   Si el cas la necessita, ha de ser al mapa (`keep` o `set`).

## `rewrites`

Opcional, pensat per BIT Vic (carpeta/CSV/pattern). S'aplica **després** del
clone i **abans** del `leaf_map` (el mapa pot corregir un rewrite).

- `in`: quins camps string-ish (`query_path`, valors de `query_arguments`).
- `replace` / `with`: substitució literal, no regex. Regex seria
  `dangerous_exec` de facto i no entra en aquest tool.
- Informe de dry-run: cada substitució (`path`, camp, before, after).
- Zero matches d'un rewrite → `rewrite_noop` (warning al preview, no error).
  Un rewrite que no toca res no ha de fer fallar BIT Vic si encara no hi ha
  galeria nova.

## Invariants del write

Risc: `guarded_write`, el mateix que `element-clone`. **No** `dangerous_exec`.
No s'amaga darrere elevation. És un write de Builder, no `eval`.

Guàrdies estàndard:

- selector d'emmagatzematge (un de sol), igual que la resta de `template/*`
- `if_match` obligatori
- `dry_run=true` primer; write real amb `confirm_guarded_write=true`
- ETag **sempre** del layout complet destí, també si el read d'inspecció va
  ser `outline` / `bindings_only`
- Customizer tancat (F-011). El playbook ja ho diu; el tool no ho pot detectar

Atòmic:

1. Llegeix destí, comprova ETag.
2. Aplica disable / rewrites / leaf_map.
3. Una sola persistència. Si qualsevol pas falla, el destí no canvia.

`props.id` HTML: resolt a `template/element-clone` des del 19/08 (renombra
l'id que xocaria, segueix els `#àncora` de dins de la còpia i ho reporta a
`renamed_ids`). Aquest contracte no toca cap prop que no sigui
`source` / `status`.

Fora d'abast (explícit):

- crear o duplicar l'article/post CMS
- canviar assignments YOOtheme (`template` type, menús)
- Style / LESS
- inferir el `leaf_map` des del catàleg de sources
- «arreglar» sources de nodes disabled
- `Builder::load(context:save)` — el write path és el de MirasAI (F-018)

## Dry-run (forma de resposta)

Mateixa família que `element-source-set` (`before`/`after`, sense `raw_source`
per defecte). Extra:

```
leaves[]                        # TOTES les fulles amb binding de l'abast,
                                # cadascuna amb state rebound|kept|untouched
rebinds[]                       # {path, query_path, before, after}
skipped_disabled[]              # paths
unmatched_rewrites[]
etag                            # etag actual (no escrit)
would_write                     # true
```

El confirm reutilitza aquesta forma amb `action: updated` i `etag` nou.

## Errors

| code | quan |
|---|---|
| `missing_if_match` | com la resta de writes |
| `stale_etag` | destí canviat |
| `invalid_path` | `path` absent o `path=root` |
| `leaf_unmatched` | una entrada del mapa no resol un node |
| `leaf_conflict` | dos matches al mateix node |
| `rebind_disabled_blocked` | el match cau en disabled i `rebind_disabled=false` |
| `unknown_argument` | com la resta |

## Com s'inspecciona abans (read-modes)

Ja desplegat a industriaviva (WP) i al lab Joomla 2026-08-18.

1. `template/read mode=outline` per situar `section[n]` / files.
2. `template/element-list mode=bindings_only` per construir el `leaf_map`.
   Cada fila és `{path, type, binding:{query_path, query_arguments, field_mappings, …}}`.
3. Els nodes disabled ja es veuen des dels dos modes: no cal baixar a
   `element-list full` ni a `element-read` només per això.

**Requisit previ — FET el 19/08.** `outline` porta `status` i
`has_source_binding` (el nom que ja feia servir `element-list`, no
`has_binding`), i `bindings_only` porta `status` i **`disabled_by`**. Aquest
últim no era al pla: com que `bindings_only` és una llista plana, un binding
dins d'una fila disabled no es distingia de cap manera, i és exactament la
forma de BIT Vic (la fila apagada, el `gallery_item` de dins amb el source de
l'edició passada). `disabled_by` dona el path de l'avantpassat (o d'ell mateix)
que està apagat. Tots els flags s'ometen quan l'element renderitza i no té
binding.

## Implementació (quan es demani)

Ordre, no abans:

1. ~~`status` / `has_source_binding` / `disabled_by` als read-modes~~ — fet el 19/08.
2. ~~Forma batch `leaves[]` a `template/element-source-set` + tests de forma~~ — fet el 19/08.
3. WP al draft **548**, mai `post_id=65`. Smoke: `element-clone`
   `section[7]` → `[8]`, després batch-rebind terms 7→9 a `fs_grid_item`
   **i** `text[1]` al mapa; section[7] intacta.
4. Joomla lab (`mirasai-fase0` o article de prova), rebind in-place.
5. Skill `yootheme-builder-ops` workflow 3: cridar la forma batch; recollir
   casos nous al findings.
6. BIT Vic producció només amb article destí duplicat per l'humà i dry-run
   revisat.

Playbook: una línia a `best` de `yootheme_builder` quan el tool existeixi.
Fins llavors el workflow 3 de la skill continua sent clone + N source-set.

## Decisions tancades

- ~~Un tool, dos `scope`~~ → retallat el 19/08; forma batch a
  `template/element-source-set`.
- `guarded_write`, no elevation.
- Fail-closed al mapa; rewrite literal amb warning si no coincideix.
- Destí CMS preexistent.
- Nodes disabled = placeholder, no error.
- Incloure fulles `_condition`, i llistar-les **totes** al dry-run.
- ~~Deep-copy obligatori abans del rebind~~ → retallat el 19/08; PHP ja copia
  arrays per valor.
- No Fase 1 save-transform com a dependència (F-018: el JSON de MirasAI no
  es corromp al save del Customizer en 5.0.40 amb l'arbre natiu).
