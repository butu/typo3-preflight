<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check\Runtime;

use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

/**
 * Scans log files for new Errors/Exceptions written since preflight started.
 *
 * Uses a byte-offset approach: file sizes are recorded at start, then
 * only new bytes are checked on run().
 */
final class LogCheck implements CheckInterface
{
    private const CODE = 'log-error';

    /** @var array<string, int> path => byte offset at start */
    private array $fileOffsets = [];

    /**
     * Record the current file sizes so run() only checks new content.
     * Call this once before other checks run.
     */
    public function recordStartState(string $projectRoot): void
    {
        $this->fileOffsets = [];
        foreach ($this->findLogFiles($projectRoot) as $file) {
            if (is_readable($file)) {
                $this->fileOffsets[$file] = filesize($file) ?: 0;
            }
        }
    }

    public function name(): string
    {
        return 'log-check';
    }

    public function suite(): string
    {
        return 'runtime';
    }

    public function run(ProjectContext $context): CheckResult
    {
        $failures = [];
        $checkedFiles = 0;

        foreach ($this->findLogFiles($context->projectRoot) as $file) {
            if (!is_readable($file)) {
                continue;
            }

            $currentSize = filesize($file) ?: 0;
            $previousOffset = $this->fileOffsets[$file] ?? $currentSize;
            $newBytes = $currentSize - $previousOffset;

            if ($newBytes <= 0) {
                // No new content — nothing to check
                $this->fileOffsets[$file] = $currentSize;
                continue;
            }

            $checkedFiles++;

            // Read only new bytes
            $handle = fopen($file, 'r');
            if ($handle === false) {
                continue;
            }

            fseek($handle, $previousOffset);
            $newContent = fread($handle, $newBytes);
            fclose($handle);

            if ($newContent === false || $newContent === '') {
                $this->fileOffsets[$file] = $currentSize;
                continue;
            }

            // Look for errors/exceptions
            $lines = explode("\n", $newContent);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                if ($this->isErrorLine($line)) {
                    $failures[] = new Failure(
                        self::CODE,
                        'Log entry: ' . $this->truncate($line, 200),
                        $file,
                        ['line' => $this->truncate($line, 500)],
                    );
                }
            }

            $this->fileOffsets[$file] = $currentSize;
        }

        if ($failures !== []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Fail,
                sprintf('%d new error(s) in logs', count($failures)),
                ['files_checked' => (string) $checkedFiles],
                $failures,
            );
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Pass,
            $checkedFiles > 0
                ? sprintf('%d log file(s) checked, no new errors', $checkedFiles)
                : 'No log files with new content found',
            ['files_checked' => (string) $checkedFiles],
        );
    }

    /**
     * Find log files in TYPO3 log directories.
     *
     * @return list<string>
     */
    private function findLogFiles(string $projectRoot): array
    {
        $patterns = [
            $projectRoot . '/var/log/*.log',
            $projectRoot . '/typo3temp/var/log/*.log',
        ];

        $files = [];
        foreach ($patterns as $pattern) {
            $matches = glob($pattern);
            if (is_array($matches)) {
                foreach ($matches as $file) {
                    $files[] = $file;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Determine whether a log line indicates an error or exception.
     */
    private function isErrorLine(string $line): bool
    {
        $lower = strtolower($line);

        // TYPO3 exception log format
        if (str_contains($lower, 'uncaught exception')) {
            return true;
        }
        if (str_contains($lower, 'exception') && str_contains($lower, ' thrown ')) {
            return true;
        }

        // Generic error patterns
        if (str_contains($lower, 'php fatal error') || str_contains($lower, 'php parse error')) {
            return true;
        }
        if (preg_match('/\b(error|critical|alert|emergency)\b/i', $line) === 1) {
            // Check if it looks like a structured log entry with a level prefix
            if (preg_match('/\[(ERROR|CRITICAL|ALERT|EMERGENCY)\]/', $line) === 1) {
                return true;
            }
        }

        // TYPO3 exception log: "Exception:" or "Core: Exception"
        if (preg_match('/(?:^|\s)Exception[:\s]/i', $line) === 1) {
            return true;
        }

        return false;
    }

    private function truncate(string $text, int $maxLen): string
    {
        if (strlen($text) <= $maxLen) {
            return $text;
        }
        return substr($text, 0, $maxLen) . '…';
    }
}
