# Piano C — Eventi di visibilità via OCSearchEngine

> **Per agentic worker:** sub-skill richiesta — usare superpowers:subagent-driven-development (consigliata) oppure superpowers:executing-plans per implementare questo piano task per task. Gli step usano la sintassi a checkbox (`- [ ]`) per il tracking.

**Obiettivo:** emettere un evento Kafka ogni volta che cambia la visibilità di un contenuto (hide/show, cambio stato, cambio sezione, move, restore da cestino, ecc.), non solo alla pubblicazione di una nuova versione. Il consumer può così tenere sincronizzato un indice esterno o reagire ai cambi di accessibilità pubblica senza fare polling sul CMS.

**Cosa fa questo piano:** crea una nuova classe `OCSearchEngine` che estende `eZSolr` e si configura in `site.ini` come search engine dell'installazione. Sovrascrive `addObject()` e `removeObject()` per aggiungere l'emissione Kafka dopo la delega al parent. Un singolo punto di intercettazione copre automaticamente tutti i path che causano un re-index (UI, cron, CLI), senza registrare trigger puntuali nel DB.

**Confronto con Piano A e Piano B:**
- Il [Piano A](./2026-05-18-index-plugin-visibility-events.md) intercetta ogni operazione con hook puntuali (trigger eZ × N operazioni + listener `ezpEvent`). Non dipende da Solr, ma richiede molti pezzi mobili.
- Il [Piano B](./piano-b-solr-index-plugin.md) aggancia un `ezpIndexPlugin` dentro `eZSolr`. Compatto, ma cade silenziosamente se eZSolr viene rimosso.
- Il **Piano C** diventa il search engine ufficiale dell'installazione via `site.ini`. Estende `eZSolr` oggi; quando Solr verrà rimosso, basterà cambiare cosa si estende — senza toccare la logica di emissione.

**Stack tecnico:** PHP 7.2+, eZ Publish 5, `ezpsearchengine` interface (`html/kernel/private/interfaces/ezpsearchengine.php`), `eZSolr` (`html/extension/ezfind/search/plugins/ezsolr/ezsolr.php`), infrastruttura esistente `OCWebHookEmitter`.

**Nota sul payload builder:** questo piano usa `OCWebHookPayloadBuilder::build(eZContentObject $object)` per costruire il payload. Se il Piano A è già stato implementato, la classe esiste in `classes/ocwebhookpayloadbuilder.php` — saltare il Task 1. Se no, il Task 1 è prerequisito.

---

## Mappa file

| File | Azione | Ruolo |
|---|---|---|
| `classes/ocwebhookpayloadbuilder.php` | **Creare** (se non esiste) | Costruisce il payload ocopendata da `eZContentObject` |
| `classes/ocsearchengine.php` | **Creare** | Estende `eZSolr`; sovrascrive `addObject` e `removeObject` |
| `settings/site.ini.append.php` | **Modificare** | Aggiunge `[SearchSettings] SearchEngine=OCSearchEngine` |
| `tests/SearchEngineEmitTest.php` | **Creare** | Verifica che addObject/removeObject emettano correttamente |

---

## Task 1 — Creare `OCWebHookPayloadBuilder`

> **Salta questo task se `classes/ocwebhookpayloadbuilder.php` esiste già** (Piano A implementato).

Estrae la logica di costruzione del payload — oggi duplicata in `WorkflowWebHookType` e in `emit_all_published.php` — in una classe statica riusabile.

**File:**
- Creare: `classes/ocwebhookpayloadbuilder.php`

- [ ] **Step 1: Scrivi il test**

```php
<?php
// tests/PayloadBuilderTest.php
require_once __DIR__ . '/../classes/ocwebhookpayloadbuilder.php';

// Mock eZContentObject minimale
class eZContentObject {
    public function attribute($key) {
        $map = ['id' => 42, 'remote_id' => 'abc', 'class_identifier' => 'article',
                'current_version' => 3];
        return $map[$key] ?? null;
    }
    public function currentVersion() { return new class {
        public function languageList() { return ['it-IT']; }
    }; }
}

$obj = new eZContentObject();
$payload = OCWebHookPayloadBuilder::buildMinimal($obj);

assert(isset($payload['metadata']),              'metadata presente');
assert($payload['metadata']['id'] === 42,        'metadata.id');
assert($payload['metadata']['remoteId'] === 'abc', 'metadata.remoteId');
assert($payload['metadata']['classIdentifier'] === 'article', 'metadata.classIdentifier');
assert($payload['metadata']['currentVersion'] === 3, 'metadata.currentVersion');
echo "[PASS] PayloadBuilderTest\n";
```

