# Missing Content Types — FieldMap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere 5 content type mancanti (`insight`, `howto`, `itinerary`, `pagina_trasparenza`, `public_project`) a `OCWebHookKafkaFieldMap` con le loro canonical field name renames, in modo che il payload Kafka li emetta con nomi canonici per Meilisearch.

**Architecture:** Modifiche statiche a `OCWebHookKafkaFieldMap` ($maps + $variantAliases) e test nei file esistenti `FieldMapTest.php` e `PayloadFormatterRenameTest.php`. Nessuna modifica all'architettura di emissione. Pattern TDD: test rosso → implementazione → test verde → commit per ogni content type.

**Tech Stack:** PHP 7.4+, runner custom in `tests/run_tests.php` (no PHPUnit).

**Comandi di test:**
```bash
# Singolo file (no eZ Publish, no Kafka):
php tests/FieldMapTest.php
php tests/PayloadFormatterRenameTest.php

# Suite completa (no Kafka):
SKIP_KAFKA=1 php tests/run_tests.php
```

---

## File modificati

| File | Operazione |
|------|-----------|
| `classes/ocwebhookkafkafieldmap.php` | Aggiunge 3 sezioni in `$maps` + 1 entry in `$variantAliases` + aggiorna commento |
| `tests/FieldMapTest.php` | Aggiunge TEST 9–13 prima del blocco `Results` |
| `tests/PayloadFormatterRenameTest.php` | Aggiunge TEST 6 prima del blocco `Results` |

---

## Task 1: `insight` — variantAlias di `article`

**File:**
- Modify: `tests/FieldMapTest.php` (prima del blocco `// ── Results`)
- Modify: `classes/ocwebhookkafkafieldmap.php` (in `$variantAliases`)

### Step 1: Scrivi il test (ROSSO)

In `tests/FieldMapTest.php`, inserisci questo blocco **prima** della riga `// ── Results ─────`:

```php
// ── TEST 9: insight risolve la mappa di article via variantAlias ─────────────

assert_eq(
    OCWebHookKafkaFieldMap::getMap('insight'),
    OCWebHookKafkaFieldMap::getMap('article'),
    'insight resolves to article map via variantAlias'
);
$insightMap = OCWebHookKafkaFieldMap::getMap('insight');
assert_eq($insightMap['published'],  'published_date', 'insight: published → published_date (via article alias)');
assert_eq($insightMap['dead_line'],  'deadline_date',  'insight: dead_line → deadline_date (via article alias)');
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
php tests/FieldMapTest.php 2>&1 | grep -E "PASS|FAIL" | tail -5
```

Atteso: `[FAIL] insight resolves to article map via variantAlias`

- [ ] **Step 3: Implementa — aggiungi `insight` in `$variantAliases`**

In `classes/ocwebhookkafkafieldmap.php`, nel blocco `$variantAliases`, aggiungi `'insight' => 'article'`:

```php
    private static $variantAliases = [
        'article_with_projects'                    => 'article',
        'event_with_related'                       => 'event',
        'insight'                                  => 'article',
        'organization_with_related'                => 'organization',
        'private_organization'                     => 'organization',
        'image_with_related'                       => 'image',
        'public_service_with_related'              => 'public_service',
        'opening_hours_specification_with_related' => 'opening_hours_specification',
        'pagina_sito_with_dataset'                 => 'pagina_sito',
    ];
```

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
php tests/FieldMapTest.php 2>&1 | grep -E "PASS|FAIL" | tail -5
```

Atteso: `[PASS] insight resolves to article map via variantAlias`, `[PASS] insight: published → published_date`, `[PASS] insight: dead_line → deadline_date`

- [ ] **Step 5: Commit**

```bash
git add classes/ocwebhookkafkafieldmap.php tests/FieldMapTest.php
git commit -m "feat(fieldmap): add insight as variantAlias of article

insight (Approfondimento) shares the same date field renames as article:
published→published_date, dead_line→deadline_date. Added as variantAlias
for DRY and automatic inheritance of future article renames."
```

---

## Task 2: `howto` — nessuna rename (documentazione)

**File:**
- Modify: `tests/FieldMapTest.php` (prima del blocco `// ── Results`)
- Modify: `classes/ocwebhookkafkafieldmap.php` (commento no-renames)

