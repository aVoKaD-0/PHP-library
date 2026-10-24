<?php

declare(strict_types=1);

namespace VkPet\Tests;

use LogicException;
use VkPet\Exception\NetworkException;
use VkPet\Http\HttpClientInterface;
use VkPet\Http\HttpResponse;

final class FakeHttpClient implements HttpClientInterface
{
    public array $calls = [];

    public function __construct(private array $responses)
    {
    }

    public function post(string $url, array $parameters): HttpResponse
    {
        $this->calls[] = [$url, $parameters];
        if ($this->responses === []) {
            throw new LogicException('Unexpected HTTP call.');
        }
        $response = array_shift($this->responses);
        if ($response instanceof NetworkException) {
            throw $response;
        }
        return $response;
    }

    public static function ok(mixed $data): HttpResponse
    {
        return new HttpResponse(200, json_encode(['response' => $data], JSON_THROW_ON_ERROR));
    }

    public static function error(int $code, string $message = 'API error'): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'error' => ['error_code' => $code, 'error_msg' => $message],
        ], JSON_THROW_ON_ERROR));
    }
}
