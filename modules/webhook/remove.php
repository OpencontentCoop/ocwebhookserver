<?php

/** @var eZModule $Module */
$Module = $Params['Module'];
$webHook = OCWebHook::fetch((int)$Params['ID']);

if ($webHook instanceof OCWebHook) {
    $webHook->remove();
    $Module->redirectTo('/webhook/list');

}else{
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
}
