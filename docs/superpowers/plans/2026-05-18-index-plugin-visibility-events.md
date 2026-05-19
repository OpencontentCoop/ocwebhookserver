# Hybrid Visibility Events Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Emit Kafka events when content visibility changes (hide/show, state change, section change), not only on new-version publish.

**Piano A (questo documento) vs Piano B (alternativo):** intercettare `eZSolr::addObject()` con un `ezpIndexPlugin` — il **Piano B** — sarebbe l'entry point più compatto perché un singolo hook copre automaticamente ogni path che re-indicizza il contenuto. È stato però scartato in questa fase perché legherebbe l'emissione eventi alla presenza di Solr: uno degli obiettivi a medio termine è poter **disattivare Solr** sui tenant che non lo usano (es. sostituirlo con un motore di ricerca esterno); se l'emissione Kafka dipendesse dal plugin di indicizzazione Solr, dismetterlo significherebbe perdere gli eventi. La strategia ibrida del Piano A separa emissione e layer di ricerca. Il Piano B resta documentato in [`piano-b-solr-index-plugin.md`](./piano-b-solr-index-plugin.md) come fallback ripristinabile se i gap del Piano A (hide subtree figli, translation, trash, move) diventassero bloccanti.

**Architecture:** Three complementary mechanisms cover all visibility-change paths without depending on Solr being the event trigger:
1. **Operation handler workflows** — extend `WorkflowWebHookType` to handle `post_hide`, `post_updateobjectstate`, `post_updatesection` in addition to `post_publish`; register DB triggers for these operations. Covers all UI-originated changes.
2. **`ezpEvent` listener** — `OpenPAStateTools::flushObject()` and `OpenPASectionTools::flushObject()` fire `ezpEvent('openpa/object/flushed')`; `OCWebHookObjectFlushListener` in `ocwebhookserver` emits. Covers cron-originated changes without coupling `openpa` to `ocwebhookserver`.
3. **Payload builder extraction** — `OCWebHookPayloadBuilder` shared by all emit paths to eliminate code duplication.

**Event model (single trigger, no ce_type differentiation):** tutte le emissioni passano dall'identificatore esistente `PostPublishWebHookTrigger::IDENTIFIER` (`post_publish_ocopendata`). Non emettiamo eventi separati `.published`/`.unpublished`. Il payload include `metadata.isPublic` (computato da `checkAccess`) e il **consumer è responsabile di filtrare/derivare** quanto serve (es. ignorare eventi su contenuti privati, dedurre transizioni published↔unpublished diffando rispetto allo stato precedente).

**Queue handling:** `PostPublishWebHookTrigger::getQueueHandler()` ritorna `HANDLER_SCHEDULED`, quindi la consegna a Kafka è già asincrona via outbox per tutti i path. Il costo sincrono inevitabile è la costruzione del payload (full content fetch + `filterContent` + relation enrichment), che resta nel loop chiamante; vedere "Performance considerations" sotto.

**Casi coperti (extended scope dopo review):**
- **Hide subtree propagation ai figli** — in `post_hide` enumeriamo i discendenti del nodo nascosto (path_string LIKE) ed emettiamo un evento per ciascuno. Vedi Task 2.1 — sezione "post_hide: subtree propagation".
- **Rimozione traduzione (`post_removetranslation`)** — gestito; il payload contiene l'oggetto con le lingue residue, `checkAccess` riflette se l'oggetto è ancora pubblicamente accessibile.
- **Restore from trash** — la `kernel/content/restore.php` esegue una `AddLocation` action → `eZOperationHandler::execute('content','addlocation',...)` → trigger `post_addlocation`. Gestito.
- **Trash (soft delete)** — già coperto da `DeleteWebHookTrigger`/`DeleteWorkflowWebHookType` su `pre_delete` (`move_to_trash=1` o `0`, entrambi emettono `delete_ocopendata`). Non duplichiamo qui.
- **Move (`post_move`)** — gestito; cambi di sezione impliciti tramite ACL ereditate vengono riflessi via `checkAccess` nel payload.

**Casi NON coperti (consapevolmente):**
- **Modifiche idempotenti** (stato/sezione "cambiati" allo stesso valore corrente, hide su nodo già nascosto) — emettiamo comunque; non facciamo diff lato producer. Coerente con la decisione "il consumer filtra".
- **Contenuto privato modificato** — emettiamo comunque con `isPublic: false`; il filtraggio è demandato al consumer.
- **Hard delete senza passare da `eZOperationHandler::execute('content','delete',...)`** — fuori scope di questo piano (era già escluso dal pre-esistente `DeleteWebHookTrigger`).

**Tech Stack:** PHP 7.2, eZ Publish 5 operation handler triggers (`eZTrigger`, `eZWorkflow`), `ezpEvent` hook system, existing `OCWebHookEmitter` / `OCWebHookKafkaPayloadFormatter` infrastructure.

---

## File map

| File | Action | Role |
|---|---|---|
| `ocwebhookserver/classes/ocwebhookpayloadbuilder.php` | **Create** | Builds enriched ocopendata payload from `eZContentObject` |
| `ocwebhookserver/eventtypes/event/workflowwebhook/workflowwebhooktype.php` | **Modify** | Add `post_hide`, `post_updateobjectstate`, `post_updatesection` handling |
| `ocwebhookserver/bin/php/emit_all_published.php` | **Modify** | Use `OCWebHookPayloadBuilder::build()` instead of inline code |
| `ocwebhookserver/classes/ocwebhookobjectflushlistener.php` | **Create** | `ezpEvent` listener — emits on `openpa/object/flushed` |
| `ocwebhookserver/settings/site.ini.append.php` | **Create** | Register listener: `[Event] Listeners[]=openpa/object/flushed@OCWebHookObjectFlushListener::handle` |
| `ocwebhookserver/tests/PayloadBuilderTest.php` | **Create** | Unit tests for `OCWebHookPayloadBuilder` helpers |
| `openpa/classes/openpastatetools.php` | **Modify** | Fire `ezpEvent('openpa/object/flushed')` from `flushObject()` |
| `openpa/classes/openpasectiontools.php` | **Modify** | Fire `ezpEvent('openpa/object/flushed')` from `flushObject()` |

---

## Context you need to understand first

### Coverage by mechanism

| User action | Mechanism | Trigger | Status |
|---|---|---|---|
| Publish new version (UI) | Operation handler | `post_publish` → `WorkflowWebHookType` | ✅ existing |
| Hide/show node (UI, singolo) | Operation handler | `post_hide` → `WorkflowWebHookType` (+ enumera figli) | ✅ new |
| Hide subtree → figli (`is_invisible` propagato) | Operation handler | `post_hide` enumera path_string discendenti, 1 emit per nodo | ✅ new (cap configurabile) |
| Change state (UI) | Operation handler | `post_updateobjectstate` → `WorkflowWebHookType` | ✅ new |
| Change section (UI singola sezione) | Operation handler | `post_updatesection` → `WorkflowWebHookType` | ✅ new |
| Change state (cron `change_state.php`) | `ezpEvent` | `openpa/object/flushed` → `OCWebHookObjectFlushListener` | ✅ new |
| Change section (cron `change_section.php`) | `ezpEvent` | `openpa/object/flushed` → `OCWebHookObjectFlushListener` | ✅ new |
| Rimozione traduzione (`post_removetranslation`) | Operation handler | `post_removetranslation` → `WorkflowWebHookType` | ✅ new |
| Move tra subtree (cambio sezione implicito) | Operation handler | `post_move` → `WorkflowWebHookType` | ✅ new |
| Restore from trash | Operation handler | `post_addlocation` (la restore.php usa AddLocation) → `WorkflowWebHookType` | ✅ new |
| Trash (soft delete) | Workflow esistente | `pre_delete` → `DeleteWorkflowWebHookType` → `delete_ocopendata` | ✅ pre-esistente |
| Hard delete | Workflow esistente | `pre_delete` → `DeleteWorkflowWebHookType` → `delete_ocopendata` | ✅ pre-esistente |
| Modifica contenuto privato (no transizione visibility) | — | emesso comunque con `isPublic: false` | ⚠️ filtrato dal consumer |
| Modifica idempotente (stato/sezione invariati) | — | emesso comunque, no diff | ⚠️ filtrato dal consumer |

