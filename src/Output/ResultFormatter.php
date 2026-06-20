<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Output;

use WEBprofil\Typo3Preflight\Check\CheckResult;
use Symfony\Component\Console\Output\OutputInterface;

interface ResultFormatter
{
    /**
     * Format and write the check results to the given output.
     *
     * @param CheckResult[] $results
     */
    public function format(array $results, OutputInterface $output): void;
}
