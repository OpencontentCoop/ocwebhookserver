# Missing Content Types — FieldMap Design Spec

**Date:** 2026-05-26  
**Goal:** Aggiungere 5 content type mancanti a `OCWebHookKafkaFieldMap` in modo che il payload Kafka li emetta con field names canonici, rendendoli correttamente indicizzabili da Meilisearch nella ricerca generale del frontend.

**Scope:** Solo `OCWebHookKafkaFieldMap` (rename di field names nel payload `entity.data`). Nessuna modifica all'architettura di emissione, al producer Kafka, o ai workflow eZ Publish — il trigger `post_publish_ocopendata` già emette eventi per tutti i content type.

**Out of scope (follow-up separato):** Pubblicazione degli schemi JSON corrispondenti su `schemas.opencityitalia.it`.

---

## Contesto

L'indice Meilisearch del frontend ricerca tra i content type indicizzati. Attualmente mancano 5 content type presenti nel CMS:

| Label frontend | Class identifier | Motivo assenza |
|---|---|---|
| Approfondimento | `insight` | Non in `$maps` né in `$variantAliases` |
| Guida | `howto` | Non in `$maps` (ma non ha renames — solo documentazione) |
| Itinerario | `itinerary` | Non in `$maps` |
| Pagina trasparenza | `pagina_trasparenza` | Non in `$maps` |
| Progetto | `public_project` | Non in `$maps` |

Content type esclusi dalla lista con motivazione:
- `trasparenza` (Sezione trasparenza): container strutturale (`is_container: 1`) con campi minimi (`name`, `intro`, `descrizione`, `destinatario` email interna) — non utile per ricerca pubblica.

---

## Architettura

Nessun cambio architetturale. Il pattern è lo stesso già in uso:

```
OCWebHookKafkaPayloadFormatter::format()
    │
    └─ applica OCWebHookKafkaFieldMap::getMap($type_id)
            │
            ├─ $maps[$contentTypeId]       — mappa diretta
            └─ $variantAliases[$typeId]    — alias verso tipo base
```

Campi non presenti nella mappa passano attraverso invariati (no errori, no warning).

---

## Modifiche a `classes/ocwebhookkafkafieldmap.php`

### 1. `insight` (Approfondimento) — `$variantAliases`

`insight` condivide esattamente le stesse due rename di `article` (`published` → `published_date`, `dead_line` → `deadline_date`). Si aggiunge come alias per DRY e coerenza automatica con future rename di `article`.

```php
'insight' => 'article',
```

Tutti gli altri campi (`title`, `abstract`, `author`, `topics`, `body`, `image`, `layout`, `license`, `show_fullwidth`) sono già canonici.

### 2. `howto` (Guida) — nessuna rename

Tutti i campi (`title`, `abstract`, `image`, `description`, `audience`, `step_intro`, `steps`, `more_info`, `topics`, `attachments`) sono già in inglese snake_case.

Aggiunto al commento `// Content types with no renames:` per documentazione.

### 3. `itinerary` (Itinerario) — 2 renames

Stesso pattern di `event_title` → `title`: rimozione del prefisso `itinerary_` ridondante dal contesto dell'evento Kafka (il `type_id` è già `itinerary`).

| eZ attribute | eZ type | Canonical name | Motivo |
|---|---|---|---|
| `itinerary_types` | eztags | `types` | Prefisso `itinerary_` ridondante |
| `itinerary_difficulties` | eztags | `difficulties` | Prefisso `itinerary_` ridondante |

Campi già canonici: `title`, `abstract`, `image`, `description`, `stages`, `gpx_attachment`, `route_length`, `journey_time`, `highest_point`, `lowest_point`, `more_info`, `topics`, `attachments`.

### 4. `pagina_trasparenza` (Pagina trasparenza) — 10 renames

Tutti i campi sono in italiano. Fonte: `modules/trasparenza/classes/pagina_trasparenza.yml`.

| eZ attribute | eZ type | Canonical name | Motivo |
|---|---|---|---|
| `titolo` | ezstring | `title` | Italiano |
| `contenuto_obbligo` | ezxmltext | `obligation_content` | Italiano |
| `riferimenti_normativi` | eztext | `legislative_references` | Italiano |
| `applicabilita` | ezxmltext | `applicability` | Italiano |
| `denominazione_degli_obblighi` | ezxmltext | `obligation_name` | Italiano |
| `guida_alla_compilazione` | ezxmltext | `compilation_guide` | Italiano |
| `messaggio_di_consiglio` | ezxmltext | `advice_message` | Italiano |
| `decorrenza_di_pubblicazione` | ezselection | `publication_start` | Italiano |
| `aggiornamento` | ezselection | `update_frequency` | Italiano |
| `termine_pubblicazione` | ezselection | `publication_end` | Italiano |

Tutti gli altri campi (`fields`, `fields_blocks`) hanno già identificatori in inglese — nessuna rename necessaria, passano attraverso invariati come qualsiasi campo non mappato.

### 5. `public_project` (Progetto) — 3 renames

Fonte: `modules/projects/classes/public_project.yml`.

| eZ attribute | eZ type | Canonical name | Motivo |
|---|---|---|---|
| `published` | ezdate | `published_date` | ezdate → `_date` suffix |
| `has_status` | eztags | `status` | Scalar taxonomy — `has_` prefix fuorviante su valore semplice |
| `has_status_notes` | ezxmltext | `status_notes` | Scalar — `has_` prefix fuorviante su valore semplice |

Campi già canonici: `title`, `alternative_name`, `identifier`, `image`, `mission_text`, `description`, `topics`, `budget`, `budget_financing`, `activities`, `holds_role_in_time` (relation → `has_` invariato), `is_compliant_with_rule` (OntoPiA), `has_document` (relation → invariato), `has_online_contact_point` (relation → invariato), `has_temporal_coverage` (relation → invariato), `link`, `relation_public_project`, `keyword`, `related_news`, `mission`, `logos`.

---

## Naming Conventions applicate

Le stesse del design `2026-03-23-kafka-canonical-field-names-design.md`:

- `ezdate` fields → suffisso `_date`
- `has_*` su relazioni (`ezobjectrelationlist`, `openpareverserelationlist`) → invariato
- `has_*` su scalari (`eztags`, `ezxmltext`, `ezstring`, …) → rimuovi `has_`
- Prefisso ridondante uguale al `type_id` → rimosso (come `event_title` → `title`)
- Campi italiani → traduzione inglese snake_case

---

## Test

### `tests/FieldMapTest.php` — TEST 9–13

Un blocco per ogni nuovo content type:

- **TEST 9** — `insight` risolve la mappa di `article` via variantAlias
- **TEST 10** — `howto` restituisce array vuoto (nessuna rename)
- **TEST 11** — `itinerary`: `itinerary_types`→`types`, `itinerary_difficulties`→`difficulties`
- **TEST 12** — `pagina_trasparenza`: tutte e 10 le rename
- **TEST 13** — `public_project`: `published`→`published_date`, `has_status`→`status`, `has_status_notes`→`status_notes`

### `tests/PayloadFormatterRenameTest.php` — TEST 6

End-to-end su `pagina_trasparenza` (content type con più renames): verifica che il formatter applichi tutte e 10 le rename su `entity.data` e che i campi originali non siano presenti.

---

## Follow-up

- **schemas.opencityitalia.it**: pubblicare gli schemi JSON per `insight`, `howto`, `itinerary`, `pagina_trasparenza`, `public_project`. Responsabile: OpenCity Labs (team separato). Tracciato ma fuori scope da questo task.
