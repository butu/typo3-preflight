<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Check\ContentBlocks;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Check\ContentBlocks\ContentBlocksLintCheck;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;
use WEBprofil\Typo3Preflight\Runner\ProcessResult;
use WEBprofil\Typo3Preflight\Runner\ProcessRunner;

final class ContentBlocksLintCheckTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/wp-preflight-cb-lint-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/vendor/bin', 0777, true);
        touch($this->projectRoot . '/vendor/bin/typo3');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    #[Test]
    public function successful_lint_passes_without_command_list_probe(): void
    {
        $runner = $this->runnerWithResult(new ProcessResult(0, 'OK', ''));

        $result = (new ContentBlocksLintCheck($runner))->run(new ProjectContext($this->projectRoot, []));

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame([$this->projectRoot . '/vendor/bin/typo3 content-blocks:lint --no-interaction'], $runner->commands);
    }

    #[Test]
    public function missing_lint_command_skips(): void
    {
        $runner = $this->runnerWithResult(new ProcessResult(
            1,
            '',
            'Command "content-blocks:lint" is not defined.',
        ));

        $result = (new ContentBlocksLintCheck($runner))->run(new ProjectContext($this->projectRoot, []));

        $this->assertSame(CheckStatus::Skip, $result->status);
    }

    #[Test]
    public function lint_failure_fails(): void
    {
        $runner = $this->runnerWithResult(new ProcessResult(
            1,
            '[ERROR] packages/site/ContentBlocks/ContentElements/foo/config.yaml: Invalid config',
            '',
        ));

        $result = (new ContentBlocksLintCheck($runner))->run(new ProjectContext($this->projectRoot, []));

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('content-blocks-lint', $result->failures[0]->code);
    }

    #[Test]
    public function unparsed_lint_failure_keeps_stdout_context(): void
    {
        $runner = $this->runnerWithResult(new ProcessResult(
            1,
            'Number must be greater than or equal to 1',
            '',
        ));

        $result = (new ContentBlocksLintCheck($runner))->run(new ProjectContext($this->projectRoot, []));

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString('Number must be greater', $result->failures[0]->context['output']);
    }

    private function runnerWithResult(ProcessResult $result): ProcessRunner
    {
        return new class($result) implements ProcessRunner {
            /** @var list<string> */
            public array $commands = [];

            public function __construct(private readonly ProcessResult $result)
            {
            }

            public function run(string $command, ?string $cwd = null, int $timeout = 120): ProcessResult
            {
                $this->commands[] = $command;
                return $this->result;
            }
        };
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
