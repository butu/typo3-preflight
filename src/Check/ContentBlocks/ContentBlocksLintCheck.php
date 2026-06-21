<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check\ContentBlocks;

use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;
use WEBprofil\Typo3Preflight\Runner\ProcessRunner;

/**
 * Runs content-blocks:lint and reports failures.
 *
 * - Error if TYPO3 CLI is missing.
 * - Skip if content-blocks:lint command does not exist.
 * - Fail if lint reports errors/warnings.
 */
final class ContentBlocksLintCheck implements CheckInterface
{
    private const CODE_LINT = 'content-blocks-lint';

    public function __construct(
        private readonly ProcessRunner $runner,
    ) {
    }

    public function name(): string
    {
        return 'content-blocks-lint';
    }

    public function suite(): string
    {
        return 'content_blocks';
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

        // Run content-blocks:lint directly. Some TYPO3 installations expose the
        // command in the text command list but not in `list --format=json`.
        $lintResult = $this->runner->run($typo3Bin . ' content-blocks:lint --no-interaction', $context->projectRoot, 120);

        if ($lintResult->isSuccessful()) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Pass,
                'content-blocks:lint passed',
            );
        }

        if ($this->isCommandMissing($lintResult->stdout . "\n" . $lintResult->stderr)) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'content-blocks:lint command not available (EXT:content_blocks not installed)',
            );
        }

        $failures = $this->parseLintFailures($lintResult->stdout, $lintResult->stderr);

        if ($failures === []) {
            $failures = [
                new Failure(
                    self::CODE_LINT,
                    'content-blocks:lint failed',
                    '',
                    ['stderr' => $this->trimOutput($lintResult->stderr, 500)],
                ),
            ];
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Fail,
            sprintf('%d content-blocks lint issue(s)', count($failures)),
            [],
            $failures,
        );
    }

    private function isCommandMissing(string $output): bool
    {
        return str_contains($output, 'content-blocks:lint')
            && (str_contains($output, 'is not defined')
                || str_contains($output, 'There are no commands defined')
                || str_contains($output, 'CommandNotFoundException'));
    }

    /**
     * Parse lint output into individual failures with stable fingerprints.
     *
     * @return list<Failure>
     */
    private function parseLintFailures(string $stdout, string $stderr): array
    {
        $failures = [];
        $output = $stdout . "\n" . $stderr;

        // Try to parse structured output: each line with "ERROR" or "WARNING"
        // Typical output formats:
        //   [ERROR] file.yaml: message
        //   [WARNING] file.yaml: message
        //   - file.yaml: message
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Parse file path from line
            if (preg_match('/^(?:\[(?:ERROR|WARNING)\]\s*)?([^\s:]+(?:\.yaml|\.yml)):\s*(.+)$/i', $line, $matches)) {
                $file = $matches[1];
                $message = $matches[2];

                $failures[] = new Failure(
                    self::CODE_LINT,
                    $message,
                    $file,
                    ['raw_line' => $this->trimOutput($line, 500)],
                );
            } elseif (preg_match('/^\s*[-*]\s+([^\s:]+(?:\.yaml|\.yml)):\s*(.+)$/i', $line, $matches)) {
                $file = $matches[1];
                $message = $matches[2];

                $failures[] = new Failure(
                    self::CODE_LINT,
                    $message,
                    $file,
                    ['raw_line' => $this->trimOutput($line, 500)],
                );
            }
        }

        return $failures;
    }

    private function trimOutput(string $output, int $maxLen): string
    {
        if (strlen($output) <= $maxLen) {
            return $output;
        }
        return substr($output, 0, $maxLen) . '…';
    }
}