- [ ] **Step 1: Scrivi il test**

In `tests/FieldMapTest.php`, inserisci questo blocco **prima** della riga `// ── Results ─────` (dopo il blocco TEST 9):

```php
// ── TEST 10: howto ha nessuna rename (tutti i campi già canonici) ─────────────

assert_eq(OCWebHookKafkaFieldMap::getMap('howto'), [], 'howto has no renames (all fields already canonical)');
```

- [ ] **Step 2: Esegui il test**

```bash
php tests/FieldMapTest.php 2>&1 | grep "howto"
```

Atteso: `[PASS] howto has no renames (all fields already canonical)` — il test passa subito perché `howto` non è in `$maps` e `getMap` restituisce `[]` per default.

- [ ] **Step 3: Aggiorna il commento in `$maps`**

In `classes/ocwebhookkafkafieldmap.php`, sostituisci la riga del commento no-renames:

```php
        // Content types with no renames: public_service, faq, faq_section,
        // image, chart, banner, offer, output — all fields already canonical.
```

con:

```php
        // Content types with no renames: public_service, faq, faq_section,
        // image, chart, banner, offer, output, howto — all fields already canonical.
```

- [ ] **Step 4: Commit**

```bash
git add classes/ocwebhookkafkafieldmap.php tests/FieldMapTest.php
git commit -m "feat(fieldmap): document howto as no-renames content type

howto (Guida) fields are already canonical English snake_case.
Added to no-renames comment and test coverage."
```

---

## Task 3: `itinerary` — rimozione prefisso ridondante

**File:**
- Modify: `tests/FieldMapTest.php` (prima del blocco `// ── Results`)
- Modify: `classes/ocwebhookkafkafieldmap.php` (nuova sezione in `$maps`)

- [ ] **Step 1: Scrivi il test (ROSSO)**

In `tests/FieldMapTest.php`, inserisci questo blocco **prima** della riga `// ── Results ─────` (dopo il blocco TEST 10):

```php
// ── TEST 11: itinerary — rimozione prefisso ridondante ───────────────────────

$itineraryMap = OCWebHookKafkaFieldMap::getMap('itinerary');
assert_eq($itineraryMap['itinerary_types'],        'types',        'itinerary: itinerary_types → types');
assert_eq($itineraryMap['itinerary_difficulties'], 'difficulties', 'itinerary: itinerary_difficulties → difficulties');
assert_true(count($itineraryMap) === 2,                            'itinerary map has exactly 2 entries');
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
php tests/FieldMapTest.php 2>&1 | grep "itinerary"
```

Atteso: `[FAIL] itinerary: itinerary_types → types`

- [ ] **Step 3: Implementa — aggiungi sezione `itinerary` in `$maps`**

In `classes/ocwebhookkafkafieldmap.php`, aggiungi questa sezione in `$maps` dopo la sezione `// ── file`:

```php
        // ── itinerary (Itinerari) ─────────────────────────────────────────────────
        'itinerary' => [
            'itinerary_types'        => 'types',
            'itinerary_difficulties' => 'difficulties',
        ],
```

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
php tests/FieldMapTest.php 2>&1 | grep "itinerary"
```

Atteso: tre righe `[PASS]`.

- [ ] **Step 5: Commit**

```bash
git add classes/ocwebhookkafkafieldmap.php tests/FieldMapTest.php
git commit -m "feat(fieldmap): add itinerary content type

Removes redundant 'itinerary_' prefix from itinerary_types→types
and itinerary_difficulties→difficulties, following the same pattern
as event_title→title."
```

---

## Task 4: `pagina_trasparenza` — 10 renames (campi italiani)

**File:**
- Modify: `tests/FieldMapTest.php` (prima del blocco `// ── Results`)
- Modify: `classes/ocwebhookkafkafieldmap.php` (nuova sezione in `$maps`)

- [ ] **Step 1: Scrivi il test (ROSSO)**

