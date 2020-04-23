<?php

use Opencontent\Opendata\Api\AttributeConverterLoader;

class OCWebHookEndpointController extends OCOpenDataController2
{
    public function doPing()
    {
        try {
            $result = new ezpRestMvcResult();
            $result->variables['result'] = 'pong';
        } catch (Exception $e) {
            $result = $this->doExceptionResult($e);
        }

        return $result;
    }

    public function doImport()
    {
        try {
            $result = new ezpRestMvcResult();
            $payload = (array)$this->getPayload();

            if (isset($payload['test_webhook'])){
                $result->variables['result'] = 'ok';
                header('X-Webhook-Endpoint-Result-Test: ' . $payload['test_webhook']['id']);
            }else{
                $object = $this->createUpdate($payload, $this->request->variables['ParentNodeId']);
                if (!$object instanceof eZContentObject){
                    throw new Exception("Error creating or updating object");
                }
                $result->variables = [
                    'id' => (int)$object->attribute('id'),
                    'version' => (int)$object->attribute('current_version'),
                ];
                header('X-Webhook-Endpoint-Result-Id: ' . $object->attribute('id') . '-'  . $object->attribute('current_version'));
            }
        } catch (Exception $e) {
            header('X-Webhook-Endpoint-Error: ' . $e->getMessage());
            $result = $this->doExceptionResult($e);
        }

        return $result;
    }

    private function createUpdate($payload, $parentNodeId)
    {
        $baseUrl = $payload['metadata']['baseUrl'];
        $remoteId = $payload['metadata']['remoteId'];
        $languages = $payload['metadata']['languages'];
        $classIdentifier = $payload['metadata']['classIdentifier'];
        $data = $payload['data'];

        $parentNode = eZContentObjectTreeNode::fetch((int)$parentNodeId);
        if (!$parentNode instanceof eZContentObjectTreeNode) {
            throw new Exception("Parent node $parentNodeId not found");
        }

        $class = eZContentClass::fetchByIdentifier($classIdentifier);
        if (!$class instanceof eZContentClass) {
            throw new Exception("Class $classIdentifier not found");
        }

        $localeAttributes = $errors = [];
        $currentObject = eZContentObject::fetchByRemoteID($remoteId);
        if ($currentObject instanceof eZContentObject){
            $currentModified = $currentObject->attribute('modified');
            $payloadModified = date("U", strtotime($payload['metadata']['modified']));
            if ($currentModified <= $payloadModified){
                return $currentObject;
            }
        }

        $fakePublicationProcess = new \Opencontent\Opendata\Api\PublicationProcess([]);

        /** @var eZContentClassAttribute $classAttribute */
        foreach ($class->dataMap() as $identifier => $classAttribute) {
            foreach ($languages as $language) {
                if (isset($data[$language][$identifier]) && $data[$language][$identifier] !== null) {
                    $attributeData = $data[$language][$identifier];
                    try {
                        if ($classAttribute->attribute('data_type_string') == eZObjectRelationListType::DATA_TYPE_STRING
                            || $classAttribute->attribute('data_type_string') == eZObjectRelationType::DATA_TYPE_STRING) {
                            $relations = [];
                            foreach ($attributeData as $item) {
                                try {
                                    $relations[] = $this->createUpdateRelation($item, $language, $baseUrl);
                                } catch (Exception $e) {
                                    $errors[$language][$identifier][] = $e->getMessage();
                                }
                            }
                            $localeAttributes[$language][$identifier] = implode('-', $relations);
                        } else {

                            if ($classAttribute->attribute('data_type_string') == eZImageType::DATA_TYPE_STRING){
                                $attributeData['url'] = $baseUrl . $attributeData['url'];
                            }

                            $converter = AttributeConverterLoader::load(
                                $classIdentifier, $identifier, $classAttribute->attribute('data_type_string')
                            );
                            if ($currentObject) {
                                $converter->validateOnUpdate($identifier, $attributeData, $classAttribute);
                            } else {
                                $converter->validateOnCreate($identifier, $attributeData, $classAttribute);
                            }
                            $localeAttributes[$language][$identifier] = $converter->set($attributeData, $fakePublicationProcess);
                        }
                    } catch (Exception $e) {
                        $errors[$language][$identifier] = $e->getMessage();
                    }

                }
            }
        }

        if (count($errors) > 0) {
            eZLog::write(var_export($errors, 1), 'webhook_endpoint.log');
        }

        foreach ($localeAttributes as $locale => $attributes){
            if ($currentObject){
                eZContentFunctions::updateAndPublishObject($currentObject, [
                    'language' => $locale,
                    'attributes' => $attributes
                ]);
            }else{
                $currentObject = eZContentFunctions::createAndPublishObject([
                    'parent_node_id' => $parentNodeId,
                    'language' => $locale,
                    'class_identifier' => $classIdentifier,
                    'remote_id' => $remoteId,
                    'attributes' => $attributes
                ]);
            }
        }

        return $currentObject;
    }

