<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Check\Database\DatabaseSchemaCheck;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;
use WEBprofil\Typo3Preflight\Runner\ProcessResult;
use WEBprofil\Typo3Preflight\Runner\ProcessRunner;

final class DatabaseSchemaCheckTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/wp-preflight-db-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/vendor/bin', 0777, true);
        touch($this->projectRoot . '/vendor/bin/typo3');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    #[Test]
    public function no_schema_updates_output_passes(): void
    {
        $runner = $this->runnerWithResult(new ProcessResult(
            0,
            'No schema updates must be performed for update types:' . PHP_EOL,
            '',
        ));

        $result = (new DatabaseSchemaCheck($runner))->run(new ProjectContext($this->projectRoot, []));

        $this->assertSame(CheckStatus::Pass, $result->status);
    }

    #[Test]
    public function schema_changes_get_distinct_baseline_identifiers(): void
    {
        $runner = $this->runnerWithResult(new ProcessResult(
            0,
            "ALTER TABLE tx_demo ADD title varchar(255) DEFAULT '';\nCREATE TABLE tx_new (uid int);\n",
            '',
        ));

        $result = (new DatabaseSchemaCheck($runner))->run(new ProjectContext($this->projectRoot, []));

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(2, $result->failures);
        $this->assertStringStartsWith('schema-change-', $result->failures[0]->file);
        $this->assertNotSame($result->failures[0]->file, $result->failures[1]->file);
    }

    private function runnerWithResult(ProcessResult $result): ProcessRunner
    {
        return new class($result) implements ProcessRunner {
            public function __construct(private readonly ProcessResult $result)
            {
            }

            public function run(string $command, ?string $cwd = null, int $timeout = 120): ProcessResult
            {
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
