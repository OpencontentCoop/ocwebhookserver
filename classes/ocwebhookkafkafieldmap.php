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
            'published'       => 'published_date',
            'dead_line'       => 'deadline_date',
            'id_comunicato'   => 'notice_id',
            'attachment'      => 'attachments',
            'dataset'         => 'datasets',
            'related_service' => 'related_services',
        ],

        // ── document (Documenti) ──────────────────────────────────────────────
        'document' => [
            'has_code'             => 'code',
            'protocollo'           => 'protocol_number',
            'data_protocollazione' => 'protocollation_date',
        ],

        // ── event (Eventi) ────────────────────────────────────────────────────
        'event' => [
            'event_title'       => 'title',
            'short_event_title' => 'short_title',
            'event_abstract'    => 'abstract',
        ],

        // ── organization (Unità organizzative) ───────────────────────────────
        'organization' => [
            'alt_name'                   => 'alternative_name',
            'servizi_offerti'            => 'offered_services',
            'tax_code_e_invoice_service' => 'tax_code',
        ],

        // ── place (Luoghi) ────────────────────────────────────────────────────
        'place' => [
            'has_video' => 'video_url',
            'sede_di'   => 'headquarters_of',
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
            'eu'                   => 'eu_classification',
            'data_themes_eurovocs' => 'eurovoc_themes',
        ],

        // ── dataset ───────────────────────────────────────────────────────────
        'dataset' => [
            'modified'           => 'modified_date',
            'issued'             => 'issued_date',
            'accrualperiodicity' => 'accrual_periodicity',
        ],

        // ── pagina_sito (Pagine del sito) ─────────────────────────────────────
        'pagina_sito' => [
            'publish_date' => 'published_date',
        ],

        // ── file ──────────────────────────────────────────────────────────────
        'file' => [
            'percorso_univoco' => 'unique_path',
        ],

        // ── audio ─────────────────────────────────────────────────────────────
        'audio' => [
            'sottotitolo' => 'subtitle',
            'durata'      => 'duration',
        ],

        // ── channel ───────────────────────────────────────────────────────────
        'channel' => [
            'has_channel_type' => 'channel_type',
        ],

        // ── online_contact_point ──────────────────────────────────────────────
        'online_contact_point' => [
            'note' => 'notes',
        ],

        // ── opening_hours_specification ───────────────────────────────────────
        'opening_hours_specification' => [
            'valid_from'    => 'valid_from_date',
            'valid_through' => 'valid_through_date',
            'note'          => 'notes',
            'stagionalita'  => 'seasonality',
        ],

        // ── time_indexed_role ─────────────────────────────────────────────────
        'time_indexed_role' => [
            'compensi'              => 'compensations',
            'importi'               => 'amounts',
            'start_time'            => 'start_date',
            'end_time'              => 'end_date',
            'data_insediamento'     => 'inauguration_date',
            'incarico_dirigenziale' => 'executive_position',
            'ruolo_principale'      => 'primary_role',
            'priorita'              => 'priority',
        ],

        // Content types with no renames: public_service, faq, faq_section,
        // image, chart, banner, offer, output — all fields already canonical.
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
