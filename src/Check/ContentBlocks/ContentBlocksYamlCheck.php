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
 * - Basic references resolve to existing local Basics
 * - typeName is unique across all Content Blocks
 * - ContentElements have templates/frontend.html
 * - All Content Block configs have language/labels.xlf
 * - ext_tables.sql does not duplicate CB-managed fields
 */
final class ContentBlocksYamlCheck implements CheckInterface
{
    private const CODE_YAML_INVALID = 'cb-yaml-invalid';
    private const CODE_MISSING_IDENTIFIER = 'cb-missing-identifier';
    private const CODE_BASIC_MISSING = 'cb-basic-missing';
    private const CODE_TYPENAME_DUPLICATE = 'cb-typeName-duplicate';
    private const CODE_TEMPLATE_MISSING = 'cb-template-missing';
    private const CODE_LABELS_MISSING = 'cb-labels-missing';
    private const CODE_EXT_TABLES_FIELD_DUPLICATE = 'cb-ext-tables-field-duplicate';

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

        // Phase 1: Parse all configs, collect typeNames
        $failures = [];
        $parsedConfigs = [];
        /** @var array<string, list<string>> $typeNames typeName => list of relative files */
        $typeNames = [];

        foreach ($files as $file) {
            $relativeFile = $this->relativePath($file, $context->projectRoot);
            $parseResult = $this->parseCbConfig($file, $relativeFile);

            if ($parseResult['failures'] !== []) {
                $failures = [...$failures, ...$parseResult['failures']];
                continue;
            }

            $config = $parseResult['config'];
            $parsedConfigs[$file] = [
                'config' => $config,
                'relativeFile' => $relativeFile,
            ];

            $typeName = $this->extractTypeName($config);
            if ($typeName !== null) {
                $typeNames[$typeName][] = $relativeFile;
            }
        }

        // Phase 2: Find all available Basic files
        $availableBasics = $this->findAvailableBasics($context->projectRoot);

        // Phase 3: Per-file checks
        foreach ($parsedConfigs as $file => $data) {
            $config = $data['config'];
            $relativeFile = $data['relativeFile'];

            $failures = [
                ...$failures,
                ...$this->checkBasicReferences($config, $relativeFile, $availableBasics),
                ...$this->checkTemplatesExist($file, $relativeFile),
                ...$this->checkLabelsExist($file, $relativeFile),
                ...$this->checkExtTablesDivergence($config, $file, $relativeFile, $availableBasics, $context->projectRoot),
            ];
        }

        // Phase 4: Cross-file checks
        $failures = [
            ...$failures,
            ...$this->checkTypeNameDuplicates($typeNames),
        ];

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
     * Parse a single config.yaml and run basic validation (YAML, identifier).
     *
     * @return array{config?: array, failures: list<Failure>}
     */
    private function parseCbConfig(string $file, string $relativeFile): array
    {
        try {
            $config = Yaml::parseFile($file);
        } catch (ParseException $e) {
            return [
                'failures' => [
                    new Failure(
                        self::CODE_YAML_INVALID,
                        sprintf('Invalid YAML in %s: %s', $relativeFile, $e->getMessage()),
                        $relativeFile,
                    ),
                ],
            ];
        }

        if (!is_array($config)) {
            return [
                'failures' => [
                    new Failure(
                        self::CODE_YAML_INVALID,
                        sprintf('YAML in %s does not produce an array', $relativeFile),
                        $relativeFile,
                    ),
                ],
            ];
        }

        $hasName = isset($config['name']) && is_string($config['name']) && $config['name'] !== '';
        $hasTypeName = isset($config['typeName']) && (is_string($config['typeName']) || is_int($config['typeName'])) && $config['typeName'] !== '';

        if (!$hasName && !$hasTypeName) {
            return [
                'failures' => [
                    new Failure(
                        self::CODE_MISSING_IDENTIFIER,
                        sprintf('Missing name and typeName in %s', $relativeFile),
                        $relativeFile,
                    ),
                ],
            ];
        }

        return ['config' => $config, 'failures' => []];
    }

