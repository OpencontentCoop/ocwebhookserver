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
                    $payload['metadata']['currentVersion'] = (int)$object->attribute('current_version');

                    $mainNode = $object->mainNode();
                    if ($mainNode instanceof eZContentObjectTreeNode) {
                        $urlAlias = $mainNode->urlAlias();
                        $payload['metadata']['contentUrl'] = $payload['metadata']['baseUrl'] . '/' . ltrim($urlAlias, '/');
                    }

                    $currentVersion = $object->currentVersion();
                    $modifierId = $currentVersion instanceof eZContentObjectVersion
                        ? (int)$currentVersion->attribute('creator_id')
                        : (int)$object->attribute('owner_id');
                    $payload['metadata']['createdBy']  = self::userInfo((int)$object->attribute('owner_id'));
                    $payload['metadata']['modifiedBy'] = self::userInfo($modifierId);

                    $payload['metadata']['apiUrl'] = null;
                    if ($mainNode instanceof eZContentObjectTreeNode
                        && class_exists('Opencontent\\OpenApi\\Loader')
                    ) {
                        try {
                            $pathArray   = explode('/', $mainNode->attribute('path_string'));
                            $classId     = $object->attribute('class_identifier');
                            $remoteId    = $object->attribute('remote_id');

                            $endpoint = \Opencontent\OpenApi\Loader::instance()
                                ->getEndpointProvider()
                                ->getEndpointFactoryCollection()
                                ->findOneByCallback(
                                    function ($ep) use ($classId, $pathArray) {
                                        if (!($ep instanceof \Opencontent\OpenApi\EndpointFactory\NodeClassesEndpointFactory)) {
                                            return false;
                                        }
                                        $getOp = $ep->getOperationByMethod('get');
                                        return $getOp instanceof \Opencontent\OpenApi\OperationFactory\ContentObject\ReadOperationFactory
                                            && in_array($ep->getNodeId(), $pathArray)
                                            && in_array($classId, $ep->getClassIdentifierList());
                                    }
                                );

                            if ($endpoint instanceof \Opencontent\OpenApi\EndpointFactory\NodeClassesEndpointFactory) {
                                $parts = explode('/', $endpoint->getPath());
                                array_pop($parts);
                                $endpointUrl = \Opencontent\OpenApi\Loader::instance()
                                    ->getSettingsProvider()
                                    ->provideSettings()
                                    ->endpointUrl;
                                $basePath  = $endpointUrl . implode('/', $parts) . '/';
                                $nameSlug  = \eZCharTransform::instance()
                                    ->transformByGroup($object->attribute('name'), 'urlalias');
                                $payload['metadata']['apiUrl'] = $basePath . $remoteId . '#' . $nameSlug;
                            }
                        } catch (\Exception $e) {
                            eZLog::write(__METHOD__ . ': apiUrl build failed: ' . $e->getMessage(), 'webhook.log');
                        }
                    }

                    self::enrichRelationContentUrls($payload, $payload['metadata']['baseUrl']);

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

    /**
     * Return a minimal user descriptor array for the given content-object user ID,
     * or null if the user cannot be fetched.
     *
     * @param int $userId  eZContentObject ID of the user
     * @return array|null  ['id' => int, 'login' => string, 'name' => string]
     */
    private static function userInfo($userId)
    {
        if (!$userId) {
            return null;
        }
        $user = eZUser::fetch($userId);
        if (!($user instanceof eZUser)) {
            return null;
        }
        $userObject = eZContentObject::fetch($userId);
        $name = ($userObject instanceof eZContentObject) ? $userObject->name() : $user->attribute('login');
        return [
            'id'    => $userId,
            'login' => $user->attribute('login'),
            'name'  => (string)$name,
        ];
    }

    /**
     * Inject content_url into relation items that have a mainNodeId.
     *
     * Iterates all data languages and all attributes, finds relation item lists
     * (array of arrays each having a mainNodeId key), fetches the node's URL
     * alias, and sets content_url = baseUrl + '/' + urlAlias on each item.
     * Results are cached by nodeId to avoid duplicate DB queries.
     *
     * @param array  &$payload  Raw ocopendata payload (modified in place)
     * @param string  $baseUrl  Site base URL (e.g. https://www.comune.example.it)
     */
    private static function enrichRelationContentUrls(array &$payload, $baseUrl)
    {
        if (empty($payload['data']) || !is_array($payload['data'])) {
            return;
        }
        $nodeUrlCache = [];
        foreach ($payload['data'] as $lang => &$attributes) {
            if (!is_array($attributes)) {
                continue;
            }
            foreach ($attributes as $attrName => &$attrValue) {
                // A relation item list is stored as {content: [{id, mainNodeId, ...}, ...]}
                $items = null;
                if (is_array($attrValue) && array_key_exists('content', $attrValue)
                    && is_array($attrValue['content'])
                    && isset($attrValue['content'][0])
                    && is_array($attrValue['content'][0])
                ) {
                    $items = &$attrValue['content'];
                }
                if ($items === null) {
                    continue;
                }
                foreach ($items as &$item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $nodeId = isset($item['mainNodeId']) ? (int)$item['mainNodeId']
                            : (isset($item['main_node_id']) ? (int)$item['main_node_id'] : null);
                    if (!$nodeId) {
                        continue;
                    }
                    if (!array_key_exists($nodeId, $nodeUrlCache)) {
                        $node = eZContentObjectTreeNode::fetch($nodeId);
                        $nodeUrlCache[$nodeId] = ($node instanceof eZContentObjectTreeNode)
                            ? $baseUrl . '/' . ltrim($node->urlAlias(), '/')
                            : null;
                    }
                    if ($nodeUrlCache[$nodeId] !== null) {
                        $item['content_url'] = $nodeUrlCache[$nodeId];
                    }
                }
                unset($item);
            }
            unset($attrValue);
        }
        unset($attributes);
    }
}

eZWorkflowEventType::registerEventType(WorkflowWebHookType::WORKFLOW_TYPE_STRING, 'WorkflowWebHookType');
