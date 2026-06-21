<?php

namespace Test\Controller;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class BadController
{
    public function listAction(): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages')
            ->createQueryBuilder();
        $queryBuilder->executeQuery();
    }
}
