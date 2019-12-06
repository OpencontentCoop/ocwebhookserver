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
        return 'Standard post publish';
    }

    public function getDescription()
    {
        return 'Is triggered when the post publish workflow is activated and a content is published. The payoload is a OCOpenData API Content object (http://bit.ly/ocopendata-api)';
    }

    public function canBeEnabled()
    {
        $workflowTypeString = WorkflowWebHookType::WORKFLOW_TYPE_STRING;
        $query = "SELECT COUNT(*) FROM ezworkflow_event WHERE workflow_type_string = 'event_{$workflowTypeString}' AND workflow_id IN (SELECT workflow_id FROM eztrigger WHERE name = 'post_publish')";
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