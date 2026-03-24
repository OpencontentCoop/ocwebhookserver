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
assert_eq($meta['remote_id'],  'abc123remote', 'entity.meta.remote_id');
assert_eq($meta['type_id'],    'article',      'entity.meta.type_id');
assert_eq($meta['version'],    3,              'entity.meta.version (cast to int)');
assert_eq($meta['languages'],  ['it-IT', 'eng-GB'], 'entity.meta.languages');
assert_eq($meta['name'],       'Titolo notizia',    'entity.meta.name (primary language)');
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
assert_null($meta3['type_id'],                'Minimal: type_id is null when missing');
assert_null($meta3['version'],                'Minimal: version is null when missing');
assert_null($meta3['published_at'],           'Minimal: published_at is null when missing');
assert_null($meta3['updated_at'],             'Minimal: updated_at is null when missing');
assert_eq($meta3['languages'],  [],           'Minimal: languages is empty array');
assert_eq($meta3['name'],       '',           'Minimal: name is empty string when missing');
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
assert_eq(count($data4['attachments']), 2, 'Relation list: 2 item preservati');
assert_eq($data4['attachments'][0]['remote_id'],       'file-abc-123', 'remoteId → remote_id normalizzato');
assert_eq($data4['attachments'][0]['class_identifier'], 'file',        'classIdentifier → class_identifier normalizzato');
assert_eq($data4['attachments'][0]['main_node_id'],    '210',          'mainNodeId → main_node_id normalizzato');
assert_eq($data4['attachments'][0]['name'],            'Relazione annuale.pdf', 'Campo senza rename preservato');
assert_false(isset($data4['attachments'][0]['remoteId']),       'remoteId camelCase rimosso dopo normalizzazione');
assert_false(isset($data4['attachments'][0]['classIdentifier']),'classIdentifier camelCase rimosso dopo normalizzazione');
assert_false(isset($data4['attachments'][0]['mainNodeId']),     'mainNodeId camelCase rimosso dopo normalizzazione');
assert_eq($data4['attachments'][1]['remote_id'], 'file-def-456', 'Secondo item: remote_id normalizzato');

// Relation items già in snake_case: pass-through senza modifiche
assert_eq($data4['topics'][0]['remote_id'],       'topic-xyz', 'snake_case remote_id pass-through');
assert_eq($data4['topics'][0]['class_identifier'], 'tag',      'snake_case class_identifier pass-through');
assert_eq($data4['topics'][0]['main_node_id'],    '501',       'snake_case main_node_id pass-through');

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
// TEST 11: date strings in entity.data normalised to UTC
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
