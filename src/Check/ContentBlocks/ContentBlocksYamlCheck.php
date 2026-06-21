<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check\ContentBlocks;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

/**
 * Validates Content Blocks config.yaml files.
 *
 * Scans packages/* /ContentBlocks/** /config.yaml and
 * extensions/* /ContentBlocks/** /config.yaml.
 *
 * Checks:
 * - YAML is valid
 * - name or typeName is present (at least one, CB versions vary)
 */
final class ContentBlocksYamlCheck implements CheckInterface
{
    private const CODE_YAML_INVALID = 'cb-yaml-invalid';
    private const CODE_MISSING_IDENTIFIER = 'cb-missing-identifier';

    public function name(): string
    {
        return 'content-blocks-yaml';
    }

    public function suite(): string
    {
        return 'content_blocks';
    }

    public function run(ProjectContext $context): CheckResult
    {
        $files = $this->findCbConfigFiles($context->projectRoot);

        if ($files === []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'No Content Blocks config.yaml files found',
            );
        }

        $failures = [];
        foreach ($files as $file) {
            $relativeFile = $this->relativePath($file, $context->projectRoot);
            $failures = [...$failures, ...$this->checkCbConfig($file, $relativeFile)];
        }

        if ($failures !== []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Fail,
                sprintf('%d Content Blocks config issue(s) found', count($failures)),
                ['files_checked' => (string) count($files)],
                $failures,
            );
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Pass,
            sprintf('%d Content Blocks config(s) valid', count($files)),
            ['files_checked' => (string) count($files)],
        );
    }

    /**
     * @return list<string>
     */
    private function findCbConfigFiles(string $projectRoot): array
    {
        $files = [];
        $baseDirs = [
            $projectRoot . '/packages',
            $projectRoot . '/extensions',
        ];

        foreach ($baseDirs as $baseDir) {
            if (!is_dir($baseDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $item */
            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->getFilename() !== 'config.yaml') {
                    continue;
                }

                $path = $item->getPathname();
                if (str_contains($path, '/ContentBlocks/')) {
                    $files[] = $path;
                }
            }
        }

        sort($files);
        return $files;
    }

    /**
     * @return list<Failure>
     */
    private function checkCbConfig(string $file, string $relativeFile): array
    {
        try {
            $config = Yaml::parseFile($file);
        } catch (ParseException $e) {
            return [
                new Failure(
                    self::CODE_YAML_INVALID,
                    sprintf('Invalid YAML in %s: %s', $relativeFile, $e->getMessage()),
                    $relativeFile,
                ),
            ];
        }

        if (!is_array($config)) {
            return [
                new Failure(
                    self::CODE_YAML_INVALID,
                    sprintf('YAML in %s does not produce an array', $relativeFile),
                    $relativeFile,
                ),
            ];
        }

        // Check for name or typeName (at least one must be present)
        $hasName = isset($config['name']) && is_string($config['name']) && $config['name'] !== '';
        $hasTypeName = isset($config['typeName']) && is_string($config['typeName']) && $config['typeName'] !== '';

        if (!$hasName && !$hasTypeName) {
            return [
                new Failure(
                    self::CODE_MISSING_IDENTIFIER,
                    sprintf('Missing name and typeName in %s', $relativeFile),
                    $relativeFile,
                ),
            ];
        }

        return [];
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
