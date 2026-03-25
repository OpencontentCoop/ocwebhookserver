<?php

/**
 * Unit tests for OCWebHookKafkaFieldMap.
 *
 * Verifies that per-content-type rename maps are correct and that
 * _with_related variant types resolve to their base type map.
 *
 * No eZ Publish bootstrap or Kafka broker needed.
 *
 * Usage:
 *   php tests/FieldMapTest.php
 */

require_once __DIR__ . '/../classes/ocwebhookkafkafieldmap.php';

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

// ── TEST 1: unmapped type returns empty array ─────────────────────────────────

assert_eq(OCWebHookKafkaFieldMap::getMap('contatti'), [], 'Unknown type returns empty array');
assert_eq(OCWebHookKafkaFieldMap::getMap(''), [], 'Empty string type returns empty array');

// ── TEST 2: public_service has no renames ─────────────────────────────────────

assert_eq(OCWebHookKafkaFieldMap::getMap('public_service'), [], 'public_service has no renames (all fields already canonical)');

// ── TEST 3: article map ───────────────────────────────────────────────────────

$articleMap = OCWebHookKafkaFieldMap::getMap('article');

assert_eq($articleMap['published'],       'published_date',   'article: published → published_date');
assert_eq($articleMap['dead_line'],       'deadline_date',    'article: dead_line → deadline_date');
assert_eq($articleMap['id_comunicato'],   'notice_id',        'article: id_comunicato → notice_id');
assert_eq($articleMap['attachment'],      'attachments',      'article: attachment → attachments');
assert_eq($articleMap['dataset'],         'datasets',         'article: dataset → datasets');
assert_eq($articleMap['related_service'], 'related_services', 'article: related_service → related_services');
assert_true(count($articleMap) === 6,                         'article map has exactly 6 entries');

// ── TEST 4: event map ─────────────────────────────────────────────────────────

$eventMap = OCWebHookKafkaFieldMap::getMap('event');

assert_eq($eventMap['event_title'],       'title',       'event: event_title → title');
assert_eq($eventMap['short_event_title'], 'short_title', 'event: short_event_title → short_title');
assert_eq($eventMap['event_abstract'],    'abstract',    'event: event_abstract → abstract');

// ── TEST 5: time_indexed_role — misleading _time suffix on ezdate fields ──────

$roleMap = OCWebHookKafkaFieldMap::getMap('time_indexed_role');

assert_eq($roleMap['start_time'],            'start_date',         'time_indexed_role: start_time → start_date (ezdate)');
assert_eq($roleMap['end_time'],              'end_date',           'time_indexed_role: end_time → end_date (ezdate)');
assert_eq($roleMap['data_insediamento'],     'inauguration_date',  'time_indexed_role: data_insediamento → inauguration_date');
assert_eq($roleMap['compensi'],              'compensations',      'time_indexed_role: compensi → compensations');
assert_eq($roleMap['importi'],               'amounts',            'time_indexed_role: importi → amounts');
assert_eq($roleMap['incarico_dirigenziale'], 'executive_position', 'time_indexed_role: incarico_dirigenziale → executive_position');
assert_eq($roleMap['ruolo_principale'],      'primary_role',       'time_indexed_role: ruolo_principale → primary_role');
assert_eq($roleMap['priorita'],              'priority',           'time_indexed_role: priorita → priority');

// ── TEST 6: opening_hours_specification ───────────────────────────────────────

$ohsMap = OCWebHookKafkaFieldMap::getMap('opening_hours_specification');

assert_eq($ohsMap['valid_from'],    'valid_from_date',    'ohs: valid_from → valid_from_date');
assert_eq($ohsMap['valid_through'], 'valid_through_date', 'ohs: valid_through → valid_through_date');
assert_eq($ohsMap['note'],          'notes',              'ohs: note → notes');
assert_eq($ohsMap['stagionalita'],  'seasonality',        'ohs: stagionalita → seasonality');

// ── TEST 7: scalar has_* fields renamed ───────────────────────────────────────

assert_eq(OCWebHookKafkaFieldMap::getMap('document')['has_code'],        'code',         'document: has_code (ezstring) → code');
assert_eq(OCWebHookKafkaFieldMap::getMap('place')['has_video'],          'video_url',    'place: has_video (ezstring) → video_url');
assert_eq(OCWebHookKafkaFieldMap::getMap('channel')['has_channel_type'], 'channel_type', 'channel: has_channel_type (eztags) → channel_type');

// ── TEST 8: _with_related variants resolve to base map ───────────────────────

assert_eq(
    OCWebHookKafkaFieldMap::getMap('article_with_projects'),
    OCWebHookKafkaFieldMap::getMap('article'),
    'article_with_projects resolves to article map'
);
assert_eq(
    OCWebHookKafkaFieldMap::getMap('event_with_related'),
    OCWebHookKafkaFieldMap::getMap('event'),
    'event_with_related resolves to event map'
);
assert_eq(
    OCWebHookKafkaFieldMap::getMap('private_organization'),
    OCWebHookKafkaFieldMap::getMap('organization'),
    'private_organization resolves to organization map'
);
assert_eq(
    OCWebHookKafkaFieldMap::getMap('opening_hours_specification_with_related'),
    OCWebHookKafkaFieldMap::getMap('opening_hours_specification'),
    'opening_hours_specification_with_related resolves to opening_hours_specification map'
);
assert_eq(
    OCWebHookKafkaFieldMap::getMap('image_with_related'),
    [],
    'image_with_related resolves to image map (empty — no renames)'
);

// ── Results ───────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) { echo ", \033[31m{$FAILED} failed\033[0m"; }
echo "\n";

exit($FAILED > 0 ? 1 : 0);
