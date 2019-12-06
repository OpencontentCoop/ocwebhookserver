<?php
require 'autoload.php';

set_time_limit(0);

$cli = eZCLI::instance();
$script = eZScript::instance(array('description' => ("Re pending running jobs"),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true));

$script->startup();


$options = $script->getOptions(
    '[webhook:]',
    '',
    array(
        'webhook'  => 'WebHook ID'
    )
);
$script->initialize();
$script->setUseDebugAccumulators(true);

$user = eZUser::fetchByName('admin');
eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));

$db = eZDB::instance();
eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

$webHookId = (int)$options['webhook'];

try {

    $pendingStatus = OCWebHookJob::STATUS_PENDING;
    $runningStatus = OCWebHookJob::STATUS_RUNNING;

    $webHook = OCWebHook::fetch((int)$webHookId);
    if ($webHook instanceof OCWebHook) {
        $query = "UPDATE public.ocwebhook_job 
                      SET execution_status = $pendingStatus                          
                      WHERE webhook_id = $webHookId 
                        AND execution_status = $runningStatus";
    }else{
        $query = "UPDATE public.ocwebhook_job 
                      SET execution_status = $pendingStatus                          
                      WHERE execution_status = $runningStatus";
    }

    $cli->output($query);
    $result = $db->query($query);

} catch (Exception $e) {
    $cli->error($e->getMessage());
}

$script->shutdown();