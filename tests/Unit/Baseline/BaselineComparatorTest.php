<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Baseline;

use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Baseline\BaselineComparator;
use WEBprofil\Typo3Preflight\Baseline\BaselineEntry;
use WEBprofil\Typo3Preflight\Baseline\Fingerprint;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;

final class BaselineComparatorTest extends TestCase
{
    private BaselineComparator $comparator;
    private Fingerprint $fingerprint;

    protected function setUp(): void
    {
        $this->fingerprint = new Fingerprint();
        $this->comparator = new BaselineComparator($this->fingerprint);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function empty_baseline_leaves_results_unchanged(): void
    {
        $results = [
            new CheckResult('static', 'check-a', CheckStatus::Pass, 'ok'),
            new CheckResult('static', 'check-b', CheckStatus::Fail, 'failed'),
        ];

        $processed = $this->comparator->compare($results, []);

        $this->assertCount(2, $processed);
        $this->assertSame(CheckStatus::Pass, $processed[0]->status);
        $this->assertSame(CheckStatus::Fail, $processed[1]->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function matched_failure_is_reclassified_to_skip(): void
    {
        $code = 'error-x';
        $file = 'some/file.php';
        $fp = $this->fingerprint->compute('my-check', $code, $file);

        $failure = new Failure($code, 'Something broke', $file);
        $results = [
            new CheckResult(
                'runtime',
                'my-check',
                CheckStatus::Fail,
                'failed',
                [],
                [$failure],
            ),
        ];

        $baseline = [
            new BaselineEntry($fp, 'my-check', 'Something broke', 'known issue'),
        ];

        $processed = $this->comparator->compare($results, $baseline);

        $this->assertCount(1, $processed);
        $this->assertSame(CheckStatus::Skip, $processed[0]->status);
        $this->assertStringContainsString('baselined', $processed[0]->message);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unmatched_failure_stays_as_fail(): void
    {
        $failure = new Failure('new-error', 'New problem', 'new/file.php');
        $results = [
            new CheckResult(
                'runtime',
                'my-check',
                CheckStatus::Fail,
                'failed',
                [],
                [$failure],
            ),
        ];

        // Baseline has a different fingerprint
        $baselineFp = $this->fingerprint->compute('my-check', 'other-code', 'other/file.php');
        $baseline = [
            new BaselineEntry($baselineFp, 'my-check', 'Old problem', ''),
        ];

        $processed = $this->comparator->compare($results, $baseline);

        $this->assertCount(2, $processed);
        $this->assertSame(CheckStatus::Fail, $processed[0]->status);
        $this->assertSame('baseline-stale', $processed[1]->check);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function partially_matched_check_keeps_unmatched_failures(): void
    {
        $fpMatched = $this->fingerprint->compute('my-check', 'known-error', 'file-a.php');
        $fpUnmatched = $this->fingerprint->compute('my-check', 'new-error', 'file-b.php');

        $failure1 = new Failure('known-error', 'Known issue', 'file-a.php');
        $failure2 = new Failure('new-error', 'New issue', 'file-b.php');

        $results = [
            new CheckResult(
                'runtime',
                'my-check',
                CheckStatus::Fail,
                'two failures',
                [],
                [$failure1, $failure2],
            ),
        ];

        $baseline = [
            new BaselineEntry($fpMatched, 'my-check', 'Known issue', ''),
        ];

        $processed = $this->comparator->compare($results, $baseline);

        $this->assertCount(1, $processed);
        $this->assertSame(CheckStatus::Fail, $processed[0]->status);
        $this->assertCount(1, $processed[0]->failures);
        $this->assertSame('new-error', $processed[0]->failures[0]->code);
        $this->assertStringContainsString('some failures baselined', $processed[0]->message);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function stale_baseline_entries_are_reported_as_skip(): void
    {
        $fp = $this->fingerprint->compute('stale-check', 'stale-code', 'stale-file.php');

        $results = [
            new CheckResult('static', 'other-check', CheckStatus::Pass, 'all good'),
        ];

        $baseline = [
            new BaselineEntry($fp, 'stale-check', 'Old problem', 'was a bug'),
        ];

        $processed = $this->comparator->compare($results, $baseline);

        // We expect: original pass result + stale entry
        $this->assertCount(2, $processed);
        $this->assertSame(CheckStatus::Pass, $processed[0]->status);

        $staleResult = $processed[1];
        $this->assertSame(CheckStatus::Skip, $staleResult->status);
        $this->assertStringContainsString('Stale baseline', $staleResult->message);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function pass_results_are_never_modified(): void
    {
        $results = [
            new CheckResult('static', 'good-check', CheckStatus::Pass, 'ok'),
        ];

        $baseline = [
            new BaselineEntry('abc123', 'good-check', 'msg', ''),
        ];

        $processed = $this->comparator->compare($results, $baseline);

        // Should have the pass result + stale entry
        $this->assertCount(2, $processed);
        $this->assertSame(CheckStatus::Pass, $processed[0]->status);
        $this->assertSame('good-check', $processed[0]->check);
    }
}