### Parameters passed to WorkflowWebHookType per trigger

`$process->attribute('parameter_list')` viene popolato dall'operation handler con le chiavi definite in `html/kernel/content/operation_definition.php`. Verificate sul codice corrente:

| Trigger | Chiavi disponibili in `$parameters` | Come ricavare l'`eZContentObject` |
|---|---|---|
| `post_publish` | `object_id`, `version`, `trigger_name`, `module_name`, `module_function`, `user_id`, `workflow_id` | `eZContentObject::fetch($parameters['object_id'])` |
| `post_hide` | `node_id` (solo) + chiavi runtime | `eZContentObjectTreeNode::fetch($parameters['node_id'])->object()` |
| `post_updateobjectstate` | `object_id`, `state_id_list` | `eZContentObject::fetch($parameters['object_id'])` |
| `post_updatesection` | `node_id`, `selected_section_id` | `eZContentObjectTreeNode::fetch($parameters['node_id'])->object()` (NON c'è `object_id`) |
| `post_removetranslation` | `object_id`, `language_id_list`, `node_id` | `eZContentObject::fetch($parameters['object_id'])` |
| `post_move` | `node_id`, `object_id`, `new_parent_node_id` | `eZContentObject::fetch($parameters['object_id'])` |
| `post_addlocation` | `node_id`, `object_id`, `select_node_id_array` | `eZContentObject::fetch($parameters['object_id'])` |

**`post_hide` non porta un flag "hide/show"**: l'operazione `changeHideStatus` agisce come toggle. Per sapere lo stato risultante, dopo la fetch del nodo va letto `$node->attribute('is_hidden')` (riflette già il nuovo valore, perché il trigger `post_*` scatta dopo l'esecuzione del method body).

**`post_hide` propaga `is_invisible` ai discendenti**: vedi `eZContentObjectTreeNode::hideSubTree()` in `html/kernel/classes/ezcontentobjecttreenode.php:5972`. La query SQL `UPDATE ezcontentobject_tree SET is_invisible=1 WHERE path_string LIKE '<padre>%'` cambia la visibilità di tutti i figli in un colpo solo; non c'è trigger per nodo. Per emettere un evento per ciascun discendente lo facciamo manualmente nel branch `post_hide` (vedi Task 2.1, sezione subtree propagation).

### How eZ Publish workflow triggers work

Triggers are rows in the `eztrigger` table mapping `(module_name, function_name, connect_type)` → `workflow_id`. When `eZOperationHandler::execute('content', 'hide', ...)` runs, eZ looks up all triggers for `content/hide/post` and executes the linked workflows in order. Each workflow runs event types in sequence. `WorkflowWebHookType` is the event type we use.

The installer creates the workflow and trigger row for `post_publish`. We must add rows for the three new operations.

### ezpEvent system

`ezpEvent::getInstance()->notify($name, $params)` dispatches to all registered listeners. Listeners are registered in `site.ini.append.php`:

```ini
[Event]
Listeners[]=openpa/object/flushed@ClassName::method
```

The listener method receives the `$params` array as arguments. If we call `notify('openpa/object/flushed', [$object])`, the listener receives `handle(eZContentObject $object)`.

eZ reads listeners via `eZINI::instance('site.ini')->variable('Event', 'Listeners')` — the merge happens at runtime, so `EZINIMERGE_` env vars and extension INI files all contribute.

### Current payload-building code (to be extracted)

`eventtypes/event/workflowwebhook/workflowwebhooktype.php` lines ~26–104: builds the payload inline. Two private helpers:
- `userInfo(int $userId): ?array`
- `enrichRelationContentUrls(array &$payload, string $baseUrl): void`

`bin/php/emit_all_published.php` lines ~145–154: partially duplicates the same logic.

### Performance considerations (cron paths)

`OCWebHookPayloadBuilder::build()` esegue, per ogni oggetto: full `eZContentObject::fetch`, `Content::createFromEzContentObject`, `filterContent` (con `DefaultEnvironmentSettings` + parsing della request), `checkAccess` per anonimo, lookup endpoint OpenAPI, ed enrich relazioni (una `fetch` per ciascun `mainNodeId` di item relazione). Per pubblicazione singola UI è trascurabile; per i cron `change_state.php`/`change_section.php` che possono iterare centinaia di oggetti diventa significativo.

**Mitigazioni in atto:**
- `PostPublishWebHookTrigger::getQueueHandler()` ritorna già `HANDLER_SCHEDULED` → la push verso Kafka NON è sincrona, viene messa in outbox e processata dal worker. **Quindi il costo Kafka-network non blocca il cron.** Il readme `ocwebhookserver/readme.md:32-33` è disallineato (parla di `HANDLER_IMMEDIATE`) e va corretto in un commit separato.
- Il costo della build payload resta sincrono nel loop del cron.

**Da NON fare in questo piano:** prematura ottimizzazione (cache layer, batch, queue interno). Prima misurare:
- Tempo medio cron `change_state.php` prima/dopo il fix.
- Se cresce di >30% → aggiungere TaskCreate per progettare strategia di buffering.

**Loop guard:** il listener `OCWebHookObjectFlushListener::handle` NON deve generare a sua volta operazioni che ri-triggerano `flushObject()` o un trigger workflow (es. evitare `eZContentObjectTreeNode::hideSubTree`, `assignState`, ecc. dentro il listener). Solo lettura.

### Autoload note

After adding new PHP class files, regenerate the eZ autoload map inside the container:

```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php -d memory_limit=256M html/bin/php/ezpgenerateautoloads.php -e 2>&1); echo "$OUT"
```

---

## Task 1: Create OCWebHookPayloadBuilder

Extract all payload-building logic into a single reusable static class. After this task the workflow and the bulk script call `OCWebHookPayloadBuilder::build()` and produce identical output to today.

**Files:**
- Create: `classes/ocwebhookpayloadbuilder.php`
- Modify: `eventtypes/event/workflowwebhook/workflowwebhooktype.php`
- Modify: `bin/php/emit_all_published.php`

- [ ] **Step 1.1: Create `classes/ocwebhookpayloadbuilder.php`**

