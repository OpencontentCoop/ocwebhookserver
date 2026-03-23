# Kafka Canonical Field Names — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a static PHP mapping class that renames raw eZ Publish attribute identifiers in `entity.data` to canonical English names before they are emitted on Kafka.

**Architecture:** New class `OCWebHookKafkaFieldMap` holds per-content-type rename maps; `OCWebHookKafkaPayloadFormatter::format()` calls it after flattening attributes and before returning the payload. Unmapped fields and unmapped content types pass through unchanged.

**Tech Stack:** PHP 7.2, no external dependencies, no eZ Publish bootstrap needed for tests.

**Spec:** `docs/superpowers/specs/2026-03-23-kafka-canonical-field-names-design.md`

---

## File Map

| Action | Path | Responsibility |
|--------|------|----------------|
| **Create** | `classes/ocwebhookkafkafieldmap.php` | Static rename maps + `getMap()` resolver |
| **Modify** | `classes/ocwebhookkafkapayloadformatter.php` | Add `require_once` for fieldmap + rename step after flattening |
| **Create** | `tests/FieldMapTest.php` | Unit tests for `OCWebHookKafkaFieldMap` in isolation |
| **Create** | `tests/PayloadFormatterRenameTest.php` | Integration test: formatter applies canonical names end-to-end |
| **Modify** | `tests/run_tests.php` | Register both new test files |

---

## Task 1: Write the failing test for `OCWebHookKafkaFieldMap`

**Files:**
- Create: `tests/FieldMapTest.php`

- [ ] **Step 1: Create `tests/FieldMapTest.php`**

```php
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

assert_eq(
    OCWebHookKafkaFieldMap::getMap('contatti'),
    [],
    'Unknown type returns empty array'
);
assert_eq(
    OCWebHookKafkaFieldMap::getMap(''),
    [],
    'Empty string type returns empty array'
);

// ── TEST 2: public_service has no renames ─────────────────────────────────────

assert_eq(
    OCWebHookKafkaFieldMap::getMap('public_service'),
    [],
    'public_service has no renames (all fields already canonical)'
);

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

assert_eq($ohsMap['valid_from'],    'valid_from_date',   'ohs: valid_from → valid_from_date');
assert_eq($ohsMap['valid_through'], 'valid_through_date','ohs: valid_through → valid_through_date');
assert_eq($ohsMap['note'],          'notes',             'ohs: note → notes');
assert_eq($ohsMap['stagionalita'],  'seasonality',       'ohs: stagionalita → seasonality');

// ── TEST 7: scalar has_* fields renamed ───────────────────────────────────────

assert_eq(OCWebHookKafkaFieldMap::getMap('document')['has_code'],         'code',         'document: has_code (ezstring) → code');
assert_eq(OCWebHookKafkaFieldMap::getMap('place')['has_video'],           'video_url',    'place: has_video (ezstring) → video_url');
assert_eq(OCWebHookKafkaFieldMap::getMap('channel')['has_channel_type'],  'channel_type', 'channel: has_channel_type (eztags) → channel_type');

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

// image has no renames — variant resolves to empty array, not an error
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
```

- [ ] **Step 2: Run the test — verify it fails with class not found**

```bash
cd /home/lorello/src/ocwebhookserver
OUT=$(php tests/FieldMapTest.php 2>&1); echo "$OUT"
```

Expected output contains: `Class 'OCWebHookKafkaFieldMap' not found` (or similar fatal error).

---

## Task 2: Implement `OCWebHookKafkaFieldMap`

**Files:**
- Create: `classes/ocwebhookkafkafieldmap.php`

- [ ] **Step 3: Create `classes/ocwebhookkafkafieldmap.php`**