In `tests/FieldMapTest.php`, inserisci questo blocco **prima** della riga `// ── Results ─────` (dopo il blocco TEST 11):

```php
// ── TEST 12: pagina_trasparenza — tutti i campi italiani rinominati ──────────

$ptMap = OCWebHookKafkaFieldMap::getMap('pagina_trasparenza');
assert_eq($ptMap['titolo'],                       'title',                  'pt: titolo → title');
assert_eq($ptMap['contenuto_obbligo'],            'obligation_content',     'pt: contenuto_obbligo → obligation_content');
assert_eq($ptMap['riferimenti_normativi'],        'legislative_references', 'pt: riferimenti_normativi → legislative_references');
assert_eq($ptMap['applicabilita'],                'applicability',          'pt: applicabilita → applicability');
assert_eq($ptMap['denominazione_degli_obblighi'], 'obligation_name',        'pt: denominazione_degli_obblighi → obligation_name');
assert_eq($ptMap['guida_alla_compilazione'],      'compilation_guide',      'pt: guida_alla_compilazione → compilation_guide');
assert_eq($ptMap['messaggio_di_consiglio'],       'advice_message',         'pt: messaggio_di_consiglio → advice_message');
assert_eq($ptMap['decorrenza_di_pubblicazione'],  'publication_start',      'pt: decorrenza_di_pubblicazione → publication_start');
assert_eq($ptMap['aggiornamento'],                'update_frequency',       'pt: aggiornamento → update_frequency');
assert_eq($ptMap['termine_pubblicazione'],        'publication_end',        'pt: termine_pubblicazione → publication_end');
assert_true(count($ptMap) === 10,                                            'pagina_trasparenza map has exactly 10 entries');
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
php tests/FieldMapTest.php 2>&1 | grep "pt:"
```

Atteso: `[FAIL] pt: titolo → title`

- [ ] **Step 3: Implementa — aggiungi sezione `pagina_trasparenza` in `$maps`**

In `classes/ocwebhookkafkafieldmap.php`, aggiungi questa sezione in `$maps` dopo la sezione `// ── itinerary`:

```php
        // ── pagina_trasparenza (Pagine trasparenza) ───────────────────────────────
        'pagina_trasparenza' => [
            'titolo'                       => 'title',
            'contenuto_obbligo'            => 'obligation_content',
            'riferimenti_normativi'        => 'legislative_references',
            'applicabilita'                => 'applicability',
            'denominazione_degli_obblighi' => 'obligation_name',
            'guida_alla_compilazione'      => 'compilation_guide',
            'messaggio_di_consiglio'       => 'advice_message',
            'decorrenza_di_pubblicazione'  => 'publication_start',
            'aggiornamento'                => 'update_frequency',
            'termine_pubblicazione'        => 'publication_end',
        ],
```

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
php tests/FieldMapTest.php 2>&1 | grep -E "pt:|10 entries"
```

Atteso: undici righe `[PASS]`.

- [ ] **Step 5: Commit**

```bash
git add classes/ocwebhookkafkafieldmap.php tests/FieldMapTest.php
git commit -m "feat(fieldmap): add pagina_trasparenza content type

All 10 Italian field names translated to canonical English snake_case:
titolo→title, contenuto_obbligo→obligation_content,
riferimenti_normativi→legislative_references, etc."
```

---

## Task 5: `public_project` — 3 renames

**File:**
- Modify: `tests/FieldMapTest.php` (prima del blocco `// ── Results`)
- Modify: `classes/ocwebhookkafkafieldmap.php` (nuova sezione in `$maps`)

- [ ] **Step 1: Scrivi il test (ROSSO)**

In `tests/FieldMapTest.php`, inserisci questo blocco **prima** della riga `// ── Results ─────` (dopo il blocco TEST 12):

```php
// ── TEST 13: public_project ───────────────────────────────────────────────────

$ppMap = OCWebHookKafkaFieldMap::getMap('public_project');
assert_eq($ppMap['published'],        'published_date', 'public_project: published → published_date (ezdate)');
assert_eq($ppMap['has_status'],       'status',         'public_project: has_status (eztags scalar) → status');
assert_eq($ppMap['has_status_notes'], 'status_notes',   'public_project: has_status_notes (ezxmltext scalar) → status_notes');
assert_true(count($ppMap) === 3,                         'public_project map has exactly 3 entries');
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
php tests/FieldMapTest.php 2>&1 | grep "public_project"
```

