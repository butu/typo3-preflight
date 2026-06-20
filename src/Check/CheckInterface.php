<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check;

use WEBprofil\Typo3Preflight\Project\ProjectContext;

interface CheckInterface
{
    /** Unique check identifier, e.g. "composer-validate". */
    public function name(): string;

    /** Suite this check belongs to, e.g. "static", "runtime". */
    public function suite(): string;

    /** Execute the check against the given project context. */
    public function run(ProjectContext $context): CheckResult;
}
