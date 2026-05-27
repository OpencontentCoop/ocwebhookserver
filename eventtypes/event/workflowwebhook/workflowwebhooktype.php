<?php

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
        // Gating: se OCSearchEngine è il search engine attivo, l'emissione è già stata
        // fatta da OCSearchEngine::addObject() durante registerSearchObject().
        // Evitiamo la doppia emissione restando silenti.
        if (class_exists('OCSearchEngine') && class_exists('eZSearch')) {
            $engine = eZSearch::getEngine();
            if ($engine instanceof OCSearchEngine) {
                return eZWorkflowType::STATUS_ACCEPTED;
            }
        }

        $parameters = $process->attribute('parameter_list');
        $trigger = $parameters['trigger_name'];

        try {

            $object = eZContentObject::fetch($parameters['object_id']);
            if ($object instanceof eZContentObject) {
                if ($trigger == 'post_publish') {

                    $payload = OCWebHookPayloadBuilder::build($object);

                    $triggerInstance = OCWebHookTriggerRegistry::registeredTrigger(PostPublishWebHookTrigger::IDENTIFIER);
                    $queueHandler = $triggerInstance instanceof OCWebHookTriggerQueueAwareInterface
                        ? $triggerInstance->getQueueHandler()
                        : OCWebHookQueue::defaultHandler();
                    OCWebHookEmitter::emit(
                        PostPublishWebHookTrigger::IDENTIFIER,
                        $payload,
                        $queueHandler
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
