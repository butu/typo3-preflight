<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check;

final class Failure
{
    /**
     * @param string $code        Stable error code (check-specific slug)
     * @param string $message     Human-readable description
     * @param string $file        Affected file path (may be empty)
     * @param array<string, string> $context Additional metadata
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $file = '',
        public readonly array $context = [],
    ) {
    }
}
