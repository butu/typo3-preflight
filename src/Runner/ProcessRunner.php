<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Runner;

interface ProcessRunner
{
    /**
     * Run a shell command.
     *
     * @param string      $command The command line to execute
     * @param string|null $cwd     Working directory (default: project root)
     * @param int         $timeout Timeout in seconds (0 = no timeout)
     */
    public function run(string $command, ?string $cwd = null, int $timeout = 120): ProcessResult;
}
