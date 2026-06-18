<?php

/**
 * Unit tests for OCWebHookKafkaPayloadFormatter.
 *
 * Verifies conversion from ocopendata format to the canonical
 * OpenCity Kafka entity event format { entity: { meta, data } }.
 *
 * No eZ Publish bootstrap or broker needed.
 *
 * Usage:
 *   php tests/PayloadFormatterTest.php
 */

require_once __DIR__ . '/../classes/ocwebhookkafkapayloadformatter.php';

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void    { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a, $b, string $t, string $r = ''): void
{
    if ($a === $b) {
        ok($t);
    } else {
        fail($t, sprintf("expected %s, got %s. %s", var_export($b, true), var_export($a, true), $r));
    }
}
function assert_true(bool $v, string $t, string $r = ''): void  { $v ? ok($t) : fail($t, $r); }
function assert_false(bool $v, string $t, string $r = ''): void { (!$v) ? ok($t) : fail($t, $r); }
function assert_null($v, string $t): void { $v === null ? ok($t) : fail($t, "expected null, got " . var_export($v, true)); }

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures
// ─────────────────────────────────────────────────────────────────────────────

$publishedTs  = mktime(10, 0, 0, 3, 15, 2026);  // 2026-03-15T10:00:00
$modifiedTs   = mktime(11, 30, 0, 3, 20, 2026); // 2026-03-20T11:30:00

$ocPayload = [
    'metadata' => [
        'id'               => '42',
        'currentVersion'   => '3',
        'remoteId'         => 'abc123remote',
        'classIdentifier'  => 'article',
        'name'             => ['it-IT' => 'Titolo notizia', 'eng-GB' => 'News title'],
        'languages'        => ['it-IT', 'eng-GB'],
        'mainNodeId'       => '88',
        'parentNodes'      => ['88', '90'],
        'assignedNodes'    => ['88'],
        'published'        => (string)$publishedTs,
        'modified'         => (string)$modifiedTs,
        'baseUrl'          => 'https://www.comune.example.it',
    ],
    'data' => [
        'it-IT' => [
            'title'    => ['content' => 'Titolo notizia', 'type' => 'string'],
            'abstract' => ['content' => 'Abstract della notizia', 'type' => 'string'],
            'body'     => ['content' => '<p>Corpo testo</p>', 'type' => 'string'],
            'image'    => ['content' => null, 'type' => 'image'],
        ],
        'eng-GB' => [
            'title'    => ['content' => 'News title', 'type' => 'string'],
            'abstract' => ['content' => 'News abstract', 'type' => 'string'],
            'body'     => ['content' => '<p>Body text</p>', 'type' => 'string'],
            'image'    => ['content' => null, 'type' => 'image'],
        ],
    ],
    'extradata' => [],
];

// ─────────────────────────────────────────────────────────────────────────────
// TEST 1: Top-level structure
// ─────────────────────────────────────────────────────────────────────────────

$formatter = new OCWebHookKafkaPayloadFormatter('comune_it');
$result    = $formatter->format($ocPayload);

