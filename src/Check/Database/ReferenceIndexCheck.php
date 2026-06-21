<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check\Database;

use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;
use WEBprofil\Typo3Preflight\Runner\ProcessRunner;

/**
 * Checks for outdated reference index entries.
 *
 * Runs: vendor/bin/typo3 referenceindex:update --check
 */
final class ReferenceIndexCheck implements CheckInterface
{
    private const CODE = 'reference-index';

    public function __construct(
        private readonly ProcessRunner $runner,
    ) {
    }

    public function name(): string
    {
        return 'reference-index';
    }

    public function suite(): string
    {
        return 'database';
    }

    public function run(ProjectContext $context): CheckResult
    {
        $typo3Bin = $context->vendorBinDir() . '/typo3';

        if (!file_exists($typo3Bin)) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Error,
                'TYPO3 CLI not found at ' . $typo3Bin,
            );
        }

        $result = $this->runner->run(
            $typo3Bin . ' referenceindex:update --check --no-interaction',
            $context->projectRoot,
            300,
        );

        if ($result->isSuccessful()) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Pass,
                'Reference index is up to date',
            );
        }

        $output = $result->stdout . $result->stderr;

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Fail,
            'Reference index needs updating',
            [],
            [
                new Failure(
                    self::CODE,
                    'referenceindex:update --check returned non-zero exit code',
                    '',
                    ['output' => $this->trimOutput($output, 500)],
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
