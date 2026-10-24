<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use VkPet\AccessToken;
use VkPet\Http\CurlHttpClient;
use VkPet\VKClient;

$token = new AccessToken(getenv('VK_TOKEN') ?: '');
$client = new VKClient(new CurlHttpClient(), $token, getenv('VK_API_VERSION') ?: '5.199');
$userId = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT);
if (!$userId || $userId < 1) {
    throw new InvalidArgumentException('Usage: php examples/basic.php <VK user ID>');
}
$user = $client->getUser($userId);
echo $user->firstName . ' ' . $user->lastName . PHP_EOL;
foreach ($client->getDialogs() as $dialog) {
    echo $dialog->peerId . ': ' . ($dialog->lastMessage ?? '') . PHP_EOL;
}
