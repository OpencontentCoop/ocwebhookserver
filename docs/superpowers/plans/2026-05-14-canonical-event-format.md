# Canonical Event Format Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allineare il formato degli eventi Kafka al formato canonico concordato: envelope `event` nel body, relation items con `{type_id, id: compound, object_id, title, api_url, priority}`, taxonomy/vocabulary items con `{id, title, priority, taxonomy}`, file items con `{id, title, filename, url, mime_type, size_bytes, md5}`, e nuovi campi `is_public`/`tree_placement`/`published_by`/`updated_by` in `entity.meta`.

**Architecture:** Il formatter (`OCWebHookKafkaPayloadFormatter`) gestisce tutta la normalizzazione del payload; il producer (`OCWebHookKafkaProducer`) aggiunge il wrapper `event` prima di serializzare. I due metodi statici di normalizzazione vengono specializzati in tre (`normalizeRelationItem`, `normalizeTaxonomyItem`, `normalizeFileItem`) e il routing avviene via closure in `format()` in base alla struttura dell'item. Nessuna dipendenza esterna aggiunta.

**Tech Stack:** PHP 7.4+, php-rdkafka, eZ Publish (workflow), PHPUnit-style custom test runner in `tests/run_tests.php`.

**Comandi di test:**
```bash
# Unit test formatter (no Kafka):
SKIP_KAFKA=1 php tests/run_tests.php

# O solo il file specifico:
php tests/PayloadFormatterTest.php

# Integration test producer (richiede Redpanda dentro Docker):
docker exec cms-app-1 php extension/ocwebhookserver/tests/KafkaProducerTest.php
```

---

## File toccati

| File | Operazione | Motivo |
|------|-----------|--------|
| `classes/ocwebhookkafkaproducer.php` | Modifica | Aggiunge envelope `event` nel body JSON |
| `classes/ocwebhookkafkapayloadformatter.php` | Modifica | Tutti i normalizer + routing + nuovi campi meta |
| `tests/PayloadFormatterTest.php` | Modifica | Aggiorna assert esistenti + nuovi test |
| `tests/KafkaProducerTest.php` | Modifica | Aggiunge assert su `event.*` nel body |

---

## Task 1: `event` envelope nel body del producer

**File:** `classes/ocwebhookkafkaproducer.php`  
**Test:** `tests/KafkaProducerTest.php`

Il corpo JSON del messaggio deve avere un campo `event` radice con `id`, `type`, `occurred_at`, `producer`, `version`. I campi sono già calcolati in `buildHeaders()` — vanno duplicati nel body.

- [ ] **Step 1: Scrivi il test che verifica `event.*` nel body**

In `tests/KafkaProducerTest.php`, in fondo alla sezione TEST 2 (dopo la riga `assert_eq($data['eng-GB']['author'], ...)`, circa riga 228), aggiungi:

```php
    // event envelope
    assert_true(isset($decoded['event']),                          'Payload top-level "event" key exists');
    assert_true(isset($decoded['event']['id']),                    'event.id present');
    assert_true(
        (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $decoded['event']['id'] ?? ''),
        'event.id is a UUID v4'
    );
    assert_eq(
        $decoded['event']['type'] ?? null,
        $headers['ce_type'] ?? null,
        'event.type matches ce_type header'
    );
    assert_eq(
        $decoded['event']['occurred_at'] ?? null,
        $headers['ce_time'] ?? null,
        'event.occurred_at matches ce_time header'
    );
    assert_eq(
        ($decoded['event']['producer']['app_id'] ?? null),
        'website-comuni',
        'event.producer.app_id set from AppName'
    );
    assert_eq(
        ($decoded['event']['producer']['app_version'] ?? null),
        '1.5.0',
        'event.producer.app_version set from AppVersion'
    );
    assert_eq(
        $decoded['event']['version'] ?? null,
        1,
        'event.version = 1 (schema version)'
    );
    // entity still present alongside event
    assert_true(isset($decoded['entity']), 'entity key still present alongside event');
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
docker exec cms-app-1 php extension/ocwebhookserver/tests/KafkaProducerTest.php 2>&1 | grep -E "PASS|FAIL|event"
```

Atteso: `[FAIL] Payload top-level "event" key exists`

- [ ] **Step 3: Implementa l'envelope in `produce()`**

In `classes/ocwebhookkafkaproducer.php`, in `produce()`, sostituisci la riga:

```php
            $topic->producev(
                RD_KAFKA_PARTITION_UA,
                0,
                json_encode($payload),
                $messageKey,
                $this->buildHeaders($triggerIdentifier, $payload, $retryCount)
            );
```

con:

```php
            $headers = $this->buildHeaders($triggerIdentifier, $payload, $retryCount);
            $body    = array_merge(
                [
                    'event' => [
                        'id'          => $headers['ce_id'],
                        'type'        => $headers['ce_type'],
                        'occurred_at' => $headers['ce_time'],
                        'producer'    => [
                            'app_id'      => $this->appName,
                            'app_version' => $this->appVersion,
                        ],
                        'version' => 1,
                    ],
                ],
                $payload
            );
            $topic->producev(
                RD_KAFKA_PARTITION_UA,
                0,
                json_encode($body),
                $messageKey,
                $headers
            );
```

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
docker exec cms-app-1 php extension/ocwebhookserver/tests/KafkaProducerTest.php 2>&1 | tail -5
```

Atteso: tutti i test passano.

- [ ] **Step 5: Commit**

```bash
git add classes/ocwebhookkafkaproducer.php tests/KafkaProducerTest.php
git commit -m "feat: add event envelope to Kafka message body

