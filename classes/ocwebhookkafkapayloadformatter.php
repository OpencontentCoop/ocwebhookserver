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
require_once dirname(__FILE__) . '/ocwebhookkafkafieldmap.php';

class OCWebHookKafkaPayloadFormatter
{
    /** @var string eZ Publish siteaccess name (e.g. "frontend"), used in entity.meta.siteaccess */
    private $siteaccess;

    /** @var string Instance identifier for entity.meta.id prefix (e.g. EZ_INSTANCE "bugliano") */
    private $instanceId;

    /**
     * @param string      $siteaccess  eZ Publish siteaccess name (e.g. "frontend")
     * @param string|null $instanceId  Instance identifier for entity.meta.id (e.g. EZ_INSTANCE).
     *                                 Defaults to $siteaccess when null.
     */
    public function __construct($siteaccess, $instanceId = null)
    {
        $this->siteaccess = $siteaccess;
        $this->instanceId = $instanceId !== null ? $instanceId : $siteaccess;
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
            'id'           => $this->instanceId . ':' . $objectId,
            'siteaccess'   => $this->siteaccess,
            'object_id'    => $objectId,
            'remote_id'    => isset($metadata['remoteId'])          ? $metadata['remoteId']          : null,
            'type_id'      => isset($metadata['classIdentifier'])    ? $metadata['classIdentifier']   : null,
            'version'      => isset($metadata['currentVersion'])     ? (int)$metadata['currentVersion'] : null,
            'languages'    => $languages,
            'name'         => $name,
            'site_url'     => isset($metadata['baseUrl'])            ? $metadata['baseUrl']           : null,
            'published_at' => isset($metadata['published']) && $metadata['published'] !== null
                                ? gmdate('Y-m-d\TH:i:s\Z', (int)$metadata['published']) : null,
            'updated_at'   => isset($metadata['modified'])  && $metadata['modified']  !== null
                                ? gmdate('Y-m-d\TH:i:s\Z', (int)$metadata['modified'])  : null,
        ];

        // Flatten attribute values per language: extract the "content" field from each attribute.
        $data = [];
        foreach ($rawData as $lang => $attributes) {
            $data[$lang] = [];
            if (is_array($attributes)) {
                foreach ($attributes as $attrName => $attrValue) {
                    $content = is_array($attrValue) && array_key_exists('content', $attrValue)
                        ? $attrValue['content']
                        : $attrValue;
                    // Normalize null content of structured attributes to empty array.
                    // ocopendata wraps typed attributes as {"content": <value>};
                    // empty relation lists come as {"content": null} — normalize to [].
                    if ($content === null && is_array($attrValue) && array_key_exists('content', $attrValue)) {
                        $content = [];
                    }
                    // Normalize camelCase keys in relation item lists
                    if (is_array($content) && isset($content[0]) && is_array($content[0])) {
                        $content = array_map(['OCWebHookKafkaPayloadFormatter', 'normalizeRelationItem'], $content);
                    }
                    $data[$lang][$attrName] = $content;
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
    }

    /**
     * Normalize camelCase keys in a relation item (e.g. from ocopendata object relations).
     *
     * @param array $item
     * @return array
     */
    private static function normalizeRelationItem(array $item)
    {
        static $renames = [
            'remoteId'        => 'remote_id',
            'classIdentifier' => 'class_identifier',
            'mainNodeId'      => 'main_node_id',
        ];
        $result = [];
        foreach ($item as $key => $value) {
            $result[isset($renames[$key]) ? $renames[$key] : $key] = $value;
        }
        return $result;
    }
}
