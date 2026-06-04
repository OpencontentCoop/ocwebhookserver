<?php

use Opencontent\Opendata\Api\Values\Content;

class DeleteWorkflowWebHookType extends eZWorkflowEventType
{
    const WORKFLOW_TYPE_STRING = 'deleteworkflowwebhook';

    function __construct()
    {
        $this->eZWorkflowEventType(self::WORKFLOW_TYPE_STRING, 'Pre delete webhook');
    }

    /**
     * @param eZWorkflowProcess $process
     * @param eZWorkflowEvent $event
     *
     * @return int
     */
    function execute($process, $event)
    {
        // Gating per hard delete: se OCSearchEngine è attivo E l'oggetto è già ARCHIVED
        // (= sta per essere cancellato definitivamente dal cestino), OCSearchEngine::removeObject()
        // verrà chiamato a breve e emetterà il delete_ocopendata — restiamo silenti.
        //
        // Per soft delete (trash), l'oggetto è ancora PUBLISHED al momento del pre_delete:
        // in quel caso eZSolr::removeObject() NON viene chiamato, quindi dobbiamo emettere qui.
        if (class_exists('OCSearchEngine')) {
            $engine = eZSearch::getEngine();
            if ($engine instanceof OCSearchEngine) {
                $parameters = $process->attribute('parameter_list');
                $nodeList = eZContentObjectTreeNode::fetch($parameters['node_id_list']);
                if (!is_array($nodeList)) {
                    $nodeList = [$nodeList];
                }
                if (!empty($nodeList)) {
                    $firstNode = reset($nodeList);
                    if ($firstNode instanceof eZContentObjectTreeNode) {
                        $obj = $firstNode->object();
                        if ($obj instanceof eZContentObject
                            && (int)$obj->attribute('status') === eZContentObject::STATUS_ARCHIVED
                        ) {
                            // Hard delete: OCSearchEngine::removeObject emette
                            return eZWorkflowType::STATUS_ACCEPTED;
                        }
                    }
                }
                // Soft delete (trash): l'oggetto è PUBLISHED → caduta attraverso per emettere qui
            }
        }

        $parameters = $process->attribute('parameter_list');
        $trigger = $parameters['trigger_name'];

        try {
            if ($trigger == 'pre_delete') {

                /** @var eZContentObjectTreeNode[] $nodeList */
                $nodeList = eZContentObjectTreeNode::fetch($parameters['node_id_list']);
                if ($nodeList instanceof eZContentObjectTreeNode) {
                    $nodeList = array($nodeList);
                }
                foreach ($nodeList as $node) {
                    $content = Content::createFromEzContentObject($node->object());
                    $currentEnvironment = new DefaultEnvironmentSettings();
                    $parser = new ezpRestHttpRequestParser();
                    $request = $parser->createRequest();
                    $currentEnvironment->__set('request', $request);
                    $payload = $currentEnvironment->filterContent($content);
                    $payload['metadata']['baseUrl'] = eZSys::serverURL();

                    $triggerInstance = OCWebHookTriggerRegistry::registeredTrigger(DeleteWebHookTrigger::IDENTIFIER);
                    $queueHandler = $triggerInstance instanceof OCWebHookTriggerQueueAwareInterface
                        ? $triggerInstance->getQueueHandler()
                        : OCWebHookQueue::defaultHandler();
                    OCWebHookEmitter::emit(
                        DeleteWebHookTrigger::IDENTIFIER,
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

eZWorkflowEventType::registerEventType(DeleteWorkflowWebHookType::WORKFLOW_TYPE_STRING, 'DeleteWorkflowWebHookType');
