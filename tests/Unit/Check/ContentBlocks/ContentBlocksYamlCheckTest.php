<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Check\ContentBlocks;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Check\ContentBlocks\ContentBlocksYamlCheck;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

final class ContentBlocksYamlCheckTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/../../../Fixtures/content-blocks';
    }

    #[Test]
    public function valid_content_block_passes(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/valid', []);
        $result = (new ContentBlocksYamlCheck())->run($context);

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame([], $result->failures);
        $this->assertStringContainsString('valid', $result->message);
    }

    #[Test]
    public function invalid_yaml_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/invalid-yaml', []);
        $result = (new ContentBlocksYamlCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->failures);
        $this->assertSame('cb-yaml-invalid', $result->failures[0]->code);
        $this->assertStringContainsString('config.yaml', $result->failures[0]->file);
    }

    #[Test]
    public function missing_identifier_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/missing-identifier', []);
        $result = (new ContentBlocksYamlCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->failures);
        $this->assertSame('cb-missing-identifier', $result->failures[0]->code);
    }

    #[Test]
    public function missing_basic_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/missing-basic', []);
        $result = (new ContentBlocksYamlCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);

        $basicFailures = array_filter(
            $result->failures,
            fn($f) => $f->code === 'cb-basic-missing',
        );
        $this->assertCount(2, $basicFailures);

        $basicNames = array_map(fn($f) => $f->context['basic'] ?? '', array_values($basicFailures));
        $this->assertContains('Vendor/NonExistentBasic', $basicNames);
        $this->assertContains('Vendor/AnotherMissing', $basicNames);
    }

    #[Test]
    public function duplicate_type_name_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/duplicate-type-name', []);
        $result = (new ContentBlocksYamlCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);

        $dupFailures = array_filter(
            $result->failures,
            fn($f) => $f->code === 'cb-typeName-duplicate',
        );
        // 3 configs with the same typeName => each reports the other 2 => 3 failures
        $this->assertCount(3, $dupFailures);

        foreach ($result->failures as $failure) {
            if ($failure->code === 'cb-typeName-duplicate') {
                $this->assertSame('DuplicateType', $failure->context['typeName']);
                // Each failure should list the 2 other files as conflicts
                $this->assertStringContainsString(', ', $failure->context['conflicts']);
            }
        }
    }

    #[Test]
    public function missing_template_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/missing-template', []);
        $result = (new ContentBlocksYamlCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);

        $templateFailures = array_filter(
            $result->failures,
            fn($f) => $f->code === 'cb-template-missing',
        );
        $this->assertCount(1, $templateFailures);
        $this->assertStringContainsString('templates/frontend.html', $result->failures[0]->message);
    }

    #[Test]
    public function missing_labels_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/missing-labels', []);
        $result = (new ContentBlocksYamlCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);

        $labelFailures = array_filter(
            $result->failures,
            fn($f) => $f->code === 'cb-labels-missing',
        );
        // Both no-labels element and no-labels-page should fail for missing labels
        $this->assertCount(2, $labelFailures);

        // PageType should NOT have a template-missing failure
        $templateFailures = array_filter(
            $result->failures,
            fn($f) => $f->code === 'cb-template-missing',
        );
        $this->assertCount(0, $templateFailures, 'PageType should not require frontend.html');
    }

    #[Test]
    public function ext_tables_duplicate_field_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/ext-tables-duplicate', []);
        $result = (new ContentBlocksYamlCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);

        $dupFailures = array_filter(
            $result->failures,
            fn($f) => $f->code === 'cb-ext-tables-field-duplicate',
        );
        // title and basic_bodytext should be flagged
        $this->assertCount(2, $dupFailures);
    }

    #[Test]
    public function typoscript_core_basic_is_allowed(): void
    {
        // The valid fixture contains TYPO3/* references — verify they pass
        $context = new ProjectContext($this->fixturesDir . '/valid', []);
        $result = (new ContentBlocksYamlCheck())->run($context);

        $basicFailures = array_filter(
            $result->failures,
            fn($f) => $f->code === 'cb-basic-missing',
        );
        $this->assertCount(0, $basicFailures, 'TYPO3/* core basics should not be flagged as missing');
    }

    #[Test]
    public function vendor_basic_reference_is_allowed_when_basic_exists_in_vendor(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/vendor-basic', []);
        $result = (new ContentBlocksYamlCheck())->run($context);

        $this->assertSame(CheckStatus::Pass, $result->status);
    }

    #[Test]
    public function no_content_blocks_directory_skips(): void
    {
        $context = new ProjectContext('/tmp/nonexistent-project-cb-xyz', []);
        $result = (new ContentBlocksYamlCheck())->run($context);

        $this->assertSame(CheckStatus::Skip, $result->status);
    }
}
