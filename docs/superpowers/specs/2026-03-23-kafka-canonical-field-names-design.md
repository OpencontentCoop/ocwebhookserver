# Kafka Canonical Field Names — Design Spec

**Goal:** Normalise `entity.data` field names in the Kafka payload so that consumers receive consistent, English, schema.org/OntoPiA-aligned names regardless of the raw eZ Publish attribute identifiers stored in the CMS.

**Scope:** Output transformation only — Kafka payload body (`entity.data`). No changes to eZ Publish class definitions, REST API, or CloudEvents headers.

**Architecture:** Static PHP mapping class + one additional step in the existing formatter.

---

## Naming Conventions

| Rule | Example (before → after) |
|------|--------------------------|
| English only — no Italian words | `data_protocollazione` → `protocollation_date` |
| `snake_case` | already enforced everywhere |
| `ezdate` fields → `_date` suffix | `dead_line` → `deadline_date`, `issued` → `issued_date` |
| `ezdatetime` fields → `_at` suffix | `modified_at` (ezdatetime) → `modified_at` |
| Single identifier strings → `_id` suffix | `id_comunicato` → `notice_id` |
| Redundant content-type prefix → removed | `event_title` → `title`, `event_abstract` → `abstract` |
| Abbreviations → expanded | `alt_name` → `alternative_name`, `eu` → `eu_classification` |
| Italian plural field names → English | `servizi_offerti` → `offered_services`, `deleghe` → `delegations` |
| `has_*` fields that are object relations → **unchanged** | Whether from OntoPiA or not, if the underlying eZ type is a relation (`ezobjectrelationlist`, `openpareverserelationlist`, etc.) the `has_` prefix is semantically correct and is kept: `has_service_status`, `has_spatial_coverage`, `has_role`, `has_contact_point`, `has_online_contact_point`, `has_language`, `has_price_specification`, `has_address`, `has_office`, `has_public_event_typology`, `has_related_services`. |
| `has_*` fields that are scalars → **renamed** | If the underlying eZ type is a scalar (`ezstring`, `eztags`, `ezboolean`, `ezinteger`, etc.) the `has_` prefix is misleading — a consumer would not expect a plain value there. These are renamed: `has_code` (ezstring) → `code`, `has_video` (ezstring) → `video_url`, `has_channel_type` (eztags) → `channel_type`. |
| Singleton `note` fields → plural `notes` | `note` → `notes` (consistency across all content types) |
| schema.org personal names → **unchanged** | `given_name`, `family_name`, `legal_name` |
| Unmapped fields → pass through as-is | no rename, no error, no log warning |

---

## Architecture

### New class: `OCWebHookKafkaFieldMap`

File: `classes/ocwebhookkafkafieldmap.php`

```php
class OCWebHookKafkaFieldMap
{
    private static $maps = [ /* see Content Type Maps below */ ];

    /**
     * Returns the field rename map for a given content type identifier.
     * Fields absent from the map pass through unchanged.
     *
     * @param string $contentTypeId  eZ Publish class identifier (e.g. "article")
     * @return array                 [ 'old_name' => 'canonical_name', ... ]
     */
    public static function getMap($contentTypeId)
    {
        return isset(self::$maps[$contentTypeId]) ? self::$maps[$contentTypeId] : [];
    }
}
```

### Modified: `OCWebHookKafkaPayloadFormatter::format()`

After the attribute-flattening loop, before `return`:

```php
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
```

No changes to `entity.meta`, CloudEvents headers, or outbox persistence.

---

## Content Type Maps

### `article` (Notizie, Avvisi, Comunicati)

| eZ attribute | Canonical name | Reason |
|---|---|---|
| `published` | `published_date` | ezdate → `_date` |
| `dead_line` | `deadline_date` | ezdate + fix typo-style name |
| `id_comunicato` | `notice_id` | Italian + `_id` convention |
| `attachment` | `attachments` | ezobjectrelationlist → plural |
| `dataset` | `datasets` | ezobjectrelationlist → plural |
| `related_service` | `related_services` | ezobjectrelationlist → plural |