    /**
     * Extract typeName from a parsed config, returning null if absent.
     *
     * Normalizes to string to handle both int and string values consistently.
     */
    private function extractTypeName(array $config): ?string
    {
        if (isset($config['typeName']) && (is_string($config['typeName']) || is_int($config['typeName']))) {
            return (string) $config['typeName'];
        }
        return null;
    }

    /**
     * Find all local Basic YAML files and return a map of identifier => absolute file path.
     *
     * @return array<string, string>
     */
    private function findAvailableBasics(string $projectRoot): array
    {
        $basics = [];
        foreach ($this->findPotentialExtensionRoots($projectRoot) as $extensionRoot) {
            $basicsDir = $extensionRoot . '/ContentBlocks/Basics';
            if (!is_dir($basicsDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basicsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $item */
            foreach ($iterator as $item) {
                if (!$item->isFile()) {
                    continue;
                }

                $path = $item->getPathname();
                $ext = $item->getExtension();
                if ($ext !== 'yaml' && $ext !== 'yml') {
                    continue;
                }

                try {
                    $basicConfig = Yaml::parseFile($path);
                } catch (ParseException) {
                    continue;
                }

                if (!is_array($basicConfig)) {
                    continue;
                }

                $identifier = $basicConfig['identifier'] ?? null;
                if (is_string($identifier) && $identifier !== '') {
                    $basics[$identifier] = $path;
                }
            }
        }

        return $basics;
    }

    /**
     * @return list<string>
     */
    private function findPotentialExtensionRoots(string $projectRoot): array
    {
        $roots = [];
        $patterns = [
            $projectRoot . '/packages/*',
            $projectRoot . '/extensions/*',
            $projectRoot . '/typo3conf/ext/*',
            $projectRoot . '/public/typo3conf/ext/*',
            $projectRoot . '/vendor/*/*',
        ];

        foreach ($patterns as $pattern) {
            $matches = glob($pattern, GLOB_ONLYDIR);
            if ($matches === false) {
                continue;
            }

            foreach ($matches as $match) {
                if (is_dir($match . '/ContentBlocks')) {
                    $roots[] = $match;
                }
            }
        }

        sort($roots);
        return $roots;
    }

    /**
     * Check that all basic references resolve to existing local Basics.
     *
     * @param array<string, string> $availableBasics
     * @return list<Failure>
     */
    private function checkBasicReferences(array $config, string $relativeFile, array $availableBasics): array
    {
        $failures = [];
        $referencedBasics = $this->collectBasicReferences($config);

        foreach ($referencedBasics as $ref) {
            // TYPO3/* core basics are always allowed
            if (str_starts_with($ref, 'TYPO3/')) {
                continue;
            }

            if (!isset($availableBasics[$ref])) {
                $failures[] = new Failure(
                    self::CODE_BASIC_MISSING,
                    sprintf('Basic "%s" referenced in %s not found', $ref, $relativeFile),
                    $relativeFile,
                    ['basic' => $ref],
                );
            }
        }

        return $failures;
    }

    /**
     * Collect all basic identifiers referenced by a config.
     *
     * @return list<string>
     */
    private function collectBasicReferences(array $config): array
    {
        $refs = [];

        // Top-level basics list
        if (isset($config['basics']) && is_array($config['basics'])) {
            foreach ($config['basics'] as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $refs[] = $entry;
                }
            }
        }

        return array_values(array_unique([
            ...$refs,
            ...$this->collectBasicFieldReferences($config['fields'] ?? []),
        ]));
    }

    /**
     * @return list<string>
     */
    private function collectBasicFieldReferences(mixed $fields): array
    {
        $refs = [];
        if (!is_array($fields)) {
            return [];
        }

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            if (($field['type'] ?? null) === 'Basic' && is_string($field['identifier'] ?? null) && $field['identifier'] !== '') {
                $refs[] = $field['identifier'];
            }

            if (isset($field['fields'])) {
                $refs = [...$refs, ...$this->collectBasicFieldReferences($field['fields'])];
            }
        }

        return $refs;
    }

