# Piano C — Eventi di visibilità via OCSearchEngine

> **Per agentic worker:** sub-skill richiesta — usare superpowers:subagent-driven-development (consigliata) oppure superpowers:executing-plans per implementare questo piano task per task. Gli step usano la sintassi a checkbox (`- [ ]`) per il tracking.

**Obiettivo:** emettere un evento Kafka ogni volta che cambia la visibilità di un contenuto (hide/show, cambio stato, cambio sezione, move, rimozione traduzione, restore da cestino), non solo alla pubblicazione di una nuova versione. Il consumer può così tenere sincronizzato un indice esterno o reagire ai cambi di accessibilità pubblica senza fare polling sul CMS.

**Cosa fa questo piano:** crea una classe `OCSearchEngine` che estende `eZSolr` e diventa il search engine attivo dell'installazione via `site.ini.append.php` dell'estensione. `OCSearchEngine::addObject()` e `removeObject()` delegano al parent (Solr indicizza normalmente) e in coda emettono l'evento Kafka. Un unico punto di intercettazione copre tutti i path che provocano un re-index (UI, cron OpenPA, script batch, restore, move, removetranslation), perché tutti finiscono in `eZContentOperationCollection::registerSearchObject()` → `eZSolr::addObject()`. Per evitare doppie emissioni, `WorkflowWebHookType` e `DeleteWorkflowWebHookType` vengono gated: se il search engine attivo è `OCSearchEngine`, restano silenti.

**Confronto con Piano A e Piano B:**
- Il [Piano A](./2026-05-18-index-plugin-visibility-events.md) intercetta ogni operazione con hook puntuali (sei trigger eZ + listener `ezpEvent` + modifiche a `openpa`). Non dipende da Solr, ma richiede una migrazione DB su 500 tenant (6 righe `eztrigger` per tenant), un commit cross-repo su `openpa` e un update di `composer.lock`.
- Il [Piano B](./piano-b-solr-index-plugin.md) aggancia un `ezpIndexPlugin` dentro `eZSolr`. Più compatto del Piano A ma cade silenziosamente se Solr viene disattivato e non gestisce i `removeObject`.
- Il **Piano C** diventa il search engine ufficiale dell'installazione. Stessa copertura del Piano B più i `removeObject`, niente trigger DB nuovi, niente touch su `openpa`. **Precondizione operativa: eZ Find/eZSolr deve restare installato e `DelayedIndexing=disabled`.**

**Stack tecnico:** PHP 7.2+, eZ Publish 5, `ezpSearchEngine` interface (`html/kernel/private/interfaces/ezpsearchengine.php:17-88`), `eZSolr` (`html/extension/ezfind/search/plugins/ezsolr/ezsolr.php:11`), infrastruttura esistente `OCWebHookEmitter`/`OCWebHookKafkaPayloadFormatter`.

---

## Precondizioni operative

Da verificare PRIMA di iniziare l'implementazione. Se una di queste non è soddisfatta, il piano non è applicabile sul tenant.

1. **eZ Find/eZSolr installato e attivo.** Verifica: `class_exists('eZSolr')` deve ritornare true. Se Solr viene disinstallato in futuro, l'autoload di `OCSearchEngine extends eZSolr` produce fatal error globale. La rimozione di Solr richiede contestualmente cambiare `OCSearchEngine` o disattivarla in `site.ini`.

2. **`[SearchSettings] DelayedIndexing=disabled`** (il default eZ — `html/settings/site.ini:485`). Se è impostato a `enabled` o `classbased`, `eZContentOperationCollection::registerSearchObject()` (`html/kernel/content/ezcontentoperationcollection.php:594`) accoda in `ezpending_actions` invece di chiamare `addObject()` subito. Gli eventi Kafka non sarebbero più sincroni con l'operazione, ma deferiti al cron `ezfindexsubtree`. Per ora il piano richiede il setting `disabled`; il supporto a `classbased` può essere aggiunto in futuro.

3. **`ocwebhookserver` attiva in `ActiveExtensions[]`** (verificato in `sito-comunale-dev/conf.d/ez/override/site.ini.append.php:159`).

4. **Nessuna altra estensione sovrascrive `[SearchSettings] SearchEngine`** in `site.ini.append.php` dopo `ocwebhookserver` nell'ordine di merge.

---

## Architettura — single emit path

```
Operazione (publish/hide/state/section/move/restore/removetranslation/delete)
        │
        ├─ UI ─────────────────► eZOperationHandler::execute(...)
        │                              │
        │                              ▼
        │                       eZContentOperationCollection::registerSearchObject()
        │                              │
        │                              ▼
        │                       eZSearch::getEngine()  ← restituisce OCSearchEngine
        │                              │
        │                              ▼
        │                       OCSearchEngine::addObject($obj, ...)
        │                              ├─ parent::addObject() (Solr re-index)
        │                              └─ OCWebHookEmitter::emit('post_publish_ocopendata', payload completo)
        │
        ├─ cron change_state ─► OpenPAStateTools::flushObject()
        │   (e change_section)         │
        │                              ▼
        │                       registerSearchObject() → stesso path di UI ───────► 1 emit
        │
        └─ delete (UI/CLI) ────► eZOperationHandler::execute('content','delete',...)
                                       │
                                       ├─ trigger pre_delete → DeleteWorkflowWebHookType::execute()
                                       │     └─ check engine: instanceof OCSearchEngine? SKIP emit
                                       │
                                       └─ object marked archived → removeObject($obj, ...)
                                             ├─ parent::removeObject()
                                             └─ OCWebHookEmitter::emit('delete_ocopendata', payload minimal)
```

**Anti-doppia-emissione (cutover):** `WorkflowWebHookType::execute` e `DeleteWorkflowWebHookType::execute` controllano `eZSearch::getEngine() instanceof OCSearchEngine`. Se sì, ritornano senza emettere — il path Solr ha già coperto l'evento. Se no (fallback per tenant senza Solr), emettono come oggi. Questo approccio NON tocca la tabella `eztrigger`: il workflow `post_publish` può restare registrato. Rollback = ripristinare `[SearchSettings] SearchEngine=ezsolr` (o default) in `site.ini`.

---

## Impatto sui webhook configurati per tenant

Piano C **non introduce nuovi trigger identifier** e **non modifica i record nelle tabelle `ocwebhook` / `ocwebhook_trigger_link`**. La differenza è esclusivamente nel volume e nella tipologia degli eventi consegnati al webhook esistente `post_publish_ocopendata`.

### Tabelle DB invariate

| Tabella | Cambia con Piano C? |
|---|---|
| `ocwebhook` (record per tenant) | NO — stessi record, stessi URL (`kafka://...` o `http(s)://...`) |
| `ocwebhook_trigger_link` (link webhook ↔ trigger) | NO — stessi link |
| `eztrigger` (workflow trigger) | NO — il workflow `post_publish` resta registrato, ma è no-op quando OCSearchEngine è attivo (gating runtime, vedi Task 3) |
| `ezworkflow` / `ezworkflow_event` | NO |

### `setup_kafka_workflow.php` continua a creare gli stessi oggetti

Il setup script (via `OCWebHookKafkaSetupService`) continua a:
1. Creare il workflow `post_publish → WorkflowWebHookType` (utile come **fallback** quando `SearchEngine != OCSearchEngine`, ad es. tenant senza Solr).
2. Creare/aggiornare il record `ocwebhook` con `url = kafka://broker/topic` e il link a `post_publish_ocopendata`.

Unica aggiunta in Piano C: il `checkPreconditions()` del Task 5 valida `eZSolr` + `DelayedIndexing` + `SearchEngine` prima di procedere.

Il workflow `pre_delete → DeleteWorkflowWebHookType` (configurato manualmente per tenant, non da setup script) resta invariato in DB — gated runtime, dorme finché OCSearchEngine è attivo.

### Cosa arriva sul webhook `post_publish_ocopendata`

| Operazione | Oggi | Con Piano C |
|---|---|---|
| Publish nuova versione | ✅ 1 evento | ✅ 1 evento (stesso) |
| Hide / Show | — | ✅ nuovo (`metadata.isPublic` riflette lo stato) |
| Cambio stato (UI o cron) | — | ✅ nuovo |
| Cambio sezione (UI o cron) | — | ✅ nuovo |
| Move tra subtree | — | ✅ nuovo |
| Remove translation | — | ✅ nuovo |
| Restore da cestino | — | ✅ nuovo |

E sul webhook `delete_ocopendata`:

| Operazione | Oggi | Con Piano C |
|---|---|---|
| Trash (soft delete) | ✅ via `DeleteWorkflowWebHookType` (`pre_delete`) | ✅ via `OCSearchEngine::removeObject` |
| Hard delete | ✅ idem | ✅ idem |

Stesso volume sul webhook delete. Aumenta solo il volume sul webhook publish.

