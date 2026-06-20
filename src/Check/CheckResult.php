<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check;

use WEBprofil\Typo3Preflight\CheckStatus;

final class CheckResult
{
    /**
     * @param string $suite      Suite name (static, runtime, …)
     * @param string $check      Check name
     * @param CheckStatus $status Overall status
     * @param string $message    Human-readable summary
     * @param array<string, string> $details Additional key-value context
     * @param list<Failure> $failures Individual failure items (empty when pass/skip)
     */
    public function __construct(
        public readonly string $suite,
        public readonly string $check,
        public readonly CheckStatus $status,
        public readonly string $message = '',
        public readonly array $details = [],
        public readonly array $failures = [],
    ) {
    }

    /**
     * Return a copy with an appended detail.
     */
    public function withDetail(string $key, string $value): self
    {
        return new self(
            $this->suite,
            $this->check,
            $this->status,
            $this->message,
            [...$this->details, $key => $value],
            $this->failures,
        );
    }

    /**
     * Return a copy with an additional failure.
     */
    public function withFailure(Failure $failure): self
    {
        return new self(
            $this->suite,
            $this->check,
            $this->status,
            $this->message,
            $this->details,
            [...$this->failures, $failure],
        );
    }
}