```php
<?php

/**
 * Static rename maps for entity.data field names in the OpenCity Kafka payload.
 *
 * Maps raw eZ Publish class attribute identifiers to canonical English names
 * following the naming conventions defined in:
 *   docs/superpowers/specs/2026-03-23-kafka-canonical-field-names-design.md
 *
 * Rules:
 *   - English only, snake_case
 *   - ezdate fields → _date suffix
 *   - ezdatetime fields → _at suffix
 *   - Identifier strings → _id suffix
 *   - has_* on object-relation types → unchanged
 *   - has_* on scalar types (ezstring, eztags, …) → renamed without has_
 *   - Unmapped fields pass through as-is (no error, no warning)
 */
class OCWebHookKafkaFieldMap
{
    private static $maps = [

        // ── article (Notizie, Avvisi, Comunicati) ─────────────────────────────
        'article' => [
            'published'       => 'published_date',   // ezdate → _date
            'dead_line'       => 'deadline_date',    // ezdate + fix name
            'id_comunicato'   => 'notice_id',        // Italian + _id convention
            'attachment'      => 'attachments',      // ezobjectrelationlist → plural
            'dataset'         => 'datasets',         // ezobjectrelationlist → plural
            'related_service' => 'related_services', // ezobjectrelationlist → plural
        ],

        // ── document (Documenti) ──────────────────────────────────────────────
        'document' => [
            'has_code'             => 'code',                // ezstring scalar — has_ misleading
            'protocollo'           => 'protocol_number',     // Italian
            'data_protocollazione' => 'protocollation_date', // Italian + ezdate → _date
        ],

        // ── event (Eventi) ────────────────────────────────────────────────────
        'event' => [
            'event_title'       => 'title',       // remove redundant event_ prefix
            'short_event_title' => 'short_title', // remove redundant prefix
            'event_abstract'    => 'abstract',    // remove redundant prefix
        ],

        // ── organization (Unità organizzative) ───────────────────────────────
        'organization' => [
            'alt_name'                    => 'alternative_name', // expand abbreviation
            'servizi_offerti'             => 'offered_services', // Italian
            'tax_code_e_invoice_service'  => 'tax_code',        // simplify over-specific name
        ],

        // ── place (Luoghi) ────────────────────────────────────────────────────
        'place' => [
            'has_video' => 'video_url',      // ezstring URL scalar — has_ misleading
            'sede_di'   => 'headquarters_of', // Italian
        ],

        // ── public_person (Personale amministrativo) ──────────────────────────
        'public_person' => [
            'competenze'                          => 'competencies',
            'deleghe'                             => 'delegations',
            'situazione_patrimoniale'             => 'asset_declaration',
            'dichiarazioni_patrimoniali_soggetto' => 'asset_declarations_subject',
            'dichiarazioni_patrimoniali_parenti'  => 'asset_declarations_relatives',
            'dichiarazione_redditi'               => 'income_declaration',
            'spese_elettorali'                    => 'electoral_expenses',
            'spese_elettorali_files'              => 'electoral_expenses_files',
            'variazioni_situazione_patrimoniale'  => 'asset_declaration_changes',
            'altre_cariche'                       => 'other_positions',
            'eventuali_incarichi'                 => 'additional_assignments',
            'dichiarazione_incompatibilita'       => 'incompatibility_declaration',
        ],

        // ── topic (Argomenti) ─────────────────────────────────────────────────
        'topic' => [
            'eu'                   => 'eu_classification', // expand abbreviation
            'data_themes_eurovocs' => 'eurovoc_themes',    // simplify compound name
        ],

        // ── dataset ───────────────────────────────────────────────────────────
        'dataset' => [
            'modified'           => 'modified_date',    // ezdate → _date
            'issued'             => 'issued_date',      // ezdate → _date
            'accrualperiodicity' => 'accrual_periodicity', // add missing underscore
        ],

        // ── pagina_sito (Pagine del sito) ─────────────────────────────────────
        'pagina_sito' => [
            'publish_date' => 'published_date', // align with article.published_date; ezdate
        ],

        // ── file ──────────────────────────────────────────────────────────────
        'file' => [
            'percorso_univoco' => 'unique_path', // Italian
        ],

        // ── audio ─────────────────────────────────────────────────────────────
        'audio' => [
            'sottotitolo' => 'subtitle', // Italian
            'durata'      => 'duration', // Italian
        ],

        // ── channel ───────────────────────────────────────────────────────────
        'channel' => [
            'has_channel_type' => 'channel_type', // eztags scalar — has_ misleading
        ],

        // ── online_contact_point ──────────────────────────────────────────────
        'online_contact_point' => [
            'note' => 'notes', // plural for consistency
        ],

        // ── opening_hours_specification ───────────────────────────────────────
        'opening_hours_specification' => [
            'valid_from'    => 'valid_from_date',    // ezdate → _date
            'valid_through' => 'valid_through_date', // ezdate → _date
            'note'          => 'notes',              // plural for consistency
            'stagionalita'  => 'seasonality',        // Italian
        ],

        // ── time_indexed_role ─────────────────────────────────────────────────
        'time_indexed_role' => [
            'compensi'              => 'compensations',    // Italian
            'importi'               => 'amounts',          // Italian
            'start_time'            => 'start_date',       // misleading _time; confirmed ezdate
            'end_time'              => 'end_date',         // misleading _time; confirmed ezdate
            'data_insediamento'     => 'inauguration_date',// Italian + ezdate → _date
            'incarico_dirigenziale' => 'executive_position',// Italian
            'ruolo_principale'      => 'primary_role',     // Italian
            'priorita'              => 'priority',         // Italian (missing accent in identifier)
        ],

        // ── Content types with no renames needed ─────────────────────────────
        // public_service, faq, faq_section, image, chart, banner, offer, output
        // are intentionally absent: all their fields are already canonical.
    ];

    /**
     * Variant content types that inherit the rename map of their base type.
     * Resolved inside getMap() to avoid PHP static-initialiser self-reference.
     */
    private static $variantAliases = [
        'article_with_projects'                    => 'article',
        'event_with_related'                       => 'event',
        'organization_with_related'                => 'organization',
        'private_organization'                     => 'organization',
        'image_with_related'                       => 'image',
        'public_service_with_related'              => 'public_service',
        'opening_hours_specification_with_related' => 'opening_hours_specification',
        'pagina_sito_with_dataset'                 => 'pagina_sito',
    ];

    /**
     * Returns the field rename map for a given eZ Publish class identifier.
     * Fields absent from the map pass through unchanged.
     *
     * @param string $contentTypeId  eZ Publish class identifier (e.g. "article")
     * @return array                 [ 'old_name' => 'canonical_name', ... ]
     */
    public static function getMap($contentTypeId)
    {
        if (isset(self::$maps[$contentTypeId])) {
            return self::$maps[$contentTypeId];
        }
        if (isset(self::$variantAliases[$contentTypeId])) {
            $base = self::$variantAliases[$contentTypeId];
            return isset(self::$maps[$base]) ? self::$maps[$base] : [];
        }
        return [];
    }
}
```

