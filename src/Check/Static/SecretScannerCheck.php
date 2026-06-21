<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check\Static;

use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

/**
 * Scans text files for potential secrets (passwords, API keys, etc.).
 *
 * Skips vendor, var, public/fileadmin, build, and cache directories.
 * Respects secrets.allowlist config for suppressing false positives.
 */
final class SecretScannerCheck implements CheckInterface
{
    private const CODE = 'secret';

    /** Secret patterns to scan for */
    private const SECRET_PATTERNS = [
        '/password\s*[=:]\s*[\'"][^\'"]+[\'"]/i',
        '/api[_-]?key\s*[=:]\s*[\'"][^\'"]+[\'"]/i',
        '/secret\s*[=:]\s*[\'"][^\'"]+[\'"]/i',
        '/private[_-]?key\s*[=:]\s*[\'"][^\'"]+[\'"]/i',
        '/-----BEGIN (RSA|DSA|EC|OPENSSH) PRIVATE KEY-----/',
        '/token\s*[=:]\s*[\'"][A-Za-z0-9_\-.]{20,}[\'"]/i',
        '/auth[_-]?token\s*[=:]\s*[\'"][^\'"]+[\'"]/i',
        '/access[_-]?key\s*[=:]\s*[\'"][^\'"]+[\'"]/i',
        '/client[_-]?secret\s*[=:]\s*[\'"][^\'"]+[\'"]/i',
        '/database[_-]?url\s*[=:]\s*[\'"]\w+:\/\/\w+:\w+@/i',
    ];

    /** File extensions to scan */
    private const SCAN_EXTENSIONS = ['php', 'yaml', 'yml', 'env.dist', 'js', 'ts', 'json', 'xml', 'neon', 'ini', 'conf'];

    /** Directories to skip (relative to project root) */
    private const SKIP_DIRS = ['vendor', 'var', 'build', 'cache', 'node_modules', '.git', '.ddev', '.opencode'];

    public function name(): string
    {
        return 'secret-scanner';
    }

    public function suite(): string
    {
        return 'static';
    }

    public function run(ProjectContext $context): CheckResult
    {
        $allowlist = $this->getAllowlist($context);
        $files = $this->findScanFiles($context->projectRoot);

        if ($files === []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'No scannable files found',
            );
        }

        $failures = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $relativeFile = $this->relativePath($file, $context->projectRoot);

            foreach (self::SECRET_PATTERNS as $pattern) {
                if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $matchedText = $match[0];
                        $offset = $match[1];
                        $line = $this->lineNumberAtOffset($content, $offset);

                        // Check allowlist
                        if ($this->isAllowed($matchedText, $allowlist)) {
                            continue;
                        }

                        $failures[] = new Failure(
                            self::CODE,
                            sprintf(
                                'Potential secret in %s:%d — "%s"',
                                $relativeFile,
                                $line,
                                $this->maskSecret($matchedText),
                            ),
                            $relativeFile,
                            ['line' => (string) $line, 'pattern_matched' => $this->maskSecret($matchedText)],
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
                sprintf('%d potential secret(s) found', count($failures)),
                ['files_scanned' => (string) count($files)],
                $failures,
            );
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Pass,
            sprintf('%d file(s) scanned, no secrets found', count($files)),
            ['files_scanned' => (string) count($files)],
        );
    }

    /**
     * @return list<string> allowlist regex patterns from config
     */
    private function getAllowlist(ProjectContext $context): array
    {
        $allowlist = $context->config['secrets']['allowlist'] ?? [];
        return is_array($allowlist) ? $allowlist : [];
    }

    /**
     * Check if a matched text is suppressed by the allowlist.
     */
    private function isAllowed(string $matchedText, array $allowlist): bool
    {
        foreach ($allowlist as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }
            // Add delimiters if not present
            if (!str_starts_with($pattern, '/')) {
                $pattern = '/' . preg_quote($pattern, '/') . '/i';
            }
            try {
                if (@preg_match($pattern, $matchedText) === 1) {
                    return true;
                }
            } catch (\Exception $e) {
                // Invalid regex — skip
                continue;
            }
        }
        return false;
    }

    /**
     * Mask a secret so the value part is hidden in output.
     */
    private function maskSecret(string $matchedText): string
    {
        // Replace the value part after = or : with ***
        return preg_replace(
            "/([=:]\s*['\"])[^'\"]+(['\"])/",
            '$1***$2',
            $matchedText,
        );
    }

    /**
     * Find files to scan, excluding skipped directories.
     *
     * @return list<string>
     */
    private function findScanFiles(string $projectRoot): array
    {
        if (!is_dir($projectRoot)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $path = $item->getPathname();
            $relativePath = $this->relativePath($path, $projectRoot);

            // Skip excluded directories and fixture data that intentionally contains secrets.
            $skip = false;
            foreach (self::SKIP_DIRS as $skipDir) {
                if (str_starts_with($relativePath, $skipDir . '/') || $relativePath === $skipDir) {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                continue;
            }

            if (str_contains($relativePath, '/node_modules/')
                || str_contains($relativePath, '/.opencode/')
                || str_starts_with($relativePath, 'public/fileadmin/')
                || str_contains($relativePath, '/Tests/Fixtures/')
                || str_contains($relativePath, '/tests/Fixtures/')
                || str_starts_with($relativePath, 'Tests/Fixtures/')
                || str_starts_with($relativePath, 'tests/Fixtures/')
            ) {
                continue;
            }

            // Check extension
            $basename = $item->getBasename();

            // Special handling for env.dist files
            if ($basename === 'env.dist') {
                $files[] = $path;
                continue;
            }

            // Check if the file extension is in our scan list
            $ext = $item->getExtension();
            $fullBasename = $item->getBasename();

            // Handle .env.dist style files (two dots)
            if (str_ends_with($fullBasename, '.env.dist')) {
                $files[] = $path;
                continue;
            }

            if (in_array($ext, self::SCAN_EXTENSIONS, true)) {
                $files[] = $path;
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
