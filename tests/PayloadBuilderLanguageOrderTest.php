<?php

/**
 * Unit tests for OCWebHookPayloadBuilder::reorderLanguagesInitialFirst().
 *
 * Bug: ocopendata ordina languages[] per ID in ezcontentlanguage (ordine DB), non
 * rispettando quale lingua sia marcata "principale" nell'oggetto eZ. Su siti con
 * ger-DE registrato prima di ita-IT nel sistema, ger-DE finisce in languages[0] e
 * meta.name dell'evento Kafka risulta in tedesco anche se la lingua principale è
 * italiana.
 *
 * Fix: OCWebHookPayloadBuilder::build() chiama reorderLanguagesInitialFirst() per
 * portare la lingua iniziale (initialLanguage) in prima posizione prima di passare
 * il payload al formatter.
 *
 * No eZ Publish bootstrap needed — il metodo è puro PHP.
 *
 * Usage:
 *   php tests/PayloadBuilderLanguageOrderTest.php
 */

require_once __DIR__ . '/../classes/ocwebhookpayloadbuilder.php';

$PASSED = 0;
$FAILED = 0;

function ok2(string $name): void    { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail2(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eq2($a, $b, string $t): void
{
    if ($a === $b) {
        ok2($t);
    } else {
        fail2($t, sprintf("expected %s, got %s", var_export($b, true), var_export($a, true)));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Caso principale: lingua iniziale non in posizione 0 → va portata in testa
// Questo è il bug originale: ger-DE arriva prima perché ha ID DB inferiore,
// ma la lingua principale del contenuto è ita-IT.
// ─────────────────────────────────────────────────────────────────────────────

assert_eq2(
    OCWebHookPayloadBuilder::reorderLanguagesInitialFirst(['ger-DE', 'ita-IT'], 'ita-IT'),
    ['ita-IT', 'ger-DE'],
    'ger-DE first (ordine DB), ita-IT principale → ita-IT portata in testa'
);

// ─────────────────────────────────────────────────────────────────────────────
// Lingua iniziale già in posizione 0 → nessun cambio
// ─────────────────────────────────────────────────────────────────────────────

assert_eq2(
    OCWebHookPayloadBuilder::reorderLanguagesInitialFirst(['ita-IT', 'ger-DE'], 'ita-IT'),
    ['ita-IT', 'ger-DE'],
    'ita-IT già in posizione 0 → array invariato'
);

// ─────────────────────────────────────────────────────────────────────────────
// Singola lingua → nessun cambio
// ─────────────────────────────────────────────────────────────────────────────

assert_eq2(
    OCWebHookPayloadBuilder::reorderLanguagesInitialFirst(['ita-IT'], 'ita-IT'),
    ['ita-IT'],
    'Singola lingua → nessun cambio'
);

// ─────────────────────────────────────────────────────────────────────────────
// Array vuoto → nessun crash
// ─────────────────────────────────────────────────────────────────────────────

assert_eq2(
    OCWebHookPayloadBuilder::reorderLanguagesInitialFirst([], 'ita-IT'),
    [],
    'Array vuoto → nessun crash, array vuoto restituito'
);

// ─────────────────────────────────────────────────────────────────────────────
// Tre lingue: lingua iniziale in posizione 2 → portata in testa, resto preservato
// ─────────────────────────────────────────────────────────────────────────────

assert_eq2(
    OCWebHookPayloadBuilder::reorderLanguagesInitialFirst(['ger-DE', 'eng-GB', 'ita-IT'], 'ita-IT'),
    ['ita-IT', 'ger-DE', 'eng-GB'],
    'Tre lingue: ita-IT in posizione 2 portata in testa, ordine restanti preservato'
);

// ─────────────────────────────────────────────────────────────────────────────
// Lingua iniziale non presente in array (caso anomalo) → array invariato
// ─────────────────────────────────────────────────────────────────────────────

assert_eq2(
    OCWebHookPayloadBuilder::reorderLanguagesInitialFirst(['ger-DE', 'eng-GB'], 'ita-IT'),
    ['ger-DE', 'eng-GB'],
    'Lingua iniziale assente dall\'array → array invariato (nessun crash)'
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