- [ ] **Step 4: Run the test — verify it passes**

```bash
cd /home/lorello/src/ocwebhookserver
OUT=$(php tests/FieldMapTest.php 2>&1); echo "$OUT"
```

Expected: all `[PASS]`, exit 0, `Results: N passed`.

- [ ] **Step 5: Commit**

```bash
cd /home/lorello/src/ocwebhookserver
git add classes/ocwebhookkafkafieldmap.php tests/FieldMapTest.php
git commit -m "feat: aggiunge OCWebHookKafkaFieldMap con mappe per 15 content type

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 3: Write the failing formatter rename test

**Files:**
- Create: `tests/PayloadFormatterRenameTest.php`

- [ ] **Step 6: Create `tests/PayloadFormatterRenameTest.php`**

```php
<?php

/**
 * Integration test: OCWebHookKafkaPayloadFormatter applies canonical field names.
 *
 * Verifies that the formatter renames entity.data fields using OCWebHookKafkaFieldMap,
 * that unmapped fields pass through unchanged, and that the rename applies to all languages.
 *
 * No eZ Publish bootstrap or Kafka broker needed.
 *
 * Usage:
 *   php tests/PayloadFormatterRenameTest.php
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
// Payload with both mapped fields (published, dead_line, attachment) and
// already-canonical fields (title, abstract) — must rename only the former.

$articlePayload = [
    'metadata' => [
        'id'              => '10',
        'classIdentifier' => 'article',
        'languages'       => ['it-IT'],
        'name'            => ['it-IT' => 'Test notizia'],
    ],
    'data' => [
        'it-IT' => [
            'title'          => ['content' => 'Test notizia'],
            'abstract'       => ['content' => 'Abstract'],
            'published'      => ['content' => '2026-01-15'],   // → published_date
            'dead_line'      => ['content' => '2026-06-30'],   // → deadline_date
            'id_comunicato'  => ['content' => 'COM-2026-001'], // → notice_id
            'attachment'     => ['content' => []],             // → attachments
        ],
    ],
];

$result = $formatter->format($articlePayload);
$data   = $result['entity']['data']['it-IT'];

// Renamed fields present with new names
assert_eq($data['published_date'], '2026-01-15',   'article: published renamed to published_date');
assert_eq($data['deadline_date'],  '2026-06-30',   'article: dead_line renamed to deadline_date');
assert_eq($data['notice_id'],      'COM-2026-001', 'article: id_comunicato renamed to notice_id');
assert_eq($data['attachments'],    [],              'article: attachment renamed to attachments');

// Old names no longer present
assert_true(!array_key_exists('published',     $data), 'article: old key "published" not present');
assert_true(!array_key_exists('dead_line',     $data), 'article: old key "dead_line" not present');
assert_true(!array_key_exists('id_comunicato', $data), 'article: old key "id_comunicato" not present');
assert_true(!array_key_exists('attachment',    $data), 'article: old key "attachment" not present');

// Already-canonical fields preserved as-is
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
            'topics'            => ['content' => []],  // already canonical
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

// ── TEST 5: article_with_related variant resolves to article map ──────────────

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
```

- [ ] **Step 7: Run the test — verify it fails**

```bash
cd /home/lorello/src/ocwebhookserver
OUT=$(php tests/PayloadFormatterRenameTest.php 2>&1); echo "$OUT"
```

Expected: `[FAIL]` on the rename assertions (e.g., `published_date` key does not exist). The formatter does not rename yet.

---

## Task 4: Modify the formatter to apply the rename

**Files:**
- Modify: `classes/ocwebhookkafkapayloadformatter.php`

- [ ] **Step 8: Add `require_once` for the fieldmap at the top of the formatter**

In `classes/ocwebhookkafkapayloadformatter.php`, add this line immediately before the class declaration (after any existing `<?php`):

```php
require_once dirname(__FILE__) . '/ocwebhookkafkafieldmap.php';
```

The top of the file should look like:

```php
<?php

require_once dirname(__FILE__) . '/ocwebhookkafkafieldmap.php';

/**
 * Converts an ocopendata payload array ...
 */
