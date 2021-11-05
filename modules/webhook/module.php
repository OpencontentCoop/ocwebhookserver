<?php

$Module = array('name' => 'webhook');

$ViewList = array();

$ViewList['list'] = array(
    'script' => 'list.php',
    'params' => array(),
    'unordered_params' => array(),
    'functions' => array('admin'),
    "default_navigation_part" => 'ezsetupnavigationpart',
);
$ViewList['logs'] = array(
    'script' => 'logs.php',
    'params' => array('ID'),
    'single_post_actions' => array(),
    'post_action_parameters' => array(),
    'functions' => array('admin'),
    "default_navigation_part" => 'ezsetupnavigationpart',
);
$ViewList['edit'] = array(
    'script' => 'edit.php',
    'params' => array('ID'),
    'single_post_actions' => array(),
    'post_action_parameters' => array(),
    'functions' => array('admin'),
    "default_navigation_part" => 'ezsetupnavigationpart',
);
$ViewList['remove'] = array(
    'script' => 'remove.php',
    'params' => array('ID'),
    'single_post_actions' => array(),
    'post_action_parameters' => array(),
    'functions' => array('admin'),
    "default_navigation_part" => 'ezsetupnavigationpart',
);
$ViewList['job'] = array(
    'script' => 'job.php',
    'params' => array('JobID', 'Action'),
    'unordered_params' => array(),
    'functions' => array('admin'),
    "default_navigation_part" => 'ezsetupnavigationpart',
);

$FunctionList['admin'] = array();
