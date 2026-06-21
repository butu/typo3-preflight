<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Project;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

final class ProjectContextTest extends TestCase
{
    #[Test]
    public function checks_are_enabled_by_default(): void
    {
        $context = new ProjectContext('/tmp/project', []);

        $this->assertTrue($context->isCheckEnabled('reference-index'));
    }

    #[Test]
    public function checks_can_be_disabled_by_name(): void
    {
        $context = new ProjectContext('/tmp/project', [
            'checks' => [
                'reference-index' => ['enabled' => false],
            ],
        ]);

        $this->assertFalse($context->isCheckEnabled('reference-index'));
        $this->assertTrue($context->isCheckEnabled('database-schema'));
    }
}