class OCWebHookKafkaPayloadFormatter
{
```

- [ ] **Step 9: Add the rename step inside `format()`, after attribute flattening**

In `classes/ocwebhookkafkapayloadformatter.php`, find this block (currently the last thing before `return`):

```php
        // Flatten attribute values per language: extract the "content" field from each attribute.
        $data = [];
        foreach ($rawData as $lang => $attributes) {
            $data[$lang] = [];
            if (is_array($attributes)) {
                foreach ($attributes as $attrName => $attrValue) {
                    $data[$lang][$attrName] = is_array($attrValue) && array_key_exists('content', $attrValue)
                        ? $attrValue['content']
                        : $attrValue;
                }
            }
        }

        return ['entity' => ['meta' => $meta, 'data' => $data]];
```

Replace it with:

```php
        // Flatten attribute values per language: extract the "content" field from each attribute.
        $data = [];
        foreach ($rawData as $lang => $attributes) {
            $data[$lang] = [];
            if (is_array($attributes)) {
                foreach ($attributes as $attrName => $attrValue) {
                    $data[$lang][$attrName] = is_array($attrValue) && array_key_exists('content', $attrValue)
                        ? $attrValue['content']
                        : $attrValue;
                }
            }
        }

        // Apply canonical field name mapping. Unmapped fields and unmapped content types pass through.
        $map = OCWebHookKafkaFieldMap::getMap($meta['type_id']);
        if (!empty($map)) {
            foreach ($data as $lang => $attrs) {
                $renamed = [];
                foreach ($attrs as $key => $val) {
                    $renamed[isset($map[$key]) ? $map[$key] : $key] = $val;
                }
                $data[$lang] = $renamed;
            }
        }

