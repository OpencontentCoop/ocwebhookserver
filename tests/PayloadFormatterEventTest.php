<?php

/**
 * Tests for OCWebHookKafkaPayloadFormatter — event content type.
 *
 * Verifies:
 *  1. time_interval is flattened into start_at / end_at / recurrences
 *  2. event_title → title, event_abstract → abstract renames applied
 *  3. event_with_related inherits the same transforms
 *  4. Non-event content types are NOT affected (time_interval passes through)
 *  5. Missing / null time_interval handled gracefully
 *  6. Schema coherence: output fields match the schemas/website/comuni/event/v1.json contract
 *
 * No eZ Publish bootstrap or Kafka broker needed.
 *
 * Usage:
 *   php tests/PayloadFormatterEventTest.php
 */

require_once __DIR__ . '/../classes/ocwebhookkafkafieldmap.php';
require_once __DIR__ . '/../classes/ocwebhookkafkapayloadformatter.php';

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void
{
    global $PASSED;
    $PASSED++;
    echo "\033[32m[PASS]\033[0m $name\n";
}

function fail(string $name, string $reason = ''): void
{
    global $FAILED;
    $FAILED++;
    echo "\033[31m[FAIL]\033[0m $name" . ($reason ? " — $reason" : '') . "\n";
}

function assert_eq($actual, $expected, string $test, string $reason = ''): void
{
    if ($actual === $expected) {
        ok($test);
    } else {
        fail($test, sprintf(
            "expected %s, got %s%s",
            var_export($expected, true),
            var_export($actual, true),
            $reason ? ". $reason" : ''
        ));
    }
}

function assert_true(bool $value, string $test, string $reason = ''): void
{
    $value ? ok($test) : fail($test, $reason);
}

function assert_null($value, string $test): void
{
    $value === null ? ok($test) : fail($test, 'expected null, got ' . var_export($value, true));
}

function assert_false(bool $value, string $test, string $reason = ''): void
{
    (!$value) ? ok($test) : fail($test, $reason);
}

function assert_isset(array $arr, string $key, string $test): void
{
    isset($arr[$key]) ? ok($test) : fail($test, "key '$key' not found in array");
}

function assert_not_isset(array $arr, string $key, string $test): void
{
    !isset($arr[$key]) ? ok($test) : fail($test, "key '$key' unexpectedly present");
}

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures
// ─────────────────────────────────────────────────────────────────────────────

/** Build a minimal ocopendata event payload with a time_interval. */
function makeEventPayload(string $classIdentifier = 'event', array $extraData = []): array
{
    $timeInterval = [
        'events' => [
            ['start' => '2026-06-01T10:00:00+02:00', 'end' => '2026-06-01T12:00:00+02:00'],
            ['start' => '2026-06-08T10:00:00+02:00', 'end' => '2026-06-08T12:00:00+02:00'],
        ],
        'default_value' => [
            'count'     => 2,
            'from_time' => '2026-06-01T10:00:00+02:00',
            'to_time'   => '2026-06-01T12:00:00+02:00',
        ],
    ];

    $baseData = [
        'event_title'    => ['content' => 'Sagra del tartufo'],
        'event_abstract' => ['content' => '<p>Una bella sagra</p>'],
        'time_interval'  => ['content' => $timeInterval],
        'topics'         => ['content' => []],
    ];

    return [
        'metadata' => [
            'id'              => '77',
            'classIdentifier' => $classIdentifier,
            'languages'       => ['it-IT'],
            'name'            => ['it-IT' => 'Sagra del tartufo'],
            'published'       => '1748772000',
            'modified'        => '1748772000',
            'baseUrl'         => 'https://www.comune.example.it',
        ],
        'data' => [
            'it-IT' => array_merge($baseData, $extraData),
        ],
    ];
}

$formatter = new OCWebHookKafkaPayloadFormatter('frontend', 'bugliano', 'bugliano');

// ─────────────────────────────────────────────────────────────────────────────
// TEST SUITE 1 — time_interval flattening
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== Suite 1: time_interval flattening ===\n";