```php
<?php

use Opencontent\Opendata\Api\Values\Content;

class OCWebHookPayloadBuilder
{
    /**
     * Build the enriched ocopendata payload for a content object.
     *
     * @param eZContentObject $object
     * @return array  Payload with keys "metadata", "data", "extradata"
     */
    public static function build(eZContentObject $object)
    {
        $content = Content::createFromEzContentObject($object);
        $currentEnvironment = new DefaultEnvironmentSettings();
        $parser = new ezpRestHttpRequestParser();
        $request = $parser->createRequest();
        $currentEnvironment->__set('request', $request);
        $payload = $currentEnvironment->filterContent($content);

        $payload['metadata']['baseUrl']        = eZSys::serverURL();
        $payload['metadata']['currentVersion'] = (int)$object->attribute('current_version');

        $mainNode = $object->mainNode();
        if ($mainNode instanceof eZContentObjectTreeNode) {
            $urlAlias = $mainNode->urlAlias();
            $payload['metadata']['contentUrl'] = $payload['metadata']['baseUrl'] . '/' . ltrim($urlAlias, '/');
            $payload['metadata']['isPublic']   = (bool)$mainNode->checkAccess('read', null, null, false, eZUser::anonymousId());
        } else {
            $payload['metadata']['isPublic'] = false;
        }

        $currentVersion = $object->currentVersion();
        $modifierId = $currentVersion instanceof eZContentObjectVersion
            ? (int)$currentVersion->attribute('creator_id')
            : (int)$object->attribute('owner_id');
        $payload['metadata']['createdBy']  = self::userInfo((int)$object->attribute('owner_id'));
        $payload['metadata']['modifiedBy'] = self::userInfo($modifierId);

        $payload['metadata']['apiUrl'] = null;
        if ($mainNode instanceof eZContentObjectTreeNode
            && class_exists('Opencontent\\OpenApi\\Loader')
        ) {
            try {
                $pathArray = explode('/', $mainNode->attribute('path_string'));
                $classId   = $object->attribute('class_identifier');
                $remoteId  = $object->attribute('remote_id');

                $endpoint = \Opencontent\OpenApi\Loader::instance()
                    ->getEndpointProvider()
                    ->getEndpointFactoryCollection()
                    ->findOneByCallback(
                        function ($ep) use ($classId, $pathArray) {
                            if (!($ep instanceof \Opencontent\OpenApi\EndpointFactory\NodeClassesEndpointFactory)) {
                                return false;
                            }
                            $getOp = $ep->getOperationByMethod('get');
                            return $getOp instanceof \Opencontent\OpenApi\OperationFactory\ContentObject\ReadOperationFactory
                                && in_array($ep->getNodeId(), $pathArray)
                                && in_array($classId, $ep->getClassIdentifierList());
                        }
                    );

                if ($endpoint instanceof \Opencontent\OpenApi\EndpointFactory\NodeClassesEndpointFactory) {
                    $parts       = explode('/', $endpoint->getPath());
                    array_pop($parts);
                    $endpointUrl = \Opencontent\OpenApi\Loader::instance()
                        ->getSettingsProvider()
                        ->provideSettings()
                        ->endpointUrl;
                    $basePath  = $endpointUrl . implode('/', $parts) . '/';
                    $nameSlug  = \eZCharTransform::instance()
                        ->transformByGroup($object->attribute('name'), 'urlalias');
                    $payload['metadata']['apiUrl'] = $basePath . $remoteId . '#' . $nameSlug;
                }
            } catch (\Exception $e) {
                eZLog::write(__METHOD__ . ': apiUrl build failed: ' . $e->getMessage(), 'webhook.log');
            }
        }

        self::enrichRelationContentUrls($payload, $payload['metadata']['baseUrl']);

        return $payload;
    }

    public static function userInfo($userId)
    {
        if (!$userId) {
            return null;
        }
        $user = eZUser::fetch($userId);
        if (!($user instanceof eZUser)) {
            return null;
        }
        $userObject = eZContentObject::fetch($userId);
        $name = ($userObject instanceof eZContentObject) ? $userObject->name() : $user->attribute('login');
        return [
            'id'    => $userId,
            'login' => $user->attribute('login'),
            'name'  => (string)$name,
        ];
    }

    public static function enrichRelationContentUrls(array &$payload, $baseUrl)
    {
        if (empty($payload['data']) || !is_array($payload['data'])) {
            return;
        }
        $nodeUrlCache = [];
        foreach ($payload['data'] as $lang => &$attributes) {
            if (!is_array($attributes)) {
                continue;
            }
            foreach ($attributes as $attrName => &$attrValue) {
                $items = null;
                if (is_array($attrValue) && array_key_exists('content', $attrValue)
                    && is_array($attrValue['content'])
                    && isset($attrValue['content'][0])
                    && is_array($attrValue['content'][0])
                ) {
                    $items = &$attrValue['content'];
                }
                if ($items === null) {
                    continue;
                }
                foreach ($items as &$item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $nodeId = isset($item['mainNodeId']) ? (int)$item['mainNodeId']
                            : (isset($item['main_node_id']) ? (int)$item['main_node_id'] : null);
                    if (!$nodeId) {
                        continue;
                    }
                    if (!array_key_exists($nodeId, $nodeUrlCache)) {
                        $node = eZContentObjectTreeNode::fetch($nodeId);
                        $nodeUrlCache[$nodeId] = ($node instanceof eZContentObjectTreeNode)
                            ? $baseUrl . '/' . ltrim($node->urlAlias(), '/')
                            : null;
                    }
                    if ($nodeUrlCache[$nodeId] !== null) {
                        $item['content_url'] = $nodeUrlCache[$nodeId];
                    }
                }
                unset($item);
            }
            unset($attrValue);
        }
        unset($attributes);
    }
}
```

- [ ] **Step 1.2: Update `WorkflowWebHookType::execute()` to use `OCWebHookPayloadBuilder`**

Replace the inline payload-building block with a call to the builder. Keep the `post_publish` handling intact; the new trigger cases are added in Task 2. The `use Opencontent\Opendata\Api\Values\Content;` import and the private helper methods (`userInfo`, `enrichRelationContentUrls`) are removed since they move to the builder.

The method body for `post_publish` becomes:

```php
function execute($process, $event)
{
    $parameters = $process->attribute('parameter_list');
    $trigger    = $parameters['trigger_name'];

    try {
        if ($trigger === 'post_publish') {
            $object = eZContentObject::fetch($parameters['object_id']);
            if (!$object instanceof eZContentObject) {
                return eZWorkflowType::STATUS_ACCEPTED;
            }
            $payload = OCWebHookPayloadBuilder::build($object);
            $triggerInstance = OCWebHookTriggerRegistry::registeredTrigger(PostPublishWebHookTrigger::IDENTIFIER);
            $queueHandler = $triggerInstance instanceof OCWebHookTriggerQueueAwareInterface
                ? $triggerInstance->getQueueHandler()
                : OCWebHookQueue::defaultHandler();
            OCWebHookEmitter::emit(PostPublishWebHookTrigger::IDENTIFIER, $payload, $queueHandler);
        }
    } catch (Exception $e) {
        eZLog::write(__METHOD__ . ': ' . $e->getMessage(), 'webhook.log');
    }

    return eZWorkflowType::STATUS_ACCEPTED;
}
```

- [ ] **Step 1.3: Update `emit_all_published.php` to use `OCWebHookPayloadBuilder`**

Replace the inline payload block (around lines 145–154):

```php
    try {
        $payload = OCWebHookPayloadBuilder::build($object);

        $classId = $object->attribute('class_identifier');
        $name    = $object->attribute('name');

        if ($dryRun) {
            $cli->output("  WOULD EMIT [$objectId] $classId: $name");
        } else {
            OCWebHookEmitter::emit($triggerIdentifier, $payload, $queueHandler);
            $emitted++;
            if ($verbose) {
                $cli->output("  EMITTED [$objectId] $classId: $name");
            } elseif (($i + 1) % 50 === 0) {
                $cli->output("  ... $emitted emessi finora (su $count)");
            }
        }
    } catch (Exception $e) {
        $errors++;
        $cli->error("  ERROR [$objectId]: " . $e->getMessage());
    }
```

