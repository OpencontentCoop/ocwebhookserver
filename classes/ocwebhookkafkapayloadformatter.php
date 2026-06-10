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
 *   metadata.isPublic        → entity.meta.is_public    (bool, null when absent)
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
     * @param string      $siteaccess        eZ Publish siteaccess name (e.g. "frontend")
     * @param string|null $instanceId        Instance identifier for entity.meta.id (e.g. EZ_INSTANCE).
     *                                       Defaults to $siteaccess when null.
     * @param string|null $tenantId          Tenant UUID from KafkaSettings.TenantId (entity.meta.tenant_id).
     * @param callable|null $imageUrlResolver Optional callable($objectId, $siteUrl): ?string that resolves
     *                                        the URL of an image object. Called for relation items whose
     *                                        class_identifier is 'image' or 'image_with_related' when no
     *                                        'url' is already present in the source item.
     */
    public function __construct($siteaccess, $instanceId = null, $tenantId = null, $imageUrlResolver = null)
    {
        $this->siteaccess        = $siteaccess;
        $this->instanceId        = $instanceId !== null ? $instanceId : $siteaccess;
        $this->tenantId          = $tenantId;
        $this->imageUrlResolver  = is_callable($imageUrlResolver) ? $imageUrlResolver : null;
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
            'created_by'     => isset($metadata['createdBy'])          ? $metadata['createdBy']           : null,
            'modified_by'    => isset($metadata['modifiedBy'])         ? $metadata['modifiedBy']          : null,
            'tree_placement' => isset($metadata['mainParentRemoteId']) ? [
                'main_parent_remote_id' => $metadata['mainParentRemoteId'],
                'parent_remote_ids'     => isset($metadata['parentRemoteIds'])
                    ? array_values((array)$metadata['parentRemoteIds']) : [],
            ] : null,
            'published_at' => isset($metadata['published']) && $metadata['published'] !== null
                                ? gmdate('Y-m-d\TH:i:s\Z', self::toTimestamp($metadata['published'])) : null,
            'updated_at'   => isset($metadata['modified'])  && $metadata['modified']  !== null
                                ? gmdate('Y-m-d\TH:i:s\Z', self::toTimestamp($metadata['modified']))  : null,
            'is_public'    => isset($metadata['isPublic']) ? (bool)$metadata['isPublic'] : null,
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
                    // Normalize item lists: route to the correct normalizer by item structure
                    if (is_array($content) && isset($content[0]) && is_array($content[0])) {
                        $instanceId = $this->instanceId;
                        $siteUrl    = $meta['site_url'];
                        $resolver   = $this->imageUrlResolver;
                        $content = array_map(
                            function ($item) use ($instanceId, $siteUrl, $resolver) {
                                if (isset($item['classIdentifier']) || isset($item['class_identifier'])) {
                                    return OCWebHookKafkaPayloadFormatter::normalizeRelationItem($item, $instanceId, $siteUrl, $resolver);
                                }
                                // Direct file items (ocmultibinary): already have filename+url,
                                // no classIdentifier — pass through as-is to avoid spurious
                                // id/title/taxonomy null fields from normalizeTaxonomyItem.
                                if (isset($item['filename'])) {
                                    return $item;
                                }
                                return OCWebHookKafkaPayloadFormatter::normalizeTaxonomyItem($item, $siteUrl);
                            },
                            $content
                        );
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

        // Apply content-type-specific structural transformations.
        $typeId = $meta['type_id'];
        if ($typeId === 'event' || $typeId === 'event_with_related') {
            foreach ($data as $lang => $attrs) {
                $attrs = self::flattenTimeInterval($attrs);
                $data[$lang] = self::castBooleans($attrs, ['is_accessible_for_free']);
            }
        }
        if ($typeId === 'time_indexed_role') {
            foreach ($data as $lang => $attrs) {
                $data[$lang] = self::castBooleans($attrs, [
                    'executive_position',
                    'primary_role',
                    'organizational_position',
                ]);
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
     * Normalize a vocabulary/taxonomy item (eztags, enum vocabulary types).
     * Detection: item has no 'classIdentifier'/'class_identifier' and no 'filename'/'mime_type'.
     *
     * Output: {id, title, priority, [code,] taxonomy: {id, api_url}}
     *
     * taxonomy is built from:
     *   1. $item['taxonomy']      — already present (pass-through)
     *   2. $item['vocabulary_id'] — e.g. "vocabulary_licenses" → constructs api_url from $siteUrl
     *
     * @param array       $item
     * @param string|null $siteUrl  e.g. "https://www.comune.example.it"
     * @return array
     */
    private static function normalizeTaxonomyItem(array $item, $siteUrl = null)
    {
        $result = [
            'id'    => isset($item['id']) ? $item['id'] : null,
            'title' => isset($item['name']) ? $item['name'] : null,
        ];

        if (isset($item['priority'])) {
            $result['priority'] = (int)$item['priority'];
        }

        static $skip = ['id' => true, 'name' => true, 'priority' => true,
                        'taxonomy' => true, 'vocabulary_id' => true,
                        'languages' => true, 'link' => true, 'class' => true];
        foreach ($item as $key => $value) {
            if (!isset($skip[$key])) {
                $result[$key] = $value;
            }
        }

        if (isset($item['taxonomy'])) {
            $result['taxonomy'] = $item['taxonomy'];
        } elseif (isset($item['vocabulary_id']) && $siteUrl !== null) {
            $vocId   = $item['vocabulary_id'];
            $vocSlug = str_replace('_', '-', str_replace('vocabulary_', '', $vocId));
            $result['taxonomy'] = [
                'id'      => $vocId,
                'api_url' => rtrim($siteUrl, '/') . '/api/openapi/vocabularies/' . $vocSlug,
            ];
        } else {
            $result['taxonomy'] = null;
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
     * Cast specified fields from ezboolean int (0/1) to PHP bool.
     * Fields absent or already null are left unchanged.
     *
     * @param array    $attrs   entity.data.{lang} attribute map
     * @param string[] $fields  field names to cast
     * @return array
     */
    private static function castBooleans(array $attrs, array $fields)
    {
        foreach ($fields as $field) {
            if (isset($attrs[$field])) {
                $attrs[$field] = (bool)$attrs[$field];
            }
        }
        return $attrs;
    }

    /**
     * Flatten the ocevent `time_interval` field into top-level start_at / end_at / recurrences.
     *
     * Input (ocevent structure from ocopendata):
     *   time_interval = {
     *     events: [{start: "...", end: "...", ...}, ...],
     *     default_value: {from_time: "...", to_time: "...", count: N}
     *   }
     *
     * Output (replaces time_interval with):
     *   start_at     — ISO 8601 UTC string (from default_value.from_time), null if absent
     *   end_at       — ISO 8601 UTC string (from default_value.to_time),   null if absent
     *   recurrences  — [{start_at, end_at}, ...] from events array (empty array if absent)
     *
     * Dates are already UTC-normalised by toUtcValue() before this method is called.
     *
     * @param array $attrs  entity.data.{lang} attribute map after FieldMap renames
     * @return array
     */
    private static function flattenTimeInterval(array $attrs)
    {
        if (!isset($attrs['time_interval']) || !is_array($attrs['time_interval'])) {
            return $attrs;
        }

        $ti = $attrs['time_interval'];
        unset($attrs['time_interval']);

        $defaultValue = isset($ti['default_value']) && is_array($ti['default_value'])
            ? $ti['default_value'] : [];
        $events = isset($ti['events']) && is_array($ti['events']) ? $ti['events'] : [];

        $attrs['start_at'] = isset($defaultValue['from_time']) ? $defaultValue['from_time'] : null;
        $attrs['end_at']   = isset($defaultValue['to_time'])   ? $defaultValue['to_time']   : null;

        $attrs['recurrences'] = array_map(
            function ($e) {
                return [
                    'start_at' => isset($e['start']) ? $e['start'] : null,
                    'end_at'   => isset($e['end'])   ? $e['end']   : null,
                ];
            },
            $events
        );

        return $attrs;
    }

    /**
     * Normalize a relation item coming from ocopendata.
     *
     * Output: {type_id, id: "instanceId:objectId", object_id, remote_id, title, [api_url, priority, ...]}
     * Dropped: class, classIdentifier, class_identifier, languages, link, mainNodeId, main_node_id, name
     *
     * @param array  $item
     * @param string $instanceId  e.g. "bugliano" — prefixed to object id
     * @return array
     */
    private static function normalizeRelationItem(array $item, $instanceId = '', $siteUrl = null, $resolver = null)
    {
        $classId  = isset($item['classIdentifier'])  ? $item['classIdentifier']
                  : (isset($item['class_identifier']) ? $item['class_identifier'] : null);
        $rawId    = isset($item['id'])               ? $item['id']       : null;
        $remoteId = isset($item['remoteId'])         ? $item['remoteId']
                  : (isset($item['remote_id'])        ? $item['remote_id'] : null);
        $title    = isset($item['name'])             ? $item['name']     : null;

        $result = [
            'type_id'   => $classId,
            'id'        => $instanceId . ':' . $rawId,
            'object_id' => $rawId !== null ? (string)$rawId : null,
            'remote_id' => $remoteId,
            'title'     => $title,
        ];

        static $skip = [
            'id' => true, 'name' => true,
            'remoteId' => true, 'remote_id' => true,
            'classIdentifier' => true, 'class_identifier' => true,
            'mainNodeId' => true, 'main_node_id' => true,
            'class' => true, 'languages' => true, 'link' => true,
        ];
        foreach ($item as $key => $value) {
            if (!isset($skip[$key])) {
                $result[$key] = $value;
            }
        }

        // Resolve image URL for image-type relation items when no url is already present.
        $imageTypes = ['image', 'image_with_related'];
        if ($resolver !== null && in_array($classId, $imageTypes, true) && !isset($result['url'])) {
            $resolved = call_user_func($resolver, $rawId, $siteUrl);
            if ($resolved !== null) {
                $result['url'] = $resolved;
            }
        }

        return $result;
    }
}
