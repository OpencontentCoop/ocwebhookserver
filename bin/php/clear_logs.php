<?php
require 'autoload.php';

set_time_limit( 0 );

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => ( "Clear webhooks logs" ),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true ) );

$script->startup();

$beforeDate = strtotime('-2 months');

$options = $script->getOptions(
    '[before_date:]',
    '',
    array(
        'before_date'  => 'Clean logs before selected date in strtotime format: default is \'-2 months\' ('. date('c', $beforeDate) . ')'
    )
);
$script->initialize();
$script->setUseDebugAccumulators( true );

$user = eZUser::fetchByName( 'admin' );
eZUser::setCurrentlyLoggedInUser( $user , $user->attribute( 'contentobject_id' ) );
eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

try
{
    $beforeDate = isset($options['before_date']) ? strtotime($options['before_date']) : $beforeDate;
    if (!$beforeDate){
        throw new Exception("Error parsing before_date param");
    }

    $cli->output("Cleaning log until " . date('c', $beforeDate));
    OCWebHookJob::removeUntilDate($beforeDate);
}
catch ( Exception $e )
{
    $cli->error( $e->getMessage() );
}

$script->shutdown();