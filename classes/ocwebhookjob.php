<?php

class OCWebHookJob extends eZPersistentObject
{
    const STATUS_PENDING = 0;

    const STATUS_RUNNING = 1;

    const STATUS_DONE = 2;

    const STATUS_FAILED = 3;

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
                'execution_status' => [
                    'name' => 'execution_status',
                    'datatype' => 'integer',
                    'default' => self::STATUS_PENDING,
                    'required' => true
                ],
                'webhook_id' => [
                    'name' => 'webhook_id',
                    'datatype' => 'integer',
                    'default' => null,
                    'required' => true
                ],
                'trigger_identifier' => [
                    'name' => 'trigger_identifier',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => true
                ],
                'payload' => [
                    'name' => 'payload',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => true
                ],
                'created_at' => [
                    'name' => 'created_at',
                    'datatype' => 'integer',
                    'default' => time(),
                    'required' => false
                ],
                'executed_at' => [
                    'name' => 'executed_at',
                    'datatype' => 'integer',
                    'default' => null,
                    'required' => false
                ],
                'response_headers' => [
                    'name' => 'response_headers',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => true
                ],
                'response_status' => [
                    'name' => 'response_status',
                    'datatype' => 'integer',
                    'default' => null,
                    'required' => true
                ],
                'hostname' => [
                    'name' => 'hostname',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => false
                ],
                'pid' => [
                    'name' => 'pid',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => false
                ],
            ],
            'keys' => ['id'],
            'increment_key' => 'id',
            'class_name' => 'OCWebHookJob',
            'name' => 'ocwebhook_job',
            'function_attributes' => [
                'webhook' => 'getWebhook',
                'trigger' => 'getTrigger',
            ]
        ];
    }

    public function getWebhook()
    {
        return OCWebHook::fetch($this->attribute('webhook_id'));
    }

    public function getTrigger()
    {
        $trigger = OCWebHookTriggerRegistry::registeredTrigger($this->attribute('trigger_identifier'));
        if ($trigger){
            return [
                'name' => $trigger->getName(),
                'identifier' => $trigger->getIdentifier(),
                'description' => $trigger->getDescription(),
            ];
        }else{
            return [
                'name' => '?',
                'identifier' => $this->attribute('trigger_identifier'),
                'description' => '?',
            ];
        }
    }

    /**
     * @return OCWebHookJob[]
     */
    public static function fetchList($offset = 0, $limit = 0, $conds = null)
    {
        if (!$limit)
            $aLimit = null;
        else
            $aLimit = array('offset' => $offset, 'length' => $limit);

        $sort = array('id' => 'desc');
        $aImports = self::fetchObjectList(self::definition(), null, $conds, $sort, $aLimit);

        return $aImports;
    }

    public static function fetchListByWebHookId($webHookId, $offset = 0, $limit = 0, $status = null)
    {
        $conds = ['webhook_id' => (int)$webHookId];
        if ($status !== null){
            $conds['execution_status'] = (int) $status;
        }

        return self::fetchList($offset, $limit, $conds);
    }

    public static function fetchCountByWebHookId($webHookId, $status = null)
    {
        $conds = ['webhook_id' => (int)$webHookId];
        if ($status !== null){
            $conds['execution_status'] = (int) $status;
        }

        return self::count(OCWebHookJob::definition(), $conds);
    }

    public static function fetchCountByExecutionStatus($status)
    {
        return OCWebHookJob::count(OCWebHookJob::definition(), ['execution_status' => (int)$status]);
    }

    public static function fetchListByExecutionStatus($status, $offset = 0, $limit = 0)
    {
        return self::fetchList($offset, $limit, ['execution_status' => (int)$status]);
    }


    public static function fetchTodoCount()
    {
        return OCWebHookJob::fetchCountByExecutionStatus(OCWebHookJob::STATUS_PENDING);
    }

    public static function fetchTodoList($offset, $limit)
    {
        return OCWebHookJob::fetchListByExecutionStatus(OCWebHookJob::STATUS_PENDING, $offset, $limit);
    }

    /**
     * @param $id
     * @return array|OCWebHookJob|null
     */
    public static function fetch($id)
    {
        $webhook = self::fetchObject(self::definition(), null, array('id' => (int)$id));

        return $webhook;
    }

    public static function removeUntilDate($timestamp)
    {
        $db = eZDB::instance();
        $db->begin();
        $db->query("DELETE FROM ocwebhook_job WHERE created_at <= $timestamp");
        $db->commit();
    }
}