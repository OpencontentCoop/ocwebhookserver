<?php

/**
 * Unit tests for OCWebHookPayloadBuilder::forceHttps().
 *
 * Bug: eZSys::serverURL() restituisce http:// quando il sito è dietro un reverse
 * proxy con SSL termination (nginx/Varnish). Tutti gli URL del payload Kafka
 * derivano da quel baseUrl: site_url, content_url, content_url nelle relation item,
 * api_url. Finiscono quindi tutti in http.
 *
 * Fix: forceHttps() applicato su baseUrl subito dopo eZSys::serverURL() e su
 * apiUrl dopo che ocopenapi lo costruisce.
 *
 * No eZ Publish bootstrap needed — il metodo è puro PHP.
 *
 * Usage:
 *   php tests/PayloadBuilderForceHttpsTest.php
 */

require_once __DIR__ . '/../classes/ocwebhookpayloadbuilder.php';

$PASSED = 0;
$FAILED = 0;

function okH(string $name): void    { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function failH(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eqH($a, $b, string $t): void
{
    if ($a === $b) {
        okH($t);
    } else {
        failH($t, sprintf("expected %s, got %s", var_export($b, true), var_export($a, true)));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Caso principale: http → https
// ─────────────────────────────────────────────────────────────────────────────

assert_eqH(
    OCWebHookPayloadBuilder::forceHttps('http://www.comune.example.it'),
    'https://www.comune.example.it',
    'http:// → https://'
);

assert_eqH(
    OCWebHookPayloadBuilder::forceHttps('http://www.comune.example.it/notizie/test'),
    'https://www.comune.example.it/notizie/test',
    'http:// con path → https:// preservando path'
);

assert_eqH(
    OCWebHookPayloadBuilder::forceHttps('http://www.comune.example.it/api/openapi/novita/notizie/abc#titolo'),
    'https://www.comune.example.it/api/openapi/novita/notizie/abc#titolo',
    'apiUrl http:// → https:// preservando fragment'
);

// ─────────────────────────────────────────────────────────────────────────────
// https già presente → nessun cambio
// ─────────────────────────────────────────────────────────────────────────────

assert_eqH(
    OCWebHookPayloadBuilder::forceHttps('https://www.comune.example.it'),
    'https://www.comune.example.it',
    'https:// già presente → invariato'
);

assert_eqH(
    OCWebHookPayloadBuilder::forceHttps('https://www.comune.example.it/notizie/test'),
    'https://www.comune.example.it/notizie/test',
    'https:// con path già presente → invariato'
);

// ─────────────────────────────────────────────────────────────────────────────
// Valori null/non-string → pass-through senza crash
// ─────────────────────────────────────────────────────────────────────────────

assert_eqH(
    OCWebHookPayloadBuilder::forceHttps(null),
    null,
    'null → null (nessun crash)'
);

assert_eqH(
    OCWebHookPayloadBuilder::forceHttps(''),
    '',
    'stringa vuota → stringa vuota'
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
