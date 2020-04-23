<?php

use Opencontent\Opendata\Api\Values\Content;

class WorkflowWebHookType extends eZWorkflowEventType
{
    const WORKFLOW_TYPE_STRING = 'workflowwebhook';

    function __construct()
    {
        $this->eZWorkflowEventType(self::WORKFLOW_TYPE_STRING, 'Post publish webhook');
    }

    /**
     * @param eZWorkflowProcess $process
     * @param eZWorkflowEvent $event
     *
     * @return int
     */
    function execute($process, $event)
    {
        $parameters = $process->attribute('parameter_list');
        $trigger = $parameters['trigger_name'];

        try {

            $object = eZContentObject::fetch($parameters['object_id']);
            if ($object instanceof eZContentObject) {
                if ($trigger == 'post_publish') {

                    $content = Content::createFromEzContentObject($object);
                    $currentEnvironment = new DefaultEnvironmentSettings();
                    $parser = new ezpRestHttpRequestParser();
                    $request = $parser->createRequest();
                    $currentEnvironment->__set('request', $request);
                    $payload = $currentEnvironment->filterContent($content);
                    $payload['metadata']['baseUrl'] = eZSys::serverURL();

                    OCWebHookEmitter::emit(
                        PostPublishWebHookTrigger::IDENTIFIER,
                        $payload,
                        OCWebHookQueue::defaultHandler()
                    );
                }
            }

        } catch (Exception $e) {
            eZLog::write(__METHOD__ . ': ' . $e->getMessage(), 'webhook.log');
        }

        return eZWorkflowType::STATUS_ACCEPTED;
    }
}

eZWorkflowEventType::registerEventType(WorkflowWebHookType::WORKFLOW_TYPE_STRING, 'WorkflowWebHookType');