Duplicates ce_id/ce_type/ce_time/app_name/app_version from CloudEvents
headers into a top-level 'event' object in the JSON body, so consumers
that don't read Kafka headers can still access event metadata."
```

---

## Task 2: Nuovi campi `entity.meta` — `is_public`, `tree_placement`, `published_by`, `updated_by`

**File:** `classes/ocwebhookkafkapayloadformatter.php`  
**Test:** `tests/PayloadFormatterTest.php`

Rinomina `created_by` → `published_by` (estraendo `login` string), `modified_by` → `updated_by` (idem), e aggiunge `is_public` e `tree_placement` da ocopendata metadata.

- [ ] **Step 1: Scrivi i test che verificano i nuovi campi e il rename**

Aggiungi in coda a `tests/PayloadFormatterTest.php`, prima del blocco `Results`:

```php
// ─────────────────────────────────────────────────────────────────────────────
// TEST 12: entity.meta — published_by, updated_by, is_public, tree_placement
// ─────────────────────────────────────────────────────────────────────────────

$payloadMetaNew = [
    'metadata' => [
        'id'                  => '200',
        'languages'           => ['it-IT'],
        'name'                => ['it-IT' => 'Test'],
        'createdBy'           => ['id' => 14, 'login' => 'admin', 'name' => 'Administrator'],
        'modifiedBy'          => ['id' => 55, 'login' => 'editor01', 'name' => 'Mario Rossi'],
        'isPublic'            => true,
        'mainParentRemoteId'  => 'trasparenza',
        'parentRemoteIds'     => ['trasparenza'],
    ],
    'data' => [],
];

$fmMeta = new OCWebHookKafkaPayloadFormatter('frontend', 'opencity');
$resMeta = $fmMeta->format($payloadMetaNew);
$metaN   = $resMeta['entity']['meta'];

assert_eq($metaN['published_by'], 'admin',    'published_by = createdBy.login string');
assert_eq($metaN['updated_by'],   'editor01', 'updated_by = modifiedBy.login string');
assert_true($metaN['is_public'] === true,     'is_public = true (bool) from metadata.isPublic');
assert_eq(
    $metaN['tree_placement'],
    ['main_parent_remote_id' => 'trasparenza', 'parent_remote_ids' => ['trasparenza']],
    'tree_placement built from mainParentRemoteId + parentRemoteIds'
);
assert_true(!isset($metaN['created_by']),  'created_by field removed (replaced by published_by)');
assert_true(!isset($metaN['modified_by']), 'modified_by field removed (replaced by updated_by)');

// null passthrough
$payloadMetaNull = [
    'metadata' => ['id' => '201', 'languages' => ['it-IT'], 'name' => ['it-IT' => 'X'],
                   'createdBy' => null, 'modifiedBy' => null],
    'data' => [],
];
$resMetaNull = $fmMeta->format($payloadMetaNull);
$metaNull    = $resMetaNull['entity']['meta'];
assert_null($metaNull['published_by'],   'published_by null when createdBy is null');
assert_null($metaNull['updated_by'],     'updated_by null when modifiedBy is null');
assert_null($metaNull['is_public'],      'is_public null when isPublic not in metadata');
assert_null($metaNull['tree_placement'], 'tree_placement null when mainParentRemoteId missing');
```

- [ ] **Step 2: Esegui e verifica che il test fallisca**

```bash
php tests/PayloadFormatterTest.php 2>&1 | tail -8
```

Atteso: `[FAIL] published_by = createdBy.login string`

- [ ] **Step 3: Aggiorna il blocco `$meta` in `format()` in `ocwebhookkafkapayloadformatter.php`**

Sostituisci il blocco `$meta = [...]` (righe 75–94) con:

```php
        $meta = [
            'id'             => $this->instanceId . ':' . $objectId,
            'tenant_id'      => $this->tenantId,
            'siteaccess'     => $this->siteaccess,
            'object_id'      => $objectId,
            'remote_id'      => isset($metadata['remoteId'])         ? $metadata['remoteId']        : null,
            'type_id'        => isset($metadata['classIdentifier'])  ? $metadata['classIdentifier'] : null,
            'version'        => isset($metadata['currentVersion'])   ? (int)$metadata['currentVersion'] : null,
            'languages'      => $languages,
            'name'           => $name,
            'site_url'       => isset($metadata['baseUrl'])          ? $metadata['baseUrl']         : null,
            'content_url'    => isset($metadata['contentUrl'])       ? $metadata['contentUrl']      : null,
            'api_url'        => isset($metadata['apiUrl'])           ? $metadata['apiUrl']          : null,
            'is_public'      => isset($metadata['isPublic'])         ? (bool)$metadata['isPublic']  : null,
            'tree_placement' => isset($metadata['mainParentRemoteId']) ? [
                'main_parent_remote_id' => $metadata['mainParentRemoteId'],
                'parent_remote_ids'     => isset($metadata['parentRemoteIds'])
                    ? array_values((array)$metadata['parentRemoteIds']) : [],
            ] : null,
            'published_at'   => isset($metadata['published']) && $metadata['published'] !== null
                ? gmdate('Y-m-d\TH:i:s\Z', self::toTimestamp($metadata['published'])) : null,
            'updated_at'     => isset($metadata['modified'])  && $metadata['modified']  !== null
                ? gmdate('Y-m-d\TH:i:s\Z', self::toTimestamp($metadata['modified']))  : null,
            'published_by'   => isset($metadata['createdBy']['login'])  ? $metadata['createdBy']['login']  : null,
            'updated_by'     => isset($metadata['modifiedBy']['login']) ? $metadata['modifiedBy']['login'] : null,
        ];
