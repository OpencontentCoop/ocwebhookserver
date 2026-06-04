<?php

use Opencontent\Opendata\Api\Values\Content;

class OCWebHookPayloadBuilder
{
    /**
     * Payload completo per addObject (publish, hide/show, state, section, move, restore, removetranslation).
     */
    public static function build(eZContentObject $object)
    {
        // Cache the environment/request per-process: creating these per-call adds unnecessary
        // churn when processing hundreds of objects in CLI scripts (emit_all_published, crons).
        static $currentEnvironment = null;
        if ($currentEnvironment === null) {
            $currentEnvironment = new DefaultEnvironmentSettings();
            $parser = new ezpRestHttpRequestParser();
            $request = $parser->createRequest();
            $currentEnvironment->__set('request', $request);
        }

        $content = Content::createFromEzContentObject($object);
        $payload = $currentEnvironment->filterContent($content);

        $payload['metadata']['baseUrl']        = eZSys::serverURL();
        $payload['metadata']['currentVersion'] = (int)$object->attribute('current_version');

        $mainNode = $object->mainNode();
        if ($mainNode instanceof eZContentObjectTreeNode) {
            $urlAlias = $mainNode->urlAlias();
            $payload['metadata']['contentUrl'] = $payload['metadata']['baseUrl'] . '/' . ltrim($urlAlias, '/');
            // isPublic: false se il nodo è nascosto/invisibile (is_invisible copre sia il nodo
            // direttamente nascosto che i figli di nodi nascosti) OPPURE se l'utente anonimo
            // non ha permesso di lettura (policy-based).
            $isInvisible = (bool)$mainNode->attribute('is_invisible');
            $payload['metadata']['isPublic'] = !$isInvisible
                && (bool)$mainNode->checkAccess('read', null, null, false, eZUser::anonymousId());
        } else {
            $payload['metadata']['isPublic'] = false;
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
                $pathArray = explode('/', $mainNode->attribute('path_string'));
                $classId   = $object->attribute('class_identifier');
                $remoteId  = $object->attribute('remote_id');

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

        return $payload;
    }

    /**
     * Payload minimal per removeObject (delete/trash): l'oggetto è in stato archived,
     * Content::createFromEzContentObject e checkAccess non sono affidabili.
     * Riempie solo i campi necessari al formatter Kafka per produrre il messaggio delete.
     */
    public static function buildMinimal(eZContentObject $object)
    {
        $version   = $object->currentVersion();
        $languages = $version instanceof eZContentObjectVersion ? $version->translationList(false, false) : [];

        return [
            'metadata' => [
                'id'              => (int)$object->attribute('id'),
                'remoteId'        => $object->attribute('remote_id'),
                'classIdentifier' => $object->attribute('class_identifier'),
                'currentVersion'  => (int)$object->attribute('current_version'),
                'languages'       => $languages,
                'isPublic'        => false, // oggetto in fase di eliminazione
            ],
            'data' => [],
        ];
    }

    public static function userInfo($userId)
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

    public static function enrichRelationContentUrls(array &$payload, $baseUrl)
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
