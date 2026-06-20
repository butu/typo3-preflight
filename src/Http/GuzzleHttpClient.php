<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class GuzzleHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly Client $client = new Client(['verify' => false]),
    ) {
    }

    public function get(string $url): HttpResponse
    {
        try {
            $response = $this->client->get($url);
            return new HttpResponse(
                $response->getStatusCode(),
                (string) $response->getBody(),
            );
        } catch (GuzzleException $e) {
            return new HttpResponse(0, $e->getMessage());
        }
    }
}