```

- [ ] **Step 4: Aggiorna il test 10 esistente** (che ora fallisce perché `created_by`/`modified_by` non esistono più)

In `tests/PayloadFormatterTest.php`, sostituisci l'intero blocco TEST 10 (righe ~395–421) con:

```php
// ─────────────────────────────────────────────────────────────────────────────
// TEST 10: published_by and updated_by mapped from metadata (login string extracted)
// ─────────────────────────────────────────────────────────────────────────────

$payloadWithUsers = [
    'metadata' => [
        'id'        => '99',
        'languages' => ['it-IT'],
        'name'      => ['it-IT' => 'Test'],
        'createdBy'  => ['id' => 14, 'login' => 'admin',    'name' => 'Administrator'],
        'modifiedBy' => ['id' => 55, 'login' => 'editor01', 'name' => 'Mario Rossi'],
    ],
    'data' => [],
];

$formatter8 = new OCWebHookKafkaPayloadFormatter('frontend', 'comune');
$result8    = $formatter8->format($payloadWithUsers);
$meta8      = $result8['entity']['meta'];

assert_eq($meta8['published_by'], 'admin',    'published_by = createdBy.login');
assert_eq($meta8['updated_by'],   'editor01', 'updated_by = modifiedBy.login');
assert_true(!isset($meta8['created_by']),  'created_by removed');
assert_true(!isset($meta8['modified_by']), 'modified_by removed');

// null passes through
$payloadNoUsers = [
    'metadata' => ['id' => '100', 'languages' => ['it-IT'], 'name' => ['it-IT' => 'X'],
                   'createdBy' => null, 'modifiedBy' => null],
    'data' => [],
];
$result9 = $formatter8->format($payloadNoUsers);
assert_null($result9['entity']['meta']['published_by'], 'published_by null when createdBy is null');
assert_null($result9['entity']['meta']['updated_by'],   'updated_by null when modifiedBy is null');
```

- [ ] **Step 5: Esegui e verifica che tutti i test passino**

```bash
SKIP_KAFKA=1 php tests/run_tests.php 2>&1 | tail -8
```

Atteso: tutti i test passano.

- [ ] **Step 6: Commit**

```bash
git add classes/ocwebhookkafkapayloadformatter.php tests/PayloadFormatterTest.php
git commit -m "feat: add is_public, tree_placement, published_by, updated_by to entity.meta

Renames created_by/modified_by to published_by/updated_by extracting the
login string from the {id,login,name} object. Adds is_public (bool) and
tree_placement (main_parent_remote_id + parent_remote_ids) from ocopendata
metadata fields isPublic/mainParentRemoteId/parentRemoteIds."
```

---

## Task 3: Relation item normalizer — `type_id`, compound `id`, `object_id`, `title`

**File:** `classes/ocwebhookkafkapayloadformatter.php`  
**Test:** `tests/PayloadFormatterTest.php`

Cambia `normalizeRelationItem` da metodo con output `{id, remote_id, class_identifier, name, main_node_id}` a `{type_id, id: compound, object_id, remote_id, title, api_url, priority}`. Richiede passare `$instanceId` via closure invece di `array_map` statico.

- [ ] **Step 1: Scrivi il test aggiornato per relation items**

In `tests/PayloadFormatterTest.php` sostituisci il blocco TEST 7 (righe ~213–301) con:

```php
// ─────────────────────────────────────────────────────────────────────────────
// TEST 7: relation items — tipo, compound id, object_id, title, api_url, priority
// ─────────────────────────────────────────────────────────────────────────────

$dropPayload = [
    'metadata' => ['id' => '50', 'classIdentifier' => 'article', 'languages' => ['it-IT']],
    'data' => [
        'it-IT' => [
            'attachments' => ['content' => [
                ['id' => 1, 'remoteId' => 'file-abc-123', 'classIdentifier' => 'file',
                 'mainNodeId' => '210', 'name' => 'Relazione annuale.pdf',
                 'class' => 'file', 'languages' => ['it-IT'], 'link' => 'read/210',
                 'api_url'  => 'https://www.comune.example.it/allegati/relazione-annuale',
                 'priority' => 1],
                ['id' => 2, 'remoteId' => 'file-def-456', 'classIdentifier' => 'file',
                 'mainNodeId' => '211', 'name' => 'Bilancio.pdf',
                 'class' => 'file', 'languages' => ['it-IT'], 'link' => 'read/211',
                 'priority' => 2],
            ], 'type' => 'ezobjectrelationlist'],
            'topics' => ['content' => [
                ['id' => 101, 'remote_id' => 'topic-xyz', 'class_identifier' => 'tag',
                 'main_node_id' => '501', 'class' => 'tag', 'languages' => ['it-IT'],
                 'link' => 'read/101'],
            ], 'type' => 'eztags'],
            'files'    => ['content' => null, 'type' => 'ezobjectrelationlist'],
            'subtitle' => null,
            'title'    => ['content' => 'Titolo', 'type' => 'ezstring'],
        ],
    ],
];
$formatter4 = new OCWebHookKafkaPayloadFormatter('frontend', 'bugliano');
$result4    = $formatter4->format($dropPayload);
$data4      = $result4['entity']['data']['it-IT'];

