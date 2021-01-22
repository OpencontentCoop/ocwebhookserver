<?php

use GuzzleHttp\Psr7;

class OCWebHook extends eZPersistentObject
{
    private $triggers;

    public static function definition()
    {
        return [
            'fields' => [
                'id' => [
                    'name' => 'ID',
                    'datatype' => 'integer',
                    'default' => null,
                    'required' => true
                ],
                'name' => [
                    'name' => 'name',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => true
                ],
                'url' => [
                    'name' => 'url',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => true
                ],
                'enabled' => [
                    'name' => 'enabled',
                    'datatype' => 'integer',
                    'default' => 0,
                    'required' => true
                ],
                'payload_params' => [
                    'name' => 'payload_params',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => false
                ],
                'method' => [
                    'name' => 'method',
                    'datatype' => 'string',
                    'default' => 'post',
                    'required' => true
                ],
                'content_type' => [
                    'name' => 'content_type',
                    'datatype' => 'string',
                    'default' => 'application/json',
                    'required' => true
                ],
                'headers' => [
                    'name' => 'headers',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => false
                ],
                'secret' => [
                    'name' => 'secret',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => false
                ],
                'created_at' => [
                    'name' => 'created_at',
                    'datatype' => 'integer',
                    'default' => time(),
                    'required' => false
                ],
            ],
            'keys' => ['id'],
            'increment_key' => 'id',
            'class_name' => 'OCWebHook',
            'name' => 'ocwebhook',
            'set_functions' => [
                'url' => 'setUrl'
            ],
            'function_attributes' => [
                'triggers' => 'getTriggers',
                'headers_array' => 'getHeadersArray'
            ]
        ];
    }

    public function getHeadersArray()
    {
        if (!empty($this->attribute('headers'))){
            $headers = json_decode($this->attribute('headers'), true);
            $simpleArray = [];
            foreach ($headers as $key => $value){
                $simpleArray[] = "{$key}: {$value}";
            }

            return $simpleArray;
        }

        return [];
    }

    public function isEnabled()
    {
        return $this->attribute('enabled') == 1;
    }

    public function setUrl($url)
    {
        $this->setAttribute('url', self::normalizeUrl($url));
    }

    public function setTriggers($triggers)
    {
        $db = eZDB::instance();
        $db->begin();
        $db->query("DELETE FROM ocwebhook_trigger_link WHERE webhook_id = " . (int)$this->attribute('id'));
        foreach ($triggers as $trigger) {
            if (is_string($trigger)) {
                $trigger = ['identifier' => $trigger, 'filters' => ''];
            }
            $db->query("INSERT INTO ocwebhook_trigger_link ( webhook_id, trigger_identifier, filters ) VALUES ( '" . (int)$this->attribute('id') . "', '" . $db->escapeString($trigger['identifier']) . "', '" . $db->escapeString($trigger['filters']) . "' )");
        }
        $db->commit();
        $this->triggers = null;
    }

    public function getTriggers()
    {
        if ($this->triggers === null) {
            $db = eZDB::instance();
            $triggers = $db->arrayQuery("SELECT trigger_identifier as identifier, filters FROM ocwebhook_trigger_link WHERE webhook_id = " . (int)$this->attribute('id'));
            $this->triggers = [];
            foreach ($triggers as $trigger) {
                $registeredTrigger = OCWebHookTriggerRegistry::registeredTrigger($trigger['identifier']);
                if ($registeredTrigger instanceof OCWebHookTriggerInterface) {
                    $trigger['name'] = $registeredTrigger->getName();
                    $this->triggers[] = $trigger;
                }
            }
        }

        return $this->triggers;
    }

    public function store($fieldFilters = null)
    {
        $this->setAttribute('url', self::normalizeUrl($this->attribute('url')));
        parent::store($fieldFilters);
    }

    /**
     * @param $id
     * @return array|OCWebHook|null
     */
    public static function fetch($id)
    {
        $webhook = self::fetchObject(self::definition(), null, array('id' => (int)$id));

        return $webhook;
    }

    /**
     * @param $name
     * @param $url
     * @return array|OCWebHook|null
     */
    public static function fetchByNameAndUrl($name, $url)
    {
        $webhook = self::fetchObject(self::definition(), null, array('name' => $name, 'url' => self::normalizeUrl($url)));

        return $webhook;
    }

    /**
     * @return OCWebHook[]
     */
    public static function fetchList($offset = 0, $limit = 0, $conds = null)
    {
        if (!$limit)
            $aLimit = null;
        else
            $aLimit = array('offset' => $offset, 'length' => $limit);

        $sort = array('created_at' => 'asc');
        $aImports = self::fetchObjectList(self::definition(), null, $conds, $sort, $aLimit);

        return $aImports;
    }

    /**
     * @return OCWebHook[]
     */
    public static function fetchListByName($name)
    {
        $webhookList = self::fetchObjectList(self::definition(), null, array('name' => $name));

        return $webhookList;
    }

    /**
     * @param $triggerIdentifier
     * @return OCWebHook[]
     */
    public static function fetchEnabledListByTrigger($triggerIdentifier)
    {
        $db = eZDB::instance();
        $triggerIdentifier = $db->escapeString($triggerIdentifier);
        $res = $db->arrayQuery("SELECT webhook_id FROM ocwebhook_trigger_link WHERE trigger_identifier = '$triggerIdentifier' ORDER BY webhook_id ASC");
        $idList = array_column($res, 'webhook_id');
        if (!empty($idList)) {
            $webhookList = self::fetchObjectList(self::definition(), null, array(
                'id' => [$idList],
                'enabled' => 1
            ));
        } else {
            $webhookList = [];
        }

        return $webhookList;
    }

    public static function removeByName($name)
    {
        self::removeObject(self::definition(), array('name' => $name));
    }

    public function remove($conditions = null, $extraConditions = null)
    {
        $def = $this->definition();
        $keys = $def["keys"];
        if (!is_array($conditions)) {
            $conditions = array();
            foreach ($keys as $key) {
                $value = $this->attribute($key);
                $conditions[$key] = $value;
            }
        }
        self::removeObject($def, $conditions, $extraConditions);
    }

    public static function removeObject($def, $conditions = null, $extraConditions = null)
    {
        $db = eZDB::instance();
        $table = $def["name"];
        if (is_array($extraConditions)) {
            foreach ($extraConditions as $key => $cond) {
                $conditions[$key] = $cond;
            }
        }
        $fields = $def['fields'];
        eZPersistentObject::replaceFieldsWithShortNames($db, $fields, $conditions);
        $condText = eZPersistentObject::conditionText($conditions);

        $db->begin();
        $db->query("DELETE FROM ocwebhook_trigger_link WHERE webhook_id IN (SELECT id FROM $table $condText)");
        $db->query("DELETE FROM ocwebhook_job WHERE execution_status = 0 AND webhook_id IN (SELECT id FROM $table $condText)");
        parent::removeObject($def, $conditions, $extraConditions);
        $db->commit();

    }

    public static function normalizeUrl($url)
    {
        $uri = new Psr7\Uri($url);
        $uri = Psr7\UriNormalizer::normalize($uri);

        return (string)$uri;
    }

    /**
     * @return bool
     */
    public function hasCustomPayloadParameters()
    {
        return !empty($this->attribute('payload_params'));
    }

    /**
     * @return array
     */
    public function getCustomPayloadParameters()
    {
        return json_decode($this->attribute('payload_params'), true);
    }
}