Atteso: `[FAIL] public_project: published → published_date (ezdate)`

- [ ] **Step 3: Implementa — aggiungi sezione `public_project` in `$maps`**

In `classes/ocwebhookkafkafieldmap.php`, aggiungi questa sezione in `$maps` dopo la sezione `// ── pagina_trasparenza`:

```php
        // ── public_project (Progetti) ─────────────────────────────────────────────
        'public_project' => [
            'published'        => 'published_date',
            'has_status'       => 'status',
            'has_status_notes' => 'status_notes',
        ],
```

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
php tests/FieldMapTest.php 2>&1 | grep "public_project"
```

Atteso: quattro righe `[PASS]`.

- [ ] **Step 5: Esegui l'intera suite FieldMapTest**

```bash
php tests/FieldMapTest.php 2>&1 | tail -5
```

Atteso: `Results: N passed` senza `failed`.

- [ ] **Step 6: Commit**

```bash
git add classes/ocwebhookkafkafieldmap.php tests/FieldMapTest.php
git commit -m "feat(fieldmap): add public_project content type

published→published_date (ezdate→_date convention),
has_status→status and has_status_notes→status_notes
(scalar eztags/ezxmltext: has_* prefix removed)."
```

---

## Task 6: `PayloadFormatterRenameTest` — end-to-end `pagina_trasparenza`

**File:**
- Modify: `tests/PayloadFormatterRenameTest.php` (prima del blocco `// ── Results`)

Verifica che `OCWebHookKafkaPayloadFormatter::format()` applichi correttamente tutte e 10 le rename di `pagina_trasparenza` su `entity.data`, e che i campi originali non siano presenti.

- [ ] **Step 1: Scrivi il test (ROSSO)**

In `tests/PayloadFormatterRenameTest.php`, inserisci questo blocco **prima** della riga `// ── Results ─────`:

```php
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
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
php tests/PayloadFormatterRenameTest.php 2>&1 | grep "pt:" | head -3
```

Atteso: `[FAIL] pt: titolo → title`  
(Il test fallisce perché `pagina_trasparenza` non è ancora in `$maps` — ma lo è già dopo il Task 4. Se Task 4 è completato, il test dovrebbe passare direttamente.)

> **Nota:** se hai già completato i Task 1–5, questo test potrebbe passare subito. Esegui comunque e verifica.

- [ ] **Step 3: Esegui e verifica che tutti i test passino**

```bash
php tests/PayloadFormatterRenameTest.php 2>&1 | tail -5
```

Atteso: `Results: N passed` senza `failed`.

- [ ] **Step 4: Commit**

```bash
git add tests/PayloadFormatterRenameTest.php
git commit -m "test(fieldmap): add end-to-end rename test for pagina_trasparenza

Verifies that PayloadFormatterRenameTest applies all 10 Italian→English
renames and that original Italian keys are absent from entity.data."
```

---

## Task 7: Verifica finale

- [ ] **Step 1: Esegui l'intera suite (senza Kafka)**

```bash
SKIP_KAFKA=1 php tests/run_tests.php
```

Atteso:
```
✓ All test suites passed
Tests passed: 100%
```

- [ ] **Step 2: Verifica la mappa complessiva**

```bash
php -r "
require 'classes/ocwebhookkafkafieldmap.php';
\$types = ['insight','howto','itinerary','pagina_trasparenza','public_project'];
foreach (\$types as \$t) {
    \$map = OCWebHookKafkaFieldMap::getMap(\$t);
    echo \"\$t: \" . count(\$map) . \" renames\n\";
}
"
```

Atteso:
```
insight: 6 renames
howto: 0 renames
itinerary: 2 renames
pagina_trasparenza: 10 renames
public_project: 3 renames
```

(`insight` mostra 6 perché eredita l'intera mappa di `article`.)
