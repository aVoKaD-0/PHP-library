<?php

declare(strict_types=1);

namespace VkPet\Exception;

class ApiException extends VKException
{
    public function __construct(public readonly string $method, int $apiCode, string $message)
    {
        parent::__construct("{$method}: {$message}", $apiCode);
    }
}