    /**
     * Check typeName uniqueness across all Content Block configs.
     *
     * @param array<string, list<string>> $typeNames
     * @return list<Failure>
     */
    private function checkTypeNameDuplicates(array $typeNames): array
    {
        $failures = [];

        foreach ($typeNames as $typeName => $files) {
            if (count($files) <= 1) {
                continue;
            }

            foreach ($files as $file) {
                $others = array_values(array_filter($files, fn(string $f): bool => $f !== $file));
                $failures[] = new Failure(
                    self::CODE_TYPENAME_DUPLICATE,
                    sprintf(
                        'typeName "%s" in %s is also used in %s',
                        $typeName,
                        $file,
                        implode(', ', $others),
                    ),
                    $file,
                    ['typeName' => $typeName, 'conflicts' => implode(', ', $others)],
                );
            }
        }

        return $failures;
    }

    /**
     * Check template existence: ContentElements must have templates/frontend.html.
     *
     * @return list<Failure>
     */
    private function checkTemplatesExist(string $configFile, string $relativeFile): array
    {
        if (!str_contains($configFile, '/ContentBlocks/ContentElements/')) {
            return [];
        }

        $configDir = dirname($configFile);
        $frontendTemplate = $configDir . '/templates/frontend.html';

        if (!file_exists($frontendTemplate)) {
            return [
                new Failure(
                    self::CODE_TEMPLATE_MISSING,
                    sprintf('Missing templates/frontend.html for Content Element in %s', $relativeFile),
                    $relativeFile,
                ),
            ];
        }

        return [];
    }

    /**
     * Check that language/labels.xlf exists next to the Content Block config.
     *
     * @return list<Failure>
     */
    private function checkLabelsExist(string $configFile, string $relativeFile): array
    {
        $configDir = dirname($configFile);
        $labelsFile = $configDir . '/language/labels.xlf';

        if (!file_exists($labelsFile)) {
            return [
                new Failure(
                    self::CODE_LABELS_MISSING,
                    sprintf('Missing language/labels.xlf in %s', $relativeFile),
                    $relativeFile,
                ),
            ];
        }

        return [];
    }

    /**
     * Check ext_tables.sql for fields already managed by Content Blocks.
     *
     * @param array<string, string> $availableBasics
     * @return list<Failure>
     */
    private function checkExtTablesDivergence(
        array $config,
        string $configFile,
        string $relativeFile,
        array $availableBasics,
        string $projectRoot,
    ): array {
        $table = $config['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return [];
        }

        $extensionRoot = $this->findExtensionRoot($configFile);
        if ($extensionRoot === null) {
            return [];
        }

        $extTablesFile = $extensionRoot . '/ext_tables.sql';
        if (!file_exists($extTablesFile)) {
            return [];
        }

        $sqlContent = @file_get_contents($extTablesFile);
        if ($sqlContent === false || $sqlContent === '') {
            return [];
        }

        $sqlColumns = $this->extractSqlColumns($sqlContent, $table);
        if ($sqlColumns === []) {
            return [];
        }

        $cbFieldIdentifiers = $this->collectFieldIdentifiers($config, $availableBasics);
        if ($cbFieldIdentifiers === []) {
            return [];
        }

        $failures = [];
        $relativeExtTables = $this->relativePath($extTablesFile, $projectRoot);

        foreach ($sqlColumns as $column) {
            if (in_array($column, $cbFieldIdentifiers, true)) {
                $failures[] = new Failure(
                    self::CODE_EXT_TABLES_FIELD_DUPLICATE,
                    sprintf(
                        'Field "%s" in ext_tables.sql table "%s" is already managed by Content Block in %s',
                        $column,
                        $table,
                        $relativeFile,
                    ),
                    $relativeExtTables,
                    ['column' => $column, 'table' => $table, 'cb_config' => $relativeFile],
                );
            }
        }

        return $failures;
    }

