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
}

eZWorkflowEventType::registerEventType(WorkflowWebHookType::WORKFLOW_TYPE_STRING, 'WorkflowWebHookType');
