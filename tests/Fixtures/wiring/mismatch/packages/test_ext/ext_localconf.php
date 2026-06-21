<?php

// ext_localconf.php with limited registered actions
\TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
    'TestExt',
    'Pi1',
    [\TestExt\Controller\SomeController::class => 'list,show'],
    []
);
