<?php

class DeleteWebHookTrigger implements OCWebHookTriggerInterface
{
    const IDENTIFIER = 'delete_ocopendata';

    public function getIdentifier()
    {
        return self::IDENTIFIER;
    }

    public function getName()
    {
        return 'Pre delete event';
    }

    public function getDescription()
    {
        return 'Is triggered when the pre delete workflow is activated and a content is deleted. The payload is a OCOpenData API Content object (http://bit.ly/ocopendata-api)';
    }

    public function canBeEnabled()
    {
        $workflowTypeString = DeleteWorkflowWebHookType::WORKFLOW_TYPE_STRING;
        $query = "SELECT COUNT(*) FROM ezworkflow_event WHERE workflow_type_string = 'event_{$workflowTypeString}' AND workflow_id IN (SELECT workflow_id FROM eztrigger WHERE name = 'pre_delete')";
        $result = eZDB::instance()->arrayQuery($query);

        $hasPostPublishWorkflow = $result[0]['count'] > 0;
        if ($hasPostPublishWorkflow){
            return true;
        }

        $query = "SELECT COUNT(*) FROM ezworkflow_event WHERE workflow_type_string = 'event_ezmultiplexer' AND data_int1 IN (SELECT workflow_id FROM ezworkflow_event WHERE workflow_type_string = 'event_{$workflowTypeString}') AND workflow_id IN (SELECT workflow_id FROM eztrigger WHERE name = 'pre_delete')";
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
