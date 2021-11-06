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

                    OCWebHookEmitter::emit(
                        DeleteWebHookTrigger::IDENTIFIER,
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

eZWorkflowEventType::registerEventType(DeleteWorkflowWebHookType::WORKFLOW_TYPE_STRING, 'DeleteWorkflowWebHookType');