    /**
     * Extract column names from a CREATE TABLE statement in SQL content.
     *
     * Simple line/regex-based approach — no full SQL parser.
     * Uses parenthesis-depth tracking to find the correct closing paren.
     * KEY/UNIQUE/INDEX/PRIMARY/CONSTRAINT/FOREIGN lines are skipped.
     *
     * @return list<string>
     */
    private function extractSqlColumns(string $sqlContent, string $table): array
    {
        // Find the CREATE TABLE start position (handles IF NOT EXISTS)
        $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?' . preg_quote($table, '/') . '`?/i';
        if (!preg_match($pattern, $sqlContent, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $startPos = $matches[0][1] + strlen($matches[0][0]);

        // Find opening parenthesis after table name
        $parenPos = strpos($sqlContent, '(', $startPos);
        if ($parenPos === false) {
            return [];
        }

        // Track depth to find matching closing parenthesis
        $body = $this->extractParenBody($sqlContent, $parenPos);
        if ($body === null) {
            return [];
        }

        $columns = [];
        $parts = $this->splitSqlColumnDefinitions($body);

        $keywordPattern = '/^\s*(?:KEY|UNIQUE|INDEX|PRIMARY|CONSTRAINT|FOREIGN|CHECK|FULLTEXT|SPATIAL)\b/i';

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // Skip KEY/INDEX/UNIQUE etc. lines
            if (preg_match($keywordPattern, $part)) {
                continue;
            }

            // Extract the first identifier (column name)
            // Column definitions start with the column name, possibly backtick-quoted
            if (preg_match('/^`?(\w+)`?/', $part, $colMatches)) {
                $columns[] = $colMatches[1];
            }
        }

        return $columns;
    }

    /**
     * Extract the content between an opening paren at $openPos and its matching closing paren.
     *
     * @return string|null Body content (without the outer parentheses), or null on failure.
     */
    private function extractParenBody(string $content, int $openPos): ?string
    {
        $depth = 0;
        $len = strlen($content);

        for ($i = $openPos; $i < $len; $i++) {
            $char = $content[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $openPos + 1, $i - $openPos - 1);
                }
            }
        }

        return null;
    }

    /**
     * Split column definitions from a CREATE TABLE body, respecting nested parentheses.
     *
     * @return list<string>
     */
    private function splitSqlColumnDefinitions(string $body): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $char = $body[$i];
            if ($char === '(') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $depth--;
                $current .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Collect all field identifiers from a Content Block config and its referenced Basics.
     *
     * @param array<string, string> $availableBasics
     * @return list<string>
     */
    private function collectFieldIdentifiers(array $config, array $availableBasics): array
    {
        return array_values(array_unique([
            ...$this->collectFieldIdentifiersFromFields($config['fields'] ?? []),
            ...$this->collectBasicFieldIdentifiers($this->collectBasicReferences($config), $availableBasics),
        ]));
    }

    /**
     * @return list<string>
     */
    private function collectFieldIdentifiersFromFields(mixed $fields): array
    {
        $identifiers = [];
        if (!is_array($fields)) {
            return [];
        }

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $identifier = $field['identifier'] ?? null;
            if (is_string($identifier) && $identifier !== '') {
                $identifiers[] = $identifier;
            }

            if (isset($field['fields'])) {
                $identifiers = [...$identifiers, ...$this->collectFieldIdentifiersFromFields($field['fields'])];
            }
        }

        return $identifiers;
    }

    /**
     * @param list<string> $basicRefs
     * @param array<string, string> $availableBasics
     * @return list<string>
     */
    private function collectBasicFieldIdentifiers(array $basicRefs, array $availableBasics): array
    {
        $identifiers = [];
        $visitedBasics = [];

        while ($basicRefs !== []) {
            $ref = array_shift($basicRefs);
            if (isset($visitedBasics[$ref]) || !isset($availableBasics[$ref])) {
                continue;
            }

            $visitedBasics[$ref] = true;

            try {
                $basicConfig = Yaml::parseFile($availableBasics[$ref]);
            } catch (ParseException) {
                continue;
            }

            if (!is_array($basicConfig)) {
                continue;
            }

            $fields = $basicConfig['fields'] ?? [];
            $identifiers = [...$identifiers, ...$this->collectFieldIdentifiersFromFields($fields)];
            $basicRefs = [...$basicRefs, ...$this->collectBasicFieldReferences($fields)];
        }

        return $identifiers;
    }

    /**
     * Find the extension root directory for a Content Block config file.
     *
     * Extension root is the directory that directly contains the ContentBlocks/ folder.
     * e.g. for packages/my_ext/ContentBlocks/ContentElements/foo/config.yaml
     *      the root is packages/my_ext/
     */
    private function findExtensionRoot(string $configFile): ?string
    {
        $pos = strrpos($configFile, '/ContentBlocks/');
        if ($pos === false) {
            return null;
        }

        return substr($configFile, 0, $pos);
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
