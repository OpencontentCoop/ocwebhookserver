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

// ── TEST 9: insight risolve la mappa di article via variantAlias ─────────────

assert_eq(
    OCWebHookKafkaFieldMap::getMap('insight'),
    OCWebHookKafkaFieldMap::getMap('article'),
    'insight resolves to article map via variantAlias'
);
$insightMap = OCWebHookKafkaFieldMap::getMap('insight');
assert_eq($insightMap['published'],  'published_date', 'insight: published → published_date (via article alias)');
assert_eq($insightMap['dead_line'],  'deadline_date',  'insight: dead_line → deadline_date (via article alias)');

// ── TEST 10: howto ha nessuna rename (tutti i campi già canonici) ─────────────

assert_eq(OCWebHookKafkaFieldMap::getMap('howto'), [], 'howto has no renames (all fields already canonical)');

// ── TEST 11: itinerary — rimozione prefisso ridondante ───────────────────────────────────

$itineraryMap = OCWebHookKafkaFieldMap::getMap('itinerary');
assert_eq($itineraryMap['itinerary_types'],        'types',        'itinerary: itinerary_types → types');
assert_eq($itineraryMap['itinerary_difficulties'], 'difficulties', 'itinerary: itinerary_difficulties → difficulties');
assert_true(count($itineraryMap) === 2,                            'itinerary map has exactly 2 entries');

// ── TEST 12: pagina_trasparenza — tutti i campi italiani rinominati ──────────

$ptMap = OCWebHookKafkaFieldMap::getMap('pagina_trasparenza');
assert_eq($ptMap['titolo'],                       'title',                  'pt: titolo → title');
assert_eq($ptMap['contenuto_obbligo'],            'obligation_content',     'pt: contenuto_obbligo → obligation_content');
assert_eq($ptMap['riferimenti_normativi'],        'legislative_references', 'pt: riferimenti_normativi → legislative_references');
assert_eq($ptMap['applicabilita'],                'applicability',          'pt: applicabilita → applicability');
assert_eq($ptMap['denominazione_degli_obblighi'], 'obligation_name',        'pt: denominazione_degli_obblighi → obligation_name');
assert_eq($ptMap['guida_alla_compilazione'],      'compilation_guide',      'pt: guida_alla_compilazione → compilation_guide');
assert_eq($ptMap['messaggio_di_consiglio'],       'advice_message',         'pt: messaggio_di_consiglio → advice_message');
assert_eq($ptMap['decorrenza_di_pubblicazione'],  'publication_start_policy',      'pt: decorrenza_di_pubblicazione → publication_start');
assert_eq($ptMap['aggiornamento'],                'update_frequency',       'pt: aggiornamento → update_frequency');
assert_eq($ptMap['termine_pubblicazione'],        'publication_end_policy',        'pt: termine_pubblicazione → publication_end');
assert_true(count($ptMap) === 10,                                            'pagina_trasparenza map has exactly 10 entries');

// ── TEST 13: public_project ───────────────────────────────────────────────────

$ppMap = OCWebHookKafkaFieldMap::getMap('public_project');
assert_eq($ppMap['published'],        'published_date', 'public_project: published → published_date (ezdate)');
assert_eq($ppMap['has_status'],       'status',         'public_project: has_status (eztags scalar) → status');
assert_eq($ppMap['has_status_notes'], 'status_notes',   'public_project: has_status_notes (ezxmltext scalar) → status_notes');
assert_true(count($ppMap) === 3,                         'public_project map has exactly 3 entries');

// ── Results ───────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) { echo ", \033[31m{$FAILED} failed\033[0m"; }
echo "\n";

exit($FAILED > 0 ? 1 : 0);