### ⚠️ Implicazione per i webhook HTTP esistenti

Tenant che hanno webhook **HTTP** (non Kafka) collegati a `post_publish_ocopendata` riceveranno improvvisamente **molti più POST**. Il loro receiver è tarato oggi su "1 chiamata = 1 publish"; con Piano C diventa "1 chiamata = 1 re-index" (publish + ogni cambio di visibilità).

Questo è il **medesimo trade-off di Piano A**: la decisione architetturale "singolo identifier `post_publish_ocopendata`, `metadata.isPublic` nel payload, filtra il consumer" è coerente tra A e C.

Strategie di mitigazione (in ordine di preferenza, coerenti con Piano A):

1. **Avvisare i consumer HTTP** prima del cutover che il volume aumenta e che devono filtrare lato loro (es. ignorare eventi su `isPublic: false` se non interessati).
2. **Inventario dei webhook HTTP per trigger**: prima di abilitare Piano C su un tenant, eseguire:
   ```sql
   SELECT id, name, url FROM ocwebhook
   WHERE id IN (
     SELECT webhook_id FROM ocwebhook_trigger_link
     WHERE trigger_identifier = 'post_publish_ocopendata'
   )
   AND url NOT LIKE 'kafka://%';
   ```
   Per ogni risultato, contattare il consumer e validare che possa gestire il volume aggiuntivo.
3. **Disattivare i webhook HTTP** non compatibili (`is_enabled=0` su `ocwebhook`) prima del cutover; riattivare dopo aver aggiornato il consumer.

NON è previsto in questo piano un flag per-webhook tipo `include_visibility_events`: introdurrebbe una matrice di stati che Piano A non ha e renderebbe il modello incoerente tra HTTP e Kafka.

### `webhook.ini` invariato

