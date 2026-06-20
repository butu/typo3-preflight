<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Output;

use WEBprofil\Typo3Preflight\Check\CheckResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * JSON formatter for machine-readable check results.
 *
 * Each check result is emitted as a JSON object with:
 *   suite, check, status, message, details, fingerprints
 */
final class JsonFormatter implements ResultFormatter
{
    /**
     * @param CheckResult[] $results
     */
    public function format(array $results, OutputInterface $output): void
    {
        $data = [];
        foreach ($results as $result) {
            $entry = [
                'suite' => $result->suite,
                'check' => $result->check,
                'status' => $result->status->value,
                'message' => $result->message,
                'details' => $result->details,
            ];

            if ($result->failures !== []) {
                $entry['failures'] = [];
                foreach ($result->failures as $failure) {
                    $entry['failures'][] = [
                        'code' => $failure->code,
                        'message' => $failure->message,
                        'file' => $failure->file,
                        'context' => $failure->context,
                    ];
                }
            }

            $data[] = $entry;
        }

        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