All other fields (`title`, `content_type`, `abstract`, `topics`, `image`, `body`, `people`, `location`, `video`, `author`, `audio`, `files`, `help`, `license`, `reading_time`) are already canonical.

---

### `document` (Documenti)

| eZ attribute | eZ type | Canonical name | Reason |
|---|---|---|---|
| `has_code` | ezstring | `code` | Scalar string for document codes (DOI, ISBN, deed number) — `has_` prefix misleading for a plain value |
| `protocollo` | ezstring | `protocol_number` | Italian |
| `data_protocollazione` | ezdate | `protocollation_date` | Italian + ezdate → `_date` |

Others (`name`, `document_type`, `topics`, `abstract`, `full_description`, `image`) already canonical.

---

### `event` (Eventi)

| eZ attribute | Canonical name | Reason |
|---|---|---|
| `event_title` | `title` | Remove redundant `event_` prefix |
| `short_event_title` | `short_title` | Remove redundant prefix |
| `event_abstract` | `abstract` | Remove redundant prefix |

Others (`has_public_event_typology`, `identifier`, `topics`, `description`, `image`) already canonical. `time_interval` is a custom `ocevent` composite type — it does not map to a plain ezdate/ezdatetime, so no `_date`/`_at` suffix applies; it passes through as-is.

---

### `organization` (Unità organizzative)

| eZ attribute | Canonical name | Reason |
|---|---|---|
| `alt_name` | `alternative_name` | Expand abbreviation |
| `servizi_offerti` | `offered_services` | Italian |
| `tax_code_e_invoice_service` | `tax_code` | Simplify over-specific name |

Others (`legal_name`, `topics`, `abstract`, `image`, `main_function`, `hold_employment`, `office_manager`, `type`, `political_reference`, `people`, `has_spatial_coverage`, `has_online_contact_point`, `attachments`, `more_information`, `identifier`, `related_offices`) already canonical.

---

### `place` (Luoghi)

| eZ attribute | eZ type | Canonical name | Reason |
|---|---|---|---|
| `has_video` | ezstring | `video_url` | Scalar URL string — `has_` prefix misleading for a plain string value |
| `sede_di` | ezobjectrelationlist | `headquarters_of` | Italian |

Others (`name`, `alternative_name`, `topics`, `type`, `abstract`, `description`, `contains_place`, `image`, `video`, `accessibility`, `has_address`, `opening_hours_specification`, `help`, `has_office`, `external_contact_point`, `more_information`, `main_category`, `identifier`, `has_related_services`) already canonical. `has_related_services` is `openpareverserelationlist` — a relation — so the `has_` prefix is kept.

---

### `public_person` (Personale amministrativo)

| eZ attribute | Canonical name | Reason |
|---|---|---|
| `competenze` | `competencies` | Italian |
| `deleghe` | `delegations` | Italian |
| `situazione_patrimoniale` | `asset_declaration` | Italian |
| `dichiarazioni_patrimoniali_soggetto` | `asset_declarations_subject` | Italian |
| `dichiarazioni_patrimoniali_parenti` | `asset_declarations_relatives` | Italian |
| `dichiarazione_redditi` | `income_declaration` | Italian |
| `spese_elettorali` | `electoral_expenses` | Italian |
| `spese_elettorali_files` | `electoral_expenses_files` | Italian |
| `variazioni_situazione_patrimoniale` | `asset_declaration_changes` | Italian |
| `altre_cariche` | `other_positions` | Italian |
| `eventuali_incarichi` | `additional_assignments` | Italian |
| `dichiarazione_incompatibilita` | `incompatibility_declaration` | Italian |

Others (`given_name`, `family_name`, `abstract`, `image`, `has_role`, `bio`, `has_contact_point`, `curriculum`, `notes`, `related_news`) already canonical.

---

### `public_service` (Servizi)

