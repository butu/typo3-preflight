<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check\Static;

use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;
use WEBprofil\Typo3Preflight\Runner\ProcessRunner;

/**
 * Syntax-checks PHP files using php -l.
 *
 * Scans src, packages, extensions, and config directories for PHP files.
 * Skips vendor directory.
 */
final class PhpLintCheck implements CheckInterface
{
    private const CODE = 'php-lint';

    public function __construct(
        private readonly ProcessRunner $runner,
    ) {
    }

    public function name(): string
    {
        return 'php-lint';
    }

    public function suite(): string
    {
        return 'static';
    }

    public function run(ProjectContext $context): CheckResult
    {
        $files = $this->findPhpFiles($context->projectRoot);

        if ($files === []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'No PHP files found to lint',
            );
        }

        $failures = [];
        foreach ($files as $file) {
            $relativeFile = $this->relativePath($file, $context->projectRoot);
            $result = $this->runner->run('php -l ' . escapeshellarg($file));

            if (!$result->isSuccessful()) {
                $failures[] = new Failure(
                    self::CODE,
                    sprintf('Syntax error in %s', $relativeFile),
                    $relativeFile,
                    ['stderr' => $this->trimOutput($result->stderr ?: $result->stdout, 300)],
                );
            }
        }

        if ($failures !== []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Fail,
                sprintf('%d PHP file(s) have syntax errors', count($failures)),
                ['files_checked' => (string) count($files)],
                $failures,
            );
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Pass,
            sprintf('%d PHP file(s) passed syntax check', count($files)),
            ['files_checked' => (string) count($files)],
        );
    }

    /**
     * Find PHP files under standard source directories, excluding vendor.
     *
     * @return list<string>
     */
    private function findPhpFiles(string $projectRoot): array
    {
        $dirs = ['src', 'packages', 'extensions', 'config'];
        $files = [];

        foreach ($dirs as $dir) {
            $path = $projectRoot . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            /** @var \SplFileInfo $item */
            foreach ($iterator as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $path = $item->getPathname();
                    if (str_contains($path, '/tests/Fixtures/') || str_contains($path, '/Tests/Fixtures/')) {
                        continue;
                    }

                    $files[] = $item->getPathname();
                }
            }
        }

        sort($files);
        return $files;
    }

    private function trimOutput(string $output, int $maxLen): string
    {
        if (strlen($output) <= $maxLen) {
            return $output;
        }
        return substr($output, 0, $maxLen) . '…';
    }

    private function relativePath(string $absolutePath, string $projectRoot): string
    {
        $projectRoot = rtrim($projectRoot, '/') . '/';
        if (str_starts_with($absolutePath, $projectRoot)) {
            return substr($absolutePath, strlen($projectRoot));
        }
        return $absolutePath;
    }
}
