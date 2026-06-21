<?php

namespace Test\Domain\Model;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class BadModel
{
    public function findStuff(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->createQueryBuilder();
        return $queryBuilder->select('*')->from('tt_content')->executeQuery()->fetchAllAssociative();
    }
}