$result  = $formatter->format(makeEventPayload('event'));
$lang    = $result['entity']['data']['it-IT'];

assert_not_isset($lang, 'time_interval', '1.1 time_interval rimosso dai campi top-level');
assert_isset($lang, 'start_at',    '1.2 start_at presente');
assert_isset($lang, 'end_at',      '1.3 end_at presente');
assert_isset($lang, 'recurrences', '1.4 recurrences presente');

// Verifica normalizzazione UTC (+02:00 → Z)
assert_eq($lang['start_at'], '2026-06-01T08:00:00Z', '1.5 start_at normalizzato in UTC');
assert_eq($lang['end_at'],   '2026-06-01T10:00:00Z', '1.6 end_at normalizzato in UTC');

// Verifica struttura recurrences
assert_true(is_array($lang['recurrences']),  '1.7 recurrences è un array');
assert_eq(count($lang['recurrences']), 2,    '1.8 recurrences ha 2 elementi');
assert_isset($lang['recurrences'][0], 'start_at', '1.9 recurrence item ha start_at');
assert_isset($lang['recurrences'][0], 'end_at',   '1.10 recurrence item ha end_at');
assert_eq($lang['recurrences'][0]['start_at'], '2026-06-01T08:00:00Z', '1.11 recurrence[0].start_at UTC');
assert_eq($lang['recurrences'][1]['start_at'], '2026-06-08T08:00:00Z', '1.12 recurrence[1].start_at UTC');

// ─────────────────────────────────────────────────────────────────────────────
// TEST SUITE 2 — FieldMap renames per event
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== Suite 2: FieldMap renames per event ===\n";

assert_eq($lang['title'],    'Sagra del tartufo',    '2.1 event_title → title');
assert_eq($lang['abstract'], '<p>Una bella sagra</p>', '2.2 event_abstract → abstract');
assert_not_isset($lang, 'event_title',    '2.3 event_title rimosso');
assert_not_isset($lang, 'event_abstract', '2.4 event_abstract rimosso');

// ─────────────────────────────────────────────────────────────────────────────
// TEST SUITE 3 — event_with_related eredita lo stesso comportamento
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== Suite 3: event_with_related ===\n";

$resultVariant = $formatter->format(makeEventPayload('event_with_related'));
$langVariant   = $resultVariant['entity']['data']['it-IT'];

assert_not_isset($langVariant, 'time_interval',  '3.1 time_interval rimosso anche per event_with_related');
assert_isset($langVariant, 'start_at',           '3.2 start_at presente per event_with_related');
assert_eq($langVariant['title'], 'Sagra del tartufo', '3.3 renames applicati per event_with_related');

// ─────────────────────────────────────────────────────────────────────────────
// TEST SUITE 4 — Non-event content type non viene modificato
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== Suite 4: time_interval non toccato per altri CT ===\n";

$articleWithTi = [
    'metadata' => [
        'id'              => '99',
        'classIdentifier' => 'article',
        'languages'       => ['it-IT'],
        'name'            => ['it-IT' => 'Notizia'],
    ],
    'data' => [
        'it-IT' => [
            'title'         => ['content' => 'Notizia'],
            'time_interval' => ['content' => ['events' => [], 'default_value' => []]],
        ],
    ],
];

$resultArticle = $formatter->format($articleWithTi);
$langArticle   = $resultArticle['entity']['data']['it-IT'];

assert_isset($langArticle, 'time_interval', '4.1 time_interval NON rimosso per article');
assert_not_isset($langArticle, 'start_at',  '4.2 start_at NON aggiunto per article');

// ─────────────────────────────────────────────────────────────────────────────
// TEST SUITE 5 — Gestione casi limite
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== Suite 5: casi limite ===\n";

// time_interval assente
$noTiPayload = makeEventPayload('event', []);
unset($noTiPayload['data']['it-IT']['time_interval']);
$resultNoTi = $formatter->format($noTiPayload);
$langNoTi   = $resultNoTi['entity']['data']['it-IT'];