assert_eq($data4['files'],    [], 'Null content normalizzato a []');
assert_null($data4['subtitle'],  'Null grezzo preservato come null');
assert_eq($data4['title'],  'Titolo', 'Campo testo estratto correttamente');

// Relation items: struttura canonica
assert_eq(count($data4['attachments']), 2, 'Relation list: 2 item preservati');

$att0 = $data4['attachments'][0];
assert_eq($att0['type_id'],   'file',         'type_id = classIdentifier');
assert_eq($att0['id'],        'bugliano:1',   'id = instanceId:objectId (compound)');
assert_eq($att0['object_id'], '1',            'object_id = string original id');
assert_eq($att0['remote_id'], 'file-abc-123', 'remote_id rinominato da remoteId');
assert_eq($att0['title'],     'Relazione annuale.pdf', 'title rinominato da name');
assert_eq($att0['api_url'],   'https://www.comune.example.it/allegati/relazione-annuale', 'api_url pass-through');
assert_eq((int)$att0['priority'], 1,          'priority pass-through');

assert_false(isset($att0['name']),            'name rimosso (rinominato title)');
assert_false(isset($att0['class_identifier']),'class_identifier rimosso (rinominato type_id)');
assert_false(isset($att0['classIdentifier']), 'classIdentifier camelCase rimosso');
assert_false(isset($att0['main_node_id']),    'main_node_id rimosso (non nel formato target)');
assert_false(isset($att0['mainNodeId']),      'mainNodeId camelCase rimosso');
assert_false(isset($att0['class']),           'class eliminato (duplicato)');
assert_false(isset($att0['languages']),       'languages eliminato');
assert_false(isset($att0['link']),            'link eliminato');

$att1 = $data4['attachments'][1];
assert_eq($att1['id'],        'bugliano:2',   'Secondo item: compound id corretto');
assert_eq($att1['remote_id'], 'file-def-456', 'Secondo item: remote_id');
assert_eq((int)$att1['priority'], 2,          'Secondo item: priority');

// topics con snake_case già normalizzato (class_identifier invece di classIdentifier)
$top0 = $data4['topics'][0];
assert_eq($top0['type_id'],   'tag',        'topics: type_id da class_identifier');
assert_eq($top0['id'],        'bugliano:101', 'topics: id compound');
assert_eq($top0['object_id'], '101',         'topics: object_id');
assert_eq($top0['remote_id'], 'topic-xyz',  'topics: remote_id pass-through');
assert_false(isset($top0['class']),          'topics: class eliminato');
assert_false(isset($top0['languages']),      'topics: languages eliminato');
assert_false(isset($top0['link']),           'topics: link eliminato');
```

- [ ] **Step 2: Esegui e verifica che i nuovi assert falliscano**

```bash
php tests/PayloadFormatterTest.php 2>&1 | grep -E "FAIL" | head -5
```

Atteso: `[FAIL] type_id = classIdentifier`, `[FAIL] id = instanceId:objectId (compound)`, ecc.

- [ ] **Step 3: Aggiorna `normalizeRelationItem` e la chiamata in `format()`**

**In `ocwebhookkafkapayloadformatter.php`**, sostituisci il blocco che chiama `normalizeRelationItem` (riga ~112–114):

```php
                    // Normalize camelCase keys in relation item lists
                    if (is_array($content) && isset($content[0]) && is_array($content[0])) {
                        $content = array_map(['OCWebHookKafkaPayloadFormatter', 'normalizeRelationItem'], $content);
                    }
```

con:

```php
                    // Normalize item lists: route to the correct normalizer
                    if (is_array($content) && isset($content[0]) && is_array($content[0])) {
                        $instanceId = $this->instanceId;
                        $content = array_map(
                            function ($item) use ($instanceId) {
                                return OCWebHookKafkaPayloadFormatter::normalizeRelationItem($item, $instanceId);
                            },
                            $content
                        );
                    }
