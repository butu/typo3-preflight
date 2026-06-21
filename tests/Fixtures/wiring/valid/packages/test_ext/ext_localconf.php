<?php

// Valid ext_localconf.php with registered plugins
\TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
    'TestExt',
    'Pi1',
    [\TestExt\Controller\SomeController::class => 'list,show,detail'],
    []
);
