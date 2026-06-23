<?php

/**
 * Test: content_url nel payload di emit_all_published
 *
 * Verifica che:
 *   1. buildContentUrl() costruisce l'URL correttamente da baseUrl + urlAlias
 *   2. Il formatter produce entity.meta.content_url quando metadata.contentUrl è presente
 *      (contratto che emit_all_published DEVE rispettare)
 *   3. content_url è null se contentUrl non viene impostato nello script
 *
 * Senza bootstrap eZ — test puri sulla logica di costruzione URL.
 *
 * Usage:
 *   php tests/EmitAllPublishedContentUrlTest.php
 */

require_once __DIR__ . '/../classes/ocwebhookpayloadbuilder.php';
require_once __DIR__ . '/../classes/ocwebhookkafkapayloadformatter.php';

// ── Helpers ───────────────────────────────────────────────────────────────────

$PASSED = 0;
$FAILED = 0;
$LOG    = [];

function ok($name)              { global $PASSED, $LOG; $PASSED++; $LOG[] = "\033[32m[PASS]\033[0m $name"; }
function fail($name, $why = '') { global $FAILED, $LOG; $FAILED++; $LOG[] = "\033[31m[FAIL]\033[0m $name" . ($why ? " — $why" : ''); }
function assert_eq($a, $b, $name, $why = '') {
    $a === $b ? ok($name) : fail($name, "expected " . var_export($b, true) . ", got " . var_export($a, true) . ($why ? " — $why" : ''));
}
function assert_null($v, $name) {
    $v === null ? ok($name) : fail($name, "expected null, got " . var_export($v, true));
}

// ── Helper: buildContentUrl (logica usata da emit_all_published e WorkflowWebHookType) ───

/**
 * Costruisce il content_url a partire da baseUrl e urlAlias del nodo principale.
 * Corrisponde alla logica in WorkflowWebHookType e che emit_all_published deve usare.
 */
function buildContentUrl($baseUrl, $urlAlias)
{
    return rtrim($baseUrl, '/') . '/' . ltrim($urlAlias, '/');
}

// ── TEST 1: buildContentUrl — costruisce URL correttamente ────────────────────

assert_eq(
    buildContentUrl('https://www.comune.example.it', 'novita/notizie/mia-notizia'),
    'https://www.comune.example.it/novita/notizie/mia-notizia',
    'buildContentUrl: baseUrl senza slash + urlAlias senza slash iniziale'
);

assert_eq(
    buildContentUrl('https://www.comune.example.it', '/novita/notizie/mia-notizia'),
    'https://www.comune.example.it/novita/notizie/mia-notizia',
    'buildContentUrl: urlAlias con slash iniziale viene rimosso'
);

assert_eq(
    buildContentUrl('https://www.comune.example.it/', 'servizi/anagrafe'),
    'https://www.comune.example.it/servizi/anagrafe',
    'buildContentUrl: baseUrl con slash finale viene normalizzato'
);

assert_eq(
    buildContentUrl('https://www.comune.example.it', ''),
    'https://www.comune.example.it/',
    'buildContentUrl: urlAlias vuoto produce URL con solo trailing slash'
);

// ── TEST 2: il formatter usa metadata.contentUrl → entity.meta.content_url ───
// Questo è il contratto che emit_all_published DEVE rispettare:
// prima di chiamare l'emitter, deve impostare $payload['metadata']['contentUrl'].

$baseUrl  = 'https://www.comune.example.it';
$urlAlias = 'novita/notizie/bilancio-2026';
$contentUrl = buildContentUrl($baseUrl, $urlAlias);

$payloadConContentUrl = [
    'metadata' => [
        'id'        => '999',
        'languages' => ['it-IT'],
        'name'      => ['it-IT' => 'Bilancio 2026'],
        'baseUrl'   => $baseUrl,
        'contentUrl'=> $contentUrl,  // ← emit_all_published deve impostare questo
    ],
    'data' => [],
];

$fm = new OCWebHookKafkaPayloadFormatter('frontend', 'comune_it');
$result = $fm->format($payloadConContentUrl);

assert_eq(
    $result['entity']['meta']['content_url'],
    'https://www.comune.example.it/novita/notizie/bilancio-2026',
    'formatter: content_url presente quando emit_all_published imposta metadata.contentUrl'
);

// ── TEST 3: senza contentUrl (bug attuale di emit_all_published) → content_url null ──
// Documenta il problema: se emit_all_published NON imposta contentUrl, il campo è null.

$payloadSenzaContentUrl = [
    'metadata' => [
        'id'        => '998',
        'languages' => ['it-IT'],
        'name'      => ['it-IT' => 'Test'],
        'baseUrl'   => $baseUrl,
        // contentUrl NON impostato — bug attuale di emit_all_published
    ],
    'data' => [],
];

$resultSenza = $fm->format($payloadSenzaContentUrl);

assert_null(
    $resultSenza['entity']['meta']['content_url'],
    'senza contentUrl in metadata, entity.meta.content_url è null (bug: emit_all_published non lo imposta)'
);

// ── Results ───────────────────────────────────────────────────────────────────

echo implode("\n", $LOG) . "\n";
echo "\n" . str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) echo ", \033[31m{$FAILED} failed\033[0m";
echo "\n";
exit($FAILED > 0 ? 1 : 0);
