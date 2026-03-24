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
 *   (constructor $tenantId)  → entity.meta.tenant_id
 *   metadata.baseUrl         → entity.meta.site_url
 *   metadata.contentUrl      → entity.meta.content_url  (public frontend URL)
 *   metadata.apiUrl          → entity.meta.api_url      (ocopenapi resource URI, null if ocopenapi unavailable)
 *   metadata.createdBy       → entity.meta.created_by   ({id, login, name} of content owner)
 *   metadata.modifiedBy      → entity.meta.modified_by  ({id, login, name} of current-version author)
 *   metadata.published       → entity.meta.published_at (ISO 8601)
 *   metadata.modified        → entity.meta.updated_at   (ISO 8601)
 *   data.<lang>.<attr>.content → entity.data.<lang>.<attr>  (ISO 8601 date strings normalised to UTC)
 */
require_once dirname(__FILE__) . '/ocwebhookkafkafieldmap.php';

class OCWebHookKafkaPayloadFormatter
{
    /** @var string eZ Publish siteaccess name (e.g. "frontend"), used in entity.meta.siteaccess */
    private $siteaccess;

    /** @var string Instance identifier for entity.meta.id prefix (e.g. EZ_INSTANCE "bugliano") */
    private $instanceId;

    /** @var string|null Tenant UUID from KafkaSettings.TenantId (entity.meta.tenant_id) */
    private $tenantId;

    /**
     * @param string      $siteaccess  eZ Publish siteaccess name (e.g. "frontend")
     * @param string|null $instanceId  Instance identifier for entity.meta.id (e.g. EZ_INSTANCE).
     *                                 Defaults to $siteaccess when null.
     * @param string|null $tenantId    Tenant UUID from KafkaSettings.TenantId (entity.meta.tenant_id).
     */
    public function __construct($siteaccess, $instanceId = null, $tenantId = null)
    {
        $this->siteaccess = $siteaccess;
        $this->instanceId = $instanceId !== null ? $instanceId : $siteaccess;
        $this->tenantId   = $tenantId;
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
            'tenant_id'    => $this->tenantId,
            'siteaccess'   => $this->siteaccess,
            'object_id'    => $objectId,
            'remote_id'    => isset($metadata['remoteId'])          ? $metadata['remoteId']          : null,
            'type_id'      => isset($metadata['classIdentifier'])    ? $metadata['classIdentifier']   : null,
            'version'      => isset($metadata['currentVersion'])     ? (int)$metadata['currentVersion'] : null,
            'languages'    => $languages,
            'name'         => $name,
            'site_url'     => isset($metadata['baseUrl'])            ? $metadata['baseUrl']           : null,
            'content_url'  => isset($metadata['contentUrl'])        ? $metadata['contentUrl']        : null,
            'api_url'      => isset($metadata['apiUrl'])            ? $metadata['apiUrl']            : null,
            'created_by'   => isset($metadata['createdBy'])        ? $metadata['createdBy']         : null,
            'modified_by'  => isset($metadata['modifiedBy'])       ? $metadata['modifiedBy']        : null,
            'published_at' => isset($metadata['published']) && $metadata['published'] !== null
                                ? gmdate('Y-m-d\TH:i:s\Z', self::toTimestamp($metadata['published'])) : null,
            'updated_at'   => isset($metadata['modified'])  && $metadata['modified']  !== null
                                ? gmdate('Y-m-d\TH:i:s\Z', self::toTimestamp($metadata['modified']))  : null,
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
                    // Resolve multi-language maps to the current language (e.g. relation item "name" fields)
                    $content = self::resolveForLanguage($content, $lang);
                    $data[$lang][$attrName] = self::toUtcValue($content);
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
     * Convert a timestamp value to a Unix timestamp integer.
     * Accepts both Unix timestamps (int/numeric string) and date strings (ISO 8601, etc.).
     *
     * @param int|string $value
     * @return int
     */
    private static function toTimestamp($value)
    {
        if (is_numeric($value)) {
            return (int)$value;
        }
        $ts = strtotime($value);
        return $ts !== false ? $ts : 0;
    }

    /**
     * Recursively resolve multi-language maps to a single value for the given language.
     *
     * ocopendata returns relation items with a "name" field (and other fields) as a map
     * of {lang-code: value} pairs, e.g. {"eng-GB": "Innovation", "ita-IT": "Innovazione"}.
     * When serialising entity.data.eng-GB, those maps should be resolved to "Innovation".
     *
     * Detection: an associative array (no integer index 0) whose ALL keys match
     * the BCP-47 pattern /^[a-z]{2,3}-[A-Z]{2}$/ is treated as a multi-language map.
     * Arrays with mixed/numeric keys (lists, relation-item arrays) are traversed recursively.
     *
     * @param mixed  $value  Value from a content attribute or relation item
     * @param string $lang   Current language code (e.g. "ita-IT")
     * @return mixed
     */
    private static function resolveForLanguage($value, $lang)
    {
        if (!is_array($value)) {
            return $value;
        }
        // Is it a multi-language map? (non-empty, no numeric keys, all keys look like lang codes)
        if (!isset($value[0]) && count($value) > 0) {
            $allLangKeys = true;
            foreach (array_keys($value) as $k) {
                if (!preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $k)) {
                    $allLangKeys = false;
                    break;
                }
            }
            if ($allLangKeys) {
                if (array_key_exists($lang, $value)) {
                    return $value[$lang];
                }
                $first = reset($value);
                return $first !== false ? $first : null;
            }
        }
        // Recurse into list or object array
        $result = [];
        foreach ($value as $k => $v) {
            $result[$k] = self::resolveForLanguage($v, $lang);
        }
        return $result;
    }

    /**
     * Recursively convert ISO 8601 datetime strings (with any timezone) to UTC.
     * Strings matching YYYY-MM-DDTHH:MM:SS... are normalised to YYYY-MM-DDTHH:MM:SSZ.
     * Arrays are traversed recursively; all other types pass through unchanged.
     *
     * ocopendata serializes ezdate/ezdatetime attributes with date('c', $ts) which
     * uses the server's local timezone (e.g. "+01:00"). This method ensures the
     * canonical Kafka payload always uses UTC for all date/time values.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function toUtcValue($value)
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
            $ts = strtotime($value);
            return $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : $value;
        }
        if (is_array($value)) {
            return array_map(['OCWebHookKafkaPayloadFormatter', 'toUtcValue'], $value);
        }
        return $value;
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