No renames needed. All attributes (`type`, `name`, `identifier`, `other_service_code`, `has_service_status`, `status_note`, `alternative_name`, `image`, `abstract`, `description`, `audience`, `has_spatial_coverage`, `process`, `has_language`, `how_to`, `has_input`, `produces_output`, `is_physically_available_at`, `terms_of_service`, `has_online_contact_point`, `holds_role_in_time`, `topics`, `has_cost_description`, `output_notes`) are already canonical (OntoPiA vocabulary extensively used).

---

### `topic` (Argomenti)

| eZ attribute | Canonical name | Reason |
|---|---|---|
| `eu` | `eu_classification` | Cryptic abbreviation; expanded to describe the European classification field |
| `data_themes_eurovocs` | `eurovoc_themes` | Legacy compound name; simplified to the standard EuroVoc vocabulary term |

Others (`name`, `managed_by_area`, `managed_by_political_body`, `type`, `description`, `theme`, `image`, `abstract`, `icon`, `layout`, `help`, `show_topic_children`) already canonical.

---

### `dataset` (Dataset)

| eZ attribute | Canonical name | Reason |
|---|---|---|
| `modified` | `modified_date` | ezdate → `_date` |
| `issued` | `issued_date` | ezdate → `_date` |
| `accrualperiodicity` | `accrual_periodicity` | Missing underscore |

Others (`title`, `abstract`, `theme`, `format`, `license`, `resources`, `download_url`, `download_file`, `csv_resource`, `rights_holder`, `identifier`, `spatial`, `temporal`, `creator`, `publisher`, `conforms_to`, `language`, `version_info`, `contact_point`, `keyword`) already canonical.

---

### `pagina_sito` (Pagine del sito)

| eZ attribute | eZ type | Canonical name | Reason |
|---|---|---|---|
| `publish_date` | ezdate | `published_date` | Align with `article.published_date`; ezdate → `_date` confirmed |

Others (`name`, `short_name`, `menu_name`, `abstract`, `description`, `image`, `show_children`, `layout`, `icon`, `show_topics`, `show_search_form`, `tag_menu`, `show_tag_cards`) already canonical.

---

### `faq` / `faq_section`

No renames needed. (`question`, `answer`, `priority` / `name`, `description`, `image`)

---

### `image`

No renames needed. (`name`, `caption`, `image`, `tags`, `license`, `proprietary_license`, `proprietary_license_source`, `author`)

---

### `file`

| eZ attribute | Canonical name | Reason |
|---|---|---|
| `percorso_univoco` | `unique_path` | Italian |

Others (`name`, `description`, `file`, `tags`) already canonical.

---

### `audio`

| eZ attribute | Canonical name | Reason |
|---|---|---|
| `sottotitolo` | `subtitle` | Italian |
| `durata` | `duration` | Italian |

Others (`name`, `media`, `abstract`) already canonical.

---

### `chart`

No renames needed. (`name`, `description`, `chart`)

---

### `banner`

No renames needed. (`name`, `description`, `image`, `internal_location`, `location`, `background_color`, `topics`)

---

### `offer`

`has_price_specification` is a CPSV-AP/schema.org term — unchanged. `has_currency` and `has_eligible_user` are schema.org terms (`schema:priceCurrency`, `schema:eligibleCustomerType`) used with the Italian OntoPiA `has_` prefix convention — unchanged. `description` already canonical. `start_time` type could not be verified from installer source — moved to **Future Work**.

---

### `output`

No renames needed. (`name`, `short_name`, `abstract`, `description`, `image`)

---

### `channel`

| eZ attribute | eZ type | Canonical name | Reason |
|---|---|---|---|
| `has_channel_type` | eztags | `channel_type` | Scalar taxonomy tag — `has_` prefix misleading for a plain value |

Others (`object`, `abstract`, `description`, `channel_url`) already canonical.

---

### `online_contact_point`

| eZ attribute | Canonical name | Reason |
|---|---|---|
| `note` | `notes` | Plural for consistency |

Others (`name`, `contact`, `phone_availability_time`, `is_contact_point_of_organizations`) already canonical.

---

### `opening_hours_specification`

