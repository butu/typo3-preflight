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
 * Checks for pending database schema changes.
 *
 * Runs: vendor/bin/typo3 database:updateschema --dry-run
 * Fails if the command reports pending schema changes.
 */
final class DatabaseSchemaCheck implements CheckInterface
{
    private const CODE = 'database-schema';

    public function __construct(
        private readonly ProcessRunner $runner,
    ) {
    }

    public function name(): string
    {
        return 'database-schema';
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
            $typo3Bin . ' database:updateschema --dry-run --no-interaction',
            $context->projectRoot,
            120,
        );

        if (!$result->isSuccessful()) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Fail,
                'database:updateschema --dry-run returned errors',
                [],
                [
                    new Failure(
                        self::CODE,
                        'Database schema changes needed (non-zero exit code)',
                        '',
                        ['stderr' => $this->trimOutput($result->stderr, 500)],
                    ),
                ],
            );
        }

        $output = $result->stdout . $result->stderr;

        if (stripos($output, 'No schema updates must be performed') !== false) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Pass,
                'Database schema is up to date',
            );
        }

        // Check for indications of pending schema changes
        $hasChanges = false;
        $indicators = [
            'CREATE TABLE',
            'ALTER TABLE',
            'DROP TABLE',
            'CREATE INDEX',
            'DROP INDEX',
            'Schema update',
            'Pending',
            'needs to be',
            'shall be',
            'must be',
            'Change set',
        ];

        foreach ($indicators as $indicator) {
            if (stripos($output, $indicator) !== false) {
                $hasChanges = true;
                break;
            }
        }

        if ($hasChanges) {
            // Parse individual change lines for fingerprinting
            $failures = $this->parseSchemaChanges($output);

            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Fail,
                sprintf('%d pending database schema change(s)', count($failures)),
                [],
                $failures,
            );
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Pass,
            'Database schema is up to date',
        );
    }

    /**
     * Parse schema change output into individual failures.
     *
     * @return list<Failure>
     */
    private function parseSchemaChanges(string $output): array
    {
        $failures = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }

            // Look for SQL statements
            if (preg_match('/^(CREATE|ALTER|DROP)\s+(TABLE|INDEX|COLUMN)/i', $line)) {
                $failures[] = new Failure(
                    self::CODE,
                    $line,
                    'schema-change-' . substr(hash('sha256', $line), 0, 12),
                    ['sql_line' => $this->trimOutput($line, 300)],
                );
            } elseif (preg_match('/^\s*[-*+]\s*(.+)$/', $line, $m)) {
                $change = trim($m[1]);
                $failures[] = new Failure(
                    self::CODE,
                    $change,
                    'schema-change-' . substr(hash('sha256', $change), 0, 12),
                    ['raw_line' => $this->trimOutput($line, 300)],
                );
            }
        }

        if ($failures === []) {
            // Fallback: create a single failure from relevant output
            $failures[] = new Failure(
                self::CODE,
                'Database schema has pending changes',
                'schema-change-' . substr(hash('sha256', $output), 0, 12),
                ['output_summary' => $this->trimOutput($output, 500)],
            );
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