assert_true(
    isset($result['entity']),
    'Top-level key "entity" exists'
);
assert_true(
    isset($result['entity']['meta']),
    'entity.meta exists'
);
assert_true(
    isset($result['entity']['data']),
    'entity.data exists'
);
assert_true(
    !isset($result['metadata']),
    'Raw "metadata" key not present in output'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2: entity.meta fields
// ─────────────────────────────────────────────────────────────────────────────

$meta = $result['entity']['meta'];

assert_eq($meta['id'],         'comune_it:42', 'entity.meta.id = "<siteaccess>:<object_id>"');
assert_eq($meta['tenant_id'],  null,           'entity.meta.tenant_id is null when not provided');
assert_eq($meta['siteaccess'], 'comune_it',    'entity.meta.siteaccess');
assert_eq($meta['object_id'],  '42',           'entity.meta.object_id');
assert_eq($meta['remote_id'],          'abc123remote', 'entity.meta.remote_id');
assert_eq($meta['type']['id'],         'article',      'entity.meta.type.id (class identifier)');
assert_null($meta['type']['remote_id'] ?? null,        'entity.meta.type.remote_id null when classRemoteId absent');
assert_eq($meta['version'],    3,              'entity.meta.version (cast to int)');
assert_eq($meta['languages'],  ['it-IT', 'eng-GB'], 'entity.meta.languages');
assert_eq($meta['name'],       ['it-IT' => 'Titolo notizia', 'eng-GB' => 'News title'], 'entity.meta.name è mappa multilingue');
assert_eq($meta['site_url'],    'https://www.comune.example.it', 'entity.meta.site_url');
assert_null($meta['content_url'],   'entity.meta.content_url is null when not in metadata');
assert_null($meta['api_url'],       'entity.meta.api_url is null when not in metadata');
assert_null($meta['created_by'],    'entity.meta.created_by is null when not in metadata');
assert_null($meta['modified_by'],   'entity.meta.modified_by is null when not in metadata');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 3: entity.meta timestamps as ISO 8601
// ─────────────────────────────────────────────────────────────────────────────

assert_eq(
    $meta['published_at'],
    gmdate('Y-m-d\TH:i:s\Z', $publishedTs),
    'entity.meta.published_at is ISO 8601 UTC'
);
assert_eq(
    $meta['updated_at'],
    gmdate('Y-m-d\TH:i:s\Z', $modifiedTs),
    'entity.meta.updated_at is ISO 8601 UTC'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEST 4: entity.data — attribute content extracted per language
// ─────────────────────────────────────────────────────────────────────────────

$data = $result['entity']['data'];

assert_true(
    isset($data['it-IT']) && isset($data['eng-GB']),
    'entity.data has both languages'
);
assert_eq($data['it-IT']['title'],    'Titolo notizia',         'it-IT title content extracted');
assert_eq($data['it-IT']['abstract'], 'Abstract della notizia', 'it-IT abstract content extracted');
assert_eq($data['it-IT']['body'],     '<p>Corpo testo</p>',     'it-IT body content extracted');
assert_eq($data['it-IT']['image'], [], 'it-IT null image content normalized to []');
assert_eq($data['eng-GB']['title'],   'News title',             'eng-GB title content extracted');

assert_true(
    !isset($data['it-IT']['type']),
    '"type" metadata key not propagated to entity.data'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEST 5: siteaccess in id
// ─────────────────────────────────────────────────────────────────────────────

$formatter2 = new OCWebHookKafkaPayloadFormatter('pat_pub');
$result2    = $formatter2->format($ocPayload);

assert_eq(
    $result2['entity']['meta']['id'],
    'pat_pub:42',
    'Different siteaccess used in entity.meta.id'
);
assert_eq(
    $result2['entity']['meta']['siteaccess'],
    'pat_pub',
    'Different siteaccess stored in entity.meta.siteaccess'
);
assert_null($result2['entity']['meta']['tenant_id'], 'tenant_id null when not passed to constructor');

// tenant_id valorizzato
$formatterWithTenant = new OCWebHookKafkaPayloadFormatter('frontend', 'comune', '00000000-0000-0000-0000-000000000001');
$resultWithTenant    = $formatterWithTenant->format($ocPayload);
assert_eq(
    $resultWithTenant['entity']['meta']['tenant_id'],
    '00000000-0000-0000-0000-000000000001',
    'tenant_id propagated to entity.meta.tenant_id when passed to constructor'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEST 6: Missing/null metadata fields handled gracefully
// ─────────────────────────────────────────────────────────────────────────────

$minimalPayload = [
    'metadata' => ['id' => '99'],
    'data'     => [],
];

$formatter3 = new OCWebHookKafkaPayloadFormatter('test_sa');
$result3    = $formatter3->format($minimalPayload);
$meta3      = $result3['entity']['meta'];

assert_eq($meta3['id'],         'test_sa:99', 'Minimal: id constructed correctly');
assert_null($meta3['remote_id'],              'Minimal: remote_id is null when missing');
assert_null($meta3['type'],                   'Minimal: type is null when classIdentifier missing');
assert_null($meta3['version'],                'Minimal: version is null when missing');
assert_null($meta3['published_at'],           'Minimal: published_at is null when missing');
assert_null($meta3['updated_at'],             'Minimal: updated_at is null when missing');
assert_eq($meta3['languages'],  [],           'Minimal: languages is empty array');
assert_eq($meta3['name'],       [],           'Minimal: name è mappa vuota quando assente');
assert_eq($result3['entity']['data'], [],     'Minimal: entity.data is empty array');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 7: null content di attributi strutturati normalizzato a []
// ocopendata restituisce {"content": null} per liste relazionate vuote
// ─────────────────────────────────────────────────────────────────────────────

$payloadWithNullContent = [
    'metadata' => ['id' => '50', 'classIdentifier' => 'article', 'languages' => ['it-IT']],
    'data'     => [
        'it-IT' => [
            // relation list vuota (null content): deve diventare []
            'files'       => ['content' => null, 'type' => 'ezbinaryfilecollection'],
            // relation list con item con chiavi camelCase (formato ocopendata) → vanno normalizzate
            'attachments' => ['content' => [
                ['id' => 1, 'remoteId' => 'file-abc-123', 'classIdentifier' => 'file', 'mainNodeId' => '210', 'name' => 'Relazione annuale.pdf'],
                ['id' => 2, 'remoteId' => 'file-def-456', 'classIdentifier' => 'file', 'mainNodeId' => '211', 'name' => 'Bilancio.pdf'],
            ], 'type' => 'ezbinaryfilecollection'],
            // relation items senza chiavi camelCase (già snake_case): pass-through
            'topics'      => ['content' => [
                ['id' => 101, 'remote_id' => 'topic-xyz', 'class_identifier' => 'tag', 'main_node_id' => '501'],
            ], 'type' => 'eztags'],
            // campo testo: null resta null (non avvolto in content wrapper)
            'subtitle'    => null,
            // campo testo con valore stringa normale
            'title'       => ['content' => 'Titolo', 'type' => 'ezstring'],
        ],
    ],
];

$formatter4 = new OCWebHookKafkaPayloadFormatter('frontend', 'bugliano');
$result4    = $formatter4->format($payloadWithNullContent);
$data4      = $result4['entity']['data']['it-IT'];

assert_eq($data4['files'],  [], 'Null content normalizzato a [] (lista vuota)');
assert_null($data4['subtitle'], 'Null grezzo (non content-wrapped) preservato come null');
assert_eq($data4['title'],  'Titolo', 'Campo testo estratto correttamente');

// Relation items: chiavi camelCase normalizzate a snake_case
$dropPayload = [
    'metadata' => ['id' => '50', 'classIdentifier' => 'article', 'languages' => ['it-IT']],
    'data' => [
        'it-IT' => [
            'attachments' => ['content' => [
                ['id' => 1, 'remoteId' => 'file-abc-123', 'classIdentifier' => 'file',
                 'mainNodeId' => '210', 'name' => 'Relazione annuale.pdf',
                 'class' => 'file',              // must be dropped
                 'languages' => ['it-IT'],        // must be dropped
                 'link' => 'read/210',            // must be dropped
                 'content_url' => 'https://www.comune.example.it/allegati/relazione-annuale'], // must pass through
                ['id' => 2, 'remoteId' => 'file-def-456', 'classIdentifier' => 'file',
                 'mainNodeId' => '211', 'name' => 'Bilancio.pdf',
                 'class' => 'file', 'languages' => ['it-IT'], 'link' => 'read/211'],
            ], 'type' => 'ezbinaryfilecollection'],
            'topics' => ['content' => [
                ['id' => 101, 'remote_id' => 'topic-xyz', 'class_identifier' => 'tag',
                 'main_node_id' => '501', 'class' => 'tag', 'languages' => ['it-IT'],
                 'link' => 'read/101'],
            ], 'type' => 'eztags'],
            'files'    => ['content' => null, 'type' => 'ezbinaryfilecollection'],
            'subtitle' => null,
            'title'    => ['content' => 'Titolo', 'type' => 'ezstring'],
        ],
    ],
];
$formatter4 = new OCWebHookKafkaPayloadFormatter('frontend', 'bugliano');
$result4    = $formatter4->format($dropPayload);
$data4      = $result4['entity']['data']['it-IT'];

assert_eq($data4['files'],  [], 'Null content normalizzato a [] (lista vuota)');
assert_null($data4['subtitle'], 'Null grezzo (non content-wrapped) preservato come null');
assert_eq($data4['title'],  'Titolo', 'Campo testo estratto correttamente');

assert_eq(count($data4['attachments']), 2, 'Relation list: 2 item preservati');

$att0 = $data4['attachments'][0];
assert_eq($att0['type_id'],   'file',         'classIdentifier → type_id');
assert_eq($att0['id'],        'bugliano:1',   'id = instanceId:objectId (compound)');
assert_eq($att0['object_id'], '1',            'object_id = string del id originale');
assert_eq($att0['remote_id'], 'file-abc-123', 'remoteId → remote_id');
assert_eq($att0['title'],     'Relazione annuale.pdf', 'name → title');
assert_eq($att0['content_url'], 'https://www.comune.example.it/allegati/relazione-annuale',
    'content_url pass-through');

assert_false(isset($att0['name']),            'name rimosso (rinominato title)');
assert_false(isset($att0['class_identifier']),'class_identifier rimosso (rinominato type_id)');
assert_false(isset($att0['classIdentifier']), 'classIdentifier camelCase rimosso');
assert_false(isset($att0['main_node_id']),    'main_node_id rimosso');
assert_false(isset($att0['mainNodeId']),      'mainNodeId camelCase rimosso');
assert_false(isset($att0['class']),           '"class" eliminato');
assert_false(isset($att0['languages']),       '"languages" eliminato');
assert_false(isset($att0['link']),            '"link" eliminato');

assert_eq($data4['attachments'][1]['id'],        'bugliano:2',   'Secondo item: id compound');
assert_eq($data4['attachments'][1]['remote_id'], 'file-def-456', 'Secondo item: remote_id');

$top0 = $data4['topics'][0];
assert_eq($top0['type_id'],   'tag',           'topics: class_identifier → type_id');
assert_eq($top0['id'],        'bugliano:101',  'topics: id compound');
assert_eq($top0['object_id'], '101',           'topics: object_id');
assert_eq($top0['remote_id'], 'topic-xyz',     'topics: remote_id pass-through');
assert_false(isset($top0['class_identifier']), 'topics: class_identifier rimosso');
assert_false(isset($top0['main_node_id']),     'topics: main_node_id rimosso');
assert_false(isset($top0['class']),            'topics: "class" eliminato');
assert_false(isset($top0['languages']),        'topics: "languages" eliminato');
assert_false(isset($top0['link']),             'topics: "link" eliminato');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 8: ISO 8601 date strings (real ocopendata format uses date('c'))
// ─────────────────────────────────────────────────────────────────────────────

$iso8601Published = '2026-03-15T10:00:00+01:00';  // date('c', $publishedTs) in Europe/Rome
$iso8601Modified  = '2026-03-20T11:30:00+01:00';

$payloadIso = [
    'metadata' => [
        'id'              => '55',
        'classIdentifier' => 'article',
        'published'       => $iso8601Published,
        'modified'        => $iso8601Modified,
        'languages'       => ['it-IT'],
        'name'            => ['it-IT' => 'ISO date test'],
    ],
    'data' => [],
];

$formatter5 = new OCWebHookKafkaPayloadFormatter('frontend', 'comune');
$result5    = $formatter5->format($payloadIso);
$meta5      = $result5['entity']['meta'];

assert_eq(
    $meta5['published_at'],
    gmdate('Y-m-d\TH:i:s\Z', strtotime($iso8601Published)),
    'ISO 8601 date string converted to UTC for published_at'
);
assert_eq(
    $meta5['updated_at'],
    gmdate('Y-m-d\TH:i:s\Z', strtotime($iso8601Modified)),
    'ISO 8601 date string converted to UTC for updated_at'
);
// Make sure it's not "1970-01-01" (the (int)"2026-03-15..." = 2026 bug)
assert_true(
    strpos($meta5['published_at'], '1970') === false,
    'published_at is not 1970 (ISO 8601 string parsed correctly)'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEST 9: content_url and api_url mapped from metadata
// ─────────────────────────────────────────────────────────────────────────────

$payloadWithUrls = [
    'metadata' => [
        'id'               => '77',
        'classIdentifier'  => 'article',
        'languages'        => ['it-IT'],
        'name'             => ['it-IT' => 'Test notizia'],
        'baseUrl'          => 'https://www.comune.example.it',
        'contentUrl'       => 'https://www.comune.example.it/notizie/test-notizia',
        'apiUrl'           => 'https://www.comune.example.it/api/openapi/novita/notizie/abc123#test-notizia',
    ],
    'data' => [],
];

$formatter6 = new OCWebHookKafkaPayloadFormatter('frontend', 'example');
$result6    = $formatter6->format($payloadWithUrls);
$meta6      = $result6['entity']['meta'];

assert_eq(
    $meta6['content_url'],
    'https://www.comune.example.it/notizie/test-notizia',
    'entity.meta.content_url mapped from metadata.contentUrl'
);
assert_eq(
    $meta6['api_url'],
    'https://www.comune.example.it/api/openapi/novita/notizie/abc123#test-notizia',
    'entity.meta.api_url mapped from metadata.apiUrl'
);

// null apiUrl (ocopenapi not available) passes through as null
$payloadUrlNullApi = [
    'metadata' => [
        'id'         => '78',
        'languages'  => ['it-IT'],
        'name'       => ['it-IT' => 'Test'],
        'baseUrl'    => 'https://www.comune.example.it',
        'contentUrl' => 'https://www.comune.example.it/test',
        'apiUrl'     => null,
    ],
    'data' => [],
];
$result7  = $formatter6->format($payloadUrlNullApi);
$meta7    = $result7['entity']['meta'];

assert_eq($meta7['content_url'], 'https://www.comune.example.it/test', 'content_url set even when api_url is null');
assert_null($meta7['api_url'],   'api_url is null when metadata.apiUrl is explicitly null');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 10: created_by and modified_by mapped from metadata
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

assert_eq($meta8['created_by'],  ['id' => 14, 'login' => 'admin',    'name' => 'Administrator'], 'created_by mapped correctly');
assert_eq($meta8['modified_by'], ['id' => 55, 'login' => 'editor01', 'name' => 'Mario Rossi'],   'modified_by mapped correctly');

// null passes through
$payloadNoUsers = [
    'metadata' => ['id' => '100', 'languages' => ['it-IT'], 'name' => ['it-IT' => 'X'],
                   'createdBy' => null, 'modifiedBy' => null],
    'data' => [],
];
$result9 = $formatter8->format($payloadNoUsers);
assert_null($result9['entity']['meta']['created_by'],  'created_by null when metadata.createdBy is null');
assert_null($result9['entity']['meta']['modified_by'], 'modified_by null when metadata.modifiedBy is null');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 11: multi-language maps in relation items resolved to current language
// ─────────────────────────────────────────────────────────────────────────────

$payloadMultilang = [
    'metadata' => ['id' => '150', 'languages' => ['eng-GB', 'ita-IT'], 'name' => ['eng-GB' => 'EN', 'ita-IT' => 'IT']],
    'data' => [
        'eng-GB' => [
            // relation item with multilang name map
            'topics' => ['content' => [
                [
                    'id'     => 1,
                    'name'   => ['eng-GB' => 'Innovation', 'ger-DE' => 'Innovation', 'ita-IT' => 'Innovazione'],
                    'languages' => ['eng-GB', 'ger-DE', 'ita-IT'],  // list: must NOT be resolved
                ],
            ], 'type' => 'eztags'],
            // relation item whose name only exists in ita-IT → fallback
            'author' => ['content' => [
                [
                    'id'   => 5,
                    'name' => ['ita-IT' => 'Ufficio anagrafe'],
                ],
            ], 'type' => 'ezobjectrelationlist'],
        ],
        'ita-IT' => [
            'topics' => ['content' => [
                [
                    'id'     => 1,
                    'name'   => ['eng-GB' => 'Innovation', 'ger-DE' => 'Innovation', 'ita-IT' => 'Innovazione'],
                    'languages' => ['eng-GB', 'ger-DE', 'ita-IT'],
                ],
            ], 'type' => 'eztags'],
        ],
    ],
];

$formatterML = new OCWebHookKafkaPayloadFormatter('frontend', 'comune');
$resultML    = $formatterML->format($payloadMultilang);

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
// Fallback: title only in ita-IT, requested lang is eng-GB → returns ita-IT value
assert_eq(
    $resultML['entity']['data']['eng-GB']['author'][0]['title'],
    'Ufficio anagrafe',
    'Multi-lang map with missing eng-GB falls back to first available language'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEST 13: date strings in entity.data normalised to UTC
// ocopendata uses date('c', $ts) → "+01:00" local timezone
// ─────────────────────────────────────────────────────────────────────────────

// Simulate what ocopendata produces for an ezdate/ezdatetime attribute in Europe/Rome
$localDateStr = '2026-03-15T10:00:00+01:00';  // 09:00 UTC
$expectedUtc  = gmdate('Y-m-d\TH:i:s\Z', strtotime($localDateStr));

$payloadWithDates = [
    'metadata' => ['id' => '200', 'languages' => ['it-IT'], 'name' => ['it-IT' => 'Date test']],
    'data' => [
        'it-IT' => [
            // ezdate-like: string with timezone offset
            'event_date'   => ['content' => $localDateStr,          'type' => 'ezdate'],
            // already UTC (Z suffix) — must not be double-converted
            'created_at'   => ['content' => '2026-01-01T00:00:00Z', 'type' => 'ezdatetime'],
            // null — must stay null (not crash)
            'empty_date'   => ['content' => null,                   'type' => 'ezdate'],
            // plain string — must NOT be touched
            'title'        => ['content' => 'Non è una data',       'type' => 'ezstring'],
            // relation item with a date field inside (deep recursion)
            'attachments'  => ['content' => [
                ['id' => 1, 'remoteId' => 'file-abc', 'date' => '2025-12-01T08:00:00+01:00'],
            ], 'type' => 'ezbinaryfilecollection'],
        ],
    ],
];

$formatterDates = new OCWebHookKafkaPayloadFormatter('frontend', 'comune');
$resultDates    = $formatterDates->format($payloadWithDates);
$dataDates      = $resultDates['entity']['data']['it-IT'];

assert_eq(
    $dataDates['event_date'],
    $expectedUtc,
    'ezdate string with +01:00 offset normalised to UTC'
);
assert_eq(
    $dataDates['created_at'],
    '2026-01-01T00:00:00Z',
    'Already-UTC string (Z suffix) left unchanged'
);
assert_eq($dataDates['empty_date'], [],   'null date (no-content) normalised to [] as usual');
assert_eq($dataDates['title'],      'Non è una data', 'Plain string not touched');

// Deep recursion: date inside a relation item
$attachDate = $dataDates['attachments'][0]['date'] ?? null;
assert_eq(
    $attachDate,
    gmdate('Y-m-d\TH:i:s\Z', strtotime('2025-12-01T08:00:00+01:00')),
    'Date inside nested relation item also normalised to UTC'
);

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

// ─────────────────────────────────────────────────────────────────────────────
// TEST 15: entity.meta.tree_placement
// ─────────────────────────────────────────────────────────────────────────────

$payloadTP = [
    'metadata' => [
        'id'             => '500',
        'languages'      => ['ita-IT'],
        'name'           => ['ita-IT' => 'Test'],
        'mainParentNode' => ['remote_id' => 'notizie', 'ita-IT' => 'Notizie', 'eng-GB' => 'News'],
        'parentNodes'    => [
            ['remote_id' => 'homepage', 'ita-IT' => 'Homepage', 'eng-GB' => 'Homepage'],
            ['remote_id' => 'notizie',  'ita-IT' => 'Notizie',  'eng-GB' => 'News'],
        ],
    ],
    'data' => [],
];
$fmTP  = new OCWebHookKafkaPayloadFormatter('frontend', 'opencity');
$resTP = $fmTP->format($payloadTP);
$metaTP = $resTP['entity']['meta'];

assert_eq(
    $metaTP['tree_placement'],
    [
        'parent'    => ['remote_id' => 'notizie',  'labels' => ['ita-IT' => 'Notizie',  'eng-GB' => 'News']],
        'ancestors' => [
            ['remote_id' => 'homepage', 'labels' => ['ita-IT' => 'Homepage', 'eng-GB' => 'Homepage']],
            ['remote_id' => 'notizie',  'labels' => ['ita-IT' => 'Notizie',  'eng-GB' => 'News']],
        ],
    ],
    'tree_placement con parent e ancestors — nomi tradotti in labels annidato'
);

// null when mainParentNode absent
$payloadNoTP = [
    'metadata' => ['id' => '501', 'languages' => ['ita-IT'], 'name' => ['ita-IT' => 'X']],
    'data' => [],
];
$resNoTP = $fmTP->format($payloadNoTP);
assert_null($resNoTP['entity']['meta']['tree_placement'], 'tree_placement null when mainParentNode absent');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 16: entity.meta.is_public mapped from metadata.isPublic
// ─────────────────────────────────────────────────────────────────────────────

$payloadPublic = [
    'metadata' => ['id' => '600', 'languages' => ['it-IT'], 'name' => ['it-IT' => 'X'], 'isPublic' => true],
    'data' => [],
];
$payloadPrivate = [
    'metadata' => ['id' => '601', 'languages' => ['it-IT'], 'name' => ['it-IT' => 'X'], 'isPublic' => false],
    'data' => [],
];
$payloadNoPublic = [
    'metadata' => ['id' => '602', 'languages' => ['it-IT'], 'name' => ['it-IT' => 'X']],
    'data' => [],
];

$fmPub = new OCWebHookKafkaPayloadFormatter('frontend', 'comune');
assert_eq($fmPub->format($payloadPublic)['entity']['meta']['is_public'],  true,  'is_public true when isPublic=true');
assert_eq($fmPub->format($payloadPrivate)['entity']['meta']['is_public'], false, 'is_public false when isPublic=false');
assert_null($fmPub->format($payloadNoPublic)['entity']['meta']['is_public'], 'is_public null when isPublic absent');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 12b: file items (ocmultibinary) passano attraverso senza normalizzazione
// — nessun id/title/taxonomy spurio aggiunto da normalizeTaxonomyItem
// ─────────────────────────────────────────────────────────────────────────────

$payloadWithFiles = [
    'metadata' => ['id' => '800', 'classIdentifier' => 'article', 'languages' => ['it-IT'],
                   'baseUrl' => 'https://www.comune.example.it'],
    'data' => [
        'it-IT' => [
            // file diretto (ocmultibinary): ha filename+url, nessun classIdentifier
            'files' => [
                ['filename' => 'relazione.pdf',
                 'url' => 'https://www.comune.example.it/ocmultibinary/download/800/1/relazione.pdf',
                 'displayName' => 'Relazione annuale', 'group' => '', 'text' => ''],
                ['filename' => 'allegato.docx',
                 'url' => 'https://www.comune.example.it/ocmultibinary/download/800/2/allegato.docx',
                 'displayName' => 'Allegato B', 'group' => '', 'text' => ''],
            ],
            // relazione a documento (classIdentifier presente) — deve usare normalizeRelationItem
            'attachment' => [
                ['id' => 99, 'remoteId' => 'doc-xyz', 'classIdentifier' => 'document',
                 'mainNodeId' => '999', 'name' => ['it-IT' => 'Delibera'],
                 'content_url' => 'https://www.comune.example.it/delibera',
                 'languages' => ['it-IT'], 'link' => 'read/99'],
            ],
        ],
    ],
];

$fmFiles = new OCWebHookKafkaPayloadFormatter('frontend', 'comune');
$resFiles = $fmFiles->format($payloadWithFiles);
$dFiles   = $resFiles['entity']['data']['it-IT'];

// files: pass-through diretto — nessun campo spurio
$file0 = $dFiles['files'][0];
assert_eq($file0['filename'],    'relazione.pdf',  'file item: filename preservato');
assert_eq($file0['url'],         'https://www.comune.example.it/ocmultibinary/download/800/1/relazione.pdf',
    'file item: url preservato');
assert_eq($file0['displayName'], 'Relazione annuale', 'file item: displayName preservato');
assert_false(isset($file0['id']),       'file item: nessun id spurio da normalizeTaxonomyItem');
assert_false(isset($file0['title']),    'file item: nessun title spurio');
assert_false(isset($file0['taxonomy']), 'file item: nessun taxonomy spurio');
assert_eq(count($dFiles['files']), 2, 'file item: entrambi i file presenti');

// attachment (rinominato in attachments dalla FieldMap): normalizzato correttamente
$att = $dFiles['attachments'][0];
assert_eq($att['type_id'],     'document',  'attachment: type_id corretto');
assert_eq($att['id'],          'comune:99', 'attachment: id compound');
assert_eq($att['title'],       'Delibera',  'attachment: title risolto dalla lingua');
assert_eq($att['content_url'], 'https://www.comune.example.it/delibera', 'attachment: content_url pass-through');
assert_false(isset($att['classIdentifier']), 'attachment: classIdentifier rimosso');
assert_false(isset($att['languages']),       'attachment: languages rimosso');

// documentFilesResolver: aggiunge files ai document items
$docFiles = [
    ['filename' => 'delibera.pdf', 'url' => 'https://www.comune.example.it/ocmultibinary/download/99/1/delibera.pdf',
     'displayName' => 'Delibera N. 42', 'group' => '', 'text' => ''],
];
$fmWithDocResolver = new OCWebHookKafkaPayloadFormatter('frontend', 'comune', null, null,
    function ($objectId) use ($docFiles) { return $objectId == 99 ? $docFiles : null; }
);
$resWithDoc = $fmWithDocResolver->format($payloadWithFiles);
$attWithFiles = $resWithDoc['entity']['data']['it-IT']['attachments'][0];
assert_eq($attWithFiles['files'], $docFiles, 'document item: files aggiunti dal documentFilesResolver');
assert_false(isset($attWithFiles['files'][0]['id']),       'document files: nessun id spurio');
assert_false(isset($attWithFiles['files'][0]['taxonomy']), 'document files: nessun taxonomy spurio');

// Senza documentFilesResolver: nessun files aggiunto
$fmNoDocResolver = new OCWebHookKafkaPayloadFormatter('frontend', 'comune');
$resNoDR = $fmNoDocResolver->format($payloadWithFiles);
assert_false(isset($resNoDR['entity']['data']['it-IT']['attachments'][0]['files']),
    'document item: files NON aggiunti senza documentFilesResolver');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 12: image URL resolver — relation items di tipo image/image_with_related
// ricevono il campo "url" dalla callable iniettata nel costruttore.
// ─────────────────────────────────────────────────────────────────────────────

$resolverCalls = [];
$mockResolver = function ($objectId, $siteUrl) use (&$resolverCalls) {
    $resolverCalls[] = ['objectId' => $objectId, 'siteUrl' => $siteUrl];
    return $siteUrl . '/var/storage/images/' . $objectId . '.jpg';
};

$payloadWithImages = [
    'metadata' => [
        'id' => '700', 'classIdentifier' => 'article', 'languages' => ['it-IT'],
        'baseUrl' => 'https://www.comune.example.it',
    ],
    'data' => [
        'it-IT' => [
            // image relation — deve ricevere "url" dal resolver
            'photo' => ['content' => [
                ['id' => 55, 'remoteId' => 'img-abc', 'classIdentifier' => 'image',
                 'mainNodeId' => '555', 'name' => 'Foto evento'],
            ], 'type' => 'ezobjectrelation'],
            // image_with_related — stesso trattamento
            'hero' => ['content' => [
                ['id' => 66, 'remoteId' => 'img-def', 'classIdentifier' => 'image_with_related',
                 'mainNodeId' => '666', 'name' => 'Hero image'],
            ], 'type' => 'ezobjectrelation'],
            // file relation — NON deve chiamare il resolver
            'attachment' => ['content' => [
                ['id' => 77, 'remoteId' => 'file-ghi', 'classIdentifier' => 'file',
                 'mainNodeId' => '777', 'name' => 'Allegato.pdf'],
            ], 'type' => 'ezbinaryfilecollection'],
            // image con "url" già presente — resolver NON deve essere chiamato (pass-through)
            'logo' => ['content' => [
                ['id' => 88, 'remoteId' => 'img-already', 'classIdentifier' => 'image',
                 'mainNodeId' => '888', 'name' => 'Logo', 'url' => 'https://cdn.example.it/logo.png'],
            ], 'type' => 'ezobjectrelation'],
        ],
    ],
];

$fmResolver = new OCWebHookKafkaPayloadFormatter('frontend', 'comune', null, $mockResolver);
$resResolver = $fmResolver->format($payloadWithImages);
$dataImg = $resResolver['entity']['data']['it-IT'];

// photo: url risolto dal resolver
assert_eq(
    $dataImg['photo'][0]['url'],
    'https://www.comune.example.it/var/storage/images/55.jpg',
    'image item: url aggiunto dal resolver'
);
assert_eq($dataImg['photo'][0]['type_id'], 'image',       'image item: type_id corretto');
assert_eq($dataImg['photo'][0]['title'],   'Foto evento', 'image item: title corretto');

// hero (image_with_related): url risolto
assert_eq(
    $dataImg['hero'][0]['url'],
    'https://www.comune.example.it/var/storage/images/66.jpg',
    'image_with_related item: url aggiunto dal resolver'
);

// attachment (file): resolver NON chiamato, nessun url aggiunto
assert_false(isset($dataImg['attachment'][0]['url']), 'file item: url NON aggiunto dal resolver');

// logo: url già presente → resolver NON chiamato, pass-through
assert_eq(
    $dataImg['logo'][0]['url'],
    'https://cdn.example.it/logo.png',
    'image item con url preesistente: pass-through, resolver non chiamato'
);

// Resolver chiamato esattamente per photo (55) e hero (66), non per file (77) né logo (88)
$calledIds = array_column($resolverCalls, 'objectId');
sort($calledIds);
assert_eq($calledIds, [55, 66], 'Resolver chiamato esattamente per i 2 item senza url preesistente');
assert_eq($resolverCalls[0]['siteUrl'], 'https://www.comune.example.it', 'siteUrl passato correttamente al resolver');

// Senza resolver: nessun url aggiunto, comportamento invariato
$fmNoResolver = new OCWebHookKafkaPayloadFormatter('frontend', 'comune');
$resNoResolver = $fmNoResolver->format($payloadWithImages);
assert_false(
    isset($resNoResolver['entity']['data']['it-IT']['photo'][0]['url']),
    'Senza resolver: url NON aggiunto agli image item'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEST 17: URL in https — site_url, content_url, api_url nel formatter
// Il formatter riceve gli URL già normalizzati dal builder (forceHttps).
// Questo test verifica che il formatter li passi correttamente a entity.meta.
// ─────────────────────────────────────────────────────────────────────────────

$payloadHttpUrls = [
    'metadata' => [
        'id'         => '750',
        'languages'  => ['ita-IT'],
        'name'       => ['ita-IT' => 'Test'],
        'baseUrl'    => 'https://www.comune.example.it',
        'contentUrl' => 'https://www.comune.example.it/notizie/test',
        'apiUrl'     => 'https://www.comune.example.it/api/openapi/novita/notizie/abc#test',
    ],
    'data' => [],
];

$fmHttps  = new OCWebHookKafkaPayloadFormatter('frontend', 'comune');
$resHttps = $fmHttps->format($payloadHttpUrls);
$metaHttps = $resHttps['entity']['meta'];

assert_eq($metaHttps['site_url'],    'https://www.comune.example.it',                                      'site_url è https');
assert_eq($metaHttps['content_url'], 'https://www.comune.example.it/notizie/test',                        'content_url è https');
assert_eq($metaHttps['api_url'],     'https://www.comune.example.it/api/openapi/novita/notizie/abc#test', 'api_url è https');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 17b: bilingue ita-IT/ger-DE — meta.name dalla lingua principale
//
// Regression: OCWebHookPayloadBuilder::build() deve riordinare languages[] in modo
// che la lingua iniziale (principale nel CMS) sia sempre a languages[0].
// ocopendata la ordina per ID DB — su siti bilingue con ger-DE aggiunta prima di
// ita-IT nel sistema, arriva ger-DE first, e meta.name finisce in tedesco anche se
// la lingua principale è italiana.
// Il formatter usa correttamente languages[0] per derivare meta.name: questo test
// verifica il contratto a valle, che il builder deve rispettare producendo il
// payload corretto.
// ─────────────────────────────────────────────────────────────────────────────

// Payload come prodotto dal builder DOPO il fix: ita-IT (lingua principale) è first
$payloadBilingual = [
    'metadata' => [
        'id'              => '999',
        'classIdentifier' => 'argomento',
        'languages'       => ['ita-IT', 'ger-DE'],
        'name'            => ['ita-IT' => 'Elezioni', 'ger-DE' => 'Wahlverfahren'],
    ],
    'data' => [
        'ita-IT' => ['name' => ['content' => 'Elezioni',      'type' => 'ezstring']],
        'ger-DE' => ['name' => ['content' => 'Wahlverfahren', 'type' => 'ezstring']],
    ],
];

$fmBilingual  = new OCWebHookKafkaPayloadFormatter('frontend', 'arco');
$resBilingual = $fmBilingual->format($payloadBilingual);
$metaBi       = $resBilingual['entity']['meta'];

assert_eq($metaBi['languages'][0], 'ita-IT',    'Bilingue: languages[0] è la lingua principale (ita-IT)');
assert_eq(
    $metaBi['name'],
    ['ita-IT' => 'Elezioni', 'ger-DE' => 'Wahlverfahren'],
    'Bilingue: meta.name è mappa con tutte le traduzioni'
);
assert_eq($metaBi['languages'], ['ita-IT', 'ger-DE'], 'Bilingue: entrambe le lingue in meta.languages');
assert_true(
    isset($resBilingual['entity']['data']['ita-IT']) && isset($resBilingual['entity']['data']['ger-DE']),
    'Bilingue: entity.data contiene entrambe le traduzioni'
);

// Con meta.name come mappa, l'ordine di languages[] non altera il contenuto di name
// ma è ancora importante per il consumer (languages[0] = lingua principale).
// Verifica che la mappa contenga sempre tutte le traduzioni indipendentemente dall'ordine.
$payloadWrongOrder = [
    'metadata' => [
        'id'              => '998',
        'classIdentifier' => 'argomento',
        'languages'       => ['ger-DE', 'ita-IT'],
        'name'            => ['ita-IT' => 'Elezioni', 'ger-DE' => 'Wahlverfahren'],
    ],
    'data' => [
        'ger-DE' => ['name' => ['content' => 'Wahlverfahren', 'type' => 'ezstring']],
        'ita-IT' => ['name' => ['content' => 'Elezioni',      'type' => 'ezstring']],
    ],
];

$resWrong = $fmBilingual->format($payloadWrongOrder);
assert_eq(
    $resWrong['entity']['meta']['name'],
    ['ita-IT' => 'Elezioni', 'ger-DE' => 'Wahlverfahren'],
    'Bilingue pre-fix: meta.name è mappa con entrambe le traduzioni anche se ger-DE è languages[0]'
);
assert_eq(
    $resWrong['entity']['meta']['languages'][0],
    'ger-DE',
    'Bilingue pre-fix: languages[0] riflette ancora l\'ordine errato (il builder deve correggere questo)'
);

// ─────────────────────────────────────────────────────────────────────────────
// Results
// ─────────────────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) {
    echo ", \033[31m{$FAILED} failed\033[0m";
}
echo "\n";

exit($FAILED > 0 ? 1 : 0);
