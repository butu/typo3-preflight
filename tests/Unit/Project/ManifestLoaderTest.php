<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Project;

use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Project\ManifestLoader;

final class ManifestLoaderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/../../Fixtures/configs';
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function defaults_are_returned_when_no_project_config_exists(): void
    {
        $loader = new ManifestLoader();
        $config = $loader->load($this->fixturesDir . '/empty-project');

        $this->assertTrue($config['suites']['static']['enabled']);
        $this->assertTrue($config['suites']['runtime']['enabled']);
        $this->assertSame([], $config['urls']);
        $this->assertSame('build/preflight', $config['baseline']['path']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function project_config_overrides_defaults(): void
    {
        $loader = new ManifestLoader();
        $config = $loader->load($this->fixturesDir . '/with-config');

        $this->assertTrue($config['suites']['static']['enabled']);
        $this->assertFalse($config['suites']['runtime']['enabled']);
        $this->assertSame(['/home'], $config['urls']);
        $this->assertSame('custom/path', $config['baseline']['path']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getDefaults_returns_sensible_defaults(): void
    {
        $loader = new ManifestLoader();
        $defaults = $loader->getDefaults();

        $this->assertIsArray($defaults['suites']);
        $this->assertArrayHasKey('static', $defaults['suites']);
        $this->assertArrayHasKey('runtime', $defaults['suites']);
    }
}