- `[TriggersSettings]`: stessi due trigger registrati (`post_publish_ocopendata`, `delete_ocopendata`).
- `[KafkaCeTypeMap]`: stessi mapping. `ce_type` continua a derivare da `entity.meta.type_id` (= `class_identifier` dell'oggetto), non dal trigger.
- `oc_operation` header CloudEvents: resta derivato da `entity.meta.version` (`version=1` → `created`, `version>1` → `updated`, trigger delete → `deleted`).

**Conseguenza sui ce_type per gli eventi di visibilità**: tutti i cambi su contenuti esistenti hanno `version > 1`, quindi arrivano come `it.opencity.{productSlug}.{type_id}.updated`. Semanticamente corretto: "lo stato pubblico del contenuto è cambiato" è un update.

### Checklist pre-cutover per tenant

Da eseguire prima di mergiare il PR di Piano C su un tenant in produzione:

- [ ] Inventario dei webhook HTTP sul tenant (query SQL sopra).
- [ ] Comunicazione ai consumer HTTP del volume aggiuntivo (o disabilitazione concordata).
- [ ] Verifica `[SearchSettings] DelayedIndexing=disabled` sul tenant (`setup_kafka_workflow.php` lo controlla, ma vale farlo in anticipo).
- [ ] Conferma che eZ Find/eZSolr è attiva sul tenant.
- [ ] Smoke test post-deploy (Task 6) per validare "1 emit per operazione".

---

## Mappa file

| File | Azione | Ruolo |
|---|---|---|
| `classes/ocwebhookpayloadbuilder.php` | **Creare** (se non esiste) | Costruisce il payload ocopendata (`build` completo + `buildMinimal` per delete) |
| `classes/ocsearchengine.php` | **Creare** | Estende `eZSolr`; sovrascrive `addObject`/`removeObject`; loop guard + try/catch |
| `eventtypes/event/workflowwebhook/workflowwebhooktype.php` | **Modificare** | Gate su `eZSearch::getEngine() instanceof OCSearchEngine` |
| `eventtypes/event/deleteworkflowwebhook/deleteworkflowwebhooktype.php` | **Modificare** | Gate su `eZSearch::getEngine() instanceof OCSearchEngine` |
| `settings/site.ini.append.php` | **Modificare** | Aggiunge `[SearchSettings] SearchEngine=OCSearchEngine` |
| `classes/ocwebhookkafkasetupservice.php` | **Modificare** | Aggiunge check precondizioni (`DelayedIndexing`, `eZSolr`, engine) |
| `tests/PayloadBuilderTest.php` | **Creare** (se non esiste) | Unit test helper builder |
| `tests/SearchEngineEmitTest.php` | **Creare** | Unit test `OCSearchEngine` (gate, loop guard, trigger corretto) |

---

## Contesto da conoscere prima

### `addObject` viene chiamato da

| Path | Chiama `addObject`? | Riferimento |
|---|---|---|
| Publish UI (`post_publish` operation) | sì, via `registerSearchObject` | `ezcontentoperationcollection.php:629` |
| Hide/show nodo (`updateNodeVisibility`) | sì, via `eZSearch::updateNodeVisibility` | `kernel/classes/ezsearch.php:548` → `ezsolr.php:1459` |
| Cambio stato UI (`updateObjectState`) | sì, via `eZSearch::updateObjectState` | `ezsearch.php:631` |
| Cambio sezione UI (`updateObjectsSection`) | sì, via re-index su nodo | analogo a sopra |
| Cron `change_state.php` | sì, via `OpenPAStateTools::flushObject` → `registerSearchObject` | `openpastatetools.php:554` |
| Cron `change_section.php` | sì, via `OpenPASectionTools::flushObject` → `registerSearchObject` | `openpasectiontools.php:551` |
| Restore da cestino (`addlocation`) | sì, via `registerSearchObject` durante AddLocation | `ezcontentoperationcollection.php` |
| Move (`moveNode`) | sì, via `registerSearchObject` | idem |
| Remove translation | sì, via `registerSearchObject` | idem |
| Hide subtree → figli | parziale: il padre subito, i figli via cron `ezfindexsubtree` | pending action in `ezpending_actions` |

### `removeObject` viene chiamato da

| Path | Chiama `removeObject`? |
|---|---|
| Trash (`move_to_trash=1`) | sì |
| Hard delete | sì |

`removeObject` non distingue i due casi dai parametri. Per il consumer, entrambi mappano a `delete_ocopendata`.

### Loop guard

Oggi `OCWebHookEmitter::emit()` (`classes/ocwebhookemitter.php:10`) scrive solo job nella tabella `ocwebhook_job` via `$job->store()`. **Non** modifica l'`eZContentObject` né chiama `clearCache`/`store`. Quindi nessun rischio di ri-entrata su `addObject`.

Future modifiche al payload builder o al producer potrebbero introdurre `eZContentObject::store()` o `clearCache()` aggressivi: il loop guard statico (vedi Task 2) protegge da una ri-entrata accidentale di `addObject`/`removeObject` durante l'emissione.

### Firme reali verificate sul codice

```php
// html/extension/ezfind/search/plugins/ezsolr/ezsolr.php:448
public function addObject($contentObject, $commit = true, $commitWithin = 0, $softCommit = null)

// html/extension/ezfind/search/plugins/ezsolr/ezsolr.php:870
public function removeObject($contentObject, $commit = null, $commitWithin = 0)
```

```php
// ocwebhookserver/classes/ocwebhookemitter.php:10
public static function emit($triggerIdentifier, $payload, $queueHandlerIdentifier)
```

```php
// ocwebhookserver/classes/triggers/post_publish.php:5,49
class PostPublishWebHookTrigger implements OCWebHookTriggerInterface, OCWebHookTriggerQueueAwareInterface
{
    const IDENTIFIER = 'post_publish_ocopendata';
    public function getQueueHandler() { return OCWebHookQueue::HANDLER_SCHEDULED; } // ISTANZA, NON STATICO
}
```

```php
// ocwebhookserver/classes/triggers/delete.php:5
const IDENTIFIER = 'delete_ocopendata';
// stesso pattern: getQueueHandler() ISTANZA
```

**Attenzione:** `getQueueHandler()` è metodo di istanza, non statico. Il pattern reale (da `eventtypes/event/workflowwebhook/workflowwebhooktype.php:99-107`) è:

```php
$triggerInstance = OCWebHookTriggerRegistry::registeredTrigger(PostPublishWebHookTrigger::IDENTIFIER);
$queueHandler = $triggerInstance instanceof OCWebHookTriggerQueueAwareInterface
    ? $triggerInstance->getQueueHandler()
    : OCWebHookQueue::defaultHandler();
```

### Nota sull'autoload

Dopo aver aggiunto nuovi file di classe PHP, rigenerare la mappa di autoload eZ dentro al container:

```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php -d memory_limit=256M \
  html/bin/php/ezpgenerateautoloads.php -e 2>&1); echo "$OUT"
```

---

## Task 1 — Creare `OCWebHookPayloadBuilder`

> **Salta questo task se `classes/ocwebhookpayloadbuilder.php` esiste già** (Piano A implementato per primo). In tal caso aggiungi solo il metodo `buildMinimal()` se non c'è e prosegui al Task 2.

Estrae la logica di costruzione del payload (oggi duplicata in `WorkflowWebHookType` righe ~26-104 e in `emit_all_published.php` righe ~145-154) in una classe statica riusabile, e aggiunge un secondo metodo `buildMinimal()` per i `removeObject` (dove l'oggetto è in stato archived e il `build()` completo non è affidabile).

**File:**
- Creare: `classes/ocwebhookpayloadbuilder.php`
- Modificare: `eventtypes/event/workflowwebhook/workflowwebhooktype.php` (sostituire blocco inline con chiamata al builder; il gating su engine arriva al Task 3)
- Modificare: `bin/php/emit_all_published.php` (sostituire blocco inline con chiamata al builder)
- Creare: `tests/PayloadBuilderTest.php`

- [ ] **Step 1.1: creare `classes/ocwebhookpayloadbuilder.php`**

Stesso codice di Piano A Task 1, più il metodo `buildMinimal()`:

```php
<?php

use Opencontent\Opendata\Api\Values\Content;

class OCWebHookPayloadBuilder
{
    /**
     * Payload completo per addObject (publish, hide/show, state, section, move, restore, removetranslation).
     */
    public static function build(eZContentObject $object)
    {
        // [codice identico a Piano A Task 1 Step 1.1 — vedi
        // docs/superpowers/plans/2026-05-18-index-plugin-visibility-events.md righe 152-237]
        // Costruisce: metadata (id, remoteId, classIdentifier, currentVersion, languages,
        //             baseUrl, contentUrl, apiUrl, isPublic, createdBy, modifiedBy),
        //             data (filtrato via ocopendata), relazioni arricchite con content_url.
    }

    /**
     * Payload minimal per removeObject (delete/trash): l'oggetto è in stato archived,
     * Content::createFromEzContentObject e checkAccess non sono affidabili.
     * Riempie solo i campi necessari al formatter Kafka per produrre il messaggio delete.
     */
    public static function buildMinimal(eZContentObject $object)
    {
        $version   = $object->currentVersion();
        $languages = $version instanceof eZContentObjectVersion ? $version->languageList() : [];

        return [
            'metadata' => [
                'id'              => (int)$object->attribute('id'),
                'remoteId'        => $object->attribute('remote_id'),
                'classIdentifier' => $object->attribute('class_identifier'),
                'currentVersion'  => (int)$object->attribute('current_version'),
                'languages'       => $languages,
                'isPublic'        => false, // oggetto in fase di eliminazione
            ],
            'data' => [],
        ];
    }

    public static function userInfo($userId) { /* vedi Piano A */ }
    public static function enrichRelationContentUrls(array &$payload, $baseUrl) { /* vedi Piano A */ }
}
```

> **Nota:** se Piano A è già stato implementato, `build()`/`userInfo`/`enrichRelationContentUrls` esistono già. Aggiungi solo `buildMinimal()` e i relativi test.

- [ ] **Step 1.2: aggiornare `WorkflowWebHookType::execute()` per usare `OCWebHookPayloadBuilder::build()`**

Sostituire il blocco inline di costruzione del payload (righe ~26-104) con `OCWebHookPayloadBuilder::build($object)`. Lasciare per ora la chiamata a `OCWebHookEmitter::emit()` invariata — il gating su engine arriva al Task 3.

- [ ] **Step 1.3: aggiornare `emit_all_published.php` per usare `OCWebHookPayloadBuilder::build()`**

Stesso refactor — sostituire il blocco inline con la chiamata al builder.

- [ ] **Step 1.4: creare `tests/PayloadBuilderTest.php`**

Stesso file di Piano A Task 5 (`tests/PayloadBuilderTest.php`), più assertion specifici per `buildMinimal()`:

```php
// In aggiunta a quanto in Piano A:
$min = OCWebHookPayloadBuilder::buildMinimal(new eZContentObject(42));
assert_eq($min['metadata']['id'], 42, 'buildMinimal: id');
assert_eq($min['metadata']['isPublic'], false, 'buildMinimal: isPublic always false for delete');
assert_eq($min['data'], [], 'buildMinimal: data is empty');
```

- [ ] **Step 1.5: eseguire i test**

```bash
cd /Volumes/Repos/sviluppo-sito-comunale/ocwebhookserver
php tests/PayloadBuilderTest.php
php tests/PayloadFormatterTest.php
```

Atteso: entrambi PASS, exit 0.

- [ ] **Step 1.6: commit**

```bash
git add classes/ocwebhookpayloadbuilder.php \
        eventtypes/event/workflowwebhook/workflowwebhooktype.php \
        bin/php/emit_all_published.php \
        tests/PayloadBuilderTest.php
git commit -m "refactor: extract OCWebHookPayloadBuilder (build + buildMinimal)"
```

---

## Task 2 — Creare `OCSearchEngine`

La classe estende `eZSolr`, delega tutto al parent, e aggiunge l'emissione Kafka in `addObject` (payload completo) e `removeObject` (payload minimal). Include loop guard statico, try/catch, queue handler via `OCWebHookTriggerRegistry`.

**File:**
- Creare: `classes/ocsearchengine.php`
- Creare: `tests/SearchEngineEmitTest.php`

- [ ] **Step 2.1: scrivere il test PRIMA dell'implementazione**

```php
<?php
// tests/SearchEngineEmitTest.php
//
// Verifica:
//   - addObject delega a parent E emette post_publish_ocopendata con payload completo
//   - removeObject delega a parent E emette delete_ocopendata con payload minimal
//   - loop guard: se durante emit() si rientra in addObject, l'emit interno è skippato
//   - try/catch: un'eccezione nell'emit non blocca l'indicizzazione (parent::addObject ritorna comunque)

// ── stub minimi (definire PRIMA del require di OCSearchEngine) ─────────────────

class eZSolr {
    public static $addCalls = 0;
    public static $removeCalls = 0;
    public function addObject($obj, $commit = true, $commitWithin = 0, $softCommit = null) {
        self::$addCalls++;
        return true;
    }
    public function removeObject($obj, $commit = null, $commitWithin = 0) {
        self::$removeCalls++;
        return true;
    }
    public function needCommit() { return true; }
    public function needRemoveWithUpdate() { return true; }
    public function removeObjectById($id, $commit = null) { return true; }
    public function search($searchText, $params = [], $searchTypes = []) { return []; }
    public function supportedSearchTypes() { return []; }
    public function commit() { return true; }
}

class eZDebug {
    public static $errors = [];
    public static function writeError($msg, $label = '') { self::$errors[] = "$label: $msg"; }
}

class eZContentObject {
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function attribute($k) {
        $map = ['id' => $this->id, 'remote_id' => 'r-' . $this->id,
                'class_identifier' => 'article', 'current_version' => 2, 'name' => 'Test'];
        return $map[$k] ?? null;
    }
    public function currentVersion() { return null; }
}

// Stub trigger registry + queue
class OCWebHookQueue {
    const HANDLER_SCHEDULED = 'scheduled';
    public static function defaultHandler() { return 'immediate'; }
}

interface OCWebHookTriggerQueueAwareInterface {
    public function getQueueHandler();
}

class FakeQueueAwareTrigger implements OCWebHookTriggerQueueAwareInterface {
    public function getQueueHandler() { return OCWebHookQueue::HANDLER_SCHEDULED; }
}

class OCWebHookTriggerRegistry {
    public static function registeredTrigger($id) { return new FakeQueueAwareTrigger(); }
}

class PostPublishWebHookTrigger {
    const IDENTIFIER = 'post_publish_ocopendata';
}

class DeleteWebHookTrigger {
    const IDENTIFIER = 'delete_ocopendata';
}

class OCWebHookPayloadBuilder {
    public static $shouldThrow = false;
    public static function build(eZContentObject $obj) {
        if (self::$shouldThrow) throw new RuntimeException('builder failure');
        return ['metadata' => ['id' => $obj->attribute('id'), 'isPublic' => true], 'data' => ['it-IT' => ['title' => 'X']]];
    }
    public static function buildMinimal(eZContentObject $obj) {
        return ['metadata' => ['id' => $obj->attribute('id'), 'isPublic' => false], 'data' => []];
    }
}

class OCWebHookEmitter {
    public static $log = [];
    public static $reentryEngine = null;
    public static function emit($trigger, $payload, $handler) {
        self::$log[] = ['trigger' => $trigger, 'payload' => $payload, 'handler' => $handler];
        // Simula ri-entrata: emit() richiama addObject sullo stesso engine
        if (self::$reentryEngine !== null) {
            $eng = self::$reentryEngine;
            self::$reentryEngine = null; // armato una volta sola
            $eng->addObject(new eZContentObject(999));
        }
    }
}

// ── load OCSearchEngine ────────────────────────────────────────────────────────

require_once __DIR__ . '/../classes/ocsearchengine.php';

// ── test helpers ───────────────────────────────────────────────────────────────

$PASSED = 0; $FAILED = 0;
function ok($n)   { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $n\n"; }
function fail($n,$r='') { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $n" . ($r ? " — $r" : '') . "\n"; }
function eq($a,$b,$t)   { $a === $b ? ok($t) : fail($t, sprintf('expected %s, got %s', var_export($b,true), var_export($a,true))); }

// ── test 1: addObject → 1 emit post_publish_ocopendata + parent chiamato ───────

OCWebHookEmitter::$log = [];
eZSolr::$addCalls = 0;
$engine = new OCSearchEngine();
$engine->addObject(new eZContentObject(42));

eq(eZSolr::$addCalls,                 1,                                  'addObject: parent invocato');
eq(count(OCWebHookEmitter::$log),     1,                                  'addObject: 1 emit');
eq(OCWebHookEmitter::$log[0]['trigger'], PostPublishWebHookTrigger::IDENTIFIER, 'addObject: trigger corretto');
eq(OCWebHookEmitter::$log[0]['handler'], OCWebHookQueue::HANDLER_SCHEDULED,     'addObject: queue handler dalla Registry');
eq(OCWebHookEmitter::$log[0]['payload']['metadata']['id'], 42,            'addObject: payload completo da build()');
eq(OCWebHookEmitter::$log[0]['payload']['data']['it-IT']['title'], 'X',   'addObject: payload include data');

// ── test 2: removeObject → 1 emit delete_ocopendata + parent chiamato ──────────

OCWebHookEmitter::$log = [];
eZSolr::$removeCalls = 0;
$engine->removeObject(new eZContentObject(42));

eq(eZSolr::$removeCalls,              1,                                  'removeObject: parent invocato');
eq(count(OCWebHookEmitter::$log),     1,                                  'removeObject: 1 emit');
eq(OCWebHookEmitter::$log[0]['trigger'], DeleteWebHookTrigger::IDENTIFIER, 'removeObject: trigger corretto');
eq(OCWebHookEmitter::$log[0]['payload']['metadata']['isPublic'], false,   'removeObject: payload minimal isPublic=false');
eq(OCWebHookEmitter::$log[0]['payload']['data'], [],                      'removeObject: payload minimal data vuoto');

// ── test 3: loop guard ─────────────────────────────────────────────────────────

OCWebHookEmitter::$log = [];
eZSolr::$addCalls = 0;
OCWebHookEmitter::$reentryEngine = $engine; // arma: il primo emit() richiama addObject
$engine->addObject(new eZContentObject(7));

// Il parent::addObject viene chiamato 2 volte (Solr deve indicizzare anche l'oggetto re-entrante)
// MA emit() viene chiamato solo 1 volta (il secondo è gated dal loop guard)
eq(eZSolr::$addCalls,             2, 'loop guard: parent chiamato 2 volte (Solr indicizza)');
eq(count(OCWebHookEmitter::$log), 1, 'loop guard: emit chiamato 1 sola volta (no doppio evento)');

// ── test 4: eccezione nel builder NON blocca parent::addObject ─────────────────

OCWebHookEmitter::$log = [];
eZDebug::$errors = [];
eZSolr::$addCalls = 0;
OCWebHookPayloadBuilder::$shouldThrow = true;
$result = $engine->addObject(new eZContentObject(99));
OCWebHookPayloadBuilder::$shouldThrow = false;

eq(eZSolr::$addCalls,             1,    'eccezione builder: parent chiamato comunque (Solr indicizza)');
eq($result,                       true, 'eccezione builder: addObject ritorna true (no rethrow)');
eq(count(OCWebHookEmitter::$log), 0,    'eccezione builder: nessun emit (builder ha fallito)');
ok('eccezione builder: ' . count(eZDebug::$errors) . ' errori loggati via eZDebug');

// ── test 5: eccezione in parent::addObject (Solr down) → Kafka emette comunque ──
// Scelta architetturale "Kafka indipendente da Solr": l'evento parte anche se
// Solr lancia. L'eccezione di Solr viene poi rilanciata al chiamante.

// Sostituisci eZSolr con stub che lancia
class FailingSolr extends eZSolr {
    public function addObject($obj, $commit = true, $commitWithin = 0, $softCommit = null) {
        eZSolr::$addCalls++;
        throw new RuntimeException('Solr unreachable');
    }
}

// Per il test serve istanziare OCSearchEngine ereditando da FailingSolr.
// Workaround: definisci una sottoclasse di test per simulare il fallimento.
class TestEngineSolrFails extends OCSearchEngine {
    public function addObject($obj, $commit = true, $commitWithin = 0, $softCommit = null) {
        // Simula parent::addObject che lancia
        $solrException = null;
        try {
            eZSolr::$addCalls++;
            throw new RuntimeException('Solr unreachable');
        } catch (Exception $e) {
            $solrException = $e;
            if (class_exists('eZDebug')) eZDebug::writeError($e->getMessage(), __METHOD__);
        }
        // Stessa identica logica di OCSearchEngine::addObject — chiama emitSafely
        $reflection = new ReflectionClass('OCSearchEngine');
        $method = $reflection->getMethod('emitSafely');
        $method->setAccessible(true);
        $method->invoke($this, PostPublishWebHookTrigger::IDENTIFIER, $obj, 'build');
        if ($solrException !== null) throw $solrException;
    }
}

OCWebHookEmitter::$log = [];
eZSolr::$addCalls = 0;
eZDebug::$errors = [];
$failEngine = new TestEngineSolrFails();

$caughtException = null;
try {
    $failEngine->addObject(new eZContentObject(123));
} catch (Exception $e) {
    $caughtException = $e;
}

eq(eZSolr::$addCalls,             1,                  'Solr down: parent invocato (e ha lanciato)');
eq(count(OCWebHookEmitter::$log), 1,                  'Solr down: Kafka emette COMUNQUE');
eq(OCWebHookEmitter::$log[0]['trigger'], PostPublishWebHookTrigger::IDENTIFIER, 'Solr down: trigger corretto');
eq($caughtException !== null,     true,               'Solr down: eccezione rilanciata al chiamante');
eq($caughtException->getMessage(),'Solr unreachable', 'Solr down: messaggio eccezione preservato');

// ── risultati ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 60) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) echo ", \033[31m{$FAILED} failed\033[0m";
echo "\n";
exit($FAILED > 0 ? 1 : 0);
```

- [ ] **Step 2.2: eseguire il test e verificare che fallisca**

```bash
cd /Volumes/Repos/sviluppo-sito-comunale/ocwebhookserver
php tests/SearchEngineEmitTest.php 2>&1
```

Atteso: errore di caricamento di `classes/ocsearchengine.php` (file non ancora creato).

- [ ] **Step 2.3: implementare `OCSearchEngine`**

```php
<?php
// classes/ocsearchengine.php
//
// Search engine wrapper che estende eZSolr aggiungendo emissione Kafka.
// Configurare in site.ini: [SearchSettings] SearchEngine=OCSearchEngine
//
// PRECONDIZIONI:
//   - eZSolr deve essere caricabile (eZ Find installato).
//   - [SearchSettings] DelayedIndexing=disabled (default eZ).

if (!class_exists('eZSolr')) {
    // eZ Find non installato: non possiamo definire OCSearchEngine come search engine.
    // Documento l'errore esplicitamente piuttosto che produrre un fatal opaco.
    if (class_exists('eZDebug')) {
        eZDebug::writeError(
            'OCSearchEngine richiede eZSolr/eZFind. Rimuovere SearchEngine=OCSearchEngine da site.ini.',
            __FILE__
        );
    }
    // Non definiamo la classe. Lasciamo che eZ produca l'errore di search engine non trovato:
    // sarà più chiaro di un fatal "Class eZSolr not found" durante l'autoload.
    return;
}

class OCSearchEngine extends eZSolr
{
    /**
     * Loop guard: previene una ri-entrata accidentale di emit() → addObject/removeObject.
     * Statico perché eZ può istanziare più volte il search engine nella stessa request.
     */
    private static $emitting = false;

    public function addObject($contentObject, $commit = true, $commitWithin = 0, $softCommit = null)
    {
        // Scelta architetturale: Kafka indipendente da Solr.
        // Se parent::addObject lancia (Solr down/lento), emettiamo Kafka comunque
        // e poi rilanciamo l'eccezione per preservare il comportamento verso eZ.
        $solrException = null;
        $result = false;
        try {
            $result = parent::addObject($contentObject, $commit, $commitWithin, $softCommit);
        } catch (Exception $e) {
            $solrException = $e;
            if (class_exists('eZDebug')) {
                eZDebug::writeError('Solr addObject failed: ' . $e->getMessage(), __METHOD__);
            }
        }

        $this->emitSafely(
            PostPublishWebHookTrigger::IDENTIFIER,
            $contentObject,
            'build'
        );

        if ($solrException !== null) {
            throw $solrException;
        }
        return $result;
    }

    public function removeObject($contentObject, $commit = null, $commitWithin = 0)
    {
        // Stessa policy di addObject: Kafka indipendente da Solr.
        $solrException = null;
        $result = false;
        try {
            $result = parent::removeObject($contentObject, $commit, $commitWithin);
        } catch (Exception $e) {
            $solrException = $e;
            if (class_exists('eZDebug')) {
                eZDebug::writeError('Solr removeObject failed: ' . $e->getMessage(), __METHOD__);
            }
        }

        $this->emitSafely(
            DeleteWebHookTrigger::IDENTIFIER,
            $contentObject,
            'buildMinimal'
        );

        if ($solrException !== null) {
            throw $solrException;
        }
        return $result;
    }

    /**
     * Emette l'evento webhook senza propagare eccezioni al chiamante (Solr deve sempre indicizzare).
     * Loop guard incluso: la chiamata interna a emit() può finire in registerSearchObject
     * → addObject di nuovo; il flag statico previene la doppia emissione.
     *
     * @param string          $triggerIdentifier  es. PostPublishWebHookTrigger::IDENTIFIER
     * @param eZContentObject $contentObject
     * @param string          $builderMethod      'build' per addObject, 'buildMinimal' per removeObject
     */
    private function emitSafely($triggerIdentifier, $contentObject, $builderMethod)
    {
        if (self::$emitting) {
            // Ri-entrata: già dentro un emit; salta per evitare loop / doppia emissione
            return;
        }
        self::$emitting = true;
        try {
            $payload = OCWebHookPayloadBuilder::$builderMethod($contentObject);

            $triggerInstance = OCWebHookTriggerRegistry::registeredTrigger($triggerIdentifier);
            $queueHandler = $triggerInstance instanceof OCWebHookTriggerQueueAwareInterface
                ? $triggerInstance->getQueueHandler()
                : OCWebHookQueue::defaultHandler();

            OCWebHookEmitter::emit($triggerIdentifier, $payload, $queueHandler);
        } catch (Exception $e) {
            if (class_exists('eZDebug')) {
                eZDebug::writeError($e->getMessage(), __METHOD__);
            }
        } finally {
            self::$emitting = false;
        }
    }
}
```

> **Nota — perché `return` invece di lanciare quando manca `eZSolr`**: l'autoload eZ chiama il file via `require_once`. Se il file ritorna senza definire la classe, eZ riceve poi un errore "Class OCSearchEngine not found" quando prova ad istanziarla via `eZSearch::getEngine()`. Quel messaggio è più leggibile e legale di un fatal `Class 'eZSolr' not found` da `extends`. Il warning su `eZDebug` indirizza l'operatore al file di configurazione.

> **Nota — perché loop guard statico e non di istanza**: `eZSearch::getEngine()` può creare istanze diverse di `OCSearchEngine` nella stessa request. Un flag di istanza non proteggerebbe se la ri-entrata avvenisse su un'istanza diversa. Lo statico è sicuro perché PHP è single-thread su request.

> **Nota — perché try/catch attorno a `emit`**: Solr DEVE indicizzare anche se il webhook fallisce (lib rdkafka non installata, DB indisponibile, ecc.). Un'eccezione non gestita nell'emit bloccherebbe `addObject` e quindi la pubblicazione del contenuto — inaccettabile.

> **Nota — scelta architetturale "Kafka indipendente da Solr"**: il try/catch attorno a `parent::addObject` rende l'emissione Kafka resiliente a problemi di Solr (down, lento, network glitch). Il consumer Kafka riceve l'evento anche durante degradi temporanei dell'indice di ricerca. L'eccezione di Solr viene comunque rilanciata al chiamante eZ, quindi l'utente admin vede l'errore di publish come oggi. Conseguenza accettata: in caso di Solr degradato, il consumer Kafka può ricevere un evento per un contenuto non ancora cercabile via Solr. Vedi anche "Rischi residui — Coupling Solr/Kafka".

- [ ] **Step 2.4: eseguire il test e verificare che passi**

```bash
cd /Volumes/Repos/sviluppo-sito-comunale/ocwebhookserver
php tests/SearchEngineEmitTest.php 2>&1
```

Atteso: tutti i PASS (almeno 16 assertion), exit 0.

- [ ] **Step 2.5: commit**

```bash
git add classes/ocsearchengine.php tests/SearchEngineEmitTest.php
git commit -m "feat: add OCSearchEngine — extends eZSolr, emits Kafka with loop guard"
```

---

## Task 3 — Gating dei workflow handler su `OCSearchEngine`

Per evitare la doppia emissione (workflow `post_publish` + `OCSearchEngine::addObject`, oppure workflow `pre_delete` + `OCSearchEngine::removeObject`), i due workflow handler controllano l'engine attivo. Se è `OCSearchEngine`, ritornano `STATUS_ACCEPTED` senza emettere — il path Solr ha già coperto.

**Approccio scelto:** gating runtime via `eZSearch::getEngine() instanceof OCSearchEngine`. Vantaggi rispetto alla rimozione delle righe `eztrigger` da DB:
- Niente migrazione DB su 500 tenant.
- Rollback istantaneo: cambia la riga `SearchEngine=...` in `site.ini` e i workflow handler riprendono a emettere.
- Funziona come **fallback automatico** per tenant senza Solr (dove il search engine NON è `OCSearchEngine` → workflow emette come oggi).

**File:**
- Modificare: `eventtypes/event/workflowwebhook/workflowwebhooktype.php`
- Modificare: `eventtypes/event/deleteworkflowwebhook/deleteworkflowwebhooktype.php`

- [ ] **Step 3.1: modificare `WorkflowWebHookType::execute()`**

Aggiungere il gating in cima al metodo, prima della logica di build/emit:

```php
function execute($process, $event)
{
    // Gating: se OCSearchEngine è il search engine attivo, l'emissione è già stata
    // fatta da OCSearchEngine::addObject() durante registerSearchObject().
    // Evitiamo la doppia emissione restando silenti.
    if (class_exists('OCSearchEngine')) {
        $engine = eZSearch::getEngine();
        if ($engine instanceof OCSearchEngine) {
            return eZWorkflowType::STATUS_ACCEPTED;
        }
    }

    // ── fallback: logica originale di emit, invariata ─────────────────────────
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

- [ ] **Step 3.2: modificare `DeleteWorkflowWebHookType::execute()`**

Stesso pattern, gating in cima:

```php
function execute($process, $event)
{
    if (class_exists('OCSearchEngine')) {
        $engine = eZSearch::getEngine();
        if ($engine instanceof OCSearchEngine) {
            return eZWorkflowType::STATUS_ACCEPTED; // OCSearchEngine::removeObject emette
        }
    }

    // ── fallback: logica originale di emit per pre_delete, invariata ──────────
    // [...lascia il codice esistente di emit delete_ocopendata...]

    return eZWorkflowType::STATUS_ACCEPTED;
}
```

- [ ] **Step 3.3: verificare che il gating sia rispettato**

Sul container, dopo che Task 4 ha attivato `OCSearchEngine`:

```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php -r "
require 'html/autoload/ezp_extension.php';
\$engine = eZSearch::getEngine();
echo 'Engine: ' . get_class(\$engine) . PHP_EOL;
echo 'Is OCSearchEngine: ' . (\$engine instanceof OCSearchEngine ? 'YES' : 'NO') . PHP_EOL;
" 2>&1); echo "$OUT"
```

Atteso: `Engine: OCSearchEngine` e `Is OCSearchEngine: YES`.

- [ ] **Step 3.4: commit**

```bash
git add eventtypes/event/workflowwebhook/workflowwebhooktype.php \
        eventtypes/event/deleteworkflowwebhook/deleteworkflowwebhooktype.php
git commit -m "feat: gate workflow webhook handlers when OCSearchEngine is active"
```

---

## Task 4 — Attivare `OCSearchEngine` via `site.ini.append.php`

**File:**
- Modificare: `settings/site.ini.append.php`

- [ ] **Step 4.1: aggiungere il blocco `[SearchSettings]`**

Il file attuale (`/Volumes/Repos/sviluppo-sito-comunale/ocwebhookserver/settings/site.ini.append.php`) contiene:

```php
<?php /* #?ini charset="utf-8"?

[RegionalSettings]
TranslationExtensions[]=ocwebhookserver

[RoleSettings]
PolicyOmitList[]=webhook/metrics

*/ ?>
```

Aggiungere il blocco `[SearchSettings]` per fare `OCSearchEngine` il search engine attivo:

```php
<?php /* #?ini charset="utf-8"?

[RegionalSettings]
TranslationExtensions[]=ocwebhookserver

[RoleSettings]
PolicyOmitList[]=webhook/metrics

[SearchSettings]
SearchEngine=OCSearchEngine

*/ ?>
```

- [ ] **Step 4.2: rigenerare la mappa autoload eZ**

```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php -d memory_limit=256M \
  html/bin/php/ezpgenerateautoloads.php -e 2>&1); echo "$OUT"
```

Verifica che `OCSearchEngine` sia nel mapping:

```bash
OUT=$(docker exec cms-app-1 grep "OCSearchEngine" html/var/autoload/ezp_extension.php 2>&1); echo "$OUT"
```

Atteso: una riga con il mapping classe → file.

- [ ] **Step 4.3: verificare che eZ scelga `OCSearchEngine`**

```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php -r "
require 'html/autoload/ezp_extension.php';
\$engine = eZSearch::getEngine();
echo get_class(\$engine) . PHP_EOL;
" 2>&1); echo "$OUT"
```

Atteso: `OCSearchEngine`.

Se invece restituisce `eZSolr` o `eZSearch`, controllare l'ordine di merge di `site.ini` (qualche altra estensione attiva dopo `ocwebhookserver` potrebbe sovrascrivere `SearchEngine`).

- [ ] **Step 4.4: commit**

```bash
git add settings/site.ini.append.php
git commit -m "feat: configure OCSearchEngine as active search engine"
```

---

## Task 5 — Verifica precondizioni nel setup script

Aggiunge controlli a `setup_kafka_workflow.php` (via `OCWebHookKafkaSetupService`) che validano le precondizioni del Piano C all'installazione di ogni tenant.

**File:**
- Modificare: `classes/ocwebhookkafkasetupservice.php`

- [ ] **Step 5.1: aggiungere `checkPreconditions()` a `OCWebHookKafkaSetupService`**

Da chiamare in `run()` prima della creazione del workflow. Restituisce array di problemi non bloccanti (warning) o blocca con eccezione su problemi critici.

```php
/**
 * Verifica le precondizioni operative del Piano C.
 * - eZSolr installato → CRITICO se OCSearchEngine è SearchEngine
 * - DelayedIndexing=disabled → CRITICO (eventi sarebbero deferiti al cron)
 * - SearchEngine = OCSearchEngine → WARNING (se non lo è, il workflow handler emetterà in fallback)
 *
 * @param array $log  log array passato per riferimento
 * @return bool  true se OK, false se ci sono blocchi
 */
private function checkPreconditions(array &$log)
{
    $ok = true;

    // 1. eZSolr deve esistere
    if (!class_exists('eZSolr')) {
        $log[] = '[fail] eZSolr non trovato — Piano C richiede eZ Find/eZSolr installato.';
        $ok = false;
    } else {
        $log[] = '[ok] eZSolr caricabile';
    }

    // 2. DelayedIndexing deve essere disabled
    $delayed = eZINI::instance('site.ini')->variable('SearchSettings', 'DelayedIndexing');
    if ($delayed !== 'disabled') {
        $log[] = "[fail] [SearchSettings] DelayedIndexing='$delayed' — Piano C richiede 'disabled'. " .
                 "Con DelayedIndexing attivo, gli eventi Kafka sarebbero deferiti al cron ezfindexsubtree.";
        $ok = false;
    } else {
        $log[] = '[ok] DelayedIndexing=disabled';
    }

    // 3. SearchEngine attivo
    $searchEngine = eZINI::instance('site.ini')->variable('SearchSettings', 'SearchEngine');
    if ($searchEngine === 'OCSearchEngine') {
        $log[] = '[ok] SearchEngine=OCSearchEngine';
    } else {
        $log[] = "[warn] SearchEngine='$searchEngine' (atteso 'OCSearchEngine'). " .
                 "Il workflow handler emetterà in fallback — verifica site.ini merge order.";
        // Non blocca: il fallback funziona, ma è un sintomo di configurazione errata.
    }

    return $ok;
}
```

E chiamarla all'inizio di `run()`:

```php
public function run()
{
    $log = [];

    // ... codice esistente fino al check Kafka enabled ...

    if (!$this->isKafkaEnabled()) {
        return ['log' => $log, 'changed' => false];
    }

    // [NUOVO] Precondizioni Piano C
    if (!$this->checkPreconditions($log)) {
        throw new RuntimeException(
            "Setup abort: precondizioni Piano C non soddisfatte. " .
            "Vedi log per dettagli."
        );
    }

    // ... resto invariato (workflow + webhook record) ...
}
```

- [ ] **Step 5.2: aggiungere test unitario per `checkPreconditions`**

Estendere `tests/SetupKafkaWorkflowTest.php` con scenari:
- eZSolr presente + DelayedIndexing=disabled + SearchEngine=OCSearchEngine → `[ok]` ovunque, no exception
- DelayedIndexing=enabled → `[fail]` + RuntimeException
- SearchEngine=ezsolr → `[warn]` ma non blocca

Riusa gli stub `eZINI` esistenti nei test.

- [ ] **Step 5.3: eseguire setup sul container**

```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php \
  extension/ocwebhookserver/bin/php/setup_kafka_workflow.php \
  --allow-root-user -sbackend 2>&1); echo "$OUT"
