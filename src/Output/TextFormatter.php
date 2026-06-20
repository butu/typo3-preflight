<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Output;

use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\CheckStatus;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Human-readable text formatter for check results.
 */
final class TextFormatter implements ResultFormatter
{
    /**
     * @param CheckResult[] $results
     */
    public function format(array $results, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<options=bold>wp-typo3-preflight</>');
        $output->writeln(str_repeat('─', 60));

        $grouped = $this->groupBySuite($results);

        foreach ($grouped as $suite => $suiteResults) {
            $output->writeln('');
            $output->writeln(sprintf('  <options=bold>Suite: %s</>', $suite));

            foreach ($suiteResults as $result) {
                $icon = $this->icon($result->status);
                $statusStr = $result->status->value;
                $output->writeln(sprintf(
                    '    %s <fg=%s>[%s]</> %s: %s',
                    $icon,
                    $this->color($result->status),
                    strtoupper($statusStr),
                    $result->check,
                    $result->message,
                ));

                foreach ($result->failures as $failure) {
                    $output->writeln(sprintf(
                        '      ╰ %s',
                        $failure->message,
                    ));
                }

                foreach ($result->details as $key => $value) {
                    if ($key === 'baselined' || $key === 'baselined_count') {
                        $output->writeln(sprintf('      ╰ baselined: %s', $value));
                    }
                }
            }
        }

        // Summary
        $output->writeln('');
        $output->writeln(str_repeat('─', 60));

        $counts = $this->countByStatus($results);
        $summaryParts = [];
        foreach (CheckStatus::cases() as $status) {
            $count = $counts[$status->value] ?? 0;
            if ($count > 0 || $status === CheckStatus::Pass) {
                $summaryParts[] = sprintf('%s: %d', $status->value, $count);
            }
        }
        $output->writeln('  ' . implode('  ', $summaryParts));

        $exitCode = $this->exitCode($results);
        $output->writeln(sprintf('  Exit code: %d', $exitCode));
        $output->writeln('');
    }

    /**
     * @param CheckResult[] $results
     * @return array<string, CheckResult[]>
     */
    private function groupBySuite(array $results): array
    {
        $grouped = [];
        foreach ($results as $result) {
            $grouped[$result->suite][] = $result;
        }
        return $grouped;
    }

    /**
     * @return array<string, int>
     */
    private function countByStatus(array $results): array
    {
        $counts = [];
        foreach ($results as $result) {
            $key = $result->status->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return $counts;
    }

    private function exitCode(array $results): int
    {
        $hasFail = false;
        $hasError = false;
        foreach ($results as $result) {
            if ($result->status === CheckStatus::Fail) {
                $hasFail = true;
            }
            if ($result->status === CheckStatus::Error) {
                $hasError = true;
            }
        }
        if ($hasError && !$hasFail) {
            return 2;
        }
        if ($hasFail) {
            return 1;
        }
        return 0;
    }

    private function icon(CheckStatus $status): string
    {
        return match ($status) {
            CheckStatus::Pass => '✓',
            CheckStatus::Fail => '✗',
            CheckStatus::Skip => '○',
            CheckStatus::Error => '⚠',
        };
    }

    private function color(CheckStatus $status): string
    {
        return match ($status) {
            CheckStatus::Pass => 'green',
            CheckStatus::Fail => 'red',
            CheckStatus::Skip => 'yellow',
            CheckStatus::Error => 'magenta',
        };
    }
}
