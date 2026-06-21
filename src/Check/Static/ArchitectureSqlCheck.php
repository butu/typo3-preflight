<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check\Static;

use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

/**
 * Architecture rule: no SQL / QueryBuilder in Domain Models or Controllers.
 *
 * Scans Classes/Domain/Model/* and Classes/Controller/* under
 * packages and extensions directories for SQL patterns.
 */
final class ArchitectureSqlCheck implements CheckInterface
{
    private const CODE = 'architecture-sql';

    /** Patterns indicating SQL/QueryBuilder usage */
    private const SQL_PATTERNS = [
        '/\bQueryBuilder\b/i',
        '/\bcreateQueryBuilder\b/i',
        '/\bgetQueryBuilder\b/i',
        '/\bexecuteQuery\b/i',
        '/\bexecuteStatement\b/i',
        '/\bconnectionPool\b/i',
        '/\bConnectionPool\b/',
        '/\bgetConnection\b/i',
        '/\bgetConnectionForTable\b/i',
        '/[\'\"]\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE)\s+[^\'\"]+/i',
    ];

    public function name(): string
    {
        return 'architecture-sql';
    }

    public function suite(): string
    {
        return 'static';
    }

    public function run(ProjectContext $context): CheckResult
    {
        $files = $this->findTargetFiles($context->projectRoot);

        if ($files === []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'No Model or Controller PHP files found to check',
            );
        }

        $failures = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $relativeFile = $this->relativePath($file, $context->projectRoot);

            foreach (self::SQL_PATTERNS as $pattern) {
                if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $matchedText = $match[0];
                        $offset = $match[1];
                        $line = $this->lineNumberAtOffset($content, $offset);

                        $failures[] = new Failure(
                            self::CODE,
                            sprintf(
                                'SQL/QueryBuilder usage in %s:%d — found "%s"',
                                $relativeFile,
                                $line,
                                $matchedText,
                            ),
                            $relativeFile,
                            ['line' => (string) $line, 'pattern' => $matchedText],
                        );
                    }
                }
            }
        }

        if ($failures !== []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Fail,
                sprintf('%d architecture violation(s) found (SQL in Model/Controller)', count($failures)),
                ['files_checked' => (string) count($files)],
                $failures,
            );
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Pass,
            sprintf('%d file(s) checked, no SQL/QueryBuilder in Models or Controllers', count($files)),
            ['files_checked' => (string) count($files)],
        );
    }

    /**
     * Find PHP files in Classes/Domain/Model/* and Classes/Controller/*
     * under packages and extensions directories.
     *
     * @return list<string>
     */
    private function findTargetFiles(string $projectRoot): array
    {
        $files = [];
        $baseDirs = ['packages', 'extensions'];

        foreach ($baseDirs as $baseDir) {
            $basePath = $projectRoot . '/' . $baseDir;
            if (!is_dir($basePath)) {
                continue;
            }

            $subdirs = glob($basePath . '/*', GLOB_ONLYDIR);
            if (!is_array($subdirs)) {
                continue;
            }

            foreach ($subdirs as $extDir) {
                $classesDir = $extDir . '/Classes';
                if (!is_dir($classesDir)) {
                    continue;
                }

                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($classesDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                );

                /** @var \SplFileInfo $item */
                foreach ($iterator as $item) {
                    if (!$item->isFile() || $item->getExtension() !== 'php') {
                        continue;
                    }

                    $path = $item->getPathname();
                    if (str_contains($path, '/Classes/Domain/Model/')
                        || str_contains($path, '/Classes/Controller/')
                    ) {
                        $files[] = $path;
                    }
                }
            }
        }

        sort($files);
        return $files;
    }

    /**
     * Calculate line number from byte offset.
     */
    private function lineNumberAtOffset(string $content, int $offset): int
    {
        $before = substr($content, 0, $offset);
        return substr_count($before, "\n") + 1;
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