| eZ attribute | Canonical name | Reason |
|---|---|---|
| `valid_from` | `valid_from_date` | ezdate → `_date` |
| `valid_through` | `valid_through_date` | ezdate → `_date` |
| `note` | `notes` | Plural for consistency |
| `stagionalita` | `seasonality` | Italian |

Others (`name`, `opening_hours`, `closure`, `place`) already canonical.

---

### `time_indexed_role`

| eZ attribute | eZ type | Canonical name | Reason |
|---|---|---|---|
| `compensi` | ezxmltext | `compensations` | Italian |
| `importi` | ezxmltext | `amounts` | Italian |
| `start_time` | ezdate | `start_date` | Misleading `_time` suffix; confirmed ezdate → renamed to `_date` |
| `end_time` | ezdate | `end_date` | Misleading `_time` suffix; confirmed ezdate → renamed to `_date` |
| `data_insediamento` | ezdate | `inauguration_date` | Italian + ezdate → `_date` |
| `incarico_dirigenziale` | ezboolean | `executive_position` | Italian |
| `ruolo_principale` | ezboolean | `primary_role` | Italian |
| `priorita` | ezinteger | `priority` | Italian (missing accent in identifier) |

Others (`label`, `person`, `role`, `type`, `for_entity`, `atto_nomina`, `competences`, `delegations`, `organizational_position`, `notes`) already canonical.

---

## `_with_related` Variants

Content types `article_with_projects`, `event_with_related`, `organization_with_related`, `image_with_related`, `public_service_with_related`, `opening_hours_specification_with_related`, `pagina_sito_with_dataset`, `private_organization` inherit the map of their base type.

**Important implementation note:** PHP static properties cannot self-reference during initialisation. Variant aliases must be resolved inside `getMap()`, not in the `$maps` initialiser:

```php
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
```

Extra fields present in variant types but absent from the base map pass through unchanged. These fields have been reviewed and are already canonical (English, snake_case).

Any additional fields in the variant that are already canonical need no entry.

---

## System Types (pass-through, no map)

`apps_container`, `edit_homepage`, `edit_page`, `folder`, `frontpage`, `homepage`, `link`, `user`, `user_group`, `faq_group`, `faq_root` — these content types do not typically trigger relevant Kafka events and are not mapped. Fields pass through unchanged.

---

## Testing

Unit tests (no Kafka broker required) added to the existing `run_tests.php` runner. **`run_tests.php` must be updated** to `require_once` the two new test files so they are executed by the CI `test` job.

### New test files

```
tests/
  unit/
    OCWebHookKafkaFieldMapTest.php        — verifica la mappa per ogni content type
    OCWebHookPayloadFormatterRenameTest.php — end-to-end: ocopendata input → canonical output
    fixtures/
      article_raw.json / article_expected.json
      document_raw.json / document_expected.json
      event_raw.json / event_expected.json
      ... (10 content type principali)
```

**Coverage priority:** `article`, `document`, `event`, `organization`, `place`, `public_person`, `public_service`, `time_indexed_role`, `opening_hours_specification`, `file`. Remaining content types added incrementally.

**Invariant tested:** fields absent from the map pass through unchanged (regression guard for new attributes).

---

## Backward Compatibility

This change is introduced as part of **V1** — the first production release of the Kafka integration. No consumers exist yet, so there is no breaking-change concern and no dual-name transition period is needed.

Going forward, new content types added to the CMS must follow the naming conventions in this spec from the start; no further remapping will be required for them.

---

## Out of Scope

- `entity.meta` field names (already canonical: `object_id`, `remote_id`, `type_id`, `published_at`, `updated_at`)
- CloudEvents headers (`ce_type`, `ce_source`, `oc_app_name`, …)
- REST API field names (ocopenapi extension)
- eZ Publish class definitions in the installer
- Per-instance INI override of the mapping (future work if needed)

## Future Work

- **`offer.start_time`**: eZ type could not be confirmed from installer YAML. To be resolved in implementation: verify type, then rename to `start_date` (ezdate) or `start_at` (ezdatetime).
