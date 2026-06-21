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
 * Validates composer.json and checks dependency installation.
 *
 * Runs:
 *   - composer validate --strict
 *   - composer install --dry-run
 */
final class ComposerCheck implements CheckInterface
{
    private const CODE_VALIDATE = 'composer-validate';
    private const CODE_INSTALL = 'composer-install-dry-run';

    public function __construct(
        private readonly ProcessRunner $runner,
    ) {
    }

    public function name(): string
    {
        return 'composer';
    }

    public function suite(): string
    {
        return 'static';
    }

    public function run(ProjectContext $context): CheckResult
    {
        $failures = [];

        // 1. composer validate --strict
        $result = $this->runner->run('composer validate --strict', $context->projectRoot);

        if (!$result->isSuccessful()) {
            foreach ($this->composerValidateFailures($result->stdout . "\n" . $result->stderr) as $failure) {
                $failures[] = $failure;
            }
        }

        // 2. composer install --dry-run
        $result = $this->runner->run('composer install --dry-run --no-interaction', $context->projectRoot);

        if (!$result->isSuccessful()) {
            $failures[] = new Failure(
                self::CODE_INSTALL,
                'composer install --dry-run failed',
                'composer.json#install-dry-run',
                ['stderr' => $this->trimOutput($result->stderr, 500)],
            );
        }

        if ($failures !== []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Fail,
                sprintf('%d composer check(s) failed', count($failures)),
                [],
                $failures,
            );
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Pass,
            'composer validate and install --dry-run passed',
        );
    }

    private function trimOutput(string $output, int $maxLen): string
    {
        if (strlen($output) <= $maxLen) {
            return $output;
        }
        return substr($output, 0, $maxLen) . '…';
    }

    /**
     * @return list<Failure>
     */
    private function composerValidateFailures(string $stderr): array
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $stderr)),
            static fn(string $line): bool => str_starts_with($line, '- '),
        ));

        if ($lines === []) {
            return [
                new Failure(
                    self::CODE_VALIDATE,
                    'composer validate failed',
                    'composer.json#validate',
                    ['stderr' => $this->trimOutput($stderr, 500)],
                ),
            ];
        }

        $failures = [];
        foreach ($lines as $line) {
            $identifier = substr(hash('sha256', $line), 0, 12);
            $failures[] = new Failure(
                self::CODE_VALIDATE,
                $line,
                'composer.json#validate-' . $identifier,
                ['stderr' => $line],
            );
        }

        return $failures;
    }
}