```

Atteso log:
```
[ok] eZSolr caricabile
[ok] DelayedIndexing=disabled
[ok] SearchEngine=OCSearchEngine
[ok] Workflow post_publish → WorkflowWebHookType già configurato
```

- [ ] **Step 5.4: commit**

```bash
git add classes/ocwebhookkafkasetupservice.php tests/SetupKafkaWorkflowTest.php
git commit -m "feat: setup_kafka_workflow validates Piano C preconditions"
```

---

## Task 6 — Smoke test E2E

Verifica che, attivato `OCSearchEngine`, ogni operazione produca **esattamente 1 evento Kafka** (no doppi, no zero).

> **Setup preliminare ambiente locale (cms-dev)**:
> ```bash
> docker compose -f docker-compose.yml -f docker-compose.events.yml up -d
> OUT=$(docker exec cms-app-1 /usr/local/bin/php extension/ocwebhookserver/bin/php/setup_kafka_workflow.php \
>   --allow-root-user -sbackend 2>&1); echo "$OUT"
> ```

Per ogni scenario, prima dell'azione catturare l'offset Kafka corrente; dopo l'azione, leggere i messaggi nuovi e contare.

- [ ] **Step 6.1: publish nuovo contenuto (UI o CLI)**

```bash
# Crea un articolo via CLI (da admin UI è equivalente)
# Atteso: 1 evento con ce_type = it.opencity.cms.article.created
```

Verifica:
```bash
OUT=$(docker exec cms-redpanda-1 /usr/bin/rpk topic consume cms \
  -X brokers=redpanda:9092 --offset end --num 5 2>&1); echo "$OUT"
