<?php

declare(strict_types=1);

namespace VkPet\Http;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = []
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }
}
