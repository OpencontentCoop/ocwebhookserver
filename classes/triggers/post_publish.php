<?php

class PostPublishWebHookTrigger implements OCWebHookTriggerInterface
{
    const IDENTIFIER = 'post_publish_ocopendata';

    public function getIdentifier()
    {
        return self::IDENTIFIER;
    }

    public function getName()
    {
        return 'Post publish event';
    }

    public function getDescription()
    {
        return 'Is triggered when the post publish workflow is activated and a content is published. The payload is a OCOpenData API Content object (http://bit.ly/ocopendata-api)';
    }

    public function canBeEnabled()
    {
        $workflowTypeString = WorkflowWebHookType::WORKFLOW_TYPE_STRING;
        $query = "SELECT COUNT(*) FROM ezworkflow_event WHERE workflow_type_string = 'event_{$workflowTypeString}' AND workflow_id IN (SELECT workflow_id FROM eztrigger WHERE name = 'post_publish')";
        $result = eZDB::instance()->arrayQuery($query);

        $hasPostPublishWorkflow = $result[0]['count'] > 0;
        if ($hasPostPublishWorkflow){
            return true;
        }

        $query = "SELECT COUNT(*) FROM ezworkflow_event WHERE workflow_type_string = 'event_ezmultiplexer' AND data_int1 IN (SELECT workflow_id FROM ezworkflow_event WHERE workflow_type_string = 'event_{$workflowTypeString}') AND workflow_id IN (SELECT workflow_id FROM eztrigger WHERE name = 'post_publish')";
        $result = eZDB::instance()->arrayQuery($query);

        return $result[0]['count'] > 0;
    }

    public function useFilter()
    {
        return false;
    }

    public function isValidPayload($payload, $filters)
    {
        return true;
    }

}