Remove the now-redundant `$mainNode` / `isPublic` lines that were previously inline (they are inside `OCWebHookPayloadBuilder::build()` now).

Add a `require_once` at the top of the script, following the same pattern as existing requires:

```php
require_once $extensionDir . '/classes/ocwebhookpayloadbuilder.php';
```

Where `$extensionDir` is derived from the existing require pattern at the top of the file.

- [ ] **Step 1.4: Run unit tests — no regression**

```bash
cd /Volumes/Repos/sviluppo-sito-comunale/ocwebhookserver
php tests/PayloadFormatterTest.php
```

Expected: all 111 tests pass, exit 0.

- [ ] **Step 1.5: Commit**

```bash
cd /Volumes/Repos/sviluppo-sito-comunale/ocwebhookserver
git add classes/ocwebhookpayloadbuilder.php \
        eventtypes/event/workflowwebhook/workflowwebhooktype.php \
        bin/php/emit_all_published.php
git commit -m "refactor: extract OCWebHookPayloadBuilder from WorkflowWebHookType"
```

---

## Task 2: Extend WorkflowWebHookType for visibility-change triggers

Add `post_hide`, `post_updateobjectstate`, `post_updatesection` handling to `WorkflowWebHookType`, then register DB trigger rows so eZ fires the workflow for these operations.

**Files:**
- Modify: `eventtypes/event/workflowwebhook/workflowwebhooktype.php`
- Modify: installer PHP callable (add trigger registration) — find it by looking at the existing installer step that creates the `post_publish` trigger

- [ ] **Step 2.1: Estendere `WorkflowWebHookType::execute()` con tutti i trigger di visibilità**

Gestisce 7 trigger (1 pre-esistente + 6 nuovi). **Attenzione ai parametri**: ogni trigger ha chiavi diverse — vedi tabella sopra. Per `post_hide` enumeriamo anche i discendenti per propagare `is_invisible`.

```php
function execute($process, $event)
{
    $parameters = $process->attribute('parameter_list');
    $trigger    = $parameters['trigger_name'];

    try {
        switch ($trigger) {
            case 'post_publish':
            case 'post_updateobjectstate':
            case 'post_removetranslation':
            case 'post_move':
            case 'post_addlocation':
                // Tutti forniscono object_id direttamente
                if (isset($parameters['object_id'])) {
                    $object = eZContentObject::fetch((int)$parameters['object_id']);
                    if ($object instanceof eZContentObject) {
                        self::emitFor($object);
                    }
                }
                break;

            case 'post_updatesection':
                // Solo node_id — risali all'object via il nodo
                if (isset($parameters['node_id'])) {
                    $node = eZContentObjectTreeNode::fetch((int)$parameters['node_id']);
                    if ($node instanceof eZContentObjectTreeNode) {
                        self::emitFor($node->object());
                    }
                }
                break;

            case 'post_hide':
                // Solo node_id + propaga ai discendenti (subtree invisibility cascade)
                if (isset($parameters['node_id'])) {
                    $node = eZContentObjectTreeNode::fetch((int)$parameters['node_id']);
                    if ($node instanceof eZContentObjectTreeNode) {
                        self::emitFor($node->object());
                        self::emitForSubtreeDescendants($node);
                    }
                }
                break;

            default:
                // trigger non gestito → no-emit
                break;
        }
    } catch (Exception $e) {
        eZLog::write(__METHOD__ . ': ' . $e->getMessage(), 'webhook.log');
    }

    return eZWorkflowType::STATUS_ACCEPTED;
}

/**
 * Costruisce il payload e lo accoda al webhook con il queue handler del trigger.
 */
private static function emitFor(eZContentObject $object)
{
    $payload = OCWebHookPayloadBuilder::build($object);
    $triggerInstance = OCWebHookTriggerRegistry::registeredTrigger(
        PostPublishWebHookTrigger::IDENTIFIER
    );
    $queueHandler = $triggerInstance instanceof OCWebHookTriggerQueueAwareInterface
        ? $triggerInstance->getQueueHandler()
        : OCWebHookQueue::defaultHandler();
    OCWebHookEmitter::emit(
        PostPublishWebHookTrigger::IDENTIFIER,
        $payload,
        $queueHandler
    );
}
```

**post_hide: subtree propagation.**

`eZContentObjectTreeNode::hideSubTree()` aggiorna in un colpo solo tutti i discendenti via `UPDATE ezcontentobject_tree SET is_invisible=1 WHERE path_string LIKE '<padre>%'`. Non c'è trigger per nodo. Per emettere un evento per ciascun discendente:

```php
/**
 * Emette un evento per ogni discendente del nodo (esclude il nodo stesso).
 * Usato in branch post_hide per propagare la cascade di is_invisible.
 *
 * Cap di sicurezza: SUBTREE_EMIT_LIMIT. Sopra il cap, log warning e nessuna emit
 * (il consumer dovrà fare una reconciliation completa del subtree).
 */
const SUBTREE_EMIT_LIMIT = 500;

private static function emitForSubtreeDescendants(eZContentObjectTreeNode $rootNode)
{
    $rootNodeId = (int)$rootNode->attribute('node_id');
    $rootPath   = $rootNode->attribute('path_string');

    // Conta prima di iterare, per applicare il cap senza caricare tutti i nodi
    $countRow = eZDB::instance()->arrayQuery(
        "SELECT COUNT(*) AS c FROM ezcontentobject_tree " .
        "WHERE path_string LIKE '" . eZDB::instance()->escapeString($rootPath) . "%' " .
        "AND node_id <> $rootNodeId"
    );
    $count = (int)($countRow[0]['c'] ?? 0);

    if ($count > self::SUBTREE_EMIT_LIMIT) {
        eZLog::write(
            sprintf(
                __METHOD__ . ': subtree under node %d has %d descendants (> %d cap); skipping per-descendant emit',
                $rootNodeId, $count, self::SUBTREE_EMIT_LIMIT
            ),
            'webhook.log'
        );
        return;
    }

    // Itera in chunk per evitare result set memory blow-up
    $offset    = 0;
    $chunkSize = 100;
    while (true) {
        $rows = eZDB::instance()->arrayQuery(
            "SELECT contentobject_id FROM ezcontentobject_tree " .
            "WHERE path_string LIKE '" . eZDB::instance()->escapeString($rootPath) . "%' " .
            "AND node_id <> $rootNodeId " .
            "ORDER BY node_id LIMIT $chunkSize OFFSET $offset"
        );
        if (empty($rows)) break;

        foreach ($rows as $row) {
            $childObject = eZContentObject::fetch((int)$row['contentobject_id']);
            if ($childObject instanceof eZContentObject) {
                self::emitFor($childObject);
            }
        }
        $offset += $chunkSize;
    }
}
```