```

Poi sostituisci l'intero metodo `normalizeRelationItem` (righe ~236–252) con:

```php
    /**
     * Normalize a relation item (ezobjectrelationlist / ezobjectrelation).
     * Detection: item has 'classIdentifier' or 'class_identifier'.
     *
     * Output: {type_id, id: "instanceId:objectId", object_id, remote_id, title, api_url, priority}
     * Dropped: class, classIdentifier, class_identifier, languages, link, main_node_id, mainNodeId, name
     *
     * @param array  $item
     * @param string $instanceId  e.g. "bugliano" — prefixed to object id
     * @return array
     */
    private static function normalizeRelationItem(array $item, $instanceId = '')
    {
        $classId  = isset($item['classIdentifier']) ? $item['classIdentifier']
                  : (isset($item['class_identifier']) ? $item['class_identifier'] : null);
        $rawId    = isset($item['id']) ? $item['id'] : null;
        $remoteId = isset($item['remoteId']) ? $item['remoteId']
                  : (isset($item['remote_id']) ? $item['remote_id'] : null);
        $title    = isset($item['name']) ? $item['name'] : null;

        $result = [
            'type_id'   => $classId,
            'id'        => $instanceId . ':' . $rawId,
            'object_id' => $rawId !== null ? (string)$rawId : null,
            'remote_id' => $remoteId,
            'title'     => $title,
        ];

        // Pass-through optional fields
        if (isset($item['api_url'])) {
            $result['api_url'] = $item['api_url'];
        }
        if (isset($item['priority'])) {
            $result['priority'] = (int)$item['priority'];
        }

        // Pass-through any remaining unknown fields (forward-compat)
        static $skip = [
            'id' => true, 'remoteId' => true, 'remote_id' => true,
            'classIdentifier' => true, 'class_identifier' => true,
            'name' => true, 'class' => true, 'languages' => true,
            'link' => true, 'mainNodeId' => true, 'main_node_id' => true,
            'api_url' => true, 'priority' => true,
        ];
        foreach ($item as $key => $value) {
            if (!isset($skip[$key]) && !isset($result[$key])) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
```

- [ ] **Step 4: Aggiorna il test 11** (multilang — usa `title` non `name`)

In `tests/PayloadFormatterTest.php`, sostituisci le tre righe di assert in TEST 11 (circa righe 462–484) che verificano `->topics[0]['name']` e `->author[0]['name']`:

```php
// eng-GB section: title resolved to English
assert_eq(
    $resultML['entity']['data']['eng-GB']['topics'][0]['title'],
    'Innovation',
    'Relation item title resolved to eng-GB in eng-GB section'
);
// ita-IT section: title resolved to Italian
assert_eq(
    $resultML['entity']['data']['ita-IT']['topics'][0]['title'],
    'Innovazione',
    'Relation item title resolved to ita-IT in ita-IT section'
);
// "languages" inside relation item is dropped by normalizeRelationItem
assert_false(
    isset($resultML['entity']['data']['eng-GB']['topics'][0]['languages']),
    '"languages" dropped from relation items by normalizeRelationItem'
);
// Fallback: name only in ita-IT, requested lang is eng-GB → returns ita-IT value
assert_eq(
    $resultML['entity']['data']['eng-GB']['author'][0]['title'],
    'Ufficio anagrafe',
    'Multi-lang map with missing eng-GB falls back to first available language'
);
```

- [ ] **Step 5: Esegui e verifica che tutti i test passino**

```bash
SKIP_KAFKA=1 php tests/run_tests.php 2>&1 | tail -5
```

Atteso: `✓ All test suites passed`

- [ ] **Step 6: Commit**

```bash
git add classes/ocwebhookkafkapayloadformatter.php tests/PayloadFormatterTest.php
git commit -m "feat: normalize relation items to canonical format

normalizeRelationItem now produces {type_id, id: 'instanceId:objectId',
object_id, remote_id, title, api_url, priority} instead of
{id, remote_id, class_identifier, name, main_node_id}. Requires instanceId
passed via closure in format(). Renames name→title, classIdentifier→type_id,
drops main_node_id, adds compound id and object_id."
```

---

## Task 4: Taxonomy/vocabulary item normalizer

**File:** `classes/ocwebhookkafkapayloadformatter.php`  
**Test:** `tests/PayloadFormatterTest.php`

Aggiunge `normalizeTaxonomyItem()` per vocabulary items: `{id, title, priority, [code,] taxonomy: {id, api_url}}`. Il metodo costruisce `taxonomy.api_url` da `site_url` + derivazione del nome vocabolario, oppure usa il campo `taxonomy` già presente nell'item se fornito da ocopendata.

- [ ] **Step 1: Scrivi il test**

Aggiungi in coda a `tests/PayloadFormatterTest.php`, prima del blocco Results:

```php
// ─────────────────────────────────────────────────────────────────────────────
// TEST 14: normalizeTaxonomyItem — vocabulary items
// ─────────────────────────────────────────────────────────────────────────────

$payloadTaxonomy = [
    'metadata' => [
        'id' => '300', 'classIdentifier' => 'document', 'languages' => ['it-IT'],
        'baseUrl' => 'https://www.comune.example.it',
    ],
    'data' => [
        'it-IT' => [
            // taxonomy item con vocabulary_id (ocopendata lo fornisce)
            'licenses' => ['content' => [
                ['id' => 'open_license', 'name' => ['it-IT' => 'Licenza aperta'], 'priority' => 1,
                 'vocabulary_id' => 'vocabulary_licenses'],
            ], 'type' => 'eztags'],
            // taxonomy item con taxonomy già presente (pass-through)
            'formats' => ['content' => [
                ['id' => 'pdf', 'name' => ['it-IT' => 'PDF'], 'priority' => 1,
                 'taxonomy' => ['id' => 'vocabulary_formats',
                                'api_url' => 'https://www.comune.example.it/api/openapi/vocabularies/formats']],
            ], 'type' => 'eztags'],
            // taxonomy item con code extra (es. spatial_coverage)
            'has_spatial_coverage' => ['content' => [
                ['id' => '069001', 'code' => '069001', 'name' => ['it-IT' => 'Altino'], 'priority' => 1,
                 'vocabulary_id' => 'vocabulary_spatial_coverage'],
            ], 'type' => 'eztags'],
        ],
    ],
];

$fmTax   = new OCWebHookKafkaPayloadFormatter('frontend', 'opencity');
$resTax  = $fmTax->format($payloadTaxonomy);
$dataTax = $resTax['entity']['data']['it-IT'];

// licenses: vocabulary_id present → taxonomy constructed
$lic = $dataTax['licenses'][0];
assert_eq($lic['id'],    'open_license',  'taxonomy item: id preserved');
assert_eq($lic['title'], 'Licenza aperta', 'taxonomy item: name→title resolved');
assert_eq((int)$lic['priority'], 1,       'taxonomy item: priority');
assert_eq($lic['taxonomy']['id'], 'vocabulary_licenses', 'taxonomy.id from vocabulary_id');
assert_eq(
    $lic['taxonomy']['api_url'],
    'https://www.comune.example.it/api/openapi/vocabularies/licenses',
    'taxonomy.api_url derived from site_url + vocabulary_id'
);
assert_false(isset($lic['name']),          'name removed from taxonomy item');
assert_false(isset($lic['vocabulary_id']), 'vocabulary_id removed after building taxonomy');

// formats: taxonomy already present → pass-through unchanged
$fmt = $dataTax['formats'][0];
assert_eq($fmt['taxonomy']['id'],      'vocabulary_formats', 'taxonomy pass-through: id');
assert_eq($fmt['taxonomy']['api_url'], 'https://www.comune.example.it/api/openapi/vocabularies/formats',
    'taxonomy pass-through: api_url');

// has_spatial_coverage: extra "code" field preserved
$spa = $dataTax['has_spatial_coverage'][0];
assert_eq($spa['id'],   '069001',  'spatial_coverage: id');
assert_eq($spa['code'], '069001',  'spatial_coverage: code extra field preserved');
assert_eq($spa['title'], 'Altino', 'spatial_coverage: title');
assert_eq($spa['taxonomy']['id'], 'vocabulary_spatial_coverage', 'spatial_coverage: taxonomy.id');
assert_eq(
    $spa['taxonomy']['api_url'],
    'https://www.comune.example.it/api/openapi/vocabularies/spatial-coverage',
    'spatial_coverage: taxonomy.api_url (underscore→hyphen in URL)'
);
```

- [ ] **Step 2: Esegui e verifica che il test fallisca**

```bash
php tests/PayloadFormatterTest.php 2>&1 | grep "FAIL" | head -3
```

Atteso: `[FAIL] taxonomy item: name→title resolved`

- [ ] **Step 3: Aggiungi il metodo `normalizeTaxonomyItem`**

Aggiungi in `classes/ocwebhookkafkapayloadformatter.php`, dopo il metodo `normalizeRelationItem`:

```php
    /**
     * Normalize a vocabulary/taxonomy item (eztags, enum vocabulary types).
     * Detection: item has no 'classIdentifier'/'class_identifier' and no 'filename'/'mime_type'.
     *
     * Output: {id, title, priority, [code,] taxonomy: {id, api_url}}
     *
     * taxonomy is built from:
     *   1. $item['taxonomy']         — already present (pass-through, ocopendata may provide it)
     *   2. $item['vocabulary_id']    — e.g. "vocabulary_licenses" → constructs api_url from $siteUrl
     *
     * @param array       $item
     * @param string|null $siteUrl  e.g. "https://www.comune.example.it" (from entity.meta.site_url)
     * @return array
     */
    private static function normalizeTaxonomyItem(array $item, $siteUrl = null)
    {
        $result = [
            'id'    => isset($item['id']) ? $item['id'] : null,
            'title' => isset($item['name']) ? $item['name'] : null,
        ];

        if (isset($item['priority'])) {
            $result['priority'] = (int)$item['priority'];
        }

        // Preserve extra fields (e.g. "code" in spatial_coverage)
        static $skip = ['id' => true, 'name' => true, 'priority' => true,
                        'taxonomy' => true, 'vocabulary_id' => true];
        foreach ($item as $key => $value) {
            if (!isset($skip[$key])) {
                $result[$key] = $value;
            }
        }

        // Build taxonomy sub-object
        if (isset($item['taxonomy'])) {
            $result['taxonomy'] = $item['taxonomy'];
        } elseif (isset($item['vocabulary_id']) && $siteUrl !== null) {
            $vocId   = $item['vocabulary_id'];
            $vocSlug = str_replace('_', '-', str_replace('vocabulary_', '', $vocId));
            $result['taxonomy'] = [
                'id'      => $vocId,
                'api_url' => rtrim($siteUrl, '/') . '/api/openapi/vocabularies/' . $vocSlug,
            ];
        } else {
            $result['taxonomy'] = null;
        }

        return $result;
    }
```

- [ ] **Step 4: Esegui e verifica che il test fallisca ancora** (il metodo esiste ma non è ancora chiamato dal routing)

```bash
php tests/PayloadFormatterTest.php 2>&1 | grep -E "FAIL|taxonomy item" | head -5
```

Atteso: ancora `[FAIL]` perché il routing in `format()` chiama ancora solo `normalizeRelationItem`.

- [ ] **Step 5: Aggiorna il routing in `format()` per chiamare `normalizeTaxonomyItem`**

In `ocwebhookkafkapayloadformatter.php`, la closure nella sezione "Normalize item lists" (aggiornata nel Task 3) va estesa per includere taxonomy e file items. Sostituisci:

```php
                    // Normalize item lists: route to the correct normalizer
                    if (is_array($content) && isset($content[0]) && is_array($content[0])) {
                        $instanceId = $this->instanceId;
                        $content = array_map(
                            function ($item) use ($instanceId) {
                                return OCWebHookKafkaPayloadFormatter::normalizeRelationItem($item, $instanceId);
                            },
                            $content
                        );
                    }
```

con:

```php
                    // Normalize item lists: route to the correct normalizer by item structure
                    if (is_array($content) && isset($content[0]) && is_array($content[0])) {
                        $instanceId = $this->instanceId;
                        $siteUrl    = $meta['site_url'];
                        $content = array_map(
                            function ($item) use ($instanceId, $siteUrl) {
                                if (isset($item['classIdentifier']) || isset($item['class_identifier'])) {
                                    return OCWebHookKafkaPayloadFormatter::normalizeRelationItem($item, $instanceId);
                                }
                                if (isset($item['filename']) || isset($item['mime_type'])) {
                                    return OCWebHookKafkaPayloadFormatter::normalizeFileItem($item);
                                }
                                return OCWebHookKafkaPayloadFormatter::normalizeTaxonomyItem($item, $siteUrl);
                            },
                            $content
                        );
                    }
```

**Nota**: `$meta['site_url']` è già calcolato prima del loop `foreach ($rawData ...)`, quindi è accessibile.

- [ ] **Step 6: Esegui e verifica che i test passino**

```bash
SKIP_KAFKA=1 php tests/run_tests.php 2>&1 | tail -5
```

Atteso: `✓ All test suites passed`

- [ ] **Step 7: Commit**

```bash
git add classes/ocwebhookkafkapayloadformatter.php tests/PayloadFormatterTest.php
git commit -m "feat: add taxonomy/vocabulary item normalizer

normalizeTaxonomyItem produces {id, title, priority, taxonomy: {id, api_url}}.
taxonomy.api_url is derived from site_url + vocabulary_id (underscore→hyphen).
Routing in format() dispatches to the correct normalizer based on item shape:
classIdentifier→relation, filename/mime_type→file, else→taxonomy."
```

---

## Task 5: File item normalizer — binary file items in liste e `primary_attachment` singolo

**File:** `classes/ocwebhookkafkapayloadformatter.php`  
**Test:** `tests/PayloadFormatterTest.php`

Aggiunge `normalizeFileItem()` per file item in liste (es. `terms_of_service`) e gestisce il caso single-object (es. `primary_attachment` — oggetto non-lista con `filename`/`mime_type`).

- [ ] **Step 1: Scrivi il test**

Aggiungi in coda a `tests/PayloadFormatterTest.php`, prima del blocco Results:

```php
// ─────────────────────────────────────────────────────────────────────────────
// TEST 15: normalizeFileItem — file items in lista e primary_attachment singolo
// ─────────────────────────────────────────────────────────────────────────────

$payloadFiles = [
    'metadata' => ['id' => '400', 'classIdentifier' => 'public_service', 'languages' => ['it-IT']],
    'data' => [
        'it-IT' => [
            // lista di file (es. terms_of_service)
            'terms_of_service' => ['content' => [
                ['id' => '31622', 'title' => 'Termini e condizioni', 'name' => 'Termini e condizioni',
                 'filename' => 'Termini.pdf',
                 'url'      => 'https://www.comune.example.it/content/download/31622/1/file.pdf',
                 'mime_type' => 'application/pdf', 'size_bytes' => 184320, 'md5' => 'abc123', 'priority' => 1],
            ], 'type' => 'ezmultibinary'],
            // singolo file object non-lista (primary_attachment)
            'primary_attachment' => ['content' => [
                'id' => 'file_111_222_3', 'title' => 'Delibera.pdf', 'filename' => 'Delibera.pdf',
                'url' => 'https://www.comune.example.it/content/download/111/222/3/Delibera.pdf',
                'mime_type' => 'application/pdf', 'size_bytes' => 245760, 'md5' => 'def456',
            ], 'type' => 'ezbinaryfile'],
        ],
    ],
];

$fmFile   = new OCWebHookKafkaPayloadFormatter('frontend', 'opencity');
$resFile  = $fmFile->format($payloadFiles);
$dataFile = $resFile['entity']['data']['it-IT'];

// terms_of_service: lista di file
$tos = $dataFile['terms_of_service'][0];
assert_eq($tos['id'],         '31622',                'file item: id string');
assert_eq($tos['title'],      'Termini e condizioni', 'file item: title');
assert_eq($tos['filename'],   'Termini.pdf',          'file item: filename');
assert_eq(
    $tos['url'],
    'https://www.comune.example.it/content/download/31622/1/file.pdf',
    'file item: url'
);
assert_eq($tos['mime_type'],   'application/pdf', 'file item: mime_type');
assert_eq((int)$tos['size_bytes'], 184320,         'file item: size_bytes int');
assert_eq($tos['md5'],         'abc123',           'file item: md5');
assert_eq((int)$tos['priority'], 1,                'file item: priority');
assert_false(isset($tos['name']),                  'file item: name removed (kept as title)');

// primary_attachment: oggetto singolo
$pa = $dataFile['primary_attachment'];
assert_true(is_array($pa) && !isset($pa[0]), 'primary_attachment è un oggetto (non lista)');
assert_eq($pa['id'],       'file_111_222_3', 'primary_attachment: id');
assert_eq($pa['title'],    'Delibera.pdf',   'primary_attachment: title');
assert_eq($pa['filename'], 'Delibera.pdf',   'primary_attachment: filename');
assert_eq($pa['mime_type'],'application/pdf','primary_attachment: mime_type');
assert_eq((int)$pa['size_bytes'], 245760,    'primary_attachment: size_bytes');
assert_eq($pa['md5'],      'def456',         'primary_attachment: md5');
```

- [ ] **Step 2: Esegui e verifica che il test fallisca**

```bash
php tests/PayloadFormatterTest.php 2>&1 | grep "FAIL" | head -3
```

Atteso: `[FAIL] file item: title`

- [ ] **Step 3: Aggiungi `normalizeFileItem()` al formatter**

Aggiungi in `classes/ocwebhookkafkapayloadformatter.php`, dopo `normalizeTaxonomyItem`:

```php
    /**
     * Normalize a binary file item (ezbinaryfile, ezmedia, ezmultibinary).
     * Detection: item has 'filename' or 'mime_type'.
     *
     * Output: {id, title, filename, url, mime_type, size_bytes, md5, [priority]}
     * Dropped: name (merged into title), other internal eZ fields.
     *
     * @param array $item
     * @return array
     */
    private static function normalizeFileItem(array $item)
    {
        return array_filter([
            'id'         => isset($item['id'])         ? (string)$item['id']         : null,
            'title'      => isset($item['title'])       ? $item['title']
                          : (isset($item['name'])       ? $item['name']               : null),
            'filename'   => isset($item['filename'])   ? $item['filename']            : null,
            'url'        => isset($item['url'])         ? $item['url']
                          : (isset($item['content_url']) ? $item['content_url']       : null),
            'mime_type'  => isset($item['mime_type'])  ? $item['mime_type']           : null,
            'size_bytes' => isset($item['size_bytes']) ? (int)$item['size_bytes']
                          : (isset($item['size'])       ? (int)$item['size']          : null),
            'md5'        => isset($item['md5'])        ? $item['md5']                 : null,
            'priority'   => isset($item['priority'])   ? (int)$item['priority']       : null,
        ], function ($v) { return $v !== null; });
    }
```

- [ ] **Step 4: Aggiungi il rilevamento del single-object file in `format()`**

Nella sezione data loop di `format()`, aggiungi il blocco per single-object file **prima** del blocco list-normalization:

```php
                    // Single-object file (e.g. primary_attachment): associative array with
                    // filename or mime_type but no integer key 0
                    if (is_array($content) && !isset($content[0])
                        && (isset($content['filename']) || isset($content['mime_type']))) {
                        $content = OCWebHookKafkaPayloadFormatter::normalizeFileItem($content);
                    }
```

- [ ] **Step 5: Esegui e verifica che tutti i test passino**

```bash
SKIP_KAFKA=1 php tests/run_tests.php 2>&1 | tail -5
```

Atteso: `✓ All test suites passed`

- [ ] **Step 6: Commit**

```bash
git add classes/ocwebhookkafkapayloadformatter.php tests/PayloadFormatterTest.php
git commit -m "feat: add file item normalizer for binary files and primary_attachment

normalizeFileItem produces {id, title, filename, url, mime_type, size_bytes, md5, priority}.
Handles both list-of-files (terms_of_service) routed via the existing
item-type routing closure, and single-object binary files (primary_attachment)
detected by presence of filename/mime_type without integer key 0."
```

---

## Task 6: Verifica finale e cleanup

- [ ] **Step 1: Esegui l'intera suite unit**

```bash
SKIP_KAFKA=1 php tests/run_tests.php
```

Atteso: `✓ All test suites passed`, `Tests passed: 100%`

- [ ] **Step 2: Esegui l'intera suite integration (dentro Docker)**

```bash
OUT=$(docker exec cms-app-1 php extension/ocwebhookserver/tests/run_tests.php 2>&1); echo "$OUT"
```

Atteso: `✓ All test suites passed`

- [ ] **Step 3: Verifica il formato reale con un publish E2E**

```bash
OUT=$(docker exec cms-app-1 php extension/ocwebhookserver/tests/e2e_documenti.php 2>&1); echo "$OUT"
```

Controlla nel messaggio Kafka prodotto:
- `event.id`, `event.type`, `event.occurred_at`, `event.producer`, `event.version` presenti
- `entity.meta.is_public`, `entity.meta.tree_placement` presenti (se ocopendata li fornisce)
- `entity.meta.published_by`, `entity.meta.updated_by` stringhe (non oggetti)
- `entity.data.it-IT.topics[0].type_id`, `.id` compound, `.object_id`, `.title` presenti
- `entity.data.it-IT.document_types[0].taxonomy.id` e `.api_url` presenti

- [ ] **Step 4: Aggiorna CLAUDE.md — sezione "Formato evento Kafka (CloudEvents)"**

Aggiungi la sezione sull'`event` envelope e aggiorna la tabella payload per riflettere:
- Top-level `event` object nel body JSON
- `published_by`/`updated_by` al posto di `created_by`/`modified_by`
- `is_public`, `tree_placement` in `entity.meta`
- Due tipi di item: relation items e taxonomy items

- [ ] **Step 5: Commit finale**

```bash
git add CLAUDE.md
git commit -m "docs: update CLAUDE.md with canonical event format details"
```