- [ ] **Step 2: Esegui e verifica che fallisca**

```bash
php tests/PayloadBuilderTest.php 2>&1
```

Atteso: `Fatal error: Class 'OCWebHookPayloadBuilder' not found`

- [ ] **Step 3: Implementa `OCWebHookPayloadBuilder`**

```php
<?php
// classes/ocwebhookpayloadbuilder.php

class OCWebHookPayloadBuilder
{
    /**
     * Costruisce un payload ocopendata minimo da un eZContentObject.
     * Usato da OCSearchEngine per addObject/removeObject quando non è
     * disponibile il contesto completo di ocopendata.
     *
     * Per il payload completo (con data, relazioni, ecc.) vedere il path
     * post_publish che usa ocopendata direttamente.
     *
     * @param eZContentObject $object
     * @return array  Payload con chiave 'metadata' compatibile con OCWebHookKafkaPayloadFormatter
     */
    public static function buildMinimal(eZContentObject $object)
    {
        $version = $object->currentVersion();
        $languages = $version ? $version->languageList() : [];

        return [
            'metadata' => [
                'id'                => $object->attribute('id'),
                'remoteId'          => $object->attribute('remote_id'),
                'classIdentifier'   => $object->attribute('class_identifier'),
                'currentVersion'    => $object->attribute('current_version'),
                'languages'         => $languages,
            ],
            'data'     => [],
        ];
    }
}
```

- [ ] **Step 4: Esegui e verifica che passi**

```bash
php tests/PayloadBuilderTest.php 2>&1
```

Atteso: `[PASS] PayloadBuilderTest`

- [ ] **Step 5: Commit**

```bash
git add classes/ocwebhookpayloadbuilder.php tests/PayloadBuilderTest.php
git commit -m "feat: add OCWebHookPayloadBuilder::buildMinimal"
```

---

## Task 2 — Creare `OCSearchEngine`

La classe estende `eZSolr`, delega tutto al parent, e aggiunge l'emissione Kafka in `addObject` e `removeObject`.

**File:**
- Creare: `classes/ocsearchengine.php`
- Test: `tests/SearchEngineEmitTest.php`

- [ ] **Step 1: Scrivi il test**

```php
<?php
// tests/SearchEngineEmitTest.php
// Verifica che OCSearchEngine chiami emit() dopo addObject/removeObject.

require_once __DIR__ . '/../classes/ocsearchengine.php';

$emitted = [];

// Stub OCWebHookEmitter
class OCWebHookEmitter {
    public static $log = [];
    public static function emit($trigger, $payload, $handler) {
        self::$log[] = ['trigger' => $trigger, 'payload' => $payload];
    }
}

// Stub OCWebHookPayloadBuilder
class OCWebHookPayloadBuilder {
    public static function buildMinimal($obj) {
        return ['metadata' => ['id' => $obj->getId()]];
    }
}

// Stub eZContentObject
class FakeObject {
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function getId() { return $this->id; }
    public function attribute($k) { return $k === 'id' ? $this->id : null; }
}

// Stub eZSolr
class eZSolr {
    public function addObject($obj, $commit = true) { return true; }
    public function removeObject($obj, $commit = null) { return true; }
}

$engine = new OCSearchEngine();
$obj = new FakeObject(99);

OCWebHookEmitter::$log = [];
$engine->addObject($obj);
assert(count(OCWebHookEmitter::$log) === 1,     'addObject: emit chiamato una volta');
assert(OCWebHookEmitter::$log[0]['trigger'] === PostPublishWebHookTrigger::IDENTIFIER,
    'addObject: trigger corretto');

OCWebHookEmitter::$log = [];
$engine->removeObject($obj);
assert(count(OCWebHookEmitter::$log) === 1,     'removeObject: emit chiamato una volta');
assert(OCWebHookEmitter::$log[0]['trigger'] === DeleteWebHookTrigger::IDENTIFIER,
    'removeObject: trigger corretto');

echo "[PASS] SearchEngineEmitTest\n";
```

- [ ] **Step 2: Esegui e verifica che fallisca**

```bash
php tests/SearchEngineEmitTest.php 2>&1
```

Atteso: `Fatal error: Class 'OCSearchEngine' not found`

- [ ] **Step 3: Implementa `OCSearchEngine`**

