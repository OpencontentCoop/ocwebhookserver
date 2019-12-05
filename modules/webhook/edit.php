<?php

/** @var eZModule $Module */
$Module = $Params['Module'];
$Result = array();
$tpl = eZTemplate::factory();
$http = eZHTTPTool::instance();
$id = $Params['ID'];
if ($id === 'new'){
    $webHook = new OCWebHook([]);
}else {
    $webHook = OCWebHook::fetch((int)$id);
}

if ($webHook instanceof OCWebHook) {

    $tpl->setVariable('id', $id);
    $tpl->setVariable('webhook', $webHook);
    $webHookTriggers = [];
    foreach ($webHook->getTriggers() as $trigger){
        $webHookTriggers[] = $trigger['identifier']; //@todo show filters
    }
    $tpl->setVariable('webhook_triggers', $webHookTriggers);
    $tpl->setVariable('triggers', OCWebhookTriggerRegistry::registeredTriggersAsArray());

    $Result['path'] = array(
        array(
            'url' => 'webhook/list',
            'text' => ezpI18n::tr('extension/ocwebhookserver', 'Webhooks')
        ),
        array(
            'url' => false,
            'text' => ezpI18n::tr('extension/ocwebhookserver', 'Edit')
        )
    );

    $Result['content'] = $tpl->fetch('design:webhook/edit.tpl');

}else{
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
}