> **Trade-off del cap a 500 discendenti**: sopra il cap rinunciamo a emettere per ciascun figlio per non bloccare la richiesta UI (la build payload è sincrona, anche se la push Kafka è async). Il consumer deve avere un meccanismo di reconciliation per casi grossi (es. nascondere un'intera area amministrativa). Il cap è configurabile via override del valore della costante in un commit successivo se serve.

> **Nota su `isPublic`:** `OCWebHookPayloadBuilder::build()` calcola `metadata.isPublic` via `$mainNode->checkAccess('read', null, null, false, eZUser::anonymousId())`. Questo singolo check riflette correttamente l'effetto combinato di `is_hidden`/`is_invisible`/section ACL/state limitations — non serve duplicare la logica nei singoli branch.

> **Nota su `post_removetranslation`:** dopo la rimozione della traduzione, l'oggetto potrebbe avere ancora altre lingue disponibili o nessuna. `checkAccess` su anonymous user riflette il fatto se il content sia ancora leggibile in qualche lingua disponibile per l'anonimo. Se era l'ultima traduzione l'object è sostanzialmente unpublished → `isPublic: false`.

> **Nota su `post_move`:** il move può cambiare sezione implicitamente se il `new_parent_node_id` appartiene a una sezione diversa (ACL ereditate da `assignSectionToSubTree`). Il `checkAccess` riflette le nuove ACL. Se il move è cross-section ma il nuovo subtree è accessibile come prima, l'evento è semanticamente "no-op" — filtra il consumer.

> **Nota su `post_addlocation` (restore):** la `kernel/content/restore.php` chiama `eZOperationHandler::execute('content','addlocation',...)` quando l'utente conferma la restore (vedi linea 47-48 del file: `$module->setCurrentAction('AddLocation')`). Quindi un singolo `post_addlocation` scatta sia per "restore from trash" sia per "add location manuale" da admin UI; non possiamo distinguerli dai parametri. Va bene: in entrambi i casi l'oggetto guadagna un nuovo nodo e potrebbe diventare pubblicamente accessibile.

- [ ] **Step 2.2: Estendere `OCWebHookKafkaSetupService` per registrare i trigger di visibilità**

Il setup esistente è in `classes/ocwebhookkafkasetupservice.php` (chiamato da `bin/php/setup_kafka_workflow.php`). Il workflow `OCWebHookServer - post_publish` viene creato da `createWorkflow()` con un singolo `INSERT INTO eztrigger` per `('content', 'publish', 'a', 'post_publish')`.

**Pattern verificato sul kernel (`html/kernel/classes/eztrigger.php`):**
- La colonna `connect_type` accetta `'a'` (after) o `'b'` (before) — **NON** `'post'`/`'pre'`.
- La colonna `name` invece è la stringa "umana" (`post_publish`, `post_hide`, ...) usata da `eZTrigger::runTrigger` per il match.
- L'API `eZTrigger::fetch($triggerID)` accetta solo l'ID; per cercare per (module, function, connectType) si usa `eZTrigger::fetchList([...])`. Ma per coerenza con il setup esistente, restiamo su SQL diretto.

Aggiungere a `OCWebHookKafkaSetupService` un nuovo metodo privato `ensureVisibilityTriggers($workflowId, array &$log)` che inserisce idempotentemente le sei righe trigger mancanti:

```php
/**
 * Garantisce che il workflow $workflowId sia collegato a tutti i trigger di
 * visibilità (hide, updateobjectstate, updatesection, removetranslation,
 * move, addlocation). Idempotente.
 */
private function ensureVisibilityTriggers($workflowId, array &$log)
{
    $extra = [
        // [module_name, function_name, name]  — connect_type sempre 'a' (after)
        ['content', 'hide',              'post_hide'],
        ['content', 'updateobjectstate', 'post_updateobjectstate'],
        ['content', 'updatesection',     'post_updatesection'],
        ['content', 'removetranslation', 'post_removetranslation'],
        ['content', 'move',              'post_move'],
        ['content', 'addlocation',       'post_addlocation'],
    ];

    foreach ($extra as [$module, $function, $name]) {
        $exists = $this->db->arrayQuery(
            "SELECT COUNT(*) AS c FROM eztrigger " .
            "WHERE module_name = '$module' " .
            "AND function_name = '$function' " .
            "AND connect_type = 'a' " .
            "AND workflow_id = $workflowId"
        );
        if ((int)($exists[0]['c'] ?? 0) > 0) {
            $log[] = "[ok] eztrigger $name → workflow $workflowId già presente";
            continue;
        }

        $this->db->query(
            "INSERT INTO eztrigger (module_name, function_name, connect_type, name, workflow_id) " .
            "VALUES ('$module', '$function', 'a', '$name', $workflowId)"
        );
        $log[] = "[ok] eztrigger $name → workflow $workflowId creato";
    }
}
```

> **Nota — collisione con `DeleteWorkflowWebHookType`:** il setup pre-esistente del workflow di delete (`pre_delete` → `DeleteWorkflowWebHookType`) usa **un workflow_id diverso** da quello del `post_publish`. Verificare prima di applicare il setup nuovo: i 6 trigger di visibilità qui sopra devono finire sul workflow_id di `post_publish`, NON su quello di delete.

Quindi nel flusso principale `run()`, dopo il blocco workflow esistente, recuperare l'`$workflowId` corrente e chiamare il nuovo metodo:

```php
// Dopo "if ($this->workflowExists()) { ... } else { createWorkflow($log); ... }"
// recupera il workflow_id corrente:
$row = $this->db->arrayQuery(
    "SELECT workflow_id FROM eztrigger " .
    "WHERE module_name = 'content' AND function_name = 'publish' AND connect_type = 'a' " .
    "AND name = 'post_publish' LIMIT 1"
);
$workflowId = (int)($row[0]['workflow_id'] ?? 0);
if ($workflowId > 0) {
    $this->ensureVisibilityTriggers($workflowId, $log);
} else {
    $log[] = '[warn] workflow_id post_publish non trovato — visibility triggers NON registrati';
}
```

- [ ] **Step 2.3: Applicare il setup sull'ambiente locale**

Il setup esistente è invocabile direttamente:

```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php -d memory_limit=256M \
  html/extension/ocwebhookserver/bin/php/setup_kafka_workflow.php \
  --siteaccess=opencity 2>&1); echo "$OUT"
```

Atteso nel log:
```
[ok] Workflow post_publish → WorkflowWebHookType già configurato
[ok] eztrigger post_hide → workflow N creato
[ok] eztrigger post_updateobjectstate → workflow N creato
[ok] eztrigger post_updatesection → workflow N creato
[ok] eztrigger post_removetranslation → workflow N creato
[ok] eztrigger post_move → workflow N creato
[ok] eztrigger post_addlocation → workflow N creato
```

- [ ] **Step 2.4: Verificare le righe inserite**

```bash
OUT=$(docker exec cms-postgres-1 psql -U openpa -d opencity -c "
SELECT id, module_name, function_name, connect_type, name, workflow_id
FROM eztrigger
WHERE name IN (
  'post_publish','post_hide','post_updateobjectstate','post_updatesection',
  'post_removetranslation','post_move','post_addlocation'
)
ORDER BY id;
" 2>&1); echo "$OUT"
```

Atteso: 7 righe (1 esistente + 6 nuove), tutte con lo stesso `workflow_id` (quello di `post_publish`) e `connect_type = 'a'`. Le righe di `pre_delete` su un workflow_id separato non vanno toccate.

- [ ] **Step 2.5: Smoke test — hide/show, state change, section change from UI**

Perform each action in the admin UI and check Kafka for events:

```bash
OUT=$(docker exec cms-redpanda-1 /usr/bin/rpk topic consume cms \
  --brokers redpanda:9092 --offset end --num 5 2>&1); echo "$OUT"
```

Expected per action: una o più messaggi Kafka con `metadata.isPublic` che riflette il nuovo stato.

1. **Hide singolo**: nodo foglia → Actions → Hide → 1 evento con `isPublic: false`.
2. **Show**: stesso nodo → Actions → Show → 1 evento con `isPublic: true`.
3. **Hide subtree**: nodo padre con N figli (N < 500) → Hide → **N+1 eventi**: 1 per il padre + N per i discendenti, tutti con `isPublic: false`.
4. **Hide subtree oltre il cap**: padre con > 500 discendenti → 1 evento (il padre) + log warning in `webhook.log` "subtree under node X has Y descendants (> 500 cap)". Nessun evento per i figli.
5. **State change UI**: Admin → States → assegna stato diverso → 1 evento.
6. **Section change UI**: Admin → cambia sezione → 1 evento.
7. **Move cross-subtree**: spostare un nodo verso un parent in sezione diversa → 1 evento con eventuale `isPublic` modificato dalle ACL nuove.
8. **Remove translation**: rimuovere una traduzione di un oggetto multilingua → 1 evento; se era l'ultima traduzione anonimamente leggibile, `isPublic: false`.
9. **Restore from trash**: cestinare un oggetto, poi `/content/restore/<id>` → 1 evento (via `post_addlocation`) con stato attuale.
10. **Trash (soft delete)**: cestinare un oggetto → 1 evento `delete_ocopendata` via `DeleteWorkflowWebHookType` (NON via questo workflow).
11. **Idempotenza**: ri-applicare lo stesso stato/sezione che il contenuto ha già → evento emesso comunque (atteso, filtra il consumer).

- [ ] **Step 2.6: Run unit tests**

```bash
php tests/PayloadFormatterTest.php
```

Expected: all 111 tests pass.

- [ ] **Step 2.7: Commit**

```bash
git add eventtypes/event/workflowwebhook/workflowwebhooktype.php \
        classes/ocwebhookkafkasetupservice.php
git commit -m "feat: emit Kafka events on hide/show, state change, section change via operation handler"
```

---

## Task 3: Fire ezpEvent from OpenPASectionTools and OpenPAStateTools

Add `ezpEvent::notify('openpa/object/flushed', [$object])` to `flushObject()` in the `openpa` extension so that cron-originated changes emit Kafka events without coupling `openpa` to `ocwebhookserver`.

**Files (path locali nella copia composer del cms-dev — i repo upstream sono in `../openpa/`):**
- Modify: `html/extension/openpa/classes/openpastatetools.php`
- Modify: `html/extension/openpa/classes/openpasectiontools.php`

### Signature reale (verificata sul codice corrente)

Entrambi i metodi sono **`private`** e ricevono già un `eZContentObject`, non un id:

```php
// openpastatetools.php:552
private function flushObject(eZContentObject $object)
{
    $object->resetDataMap();
    eZContentObject::clearCache(array($object->attribute('id')));
    $object = eZContentObject::fetch($object->attribute('id'));
    eZContentOperationCollection::registerSearchObject($object->attribute('id'));
    eZContentCacheManager::clearContentCacheIfNeeded($object->attribute('id'));
    $object->resetDataMap();
    eZContentObject::clearCache(array($object->attribute('id')));
}

// openpasectiontools.php:547
private function flushObject(eZContentObject $object)
{
    eZContentObject::clearCache(array($object->attribute('id')));
    $object = eZContentObject::fetch($object->attribute('id'));
    eZContentOperationCollection::registerSearchObject($object->attribute('id'));
    eZContentCacheManager::clearContentCacheIfNeeded($object->attribute('id'));
}
```

Quindi **non serve `eZContentObject::fetch((int)$objectId)`**: l'oggetto è già nel parametro. Notare che entrambi i metodi rifanno `eZContentObject::fetch($object->attribute('id'))` dopo `clearCache()` — useremo la variabile post-fetch per il `notify`, così il listener vede l'oggetto re-idratato (cache invalidata, datamap pulita).

- [ ] **Step 3.1: Confermare le signature sul codice corrente**

```bash
grep -n 'private function flushObject\|registerSearchObject' \
  /Volumes/Repos/sviluppo-sito-comunale/sito-comunale-dev/html/extension/openpa/classes/openpastatetools.php \
  /Volumes/Repos/sviluppo-sito-comunale/sito-comunale-dev/html/extension/openpa/classes/openpasectiontools.php
```

Atteso: due match `private function flushObject(eZContentObject $object)` e due `registerSearchObject`.

- [ ] **Step 3.2: Aggiungere il notify a `OpenPAStateTools::flushObject()`**

Dopo la chiamata a `eZContentCacheManager::clearContentCacheIfNeeded()` (subito prima del secondo `resetDataMap`), aggiungere il dispatch dell'evento. Riusare la `$object` re-fetched, non rifare la fetch:

```php
private function flushObject(eZContentObject $object)
{
    $object->resetDataMap();
    eZContentObject::clearCache(array($object->attribute('id')));
    $object = eZContentObject::fetch($object->attribute('id'));
    eZContentOperationCollection::registerSearchObject($object->attribute('id'));
    eZContentCacheManager::clearContentCacheIfNeeded($object->attribute('id'));

    if ($object instanceof eZContentObject) {
        ezpEvent::getInstance()->notify('openpa/object/flushed', [$object]);
    }

    $object->resetDataMap();
    eZContentObject::clearCache(array($object->attribute('id')));
}
```

- [ ] **Step 3.3: Aggiungere il notify a `OpenPASectionTools::flushObject()`**

Stesso pattern, sempre dopo `clearContentCacheIfNeeded`:

```php
private function flushObject(eZContentObject $object)
{
    eZContentObject::clearCache(array($object->attribute('id')));
    $object = eZContentObject::fetch($object->attribute('id'));
    eZContentOperationCollection::registerSearchObject($object->attribute('id'));
    eZContentCacheManager::clearContentCacheIfNeeded($object->attribute('id'));

    if ($object instanceof eZContentObject) {
        ezpEvent::getInstance()->notify('openpa/object/flushed', [$object]);
    }
}
```

- [ ] **Step 3.4: Verificare la dispatch (listener stub)**

Senza ancora aver creato il listener vero (Task 4), aggiungere un listener temporaneo per validazione (`file_put_contents` dentro un metodo di prova) oppure ispezionare il log dopo aver attivato Task 4. In alternativa, lanciare il cron e controllare poi che `OCWebHookObjectFlushListener::handle` venga invocato:

```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php html/runcronjobs.php --siteaccess=opencity change_state 2>&1); echo "$OUT"
```

- [ ] **Step 3.5: Commit nel repo openpa**

```bash
cd /Volumes/Repos/sviluppo-sito-comunale/openpa
git checkout -b feature/openpa-object-flushed-event  # rispettare git-workflow-feature-branches
git add classes/openpastatetools.php classes/openpasectiontools.php
git commit -m "feat: fire ezpEvent openpa/object/flushed from flushObject()"
```

> **Composer lock update richiesto**: dopo aver pushato il commit, aggiornare `composer.lock` nel repo `cms` con la SHA a 40 caratteri ottenuta da `git rev-parse HEAD` nel repo `openpa` (cfr. CLAUDE.md cms — "Aggiornare il composer.lock"). Non fabbricare SHA da abbreviazioni.

---

## Task 4: Create OCWebHookObjectFlushListener in ocwebhookserver

Create the listener class that receives `openpa/object/flushed` and emits the Kafka event. Register it in `site.ini.append.php`.

**Files:**
- Create: `ocwebhookserver/classes/ocwebhookobjectflushlistener.php`
- Create: `ocwebhookserver/settings/site.ini.append.php`

- [ ] **Step 4.1: Create `classes/ocwebhookobjectflushlistener.php`**

```php
<?php

class OCWebHookObjectFlushListener
{
    /**
     * Handles the openpa/object/flushed event.
     * Called by ezpEvent when OpenPAStateTools or OpenPASectionTools flushes an object.
     *
     * @param eZContentObject $object
     */
    public static function handle(eZContentObject $object)
    {
        try {
            $payload = OCWebHookPayloadBuilder::build($object);
            $triggerInstance = OCWebHookTriggerRegistry::registeredTrigger(PostPublishWebHookTrigger::IDENTIFIER);
            $queueHandler = $triggerInstance instanceof OCWebHookTriggerQueueAwareInterface
                ? $triggerInstance->getQueueHandler()
                : OCWebHookQueue::defaultHandler();
            OCWebHookEmitter::emit(PostPublishWebHookTrigger::IDENTIFIER, $payload, $queueHandler);
        } catch (Exception $e) {
            eZLog::write(__METHOD__ . ': ' . $e->getMessage(), 'webhook.log');
        }
    }
}
```

- [ ] **Step 4.2: Create `settings/site.ini.append.php`**

Check first whether `settings/site.ini.append.php` already exists in `ocwebhookserver`. If it does, add the `[Event]` block to the existing file. If not, create it:

```php
<?php /* #?ini charset="utf-8"?

[Event]
Listeners[]=openpa/object/flushed@OCWebHookObjectFlushListener::handle

*/ ?>
```

- [ ] **Step 4.3: Regenerate the eZ autoload map**

```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php -d memory_limit=256M \
  html/bin/php/ezpgenerateautoloads.php -e 2>&1); echo "$OUT"
```

Verify:

```bash
OUT=$(docker exec cms-app-1 grep "OCWebHookObjectFlushListener" html/var/autoload/ezp_extension.php 2>&1); echo "$OUT"
```

Expected: one line showing the class-to-file mapping.

- [ ] **Step 4.4: Smoke test — cron state change emits event**

Run the `change_state` cron (or `change_section`) on the local environment and verify a Kafka message appears:

```bash
# Trigger the cron
OUT=$(docker exec cms-app-1 /usr/local/bin/php \
  html/runcronjobs.php --siteaccess=opencity change_state 2>&1); echo "$OUT"

# Check Kafka
OUT=$(docker exec cms-redpanda-1 /usr/bin/rpk topic consume cms \
  --brokers redpanda:9092 --offset end --num 3 2>&1); echo "$OUT"
```

Expected: if there are pending state-change jobs, a Kafka message per changed object appears.

If no pending jobs exist, set up a test object with a scheduled state change, or temporarily call `OpenPAStateTools::flushObject()` directly in a test script.

- [ ] **Step 4.5: Run unit tests**

```bash
cd /Volumes/Repos/sviluppo-sito-comunale/ocwebhookserver
php tests/PayloadFormatterTest.php
```

Expected: all 111 tests pass.

- [ ] **Step 4.6: Commit**

```bash
git add classes/ocwebhookobjectflushlistener.php settings/site.ini.append.php
git commit -m "feat: add OCWebHookObjectFlushListener — emit on openpa/object/flushed (cron state/section changes)"
```

---

## Task 5: Unit tests for OCWebHookPayloadBuilder helpers

`OCWebHookPayloadBuilder::build()` depends on eZ classes and requires a full eZ bootstrap. We test the two public static helpers (`userInfo`, `enrichRelationContentUrls`) with the existing stubs infrastructure.

**Files:**
- Create: `tests/PayloadBuilderTest.php`

- [ ] **Step 5.1: Check what stubs already exist in `tests/`**

```bash
ls /Volumes/Repos/sviluppo-sito-comunale/ocwebhookserver/tests/
```

Look for `stubs.php` or similar. The formatter tests use stubs for `eZContentObject`, `eZUser`, etc. Reuse what exists.

- [ ] **Step 5.2: Create `tests/PayloadBuilderTest.php`**

```php
<?php

/**
 * Unit tests for OCWebHookPayloadBuilder public static helpers.
 * Tests the pure-PHP parts that don't need a full eZ bootstrap.
 *
 * Usage:
 *   php tests/PayloadBuilderTest.php
 */

// Minimal stubs — only what the helpers need.
// Do not redefine if already declared (e.g. from stubs.php).

if (!class_exists('eZUser')) {
    class eZUser {
        private $login;
        public function __construct($login) { $this->login = $login; }
        public static function fetch($id) {
            if ($id === 99) return null;
            return new self($id === 1 ? 'admin' : 'editor');
        }
        public function attribute($k) { return $this->login; }
    }
}

if (!class_exists('eZContentObject')) {
    class eZContentObject {
        private $id;
        public function __construct($id) { $this->id = $id; }
        public static function fetch($id) {
            if ($id === 99) return null;
            return new self($id);
        }
        public function name() { return 'Test Object ' . $this->id; }
    }
}

if (!class_exists('eZContentObjectTreeNode')) {
    class eZContentObjectTreeNode {
        private $nodeId;
        public function __construct($id) { $this->nodeId = $id; }
        public static function fetch($id) {
            if ($id === 0) return null;
            return new self($id);
        }
        public function urlAlias() { return 'notizie/test-' . $this->nodeId; }
    }
}

require_once __DIR__ . '/../classes/ocwebhookpayloadbuilder.php';

// ─────────────────────────────────────────────────────────────────────────────
$PASSED = 0; $FAILED = 0;
function ok(string $n): void { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $n\n"; }
function fail(string $n, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $n" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a, $b, string $t): void { $a === $b ? ok($t) : fail($t, sprintf('expected %s, got %s', var_export($b, true), var_export($a, true))); }
function assert_null($v, string $t): void { $v === null ? ok($t) : fail($t, 'expected null, got ' . var_export($v, true)); }
function assert_false($v, string $t): void { $v === false ? ok($t) : fail($t, 'expected false, got ' . var_export($v, true)); }

// ── userInfo ──────────────────────────────────────────────────────────────────
$info = OCWebHookPayloadBuilder::userInfo(1);
assert_eq($info['id'],    1,             'userInfo: id preserved');
assert_eq($info['login'], 'admin',       'userInfo: login from eZUser');
assert_eq($info['name'],  'Test Object 1', 'userInfo: name from eZContentObject');

assert_null(OCWebHookPayloadBuilder::userInfo(0),  'userInfo: 0 userId → null');
assert_null(OCWebHookPayloadBuilder::userInfo(99), 'userInfo: unknown userId → null');

// ── enrichRelationContentUrls ─────────────────────────────────────────────────
$payload = [
    'data' => [
        'it-IT' => [
            'author' => ['content' => [
                ['id' => 1, 'mainNodeId' => 88, 'name' => 'Ufficio'],
                ['id' => 2, 'mainNodeId' => 0,  'name' => 'Missing'],
            ], 'type' => 'ezobjectrelationlist'],
            'title' => ['content' => 'Ciao', 'type' => 'ezstring'],
        ],
    ],
];

OCWebHookPayloadBuilder::enrichRelationContentUrls($payload, 'https://www.comune.example.it');

$items = $payload['data']['it-IT']['author']['content'];
assert_eq(
    $items[0]['content_url'],
    'https://www.comune.example.it/notizie/test-88',
    'enrichRelation: content_url injected for found node'
);
assert_false(
    isset($items[1]['content_url']),
    'enrichRelation: no content_url when node not found'
);
assert_eq(
    $payload['data']['it-IT']['title'],
    ['content' => 'Ciao', 'type' => 'ezstring'],
    'enrichRelation: non-relation fields untouched'
);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) { echo ", \033[31m{$FAILED} failed\033[0m"; }
echo "\n";
exit($FAILED > 0 ? 1 : 0);
```

- [ ] **Step 5.3: Run the test**

```bash
cd /Volumes/Repos/sviluppo-sito-comunale/ocwebhookserver
php tests/PayloadBuilderTest.php
```

Expected: all PASS, exit 0.

- [ ] **Step 5.4: Add to test runner if applicable**

Check `tests/run_tests.php` (or equivalent). If it lists test files explicitly, add `PayloadBuilderTest.php`. If it auto-discovers by glob, skip this step.

- [ ] **Step 5.5: Commit**

```bash
git add tests/PayloadBuilderTest.php
git commit -m "test: add PayloadBuilderTest for OCWebHookPayloadBuilder helpers"
```

---

## Self-review

**Spec coverage:**

| Requirement | Covered by | Status |
|---|---|---|
| Emit on hide/show (UI singolo) | Task 2 — `post_hide` workflow trigger | ✅ |
| Emit on hide subtree → figli | Task 2 — `emitForSubtreeDescendants` con cap 500 | ✅ |
| Emit on state change (UI) | Task 2 — `post_updateobjectstate` workflow trigger | ✅ |
| Emit on section change (UI) | Task 2 — `post_updatesection` workflow trigger | ✅ |
| Emit on state change (cron) | Task 3+4 — `ezpEvent` + listener | ✅ |
| Emit on section change (cron) | Task 3+4 — `ezpEvent` + listener | ✅ |
| Emit on remove translation | Task 2 — `post_removetranslation` workflow trigger | ✅ |
| Emit on move (cross-section o no) | Task 2 — `post_move` workflow trigger | ✅ |
| Emit on restore from trash | Task 2 — `post_addlocation` workflow trigger | ✅ |
| Emit on trash (soft delete) | Workflow esistente `DeleteWorkflowWebHookType` (`pre_delete`) | ✅ pre-esistente |
| `isPublic` in `metadata` di ogni evento | `OCWebHookPayloadBuilder::build()` chiama `checkAccess` | ✅ |
| `emit_all_published.php` allineato (refactor) | Task 1 Step 1.3 | ✅ |
| Single trigger identifier (`post_publish_ocopendata`) per tutte le visibility | Tutti gli emit usano `PostPublishWebHookTrigger::IDENTIFIER` | ✅ — by design |
| Nessuna dipendenza da Solr per emettere | Operation handler + ezpEvent indipendenti dall'index plugin | ✅ — supporto futuro dismissione Solr |
| Filtraggio eventi su contenuti privati | — | demandato al consumer (vedi header) |
| Diff transizioni published↔unpublished | — | demandato al consumer (`metadata.isPublic`) |
| Modifiche idempotenti (no diff producer-side) | — | demandato al consumer |

**Anti-double-emit analysis** (verificata sul kernel corrente):

- **Publish UI** → `eZOperationHandler::execute('content','publish',...)` → trigger `post_publish` → workflow → `WorkflowWebHookType::execute` → 1 emit. Non ci sono altri path che attivano emit su publish (no Solr plugin, no listener su `content/publish` ezpEvent).
- **Hide singolo UI** → `kernel/content/hide.php` → `eZOperationHandler::execute('content','hide',...)` → trigger `post_hide` → workflow → 1 emit per il nodo + N emit per i discendenti (se subtree). `OpenPA*Tools::flushObject` NON viene invocato da `changeHideStatus`.
- **State change UI** → `kernel/content/state_edit.php` / `kernel/state/assign.php` → `eZOperationHandler::execute('content','updateobjectstate',...)` → trigger `post_updateobjectstate` → workflow → 1 emit. La stessa operazione, dentro `eZContentOperationCollection::updateObjectState()`, fa `ezpEvent::notify('content/state/assign', ...)` — ma noi NON ascoltiamo quell'evento (ascoltiamo solo `openpa/object/flushed`), quindi nessun doppio emit.
- **Section change UI** → `kernel/classes/ezsection.php:227` → `eZOperationHandler::execute('content','updatesection',...)` → trigger `post_updatesection` → workflow → 1 emit. `OpenPASectionTools::flushObject` NON viene chiamato da `eZContentOperationCollection::updateSection`.
- **State change CRON** (`change_state.php`) → `OpenPAStateTools::changeCurrentObjectState()` → `$object->assignState($state)` (chiamata diretta, bypassa operation handler) → `flushCurrentObject()` → `flushObject($object)` → `ezpEvent::notify('openpa/object/flushed', [$object])` → listener → 1 emit. Nessun trigger workflow scatta perché non si passa da `eZOperationHandler::execute`.
- **Section change CRON** (`change_section.php`) → `OpenPASectionTools::changeNodeSection()` → `eZContentOperationCollection::updateSection(...)` (chiamata diretta, NON via operation handler — quindi `post_updatesection` trigger non parte) → `flushObject($object)` → 1 emit via ezpEvent.
- **Remove translation UI** → `eZOperationHandler::execute('content','removetranslation',...)` → trigger `post_removetranslation` → workflow → 1 emit. Nessun altro hook tocca questo path.
- **Move UI** → `eZOperationHandler::execute('content','move',...)` → trigger `post_move` → workflow → 1 emit. Caso particolare: se il move è cross-section, `moveNode` internamente chiama `assignSectionToSubTree` ma NON innesca `post_updatesection` (è una chiamata diretta, non via operation handler). Quindi un solo emit anche nel caso cross-section.
- **Restore from trash UI** → `kernel/content/restore.php` con `AddLocation` action → `eZOperationHandler::execute('content','addlocation',...)` → trigger `post_addlocation` → workflow → 1 emit. ATTENZIONE: anche un "AddLocation" manuale (admin → aggiungi posizione a un oggetto pubblicato) passa di qui ed emetterà — questo è il comportamento corretto, l'oggetto guadagna visibilità in una nuova area.
- **Trash (soft delete)** → `eZOperationHandler::execute('content','delete',...)` con `move_to_trash=1` → trigger `pre_delete` → `DeleteWorkflowWebHookType` → 1 emit con identifier `delete_ocopendata`. Diverso identifier dagli altri eventi visibility — il consumer lo gestisce già.

In tutti i casi: **1 emit per operazione** (o N+1 per `post_hide` di un subtree con N figli). La separazione regge perché i path cron di OpenPA bypassano l'operation handler, e ogni operation handler scatta un solo trigger di visibilità.

**Diff rispetto alla design memory `design-publish-unpublish-events.md`:**

- ✗ **Modello a 5 ce_type** (`.created/.updated/.unpublished/.published/.deleted`) — abbandonato in questo piano. Singolo trigger `post_publish_ocopendata` con `metadata.isPublic`; il consumer fa diff per derivare le transizioni se gli serve.
- ✗ **"Nessun evento se contenuto modificato mentre non è pubblico"** — abbandonato. Emettiamo sempre con `isPublic: false`; il filtraggio è demandato al consumer.
- ✗ **Piano B (index plugin Solr via INI)** — rifiutato esplicitamente per non legare l'emissione Kafka al motore Solr (vedi header del piano). Se in futuro Solr verrà sostituito o disattivato, gli eventi continuano a funzionare grazie a operation handler + ezpEvent. Specifica tecnica del Piano B conservata in `piano-b-solr-index-plugin.md` accanto a questo file.
- ✓ **Decisione `checkAccess` come fonte di verità per `isPublic`** — mantenuta.
