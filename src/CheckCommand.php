<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WEBprofil\Typo3Preflight\Baseline\BaselineComparator;
use WEBprofil\Typo3Preflight\Baseline\BaselineLoader;
use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\ContentBlocks\ContentBlocksLintCheck;
use WEBprofil\Typo3Preflight\Check\ContentBlocks\ContentBlocksYamlCheck;
use WEBprofil\Typo3Preflight\Check\Database\DatabaseSchemaCheck;
use WEBprofil\Typo3Preflight\Check\Database\ReferenceIndexCheck;
use WEBprofil\Typo3Preflight\Check\Extensions\ExtensionIntegrityCheck;
use WEBprofil\Typo3Preflight\Check\Runtime\FrontendSmokeCheck;
use WEBprofil\Typo3Preflight\Check\Runtime\LogCheck;
use WEBprofil\Typo3Preflight\Check\Runtime\Typo3BootCheck;
use WEBprofil\Typo3Preflight\Check\Site\SiteConfigCheck;
use WEBprofil\Typo3Preflight\Check\Static\ArchitectureSqlCheck;
use WEBprofil\Typo3Preflight\Check\Static\ComposerCheck;
use WEBprofil\Typo3Preflight\Check\Static\PhpLintCheck;
use WEBprofil\Typo3Preflight\Check\Static\SecretScannerCheck;
use WEBprofil\Typo3Preflight\Check\Wiring\ExtbaseWiringCheck;
use WEBprofil\Typo3Preflight\Http\GuzzleHttpClient;
use WEBprofil\Typo3Preflight\Output\JsonFormatter;
use WEBprofil\Typo3Preflight\Output\ResultFormatter;
use WEBprofil\Typo3Preflight\Output\TextFormatter;
use WEBprofil\Typo3Preflight\Project\ManifestLoader;
use WEBprofil\Typo3Preflight\Project\ProjectContext;
use WEBprofil\Typo3Preflight\Runner\SymfonyProcessRunner;

final class CheckCommand extends Command
{
    public function __construct()
    {
        parent::__construct('check');
    }

    protected function configure(): void
    {
        $this->setDescription('Run preflight integration checks');
        $this->addOption('suite', 's', InputOption::VALUE_REQUIRED, 'Run only the given suite (static, extensions, site, content_blocks, wiring, database, runtime)');
        $this->addOption('fail-fast', 'f', InputOption::VALUE_NONE, 'Stop after the first failure');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (string) getcwd();

        // 1. Load configuration
        $manifestLoader = new ManifestLoader();
        $config = $manifestLoader->load($projectRoot);
        $context = new ProjectContext($projectRoot, $config);

        $format = (string) $input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln(sprintf('<error>Unknown format "%s". Use "text" or "json".</error>', $format));
            return 2;
        }

        $formatter = $this->createFormatter($format);

        if (!$context->isDdevEnvironment()) {
            $formatter->format([
                new CheckResult(
                    'runtime',
                    'ddev-environment',
                    CheckStatus::Error,
                    'DDEV environment not detected. Run this command via ddev exec vendor/bin/wp-typo3-preflight check.',
                ),
            ], $output);

            return 2;
        }

        // 2. Build checks (explicit registration, ordered by suite)
        $runner = new SymfonyProcessRunner();
        $logCheck = new LogCheck();
        $logCheck->recordStartState($projectRoot); // record log file sizes before any check runs

        /** @var CheckInterface[] $checks */
        $checks = [
            // static
            new ComposerCheck($runner),
            new PhpLintCheck($runner),
            new ArchitectureSqlCheck(),
            new SecretScannerCheck(),
            // extensions
            new ExtensionIntegrityCheck(),
            // site
            new SiteConfigCheck(),
            // content_blocks
            new ContentBlocksLintCheck($runner),
            new ContentBlocksYamlCheck(),
            // wiring
            new ExtbaseWiringCheck(),
            // database
            new DatabaseSchemaCheck($runner),
            new ReferenceIndexCheck($runner),
            // runtime
            new Typo3BootCheck($runner),
            new FrontendSmokeCheck(new GuzzleHttpClient()),
            $logCheck,
        ];

        // 3. Filter by --suite
        $suiteFilter = $input->getOption('suite');
        if ($suiteFilter !== null) {
            $checks = array_values(array_filter(
                $checks,
                fn(CheckInterface $c): bool => $c->suite() === $suiteFilter,
            ));
        }

        // 4. Filter by disabled suites in config
        $checks = array_values(array_filter(
            $checks,
            fn(CheckInterface $c): bool => $context->isSuiteEnabled($c->suite()),
        ));

        // 4b. Filter by disabled individual checks in config
        $checks = array_values(array_filter(
            $checks,
            fn(CheckInterface $c): bool => $context->isCheckEnabled($c->name()),
        ));

        if ($checks === []) {
            $output->writeln('<info>No checks to run (all suites disabled or no matching suite).</info>');
            return 0;
        }

        // 5. Run checks
        $failFast = (bool) $input->getOption('fail-fast');
        $results = $this->runChecks($checks, $context, $failFast, $output);

        // 6. Apply baselines
        $baselineLoader = new BaselineLoader();
        $baselineComparator = new BaselineComparator();
        $baselineEntries = $baselineLoader->load($context->baselinePath());
        $results = $baselineComparator->compare($results, $baselineEntries);

        // 7. Format output
        $formatter->format($results, $output);

        // 8. Determine exit code
        return $this->determineExitCode($results);
    }

    /**
     * @param CheckInterface[] $checks
     * @return CheckResult[]
     */
    private function runChecks(array $checks, ProjectContext $context, bool $failFast, OutputInterface $output): array
    {
        $results = [];

        foreach ($checks as $check) {
            if ($failFast) {
                // Check if any previous result is a failure
                foreach ($results as $r) {
                    if ($r->status === CheckStatus::Fail || $r->status === CheckStatus::Error) {
                        $output->writeln(
                            sprintf('<comment>Fail-fast: skipping remaining checks after %s</comment>', $r->check),
                            OutputInterface::VERBOSITY_VERBOSE,
                        );
                        break 2;
                    }
                }
            }

            $result = $check->run($context);
            $results[] = $result;
        }

        return $results;
    }

    private function createFormatter(string $format): ResultFormatter
    {
        return $format === 'json' ? new JsonFormatter() : new TextFormatter();
    }

    /**
     * @param CheckResult[] $results
     */
    private function determineExitCode(array $results): int
    {
        $hasFail = false;
        $hasError = false;

        foreach ($results as $result) {
            if ($result->status === CheckStatus::Fail) {
                $hasFail = true;
            }
            if ($result->status === CheckStatus::Error) {
                $hasError = true;
            }
        }

        // fail dominates error
        if ($hasFail) {
            return 1;
        }
        if ($hasError) {
            return 2;
        }
        return 0;
    }
}
