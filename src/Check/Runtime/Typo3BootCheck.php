<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check\Runtime;

use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;
use WEBprofil\Typo3Preflight\Runner\ProcessRunner;

/**
 * Checks whether TYPO3 boots by running typo3 list.
 */
final class Typo3BootCheck implements CheckInterface
{
    private const CODE = 'typo3-boot';

    public function __construct(
        private readonly ProcessRunner $runner,
    ) {
    }

    public function name(): string
    {
        return 'typo3-boot';
    }

    public function suite(): string
    {
        return 'runtime';
    }

    public function run(ProjectContext $context): CheckResult
    {
        $bin = $context->vendorBinDir() . '/typo3';

        if (!file_exists($bin)) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Error,
                'TYPO3 CLI not found at ' . $bin,
            );
        }

        $result = $this->runner->run($bin . ' list', $context->projectRoot);

        if ($result->isSuccessful()) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Pass,
                'TYPO3 boots successfully (typo3 list exited with 0)',
            );
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Fail,
            'TYPO3 failed to boot',
            [],
            [
                new Failure(
                    self::CODE,
                    'typo3 list returned non-zero exit code',
                    '',
                    ['stderr' => $this->trimOutput($result->stderr, 500)],
                ),
            ],
        );
    }

    private function trimOutput(string $output, int $maxLen): string
    {
        if (strlen($output) <= $maxLen) {
            return $output;
        }
        return substr($output, 0, $maxLen) . '…';
    }
}