        return ['entity' => ['meta' => $meta, 'data' => $data]];
```

- [ ] **Step 10: Run the rename test — verify it passes**

```bash
cd /home/lorello/src/ocwebhookserver
OUT=$(php tests/PayloadFormatterRenameTest.php 2>&1); echo "$OUT"
```

Expected: all `[PASS]`, exit 0.

- [ ] **Step 11: Run the original formatter test — verify it still passes**

```bash
cd /home/lorello/src/ocwebhookserver
OUT=$(php tests/PayloadFormatterTest.php 2>&1); echo "$OUT"
```

Expected: all `[PASS]`, exit 0. (The original test uses an `article` payload with only `title`, `abstract`, `body`, `image` — none are in the rename map, so they pass through unchanged.)

- [ ] **Step 12: Commit**

```bash
cd /home/lorello/src/ocwebhookserver
git add classes/ocwebhookkafkapayloadformatter.php tests/PayloadFormatterRenameTest.php
git commit -m "feat: applica rename campi canonici nel formatter Kafka

OCWebHookKafkaPayloadFormatter ora chiama OCWebHookKafkaFieldMap::getMap()
dopo il flattening degli attributi. I campi non mappati passano invariati.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 5: Register new tests in the test runner

**Files:**
- Modify: `tests/run_tests.php`

- [ ] **Step 13: Add the two new test files to `$testFiles` in `run_tests.php`**

In `tests/run_tests.php`, find:

```php
$testFiles = [
    __DIR__ . '/PayloadFormatterTest.php',
    __DIR__ . '/EmitterOutboxTest.php',
    __DIR__ . '/KafkaProducerTest.php',
];
```

Replace with:

```php
$testFiles = [
    __DIR__ . '/FieldMapTest.php',
    __DIR__ . '/PayloadFormatterTest.php',
    __DIR__ . '/PayloadFormatterRenameTest.php',
    __DIR__ . '/EmitterOutboxTest.php',
    __DIR__ . '/KafkaProducerTest.php',
];
```

- [ ] **Step 14: Run the full test suite locally (without Kafka)**

```bash
cd /home/lorello/src/ocwebhookserver
OUT=$(SKIP_KAFKA=1 php tests/run_tests.php 2>&1); echo "$OUT"
```

Expected: `✓ All test suites passed`, `Tests passed: 100%`, exit 0.

- [ ] **Step 15: Commit and push**

```bash
cd /home/lorello/src/ocwebhookserver
git add tests/run_tests.php
git commit -m "test: registra FieldMapTest e PayloadFormatterRenameTest nel runner

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
git push origin feature/kafka-producer
```

---

## Task 6: Update composer.lock in cms and verify CI

**Files:**
- Modify: `/home/lorello/src/opencontent/cms/composer.json` (only if ocwebhookserver is a path dependency during development)
- Modify: `/home/lorello/src/opencontent/cms/composer.lock` (update SHA)

> **Note:** If the cms repo references ocwebhookserver by git SHA in `composer.lock`, update it to the new HEAD SHA after pushing. Skip this task if the cms is already using a path/symlink override.

- [ ] **Step 16: Check current reference in cms**

```bash
cd /home/lorello/src/opencontent/cms
grep -A3 'ocwebhookserver' composer.lock | head -20
```

If it shows a `reference` SHA, continue to step 17. If it shows `"type": "path"`, skip to step 19.

- [ ] **Step 17: Get new HEAD SHA of ocwebhookserver**

```bash
cd /home/lorello/src/ocwebhookserver
git rev-parse HEAD
```

- [ ] **Step 18: Update composer.lock reference and push cms**

```bash
cd /home/lorello/src/opencontent/cms
# Replace the old SHA with the new one in composer.lock
# (use the SHA from step 17)
sed -i 's/"reference": "<OLD_SHA>"/"reference": "<NEW_SHA>"/' composer.lock
git add composer.lock
git commit -m "Aggiorna ocwebhookserver a <NEW_SHA> (canonical field names)

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
git push origin feature/kafka-rdkafka
```

- [ ] **Step 19: Monitor CI pipeline**

Open GitLab and watch the `test` and `test_integration_full` jobs on the pushed branch. Both must pass.

If `test` fails: run `php tests/run_tests.php` locally (SKIP_KAFKA=1) and fix the failing test.

If `test_integration_full` fails: check the job log for which E2E test failed, reproduce locally with docker compose, fix.

---

## Checklist summary

- [ ] Task 1: `FieldMapTest.php` written, confirmed failing
- [ ] Task 2: `OCWebHookKafkaFieldMap` implemented, test passes, committed
- [ ] Task 3: `PayloadFormatterRenameTest.php` written, confirmed failing
- [ ] Task 4: Formatter modified, both formatter tests pass, committed
- [ ] Task 5: Runner updated, full suite passes, pushed
- [ ] Task 6: `composer.lock` updated in cms (if needed), CI green
