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

// ── TEST 6: pagina_trasparenza — tutte le rename applicate end-to-end ─────────

$ptPayload = [
    'metadata' => [
        'id'              => '100',
        'classIdentifier' => 'pagina_trasparenza',
        'languages'       => ['it-IT'],
        'name'            => ['it-IT' => 'Pubblicazione degli atti'],
    ],
    'data' => [
        'it-IT' => [
            'titolo'                       => ['content' => 'Pubblicazione degli atti'],
            'contenuto_obbligo'            => ['content' => '<p>Testo obbligo</p>'],
            'riferimenti_normativi'        => ['content' => 'Art. 23 D.Lgs. 33/2013'],
            'applicabilita'                => ['content' => '<p>Applicabile</p>'],
            'denominazione_degli_obblighi' => ['content' => '<p>Denominazione</p>'],
            'guida_alla_compilazione'      => ['content' => '<p>Guida</p>'],
            'messaggio_di_consiglio'       => ['content' => '<p>Consiglio</p>'],
            'decorrenza_di_pubblicazione'  => ['content' => 'Immediata'],
            'aggiornamento'                => ['content' => 'Annuale'],
            'termine_pubblicazione'        => ['content' => 'Non specificato'],
            'fields'                       => ['content' => 'document!name,abstract'],
        ],
    ],
];

$formatter6 = new OCWebHookKafkaPayloadFormatter('frontend', 'opencity');
$result6    = $formatter6->format($ptPayload);
$data6      = $result6['entity']['data']['it-IT'];

// Campi rinominati presenti con nome canonico
assert_eq($data6['title'],                  'Pubblicazione degli atti',   'pt: titolo → title');
assert_eq($data6['obligation_content'],     '<p>Testo obbligo</p>',       'pt: contenuto_obbligo → obligation_content');
assert_eq($data6['legislative_references'], 'Art. 23 D.Lgs. 33/2013',    'pt: riferimenti_normativi → legislative_references');
assert_eq($data6['applicability'],          '<p>Applicabile</p>',         'pt: applicabilita → applicability');
assert_eq($data6['obligation_name'],        '<p>Denominazione</p>',       'pt: denominazione_degli_obblighi → obligation_name');
assert_eq($data6['compilation_guide'],      '<p>Guida</p>',               'pt: guida_alla_compilazione → compilation_guide');
assert_eq($data6['advice_message'],         '<p>Consiglio</p>',           'pt: messaggio_di_consiglio → advice_message');
assert_eq($data6['publication_start'],      'Immediata',                  'pt: decorrenza_di_pubblicazione → publication_start');
assert_eq($data6['update_frequency'],       'Annuale',                    'pt: aggiornamento → update_frequency');
assert_eq($data6['publication_end'],        'Non specificato',            'pt: termine_pubblicazione → publication_end');
assert_eq($data6['fields'],                 'document!name,abstract',     'pt: fields passa through invariato (già inglese)');

// Campi originali italiani non presenti
assert_true(!array_key_exists('titolo',                       $data6), 'pt: titolo rimosso');
assert_true(!array_key_exists('contenuto_obbligo',            $data6), 'pt: contenuto_obbligo rimosso');
assert_true(!array_key_exists('riferimenti_normativi',        $data6), 'pt: riferimenti_normativi rimosso');
assert_true(!array_key_exists('applicabilita',                $data6), 'pt: applicabilita rimosso');
assert_true(!array_key_exists('denominazione_degli_obblighi', $data6), 'pt: denominazione_degli_obblighi rimosso');
assert_true(!array_key_exists('guida_alla_compilazione',      $data6), 'pt: guida_alla_compilazione rimosso');
assert_true(!array_key_exists('messaggio_di_consiglio',       $data6), 'pt: messaggio_di_consiglio rimosso');
assert_true(!array_key_exists('decorrenza_di_pubblicazione',  $data6), 'pt: decorrenza_di_pubblicazione rimosso');
assert_true(!array_key_exists('aggiornamento',                $data6), 'pt: aggiornamento rimosso');
assert_true(!array_key_exists('termine_pubblicazione',        $data6), 'pt: termine_pubblicazione rimosso');

// ── Results ───────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) { echo ", \033[31m{$FAILED} failed\033[0m"; }
echo "\n";

exit($FAILED > 0 ? 1 : 0);