```

**Conta**: 1 messaggio. Se 2 → gating Task 3 non attivo. Se 0 → engine non è OCSearchEngine.

- [ ] **Step 6.2: update contenuto esistente (publish nuova versione)**

Atteso: 1 evento `ce_type = it.opencity.cms.article.updated`, `entity.meta.version > 1`.

- [ ] **Step 6.3: hide nodo singolo (foglia, no figli)**

Da admin UI o CLI. Atteso: 1 evento `post_publish_ocopendata` con `metadata.isPublic: false`.

- [ ] **Step 6.4: hide nodo padre con N figli (N < 50)**

Atteso: 1 evento immediato per il padre + N eventi deferiti al cron `ezfindexsubtree` (i figli vengono re-indicizzati in batch). Per ciascuno, `isPublic: false`.

**Run cron e verifica:**
```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php html/runcronjobs.php -sbackend ezfindexsubtree 2>&1); echo "$OUT"
OUT=$(docker exec cms-redpanda-1 /usr/bin/rpk topic consume cms \
  -X brokers=redpanda:9092 --offset end --num 100 2>&1); echo "$OUT"
```

- [ ] **Step 6.5: show stesso nodo padre**

Atteso: simmetrico al 6.4 — 1 evento per il padre + N per i figli (via cron), con `isPublic: true`.

- [ ] **Step 6.6: cambio stato da admin UI**

Admin → assegnare stato diverso. Atteso: 1 evento `post_publish_ocopendata`.

- [ ] **Step 6.7: cambio stato da cron `change_state.php`**

Schedulare un cambio stato con data attiva, poi:
```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php html/runcronjobs.php -sbackend change_state 2>&1); echo "$OUT"
```
Atteso: 1 evento per oggetto cambiato.

- [ ] **Step 6.8: cambio sezione da admin UI**

Atteso: 1 evento.

- [ ] **Step 6.9: cambio sezione da cron `change_section.php`**

Schedulare, eseguire cron, atteso 1 evento per oggetto.

- [ ] **Step 6.10: move tra subtree**

Spostare un nodo. Atteso: 1 evento (`isPublic` riflette le ACL della nuova sezione).

- [ ] **Step 6.11: restore da cestino**

Cestinare un oggetto, poi `/content/restore/<id>` da admin UI. Atteso: 1 evento via `addObject` post-restore.

- [ ] **Step 6.12: trash (soft delete)**

Cestinare un oggetto. Atteso: 1 evento `delete_ocopendata` via `OCSearchEngine::removeObject` (NON via `DeleteWorkflowWebHookType`, che è gated).

- [ ] **Step 6.13: hard delete**

Eliminare definitivamente. Atteso: 1 evento `delete_ocopendata`.

- [ ] **Step 6.14: remove translation**

Rimuovere una traduzione da un oggetto multilingua. Atteso: 1 evento `post_publish_ocopendata` (l'oggetto è stato re-indicizzato).

- [ ] **Step 6.15: verifica payload completo**

Pescare un messaggio recente e verificare i campi obbligatori:

```bash
OUT=$(docker exec cms-redpanda-1 /usr/bin/rpk topic consume cms \
  -X brokers=redpanda:9092 --offset end --num 1 2>&1); echo "$OUT"
