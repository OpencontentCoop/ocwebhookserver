<?php

/**
 * Integration test: OCWebHookKafkaPayloadFormatter applies canonical field names.
 *
 * Verifies that the formatter renames entity.data fields using OCWebHookKafkaFieldMap,
 * that unmapped fields pass through unchanged, and that the rename applies to all languages.
 *
 * No eZ Publish bootstrap or Kafka broker needed.
 *
 * Usage (inside container):
 *   php /var/www/html/extension/ocwebhookserver/tests/PayloadFormatterRenameTest.php
 */

require_once __DIR__ . '/../classes/ocwebhookkafkafieldmap.php';
require_once __DIR__ . '/../classes/ocwebhookkafkapayloadformatter.php';

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a, $b, string $t, string $r = ''): void
{
    if ($a === $b) { ok($t); }
    else { fail($t, sprintf("expected %s, got %s. %s", var_export($b, true), var_export($a, true), $r)); }
}
function assert_true(bool $v, string $t, string $r = ''): void { $v ? ok($t) : fail($t, $r); }

$formatter = new OCWebHookKafkaPayloadFormatter('comune_it');

// ── TEST 1: article fields renamed ────────────────────────────────────────────

$articlePayload = [
    'metadata' => [
        'id'              => '10',
        'classIdentifier' => 'article',
        'languages'       => ['it-IT'],
        'name'            => ['it-IT' => 'Test notizia'],
    ],
    'data' => [
        'it-IT' => [
            'title'         => ['content' => 'Test notizia'],
            'abstract'      => ['content' => 'Abstract'],
            'published'     => ['content' => '2026-01-15'],   // → published_date
            'dead_line'     => ['content' => '2026-06-30'],   // → deadline_date
            'id_comunicato' => ['content' => 'COM-2026-001'], // → notice_id
            'attachment'    => ['content' => []],             // → attachments
        ],
    ],
];

$result = $formatter->format($articlePayload);
$data   = $result['entity']['data']['it-IT'];

assert_eq($data['published_date'], '2026-01-15',   'article: published renamed to published_date');
assert_eq($data['deadline_date'],  '2026-06-30',   'article: dead_line renamed to deadline_date');
assert_eq($data['notice_id'],      'COM-2026-001', 'article: id_comunicato renamed to notice_id');
assert_eq($data['attachments'],    [],             'article: attachment renamed to attachments');

assert_true(!array_key_exists('published',     $data), 'article: old key "published" not present');
assert_true(!array_key_exists('dead_line',     $data), 'article: old key "dead_line" not present');
assert_true(!array_key_exists('id_comunicato', $data), 'article: old key "id_comunicato" not present');
assert_true(!array_key_exists('attachment',    $data), 'article: old key "attachment" not present');

assert_eq($data['title'],    'Test notizia', 'article: title unchanged (already canonical)');
assert_eq($data['abstract'], 'Abstract',     'article: abstract unchanged (already canonical)');

// ── TEST 2: rename applies to all languages ───────────────────────────────────

$multiLangPayload = [
    'metadata' => [
        'id'              => '11',
        'classIdentifier' => 'article',
        'languages'       => ['it-IT', 'eng-GB'],
        'name'            => ['it-IT' => 'Test'],
    ],
    'data' => [
        'it-IT'  => ['published' => ['content' => '2026-02-01']],
        'eng-GB' => ['published' => ['content' => '2026-02-01']],
    ],
];

$result2 = $formatter->format($multiLangPayload);

assert_eq($result2['entity']['data']['it-IT']['published_date'],  '2026-02-01', 'it-IT: published renamed');
assert_eq($result2['entity']['data']['eng-GB']['published_date'], '2026-02-01', 'eng-GB: published renamed');
assert_true(!array_key_exists('published', $result2['entity']['data']['it-IT']),  'it-IT: old key removed');
assert_true(!array_key_exists('published', $result2['entity']['data']['eng-GB']), 'eng-GB: old key removed');

// ── TEST 3: unmapped content type — all fields pass through unchanged ──────────

$unknownPayload = [
    'metadata' => [
        'id'              => '20',
        'classIdentifier' => 'contatti',
        'languages'       => ['it-IT'],
        'name'            => ['it-IT' => 'Test'],
    ],
    'data' => [
        'it-IT' => [
            'name'     => ['content' => 'Ufficio'],
            'telefono' => ['content' => '0123456789'],
        ],
    ],
];

$result3 = $formatter->format($unknownPayload);
$data3   = $result3['entity']['data']['it-IT'];

assert_eq($data3['name'],     'Ufficio',    'unknown type: name passes through');
assert_eq($data3['telefono'], '0123456789', 'unknown type: Italian field passes through as-is');

// ── TEST 4: event — prefix removal ────────────────────────────────────────────

$eventPayload = [
    'metadata' => [
        'id'              => '30',
        'classIdentifier' => 'event',
        'languages'       => ['it-IT'],
        'name'            => ['it-IT' => 'Sagra'],
    ],
    'data' => [
        'it-IT' => [
            'event_title'       => ['content' => 'Sagra del tartufo'],
            'short_event_title' => ['content' => 'Sagra'],
            'event_abstract'    => ['content' => 'Descrizione breve'],
            'topics'            => ['content' => []],
        ],
    ],
];

$result4 = $formatter->format($eventPayload);
$data4   = $result4['entity']['data']['it-IT'];

assert_eq($data4['title'],       'Sagra del tartufo', 'event: event_title renamed to title');
assert_eq($data4['short_title'], 'Sagra',             'event: short_event_title renamed to short_title');
assert_eq($data4['abstract'],    'Descrizione breve', 'event: event_abstract renamed to abstract');
assert_eq($data4['topics'],      [],                  'event: topics passes through unchanged');
assert_true(!array_key_exists('event_title',       $data4), 'event: old key event_title removed');
assert_true(!array_key_exists('short_event_title', $data4), 'event: old key short_event_title removed');
assert_true(!array_key_exists('event_abstract',    $data4), 'event: old key event_abstract removed');

// ── TEST 5: article_with_projects variant resolves to article map ─────────────

$variantPayload = [
    'metadata' => [
        'id'              => '40',
        'classIdentifier' => 'article_with_projects',
        'languages'       => ['it-IT'],
        'name'            => ['it-IT' => 'Notizia'],
    ],
    'data' => [
        'it-IT' => [
            'published' => ['content' => '2026-03-01'],
            'title'     => ['content' => 'Notizia con progetti'],
        ],
    ],
];

$result5 = $formatter->format($variantPayload);
$data5   = $result5['entity']['data']['it-IT'];

assert_eq($data5['published_date'], '2026-03-01',           'article_with_projects: published renamed via variant alias');
assert_eq($data5['title'],          'Notizia con progetti', 'article_with_projects: title unchanged');

// ── Results ───────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) { echo ", \033[31m{$FAILED} failed\033[0m"; }
echo "\n";

exit($FAILED > 0 ? 1 : 0);