```php
<?php
// classes/ocsearchengine.php

require_once dirname(__FILE__) . '/ocwebhookpayloadbuilder.php';

/**
 * Search engine wrapper che estende eZSolr aggiungendo emissione Kafka
 * su addObject (post_publish_ocopendata) e removeObject (delete_ocopendata).
 * Configurare in site.ini: [SearchSettings] SearchEngine=OCSearchEngine
 */
class OCSearchEngine extends eZSolr
{
    public function addObject($contentObject, $commit = true, $commitWithin = 0, $softCommit = null)
    {
        $result = parent::addObject($contentObject, $commit, $commitWithin, $softCommit);
        try {
            $payload = OCWebHookPayloadBuilder::buildMinimal($contentObject);
            OCWebHookEmitter::emit(
                PostPublishWebHookTrigger::IDENTIFIER,
                $payload,
                PostPublishWebHookTrigger::getQueueHandler()
            );
        } catch (Exception $e) {
            eZDebug::writeError($e->getMessage(), __METHOD__);
        }
        return $result;
    }

    public function removeObject($contentObject, $commit = null, $commitWithin = 0)
    {
        $result = parent::removeObject($contentObject, $commit, $commitWithin);
        try {
            $payload = OCWebHookPayloadBuilder::buildMinimal($contentObject);
            OCWebHookEmitter::emit(
                DeleteWebHookTrigger::IDENTIFIER,
                $payload,
                DeleteWebHookTrigger::getQueueHandler()
            );
        } catch (Exception $e) {
            eZDebug::writeError($e->getMessage(), __METHOD__);
        }
        return $result;
    }
}
```

- [ ] **Step 4: Esegui e verifica che passi**

```bash
php tests/SearchEngineEmitTest.php 2>&1
```

Atteso: `[PASS] SearchEngineEmitTest`

- [ ] **Step 5: Commit**

```bash
git add classes/ocsearchengine.php tests/SearchEngineEmitTest.php
git commit -m "feat: add OCSearchEngine — extends eZSolr, emits Kafka on addObject/removeObject"
```

---

## Task 3 — Configurazione e autoload

Registra `OCSearchEngine` come search engine attivo e aggiorna la mappa di autoload eZ.

**File:**
- Modificare: `settings/site.ini.append.php`

- [ ] **Step 1: Aggiungi la configurazione in `settings/site.ini.append.php`**

Aggiungi in fondo al file:

```ini
[SearchSettings]
SearchEngine=OCSearchEngine
```

Il blocco completo del file diventa:

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

- [ ] **Step 2: Rigenera la mappa autoload eZ dentro il container**

```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php -d memory_limit=256M \
  html/bin/php/ezpgenerateautoloads.php -e 2>&1); echo "$OUT"
```

Atteso: nessun errore, lista classi aggiornata.

- [ ] **Step 3: Verifica che eZ carichi la nuova classe**

```bash
OUT=$(docker exec cms-app-1 /usr/local/bin/php -r "
require 'html/autoload/ezp_extension.php';
\$e = eZSolrBase::getSearchEngine();
echo get_class(\$e) . PHP_EOL;
" 2>&1); echo "$OUT"
```

Atteso: `OCSearchEngine`

- [ ] **Step 4: Commit**

```bash
git add settings/site.ini.append.php
git commit -m "feat: configure OCSearchEngine as active search engine in site.ini"
```

---

## Task 4 — Verifica smoke test E2E

Pubblica un contenuto nel container e verifica che arrivi l'evento Kafka.

- [ ] **Step 1: Pubblica un contenuto via CLI e controlla Kafka**

```bash
# Pubblica un oggetto (usa emit_all_published su un solo oggetto come proxy)
OUT=$(docker exec cms-app-1 /usr/local/bin/php \
  extension/ocwebhookserver/bin/php/emit_all_published.php \
  --allow-root-user -sbackend --limit=1 2>&1); echo "$OUT"

# Controlla che sia arrivato su Kafka
OUT=$(docker exec cms-redpanda-1 /usr/bin/rpk topic consume cms \
  -X brokers=redpanda:9092 --offset end --num 1 2>&1); echo "$OUT"
```

Atteso: messaggio JSON con `entity.meta.type_id` valorizzato.

- [ ] **Step 2: Fai un hide da UI e controlla Kafka**

Dal backend eZ, nascondi un nodo. Poi:

```bash
OUT=$(docker exec cms-redpanda-1 /usr/bin/rpk topic consume cms \
  -X brokers=redpanda:9092 --offset end --num 1 2>&1); echo "$OUT"
```

Atteso: messaggio Kafka con `entity.meta.object_id` dell'oggetto nascosto.

- [ ] **Step 3: Commit finale se tutto ok**

```bash
git commit --allow-empty -m "chore: Piano C smoke test ok — OCSearchEngine attivo"
```