```

Atteso: il payload `entity.meta` contiene `id`, `object_id`, `type_id`, `version`, `languages`, `name`, `site_url`, `published_at`, `updated_at`. Per `addObject`, anche `entity.data` con il contenuto (non vuoto). Per `removeObject`, `entity.data: {}` (minimal).

Header CloudEvents: `ce_type`, `ce_source`, `ce_id`, `ce_time`, `oc_operation` (`created`/`updated`/`deleted`).

- [ ] **Step 6.16: commit finale se tutto ok**

```bash
git commit --allow-empty -m "chore: Piano C smoke test E2E ok — single emit per operation"
```

---

## Task 7 — Rollout multi-tenant

Piano C va attivato su ~500 tenant Boat/SaaS. Distribuire l'attivazione in modo controllato, con possibilità di rollback per singolo tenant.

**Idea chiave:** l'attivazione di Piano C su un tenant **non** richiede un deploy di codice (il codice è già su tutti i tenant attraverso l'estensione `ocwebhookserver` aggiornata). Si attiva via env var Docker, che sovrascrive il default in `site.ini.append.php` dell'estensione. Questo permette canary/disattivazione per-tenant senza redeploy globali.

### Modello di attivazione per tenant

`settings/site.ini.append.php` dell'estensione mette `SearchEngine=OCSearchEngine` come **default per tutti**. Per disabilitare il piano su un tenant specifico, basta sovrascrivere via env var nel `docker-compose.yml` di quel tenant:

```yaml
# docker-compose.yml di un tenant per cui Piano C è DISABILITATO
environment:
  EZINI_site__SearchSettings__SearchEngine: 'ezsolr'  # ripristina engine standard