assert_not_isset($langNoTi, 'time_interval', '5.1 time_interval assente: nessun errore');
assert_not_isset($langNoTi, 'start_at',      '5.2 time_interval assente: start_at non aggiunto');

// time_interval null (content: null)
$nullTiPayload = makeEventPayload('event');
$nullTiPayload['data']['it-IT']['time_interval'] = ['content' => null];
$resultNullTi = $formatter->format($nullTiPayload);
$langNullTi   = $resultNullTi['entity']['data']['it-IT'];

// null content → [] per normalizzazione outbox, poi flattenTimeInterval non trova time_interval come array
assert_not_isset($langNullTi, 'time_interval', '5.3 time_interval null: rimosso o assente');

// evento singolo (1 occorrenza)
$singleEventPayload = makeEventPayload('event');
$singleEventPayload['data']['it-IT']['time_interval']['content'] = [
    'events'        => [['start' => '2026-07-04T15:00:00Z', 'end' => '2026-07-04T17:00:00Z']],
    'default_value' => ['count' => 1, 'from_time' => '2026-07-04T15:00:00Z', 'to_time' => '2026-07-04T17:00:00Z'],
];
$resultSingle = $formatter->format($singleEventPayload);
$langSingle   = $resultSingle['entity']['data']['it-IT'];

assert_eq(count($langSingle['recurrences']), 1, '5.4 evento singolo: recurrences ha 1 elemento');
assert_eq($langSingle['start_at'], '2026-07-04T15:00:00Z', '5.5 evento singolo: start_at corretto');

// ─────────────────────────────────────────────────────────────────────────────
// TEST SUITE 6 — Coerenza schema: campi obbligatori e tipi
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== Suite 6: coerenza schema event/v1.json ===\n";

$result = $formatter->format(makeEventPayload('event'));
$meta   = $result['entity']['meta'];
$data   = $result['entity']['data']['it-IT'];

// Campi obbligatori da schema (required: id, title, tenant_id, created_at, updated_at)
assert_true(
    isset($meta['id']) && is_string($meta['id']),
    '6.1 meta.id presente e stringa'
);
assert_true(
    strpos($meta['id'], ':') !== false,
    '6.2 meta.id formato tenantId:objectId (contiene ":")'
);
assert_true(
    isset($meta['tenant_id']) && is_string($meta['tenant_id']),
    '6.3 meta.tenant_id presente e stringa (non UUID)'
);
assert_false(
    (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/', $meta['tenant_id']),
    '6.4 meta.tenant_id NON è un UUID'
);
assert_true(isset($data['title']),    '6.5 data.title presente (required)');
assert_true(is_string($data['title']), '6.6 data.title è stringa');

// start_at / end_at: stringa ISO 8601 UTC o null
assert_true(
    $data['start_at'] === null || (is_string($data['start_at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['start_at'])),
    '6.7 start_at è null o ISO 8601 UTC'
);
assert_true(
    $data['end_at'] === null || (is_string($data['end_at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['end_at'])),
    '6.8 end_at è null o ISO 8601 UTC'
);

// recurrences: array di oggetti con start_at e end_at
assert_true(is_array($data['recurrences']), '6.9 recurrences è array');
foreach ($data['recurrences'] as $i => $rec) {
    assert_true(
        is_array($rec) && array_key_exists('start_at', $rec) && array_key_exists('end_at', $rec),
        "6.10 recurrences[$i] ha start_at e end_at"
    );
    assert_true(
        $rec['start_at'] === null || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $rec['start_at']),
        "6.11 recurrences[$i].start_at è UTC"
    );
}

// topics: array (vuoto ok, ma deve essere array)
assert_true(is_array($data['topics']), '6.12 topics è array');

// ─────────────────────────────────────────────────────────────────────────────
// Risultato finale
// ─────────────────────────────────────────────────────────────────────────────

echo "\n";
if ($FAILED === 0) {
    echo "\033[32m✓ Tutti i {$PASSED} test passati.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m✗ {$FAILED} test falliti su " . ($PASSED + $FAILED) . ".\033[0m\n\n";
    exit(1);
}
