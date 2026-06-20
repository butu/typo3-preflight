<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Runner;

use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(string $command, ?string $cwd = null, int $timeout = 120): ProcessResult
    {
        $process = Process::fromShellCommandline($command, $cwd);
        $process->setTimeout($timeout);
        $process->run();

        return new ProcessResult(
            $process->getExitCode() ?? -1,
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }
}