```

Questo override ha precedenza sul file dell'estensione. Sul tenant disabilitato:
- `eZSearch::getEngine()` ritorna `eZSolr` standard
- `OCSearchEngine::addObject` non viene mai chiamato (non è l'engine attivo)
- Il gating in `WorkflowWebHookType` rileva "not instanceof OCSearchEngine" → emette via workflow (path di oggi)
- Risultato: il tenant resta sul comportamento pre-Piano-C, nessuna interruzione

**Inverso possibile (opt-in invece di opt-out):** lasciare il default in `site.ini.append.php` come `ezsolr` (stato attuale) e abilitare via env var solo sui tenant target:

```yaml
# tenant in cui ATTIVARE Piano C
environment:
  EZINI_site__SearchSettings__SearchEngine: 'OCSearchEngine'
```

**Raccomandazione:** opt-in (default `ezsolr`, attivazione esplicita per tenant) per i primi 30 giorni del rollout. Poi flip a default opt-out (default `OCSearchEngine`, disattivazione esplicita per tenant problematici).

### Fasi di rollout consigliate

- [ ] **Step 7.1: Deploy del codice senza attivazione**

Mergiare e deployare l'estensione `ocwebhookserver` con Piano C su tutti i tenant. **NON modificare `site.ini.append.php`** in questa fase: lasciare il default attuale (no `[SearchSettings]` block). Tutti i tenant continuano a comportarsi come oggi. Il codice di Piano C è dormiente.

Verifica per tenant:
```bash
OUT=$(docker exec <tenant>-app-1 /usr/local/bin/php -r "
require 'html/autoload/ezp_extension.php';
echo class_exists('OCSearchEngine') ? 'OCSearchEngine LOADED' : 'NOT LOADED';
echo PHP_EOL;
echo get_class(eZSearch::getEngine()) . PHP_EOL;
" 2>&1); echo "$OUT"
```

Atteso: `OCSearchEngine LOADED` + `eZSolr` (non OCSearchEngine). La classe c'è ma non è l'engine attivo.

- [ ] **Step 7.2: Canary su 3-5 tenant non critici**

Selezionare 3-5 tenant a basso traffico, non strategici (es. comuni piccoli senza pubblicazione frequente, ambienti di test).

Per ciascuno:
1. Aggiungere `EZINI_site__SearchSettings__SearchEngine=OCSearchEngine` al `docker-compose.yml` del tenant.
2. Riavviare il container `app` del tenant.
3. Eseguire `setup_kafka_workflow.php` (le precondizioni del Task 5 si attivano).
4. Smoke test minimo (Task 6 Step 6.1-6.3: publish, update, hide singolo).
5. Osservare 48h: outbox queue depth, error rate su `webhook.log`, lag Kafka.

Criteri di promozione alla fase successiva (tutti devono essere veri):
- Zero errori `OCSearchEngine::*` in `webhook.log`/error.log
- Outbox `ocwebhook_job` con `execution_status=FAILED` ≤ 1% del totale
- Latenza media publish dell'admin ≤ 110% rispetto a baseline pre-Piano-C
- Tasso eventi sul topic Kafka coerente con il volume atteso (publish + visibility)

- [ ] **Step 7.3: Wave 1 — 20 tenant di medie dimensioni**

Aggiungere altri 20 tenant rappresentativi (mix di carichi, mix di feature attive). Procedere come per il canary. Osservare 7 giorni.

In questa fase, dimensionare l'alerting (vedi Step 7.5).

- [ ] **Step 7.4: Wave 2 — restanti tenant in batch**

Una volta validata Wave 1, attivare i restanti tenant in batch da 50-100 per giorno. Mantenere il monitoraggio attivo.

- [ ] **Step 7.5: Monitoring & alerting (da approntare prima di Step 7.2)**

Metriche minime da esporre/monitorare durante il rollout:

| Metrica | Sorgente | Soglia di allarme |
|---|---|---|
| Errori `OCSearchEngine::*` in error.log | `eZDebug::writeError` log | > 10/min sostenuti |
| Job `ocwebhook_job` con `execution_status=FAILED` | DB query | > 1% del totale ultima ora |
| Outbox queue depth (job PENDING) | DB query | > 1000 sostenuti |
| Solr error rate (parent::addObject lancia) | log dedicato | > 5/min sostenuti |
| Tempo medio publish dell'admin | metriche app | > 130% baseline |

Dashboard suggerita (Grafana o equivalente): aggregare per tenant per identificare problemi locali.

- [ ] **Step 7.6: Procedura di rollback per singolo tenant**

Se un tenant mostra problemi (errori sopra soglia, outbox congestionato, latenza inaccettabile):

1. Rimuovere/modificare la env var nel `docker-compose.yml` del tenant:
   ```yaml
   EZINI_site__SearchSettings__SearchEngine: 'ezsolr'
   ```
2. Riavviare il container `app` del tenant.
3. Verificare con il comando di Step 7.1 che `get_class(eZSearch::getEngine())` torni `eZSolr`.
4. Il tenant torna immediatamente al comportamento pre-Piano-C. I job nell'outbox vengono comunque processati dal cron (sono pre-esistenti).
5. Investigare la causa offline.

Rollback completo del piano (tutti i tenant):
1. Step 1-3 per ogni tenant attivato, OPPURE
2. Cambiare il default in `settings/site.ini.append.php` rimuovendo il blocco `[SearchSettings]`, deployare. Tutti i tenant senza env var override esplicita tornano a `ezsolr`.

- [ ] **Step 7.7: Pulizia post-rollout completato**

Dopo che tutti i tenant target sono stati attivati con successo per 30 giorni:
1. Spostare il default in `settings/site.ini.append.php` a `SearchEngine=OCSearchEngine` (se non già lì).
2. Rimuovere le env var ridondanti dai `docker-compose.yml` dei tenant.
3. Per i tenant esclusi permanentemente, mantenere l'override `SearchEngine=ezsolr` esplicito e documentare il motivo.

### Strategia di degrado: tenant che falliscono le precondizioni

Il Task 5 (`checkPreconditions`) abortisce l'install se mancano eZSolr o se `DelayedIndexing != disabled`. Su questi tenant:
- L'attivazione di Piano C tramite env var fa fallire `setup_kafka_workflow.php` al primo run.
- Il container resta funzionante ma il setup non procede (no workflow creato, no webhook record aggiornato).
- Soluzione: lasciare il tenant su `SearchEngine=ezsolr` (fallback workflow) finché le precondizioni non sono soddisfatte.

Inventario preventivo dei tenant non idonei (da eseguire **prima** di Step 7.2):

```bash
# Per ogni tenant, controllare DelayedIndexing
for tenant in tenant1 tenant2 ...; do
    OUT=$(docker exec ${tenant}-app-1 /usr/local/bin/php -r "
    \$ini = eZINI::instance('site.ini');
    echo \"\${tenant}: \" . \$ini->variable('SearchSettings', 'DelayedIndexing') . PHP_EOL;
    " 2>&1); echo "$OUT"
done
```

I tenant con `DelayedIndexing != disabled` vanno annotati e gestiti separatamente.

---

## Auto-revisione

### Copertura

| Caso | Coperto da | Stato |
|---|---|---|
| Publish (creazione) | `OCSearchEngine::addObject` via `registerSearchObject` | ✅ |
| Publish (update) | `OCSearchEngine::addObject` via `registerSearchObject` | ✅ |
| Hide/show singolo (UI) | `OCSearchEngine::addObject` via `eZSearch::updateNodeVisibility` | ✅ |
| Hide/show subtree (UI) | 1 evento sync per il padre + N via cron `ezfindexsubtree` (deferred) | ⚠️ semantica deferred per i figli |
| Cambio stato (UI) | `OCSearchEngine::addObject` via `eZSearch::updateObjectState` | ✅ |
| Cambio stato (cron `change_state`) | `OpenPAStateTools::flushObject` → `registerSearchObject` → `addObject` | ✅ no openpa changes needed |
| Cambio sezione (UI) | `OCSearchEngine::addObject` via re-index | ✅ |
| Cambio sezione (cron `change_section`) | `OpenPASectionTools::flushObject` → `registerSearchObject` | ✅ no openpa changes needed |
| Move (cross-section o no) | `OCSearchEngine::addObject` via re-index | ✅ |
| Restore da cestino | `OCSearchEngine::addObject` via AddLocation | ✅ |
| Remove translation | `OCSearchEngine::addObject` via re-index | ✅ |
| Trash (soft delete) | `OCSearchEngine::removeObject` (workflow gated) | ✅ |
| Hard delete | `OCSearchEngine::removeObject` (workflow gated) | ✅ |
| `metadata.isPublic` su ogni evento | `OCWebHookPayloadBuilder::build` chiama `checkAccess` | ✅ |
| Singolo emit per operazione | gating dei workflow handler in Task 3 | ✅ |
| Fallback se Solr non attivo | workflow handler emette come oggi (publish e delete) | ⚠️ no visibility events |

### Anti-doppia-emissione — analisi finale

| Path | Workflow handler emette? | OCSearchEngine emette? |
|---|---|---|
| Publish UI | NO (gated) | sì, 1 evento |
| Hide singolo UI | NO (`post_hide` non registrato in `eztrigger`) | sì, 1 evento |
| Hide subtree UI | NO | 1 sync (padre) + N via cron |
| State change UI | NO | sì, 1 evento |
| State change cron | NO | sì, 1 evento (via `flushObject` → `registerSearchObject`) |
| Section change UI | NO | sì, 1 evento |
| Section change cron | NO | sì, 1 evento |
| Move | NO | sì, 1 evento |
| Restore | NO | sì, 1 evento |
| Remove translation | NO | sì, 1 evento |
| Trash / hard delete | NO (DeleteWorkflowWebHookType gated) | sì, 1 evento via `removeObject` |

In tutti i casi: **1 emit per operazione** (eccezione: hide/show subtree → 1 + N deferred).

### Rischi residui

1. **DelayedIndexing**: il setup script blocca su `enabled`/`classbased`. Se un operatore modifica `site.ini` dopo l'install, il setting non viene ri-validato. Mitigazione: eseguire `setup_kafka_workflow.php` dopo ogni redeploy.

2. **Search engine override da altra estensione**: un'estensione attivata dopo `ocwebhookserver` potrebbe sovrascrivere `SearchEngine`. Mitigazione: il setup script logga un warning se l'engine attivo non è `OCSearchEngine`; controllare in CI.

3. **`Content::createFromEzContentObject` su oggetto archived**: nel branch `removeObject` non usiamo `build()` ma `buildMinimal()` proprio per evitare il rischio. Verificato in Task 1 Step 1.4.

4. **Hide subtree N>>1**: i figli vengono re-indicizzati dal cron `ezfindexsubtree`, quindi gli eventi arrivano in modo deferrito. Se il consumer richiede sincronicità, usare Piano A (che enumera e emette in-loop con cap 500).

5. **Rimozione futura di Solr**: il file `classes/ocsearchengine.php` ha `return` precoce se `eZSolr` non esiste, ma l'autoload eZ si aspetta che la classe venga definita. Se Solr viene rimosso, `eZSearch::getEngine()` fallirà. Mitigazione: documentato come "precondizione operativa" — la rimozione di Solr richiede contestualmente di cambiare `SearchEngine` in `site.ini`.

6. **Coupling Solr/Kafka — scelta architetturale**: il codice di Task 2 implementa "Kafka indipendente da Solr" — se `parent::addObject` lancia (Solr down/lento), Kafka emette comunque. Conseguenza: il consumer Kafka può ricevere un evento per un contenuto non ancora cercabile via Solr in caso di degrado dell'indice. Mitigazione: il consumer deve essere autonomo (non interrogare Solr per arricchire il payload, o tollerare miss temporanei). Se questo non è accettabile per un dato consumer, contattarlo prima del cutover.

7. **Confine transazionale post-commit (verificato)**: `register-search-object` è step 10 dell'operation definition publish, dopo `commit-transaction` (step 7). Piano C emette quindi su stato già committato — semanticamente equivalente a oggi (`WorkflowWebHookType` emette allo step 13). Nessun rischio di evento "fantasma" per rollback. Verificato in `html/kernel/content/operation_definition.php:186,207`.

8. **Kafka push sincrono (HANDLER_IMMEDIATE hardcoded)**: il codice attuale di `OCWebHookEmitter::emit` riga 56 forza HANDLER_IMMEDIATE per Kafka, ignorando `getQueueHandler()` del trigger. Ogni emit blocca fino al timeout flush (2s default). Non è una conseguenza di Piano C — è comportamento pre-esistente — ma in cron grossi (500 oggetti) può sommare 1000s+ di tempo bloccante. Da monitorare nei test di carico.

### Rollback

Per disattivare Piano C senza perdere eventi:

1. In `settings/site.ini.append.php`, sostituire `SearchEngine=OCSearchEngine` con il valore precedente (es. `SearchEngine=ezsolr` o omettere il blocco).
2. Rigenerare autoload + cache eZ.
3. Il gating in `WorkflowWebHookType`/`DeleteWorkflowWebHookType` rileva che l'engine NON è `OCSearchEngine` e ricomincia a emettere i `post_publish` / `delete_ocopendata` via workflow handler. Si perdono le visibility events (come prima del Piano C).

Rollback completo (rimuovere anche il codice):

1. Step 1 sopra.
2. `git revert` dei commit Task 2/3/4/5 in ordine inverso.

---

## Differenze rispetto al Piano A

| Aspetto | Piano A | Piano C |
|---|---|---|
| File modificati | 8 (incluso `openpa`) | 6 (solo `ocwebhookserver`) |
| Cross-repo | sì (`openpa`) | no |
| Migrazione DB | sì (6 righe `eztrigger`) | no |
| Dipendenza Solr | no | sì (precondizione) |
| Timing eventi visibility (UI singola) | sincrono | sincrono |
| Hide subtree → figli | sync, enumera con cap 500 | deferred via cron `ezfindexsubtree` |
| Cron state/section | listener `ezpEvent` esplicito | automatico (`flushObject` chiama già `registerSearchObject`) |
| `metadata.isPublic` | sì | sì (stesso `OCWebHookPayloadBuilder::build`) |
| Rollback | revert + DB migration inverse | cambiare 1 riga in `site.ini` |
| Estendibilità futura | logica sparsa su 6+ trigger | unico file `OCSearchEngine` |

**Conclusione architetturale:** Piano C è preferibile quando Solr resta installato e `DelayedIndexing=disabled` su tutti i tenant target. Piano A è preferibile quando questa precondizione non è garantibile. I due piani sono mutualmente esclusivi: il gating in Task 3 garantisce che solo uno emetta per operazione, a seconda del search engine attivo.
