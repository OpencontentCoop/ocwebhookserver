<?php
// Entry point per eZSearch::getEngine().
// eZSearch cerca il file a extension/{ext}/search/plugins/{name}/{name}.php;
// la classe vera è in classes/ocsearchengine.php e viene caricata via autoload eZ.
// Questo file garantisce che eZSearch trovi OCSearchEngine indipendentemente
// dall'autoload, anche prima che venga inizializzato.

$classFile = dirname(dirname(dirname(dirname(__FILE__)))) . '/classes/ocsearchengine.php';
if (!class_exists('OCSearchEngine') && file_exists($classFile)) {
    require_once $classFile;
}
