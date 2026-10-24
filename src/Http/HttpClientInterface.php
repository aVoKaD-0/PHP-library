<?php

declare(strict_types=1);

namespace VkPet\Http;

interface HttpClientInterface
{
    public function post(string $url, array $parameters): HttpResponse;
}
