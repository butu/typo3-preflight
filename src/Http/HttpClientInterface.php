<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Http;

interface HttpClientInterface
{
    public function get(string $url): HttpResponse;
}
