<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check\Wiring;

use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

/**
 * Simple static plausibility check for Extbase plugin wiring.
 *
 * Parses ext_localconf.php files for ExtensionUtility::configurePlugin() registrations
 * and scans Fluid templates for action references, reporting actions that are
 * referenced in templates but not registered in ext_localconf.
 *
 * Does NOT perform complex RouteEnhancer/PageType checks (out of scope).
 */
final class ExtbaseWiringCheck implements CheckInterface
{
    private const CODE_UNREGISTERED_ACTION = 'wiring-unregistered-action';

    public function name(): string
    {
        return 'extbase-wiring';
    }

    public function suite(): string
    {
        return 'wiring';
    }

    public function run(ProjectContext $context): CheckResult
    {
        $root = $context->projectRoot;

        // Collect registered plugin actions from ext_localconf.php files
        $registeredActions = $this->collectRegisteredActions($root);

        if ($registeredActions === []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'No Extbase configurePlugin registrations found',
            );
        }

        // Collect referenced actions from Fluid templates
        $templates = $this->findFluidTemplates($root);

        if ($templates === []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'No Fluid templates found to scan for action references',
            );
        }

        $failures = [];
        foreach ($templates as $template) {
            $relativeFile = $this->relativePath($template, $root);
            $referenced = $this->extractActionReferences($template);

            foreach ($referenced as $ref) {
                $controller = $ref['controller'];
                $action = $ref['action'];

                if (str_contains($controller, '{') || str_contains($action, '{')) {
                    continue;
                }

                // Find matching registered controller by short name or FQCN
                $matchedController = $this->findMatchingController($controller, $registeredActions);

                if ($matchedController === null) {
                    $failures[] = new Failure(
                        self::CODE_UNREGISTERED_ACTION,
                        sprintf(
                            'Fluid template %s references unregistered controller "%s" (action: %s)',
                            $relativeFile,
                            $controller,
                            $action,
                        ),
                        $relativeFile,
                        ['controller' => $controller, 'action' => $action],
                    );
                    continue;
                }

                // Check if the action is registered
                if (!in_array(lcfirst($action), $registeredActions[$matchedController], true)
                    && !in_array($action, $registeredActions[$matchedController], true)
                ) {
                    $failures[] = new Failure(
                        self::CODE_UNREGISTERED_ACTION,
                        sprintf(
                            'Fluid template %s references unregistered action "%s->%s"',
                            $relativeFile,
                            $controller,
                            $action,
                        ),
                        $relativeFile,
                        ['controller' => $controller, 'action' => $action],
                    );
                }
            }
        }

        if ($failures !== []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Fail,
                sprintf('%d wiring issue(s) found', count($failures)),
                ['templates_scanned' => (string) count($templates)],
                $failures,
            );
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Pass,
            sprintf('%d template(s) checked, all action references match registered plugins', count($templates)),
            ['templates_scanned' => (string) count($templates)],
        );
    }

    /**
     * Collect registered plugin actions from all ext_localconf.php files.
     *
     * Parses patterns like:
     *   \TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
     *       'Vendor.ExtName',
     *       'PluginName',
     *       [\Vendor\Ext\Controller\SomeController::class => 'list,show'],
     *       ...
     *   );
     *
     * @return array<string, list<string>> controller FQCN => actions
     */
    private function collectRegisteredActions(string $projectRoot): array
    {
        $registered = [];
        $files = $this->findPhpFiles($projectRoot, 'ext_localconf.php');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Match configurePlugin calls and extract controller => action mappings
            // Pattern: Controller::class => 'action1,action2'
            if (preg_match_all(
                '/([\\\\\w]+Controller)::class\s*=>\s*[\'"]([a-zA-Z0-9_,\s]+)[\'"]/',
                $content,
                $matches,
                PREG_SET_ORDER,
            )) {
                foreach ($matches as $match) {
                    $controller = $match[1];
                    $actions = array_map('trim', explode(',', $match[2]));
                    $actions = array_filter($actions, static fn(string $a): bool => $a !== '');

                    if (!isset($registered[$controller])) {
                        $registered[$controller] = [];
                    }
                    $registered[$controller] = array_unique([...$registered[$controller], ...$actions]);
                }
            }
        }

        return $registered;
    }

    /**
     * Find Fluid templates under packages and extensions directories.
     *
     * @return list<string>
     */
    private function findFluidTemplates(string $projectRoot): array
    {
        $files = [];
        $baseDirs = [$projectRoot . '/packages', $projectRoot . '/extensions'];

        foreach ($baseDirs as $baseDir) {
            if (!is_dir($baseDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $item */
            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->getExtension() !== 'html') {
                    continue;
                }

                $path = $item->getPathname();
                if (str_contains($path, '/tests/Fixtures/') || str_contains($path, '/Tests/Fixtures/')) {
                    continue;
                }

                if (str_contains($path, '/Resources/Private/Templates/')
                    || str_contains($path, '/Resources/Private/Partials/')
                ) {
                    $files[] = $path;
                }
            }
        }

        sort($files);
        return $files;
    }

    /**
     * Extract controller/action references from a Fluid template.
     *
     * Looks for f:link.action and f:uri.action with controller/action attributes.
     *
     * @return list<array{controller: string, action: string}>
     */
    private function extractActionReferences(string $templatePath): array
    {
        $content = file_get_contents($templatePath);
        if ($content === false) {
            return [];
        }

        $references = [];

        // Match f:link.action and f:uri.action with optional controller and action attributes
        // Pattern: {f:link.action(controller: 'Foo', action: 'bar')} or <f:link.action controller="Foo" action="bar" />
        $patterns = [
            // Tag syntax: <f:link.action controller="..." action="...">
            '/<(?:f:link\.action|f:uri\.action)\s+[^>]*?(?:controller|action)[^>]*?>/i',
            // Inline syntax: {f:link.action(controller: '...', action: '...')}
            '/\{f:(?:link\.action|uri\.action)\([^}]*?\)\}/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $tagMatches)) {
                foreach ($tagMatches[0] as $tag) {
                    $ref = $this->parseActionTag($tag);
                    if ($ref !== null) {
                        $references[] = $ref;
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Parse a single Fluid action tag for controller and action attributes.
     *
     * @return null|array{controller: string, action: string}
     */
    private function parseActionTag(string $tag): ?array
    {
        $controller = null;
        $action = null;

        // Extract controller
        if (preg_match('/controller\s*[:=]\s*[\'"]([^\'"]+)[\'"]/', $tag, $m)) {
            $controller = $m[1];
        }

        // Extract action
        if (preg_match('/action\s*[:=]\s*[\'"]([^\'"]+)[\'"]/', $tag, $m)) {
            $action = $m[1];
        }

        if ($action !== null) {
            // If controller is not specified, we can't validate it — skip
            if ($controller === null) {
                return null;
            }
            return ['controller' => $controller, 'action' => $action];
        }

        return null;
    }

    /**
     * Find a matching registered controller by short name or FQCN.
     *
     * @param array<string, list<string>> $registeredActions
     */
    private function findMatchingController(string $controller, array $registeredActions): ?string
    {
        // Exact match first
        if (isset($registeredActions[$controller])) {
            return $controller;
        }

        // Try matching by short class name (the part after last backslash)
        foreach (array_keys($registeredActions) as $fqcn) {
            $parts = explode('\\', $fqcn);
            $shortName = end($parts);
                if ($shortName === $controller || $shortName === $controller . 'Controller') {
                    return $fqcn;
                }
        }

        return null;
    }

    /**
     * Find PHP files with a given name under packages and extensions.
     *
     * @return list<string>
     */
    private function findPhpFiles(string $projectRoot, string $filename): array
    {
        $files = [];
        $baseDirs = [$projectRoot . '/packages', $projectRoot . '/extensions'];

        foreach ($baseDirs as $baseDir) {
            if (!is_dir($baseDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $item */
            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->getFilename() !== $filename) {
                    continue;
                }

                $path = $item->getPathname();
                if (!str_contains($path, '/tests/Fixtures/') && !str_contains($path, '/Tests/Fixtures/')) {
                    $files[] = $path;
                }
            }
        }

        sort($files);
        return $files;
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
