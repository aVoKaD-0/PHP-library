<?php

declare(strict_types=1);

namespace VkPet\Http;

use InvalidArgumentException;
use VkPet\Exception\NetworkException;

final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(private int $connectTimeout = 5, private int $timeout = 15)
    {
        if ($connectTimeout < 1 || $timeout < $connectTimeout) {
            throw new InvalidArgumentException('Invalid HTTP timeouts.');
        }
    }

    public function post(string $url, array $parameters): HttpResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new NetworkException('Could not initialize HTTP transport.');
        }
        $headers = [];
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($body === false) {
            throw new NetworkException('HTTP connection failed.');
        }
        return new HttpResponse($status, $body, $headers);
    }
}
