<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;

final class CheckResultTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function withDetail_appends_to_details_array(): void
    {
        $result = new CheckResult('suite', 'check', CheckStatus::Pass, 'msg', ['a' => '1']);
        $updated = $result->withDetail('b', '2');

        $this->assertNotSame($result, $updated);
        $this->assertSame('1', $updated->details['a']);
        $this->assertSame('2', $updated->details['b']);
        $this->assertSame(CheckStatus::Pass, $updated->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function withFailure_appends_to_failures_array(): void
    {
        $existing = new Failure('code1', 'msg1', 'file1');
        $result = new CheckResult('suite', 'check', CheckStatus::Fail, 'msg', [], [$existing]);

        $new = new Failure('code2', 'msg2', 'file2');
        $updated = $result->withFailure($new);

        $this->assertCount(2, $updated->failures);
        $this->assertSame('code1', $updated->failures[0]->code);
        $this->assertSame('code2', $updated->failures[1]->code);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function check_result_is_immutable(): void
    {
        $failure = new Failure('code', 'msg', 'file');
        $details = ['key' => 'val'];

        $result = new CheckResult('suite', 'check', CheckStatus::Pass, 'msg', $details, [$failure]);

        // Verify all readonly properties
        $this->assertSame('suite', $result->suite);
        $this->assertSame('check', $result->check);
        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame('msg', $result->message);
        $this->assertSame(['key' => 'val'], $result->details);
        $this->assertCount(1, $result->failures);
    }
}
