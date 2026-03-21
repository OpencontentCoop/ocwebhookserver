<?php

/**
 * Converts an ocopendata payload array (with "metadata" and "data" keys)
 * to the canonical OpenCity Kafka event format:
 *
 *   { "entity": { "meta": { ... }, "data": { "it-IT": { ... } } } }
 *
 * The ocopendata format has:
 *   metadata.id              → entity.meta.object_id
 *   metadata.remoteId        → entity.meta.remote_id
 *   metadata.classIdentifier → entity.meta.type_id
 *   metadata.currentVersion  → entity.meta.version
 *   metadata.languages       → entity.meta.languages
 *   metadata.name            → entity.meta.name  (primary language)
 *   metadata.baseUrl         → entity.meta.site_url
 *   metadata.published       → entity.meta.published_at (ISO 8601)
 *   metadata.modified        → entity.meta.updated_at   (ISO 8601)
 *   data.<lang>.<attr>.content → entity.data.<lang>.<attr>
 */
class OCWebHookKafkaPayloadFormatter
{
    /** @var string eZ Publish siteaccess name, used as entity identifier prefix */
    private $siteaccess;

    /**
     * @param string $siteaccess eZ Publish siteaccess name (e.g. "comune_it")
     */
    public function __construct($siteaccess)
    {
        $this->siteaccess = $siteaccess;
    }

    /**
     * Format an ocopendata payload into the canonical entity event format.
     *
     * @param array $ocPayload  Raw payload from ocopendata (keys: metadata, data, extradata)
     * @return array            Formatted payload { entity: { meta, data } }
     */
    public function format(array $ocPayload)
    {
        $metadata = isset($ocPayload['metadata']) ? (array)$ocPayload['metadata'] : [];
        $rawData  = isset($ocPayload['data'])     ? (array)$ocPayload['data']     : [];

        $objectId    = isset($metadata['id']) ? (string)$metadata['id'] : '';
        $languages   = isset($metadata['languages']) ? (array)$metadata['languages'] : [];
        $primaryLang = count($languages) > 0 ? $languages[0] : null;

        $nameMap = isset($metadata['name']) ? (array)$metadata['name'] : [];
        $name    = '';
        if ($primaryLang !== null && isset($nameMap[$primaryLang])) {
            $name = $nameMap[$primaryLang];
        } elseif (count($nameMap) > 0) {
            $name = reset($nameMap);
        }

        $meta = [
            'id'           => $this->siteaccess . ':' . $objectId,
            'siteaccess'   => $this->siteaccess,
            'object_id'    => $objectId,
            'remote_id'    => isset($metadata['remoteId'])          ? $metadata['remoteId']          : null,
            'type_id'      => isset($metadata['classIdentifier'])    ? $metadata['classIdentifier']   : null,
            'version'      => isset($metadata['currentVersion'])     ? (int)$metadata['currentVersion'] : null,
            'languages'    => $languages,
            'name'         => $name,
            'site_url'     => isset($metadata['baseUrl'])            ? $metadata['baseUrl']           : null,
            'published_at' => isset($metadata['published']) && $metadata['published'] !== null
                                ? date('c', (int)$metadata['published']) : null,
            'updated_at'   => isset($metadata['modified'])  && $metadata['modified']  !== null
                                ? date('c', (int)$metadata['modified'])  : null,
        ];

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
    }
}