    private function createUpdateRelation($item, $language, $baseUrl)
    {
        $remoteId = $item['remoteId'];
        $currentObject = eZContentObject::fetchByRemoteID($remoteId);
        if ($currentObject instanceof eZContentObject) {
            return $currentObject->attribute('id');
        }

        $linkRemoteId = 'link-to-' . $item['remoteId'];
        $linkObject = eZContentObject::fetchByRemoteID($linkRemoteId);
        if ($linkObject instanceof eZContentObject) {
            eZContentFunctions::updateAndPublishObject($linkObject, [
                'language' => $language,
                'attributes' => [
                    'name' => $item['name'][$language],
                    'location' => $baseUrl . '/content/view/full/' . $item['mainNodeId']
                ]
            ]);
            return $linkObject->attribute('id');
        }

        $classIdentifier = $item['classIdentifier'];

        if ($classIdentifier == 'image') {
            $client = new \Opencontent\Opendata\Rest\Client\HttpClient($baseUrl);
            $remoteData = $client->read($remoteId);
            $remoteData['metadata']['baseUrl'] = $baseUrl;
            $object = $this->createUpdate($remoteData, 51);
            if ($object instanceof eZContentObject) {
                return $object->attribute('id');
            }
        } else {

            $linkClass = eZContentClass::fetchByIdentifier('shared_link');
            if (!$linkClass instanceof eZContentClass){
                $this->installLinkClass();
            }

            $link = eZContentFunctions::createAndPublishObject([
                'parent_node_id' => $this->getLinksNodeId(),
                'class_identifier' => 'shared_link',
                'remote_id' => $linkRemoteId,
                'attributes' => [
                    'name' => $item['name'][$language],
                    'location' => $baseUrl . '/content/view/full/' . $item['mainNodeId']
                ]
            ]);
            if ($link instanceof eZContentObject) {
                return $link->attribute('id');
            }
        }

        throw new Exception("Error creating relation {$classIdentifier}/{$remoteId}");
    }

    private function getLinksNodeId()
    {
        $remoteId = 'external-links';
        $currentObject = eZContentObject::fetchByRemoteID($remoteId);
        if ($currentObject instanceof eZContentObject) {
            return $currentObject->attribute('main_node_id');
        }

        $params = array(
            'parent_node_id' => 43,
            'remote_id' => $remoteId,
            'section_id' => 1,
            'class_identifier' => 'folder',
            'attributes' => array(
                'name' => 'Links'
            )
        );

        /** @var eZContentObject $contentObject */
        $contentObject = eZContentFunctions::createAndPublishObject($params);
        if (!$contentObject instanceof eZContentObject) {
            throw new Exception('Failed creating Links node');
        }
        return $contentObject->attribute('main_node');
    }

    private function installLinkClass()
    {        
        $classTools = new OCClassTools('shared_link', true, [], eZSys::rootDir() . '/extension/ocwebhookserver/share/shared_link.json');
        $classTools->sync();

        $class = eZContentClass::fetchByIdentifier('shared_link');
        if ($class instanceof eZContentClass){
            $extraData = json_decode(file_get_contents(eZSys::rootDir() . '/extension/ocwebhookserver/share/shared_link_extra.json'));
            OCClassExtraParametersManager::instance($class)->sync($extraData);   
        }
    }
}