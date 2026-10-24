<?php

declare(strict_types=1);

namespace VkPet;

use InvalidArgumentException;

final class AccessToken
{
    public function __construct(private string $token)
    {
        if (trim($token) === '') {
            throw new InvalidArgumentException('Access token must not be empty.');
        }
    }

    public function value(): string
    {
        return $this->token;
    }

    public function __debugInfo(): array
    {
        return ['token' => '[REDACTED]'];
    }
}
