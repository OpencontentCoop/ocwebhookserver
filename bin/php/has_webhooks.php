<?php
require 'autoload.php';

set_time_limit( 0 );

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => ( "Has webhook enabled?" ),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true ) );

$script->startup();

$options = $script->getOptions();
$script->initialize();
$script->setUseDebugAccumulators( true );

$user = eZUser::fetchByName( 'admin' );
eZUser::setCurrentlyLoggedInUser( $user , $user->attribute( 'contentobject_id' ) );
eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

try
{
    $hooks = OCWebHook::fetchList(null, null, ['enabled' => 1]);
    if (count($hooks) > 0){
        foreach ($hooks as $hook){
            $cli->output($hook->attribute('name'));
        }
    }
}
catch ( Exception $e )
{
    $cli->error( $e->getMessage() );
}

$script->shutdown();