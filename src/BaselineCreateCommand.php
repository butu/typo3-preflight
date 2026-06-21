<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WEBprofil\Typo3Preflight\Baseline\Fingerprint;
use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\ContentBlocks\ContentBlocksLintCheck;
use WEBprofil\Typo3Preflight\Check\ContentBlocks\ContentBlocksYamlCheck;
use WEBprofil\Typo3Preflight\Check\Database\DatabaseSchemaCheck;
use WEBprofil\Typo3Preflight\Check\Database\ReferenceIndexCheck;
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
use WEBprofil\Typo3Preflight\Project\ManifestLoader;
use WEBprofil\Typo3Preflight\Project\ProjectContext;
use WEBprofil\Typo3Preflight\Runner\SymfonyProcessRunner;

/**
 * Creates a baseline file from the current check run.
 *
 * Runs all checks and saves fingerprints of failures to
 * build/preflight/preflight.baseline.json.
 */
final class BaselineCreateCommand extends Command
{
    public function __construct()
    {
        parent::__construct('baseline:create');
    }

    protected function configure(): void
    {
        $this->setDescription('Create a baseline file from current check failures');
        $this->addOption('suite', 's', InputOption::VALUE_REQUIRED, 'Create baseline only for the given suite');
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path', 'build/preflight/preflight.baseline.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (string) getcwd();

        $manifestLoader = new ManifestLoader();
        $config = $manifestLoader->load($projectRoot);
        $context = new ProjectContext($projectRoot, $config);

        if (!$context->isDdevEnvironment()) {
            $output->writeln('<error>DDEV environment not detected. Run this command via ddev exec vendor/bin/wp-typo3-preflight baseline:create.</error>');
            return 2;
        }

        $runner = new SymfonyProcessRunner();
        $logCheck = new LogCheck();
        $logCheck->recordStartState($projectRoot);

        /** @var CheckInterface[] $checks */
        $checks = [
            new ComposerCheck($runner),
            new PhpLintCheck($runner),
            new ArchitectureSqlCheck(),
            new SecretScannerCheck(),
            new SiteConfigCheck(),
            new ContentBlocksLintCheck($runner),
            new ContentBlocksYamlCheck(),
            new ExtbaseWiringCheck(),
            new DatabaseSchemaCheck($runner),
            new ReferenceIndexCheck($runner),
            new Typo3BootCheck($runner),
            new FrontendSmokeCheck(new GuzzleHttpClient()),
            $logCheck,
        ];

        $suiteFilter = $input->getOption('suite');
        if ($suiteFilter !== null) {
            $checks = array_values(array_filter(
                $checks,
                fn(CheckInterface $c): bool => $c->suite() === $suiteFilter,
            ));
        }

        // Filter by enabled suites
        $checks = array_values(array_filter(
            $checks,
            fn(CheckInterface $c): bool => $context->isSuiteEnabled($c->suite()),
        ));

        // Run checks
        $results = [];
        foreach ($checks as $check) {
            $results[] = $check->run($context);
        }

        // Build baseline entries from failures
        $fingerprint = new Fingerprint();
        $entries = [];

        foreach ($results as $result) {
            if ($result->status !== CheckStatus::Fail) {
                continue;
            }

            foreach ($result->failures as $failure) {
                $fp = $fingerprint->compute($result->check, $failure->code, $failure->file);

                $entries[] = [
                    'fingerprint' => $fp,
                    'check' => $result->check,
                    'message' => $failure->message,
                    'reason' => '',
                ];
            }
        }

        // Write baseline file
        $outputPath = $input->getOption('output');
        if (!str_starts_with($outputPath, '/')) {
            $outputPath = $projectRoot . '/' . ltrim($outputPath, '/');
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                $output->writeln(sprintf('<error>Cannot create directory: %s</error>', $dir));
                return 2;
            }
        }

        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($outputPath, $json . "\n") === false) {
            $output->writeln(sprintf('<error>Cannot write baseline file: %s</error>', $outputPath));
            return 2;
        }

        $output->writeln(sprintf(
            '<info>Baseline written to %s (%d entries)</info>',
            $outputPath,
            count($entries),
        ));

        return 0;
    }
}
