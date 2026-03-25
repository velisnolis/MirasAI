# Revisió Crítica: MirasAI Joomla Component (MCP + Sandbox)

He analitzat exhaustivament el codi de la llibreria `lib_mirasai`, el component `com_mirasai` i els plugins associats. El projecte presenta una enginyeria molt sòlida, amb una clara separació de responsabilitats i un enfocament en la seguretat de producció (*Smart Sudo*) que és exemplar per a integracions d'IA.

A continuació es detallen les observacions i propostes de millora estructurades per àrees.

---

## 1. Arquitectura i Integració amb Joomla

### 1.1. Unificació de Punts d'Entrada (Protocol MCP)
Actualment, la lògica per gestionar JSON-RPC i el manteniment de la connexió SSE (Server-Sent Events) està duplicada entre `mcp-endpoint.php` (standalone) i `plg_webservices_mirasai`.
*   **Problema:** El manteniment és complex i augmenta el risc d'inconsistències en el protocol.
*   **Proposta:** Crear una classe `Mirasai\Library\Mcp\Server` que encapsuli el cicle de vida de la petició. Els punts d'entrada s'haurien de limitar a:
    ```php
    $server = new McpServer(ToolRegistry::buildDefault());
    $server->handle(); // Gestiona GET (SSE) o POST (JSON-RPC)
    ```

### 1.2. Autoloading PSR-4
El fitxer `mcp-endpoint.php` conté una llista manual de prop de 30 `require_once`.
*   **Problema:** Dificulta l'escalabilitat i és propens a errors de "class not found" si es mouen fitxers.
*   **Proposta:** Implementar un autoloader PSR-4 minimalista al punt d'entrada standalone o utilitzar l'autoloader oficial de Joomla quan el framework estigui arrencat.

### 1.3. Gestió d'Assets (Nested Sets)
A `AbstractTool.php`, el mètode `insertAssetNode` realitza càlculs manuals de `lft` i `rgt` per a la taula `#__assets`.
*   **Problema:** Qualsevol error en la lògica de bloqueig de taules o en el càlcul pot corrompre l'arbre de permisos de tot el lloc Joomla.
*   **Proposta:** Delegar aquesta operació a les classes natives de Joomla:
    *   Utilitzar `Joomla\CMS\Table\Asset`.
    *   Cridar `$assetTable->setLocation($parentId, 'last-child')` i `$assetTable->store()`. Això garanteix la integritat de l'arbre automàticament.

---

## 2. Seguretat i Sandbox

### 2.1. Aïllament del `SandboxExecutePhpTool`
L'ús d'`eval()` amb transaccions de base de dades és una solució excel·lent per revertir canvis accidentals en dades, però no ofereix un aïllament real a nivell de procés PHP.
*   **Problema:** Un agent podria executar involuntàriament (o sota atac de prompt injection) funcions com `exit()`, `die()`, o exhaurir la memòria.
*   **Proposta:** 
    *   Implementar una validació de llista negra de funcions (`exec`, `passthru`, `system`, `shell_exec`, `proc_open`).
    *   Considerar l'ús d'un wrapper com `PHP-Sandbox` o, com a mínim, una validació sintàctica prèvia amb `token_get_all()`.

### 2.2. Estandardització de Tokens
Actualment utilitzeu un sistema d'HMAC personalitzat sobre el secret de Joomla.
*   **Proposta:** Si el component requereix Joomla 4.2+, podeu utilitzar directament el sistema de "Web Services API Tokens" de Joomla. Això permetria a l'administrador revocar tokens des de la gestió d'usuaris estàndard sense necessitat de lògica addicional.

---

## 3. Integració amb YOOtheme Pro

### 3.1. Desacoblament Final
El `YooThemeLayoutProcessor` i el `YooThemeHelper` encara resideixen a la llibreria principal.
*   **Proposta:** Completar el trasllat al plugin `plg_mirasai_yootheme`. La llibreria `lib_mirasai` hauria de definir la interfície `ContentLayoutProcessorInterface` i el plugin hauria de registrar el processador de YOOtheme mitjançant l'esdeveniment `onMirasaiCollectTools`.

### 3.2. Robustesa del Layout Processor
La detecció de nodes translatables es basa en regles de regex sobre cadenes.
*   **Proposta:** Afegir un test d'integritat que verifiqui que `patchLayoutArray` manté l'estructura JSON intacta davant caràcters especials (accents, cometes dobles, etc.), utilitzant la millora ja realitzada amb `OutputFilter::stringURLSafe()`.

---

## 4. Experiència de l'Agent i Desenvolupador (DX)

### 4.1. Metadades de Permisos en MCP
L'agent d'IA no sap si un tool és destructiu fins que intenta cridar-lo i falla.
*   **Proposta:** Estendre la resposta de `tools/list` per incloure una propietat personalitzada `metadata` (admesa pel protocol MCP):
    ```json
    {
      "name": "file/delete",
      "metadata": { "destructive": true, "requires_elevation": true }
    }
    ```
    Això permet a l'agent demanar permís a l'usuari abans de fallar.

### 4.2. Suport CLI
*   **Proposta:** Crear una comanda de consola (`src/Console/McpCommand.php`) per a la CLI de Joomla. Això permetria fer "pipe" de peticions MCP directament des de la terminal per a depuració:
    `php joomla mirasai:mcp '{"method": "tools/list"}'`

---

## 5. Qualitat del Codi i Rendiment

### 5.1. Lazy Loading de Tools
El `ToolRegistry::buildDefault()` instancia tots els tools de forma immediata.
*   **Proposta:** Canviar el registre perquè accepti noms de classe o factories:
    ```php
    $registry->registerLazy('content/list', ContentListTool::class);
    ```
    Això estalvia memòria i temps de CPU en peticions senzilles com un `ping`.

### 5.2. Sincronització de Fluxos de Treball (Workflows)
El mètode `ensureWorkflowAssociation` s'encarrega d'associar articles a workflows.
*   **Proposta:** Assegurar-se que quan es crea una traducció, aquesta hereti l'estat inicial del workflow definit a la categoria de destinació, evitant que articles traduïts quedin "orfes" de flux de treball si la categoria té configuracions específiques.

---

**Conclusió:** El component està molt a prop d'un estat de maduresa "Enterprise". L'aplicació del **Smart Sudo** és el punt més fort del projecte i diferencia aquesta implementació de qualsevol altre connector d'IA per a CMS.
