<?php

declare(strict_types=1);

namespace VkPet\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VkPet\AccessToken;
use VkPet\Dto\Dialog;
use VkPet\Dto\DialogCollection;
use VkPet\Dto\DialogType;
use VkPet\Exception\AccessDeniedException;
use VkPet\Exception\ApiException;
use VkPet\Exception\AuthenticationException;
use VkPet\Exception\NetworkException;
use VkPet\Exception\RateLimitException;
use VkPet\Exception\UnexpectedResponseException;
use VkPet\Exception\UserNotFoundException;
use VkPet\Http\HttpResponse;
use VkPet\VKClient;

final class VKClientTest extends TestCase
{
    public function testSendMessageHasCorrectParametersAndGeneratedRandomId(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(557)]);
        $client = new VKClient($http, new AccessToken('test-community-token'));
        self::assertSame(557, $client->sendMessage(2001, 'Вы выиграли книгу!'));
        [$url, $parameters] = $http->calls[0];
        self::assertSame('https://api.vk.com/method/messages.send', $url);
        self::assertSame(2001, $parameters['peer_id']);
        self::assertSame('Вы выиграли книгу!', $parameters['message']);
        self::assertGreaterThan(0, $parameters['random_id']);
        self::assertSame('5.199', $parameters['v']);
        self::assertSame('test-community-token', $parameters['access_token']);
    }

    public function testChatKeyboardAndAttachmentAreSupported(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(559)]);
        $keyboard = ['one_time' => true, 'buttons' => []];
        $client = new VKClient($http, 'test');
        self::assertSame(559, $client->sendMessage(2001, '', [
            'peer_id' => 2000000001, 'random_id' => -52, 'attachment' => 'photo1_2',
            'keyboard' => $keyboard, 'disable_mentions' => true,
        ]));
        $parameters = $http->calls[0][1];
        self::assertSame(2000000001, $parameters['peer_id']);
        self::assertSame(-52, $parameters['random_id']);
        self::assertSame(1, $parameters['disable_mentions']);
        self::assertSame($keyboard, json_decode($parameters['keyboard'], true));
    }

    public function testGetUserParsesRealVkResponseShape(): void
    {
        $response = file_get_contents(__DIR__ . '/fixtures/user.json');
        $http = new FakeHttpClient([new HttpResponse(200, $response)]);
        $user = (new VKClient($http, 'test'))->getUser(2001);
        self::assertSame(2001, $user->id);
        self::assertSame('Иван', $user->firstName);
        self::assertSame('Иванов', $user->lastName);
        self::assertSame('https://example.org/avatar.jpg', $user->avatarUrl);
        self::assertSame(['user_ids' => 2001, 'fields' => 'photo_100',
            'access_token' => 'test', 'v' => '5.199'], $http->calls[0][1]);
    }

    public function testMissingAvatarIsNullable(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok([
            ['id' => 2001, 'first_name' => 'Иван', 'last_name' => 'Иванов'],
        ])]);
        self::assertNull((new VKClient($http, 'test'))->getUser(2001)->avatarUrl);
    }

    public function testEmptyUserListRaisesUserNotFound(): void
    {
        $client = new VKClient(new FakeHttpClient([FakeHttpClient::ok([])]), 'test');
        $this->expectException(UserNotFoundException::class);
        $client->getUser(2001);
    }

    public function testDialogsAreTypedAndPaginationIsPassedToVk(): void
    {
        $response = file_get_contents(__DIR__ . '/fixtures/dialogs.json');
        $http = new FakeHttpClient([new HttpResponse(200, $response)]);
        $dialogs = (new VKClient($http, 'test'))->getDialogs(20, 3);
        self::assertSame(41, $dialogs->total);
        self::assertCount(3, $dialogs);
        self::assertSame($dialogs->items(), iterator_to_array($dialogs));
        self::assertContainsOnlyInstancesOf(Dialog::class, $dialogs);
        self::assertSame(DialogType::Chat, $dialogs->items()[1]->type);
        self::assertSame(2000000001, $dialogs->items()[1]->peerId);
        self::assertSame('Спасибо за участие!', $dialogs->items()[0]->lastMessage);
        self::assertNull($dialogs->items()[2]->lastMessage);
        self::assertSame('https://api.vk.com/method/messages.getConversations', $http->calls[0][0]);
        self::assertSame(20, $http->calls[0][1]['offset']);
        self::assertSame(3, $http->calls[0][1]['count']);
    }

    public function testEmptyDialogPageKeepsTotalCount(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(['count' => 41, 'items' => []])]);
        $dialogs = (new VKClient($http, 'test'))->getDialogs(100);
        self::assertSame(41, $dialogs->total);
        self::assertCount(0, $dialogs);
    }

    public function testNetworkRetryKeepsExactlyTheSameMessageParameters(): void
    {
        $http = new FakeHttpClient([
            new NetworkException('offline'), new NetworkException('offline'), FakeHttpClient::ok(557),
        ]);
        $pauses = [];
        $client = new VKClient($http, 'test', sleep: static function (float $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        });
        self::assertSame(557, $client->sendMessage(2001, 'Ваш промокод: BOOK2026'));
        self::assertCount(3, $http->calls);
        self::assertSame($http->calls[0], $http->calls[1]);
        self::assertSame($http->calls[1], $http->calls[2]);
        self::assertSame([0.5, 1.0], $pauses);
    }

    public function testNetworkFailureStopsAfterThreeAttemptsAndRedactsTransportDetails(): void
    {
        $http = new FakeHttpClient(array_fill(0, 3, new NetworkException('secret-token')));
        $client = new VKClient($http, 'secret-token', sleep: static fn () => null);
        try {
            $client->sendMessage(2001, 'Приз');
            self::fail('Expected NetworkException.');
        } catch (NetworkException $error) {
            self::assertStringNotContainsString('secret-token', $error->getMessage());
            self::assertCount(3, $http->calls);
        }
    }

    #[DataProvider('permanentErrors')]
    public function testPermanentApiErrorIsTypedAndNotRetried(int $code, string $type): void
    {
        $http = new FakeHttpClient([FakeHttpClient::error($code, 'Access denied secret-token')]);
        $client = new VKClient($http, 'secret-token');
        try {
            $client->sendMessage(2001, 'Приз');
            self::fail('Expected API error.');
        } catch (ApiException $error) {
            self::assertInstanceOf($type, $error);
            self::assertSame($code, $error->getCode());
            self::assertSame('messages.send', $error->method);
            self::assertStringNotContainsString('secret-token', $error->getMessage());
            self::assertCount(1, $http->calls);
        }
    }

    public static function permanentErrors(): array
    {
        return [
            [5, AuthenticationException::class], [27, AuthenticationException::class],
            [7, AccessDeniedException::class], [15, AccessDeniedException::class],
            [188, AccessDeniedException::class], [901, AccessDeniedException::class],
            [123456, ApiException::class],
        ];
    }

    #[DataProvider('temporaryErrors')]
    public function testTemporaryHttpAndApiErrorsAreRetried(HttpResponse $failure): void
    {
        $http = new FakeHttpClient([$failure, FakeHttpClient::ok(557)]);
        $pauses = [];
        $client = new VKClient($http, 'test', sleep: static function (float $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        });
        self::assertSame(557, $client->sendMessage(2001, 'Приз', ['random_id' => 71]));
        self::assertCount(2, $http->calls);
        self::assertSame($http->calls[0], $http->calls[1]);
        self::assertGreaterThanOrEqual(0.5, $pauses[0]);
    }

    public static function temporaryErrors(): array
    {
        return [[new HttpResponse(429, '')], [new HttpResponse(420, '')],
            [new HttpResponse(503, '')], [FakeHttpClient::error(6)], [FakeHttpClient::error(10)],
            [FakeHttpClient::error(29)]];
    }

    public function testRetryAfterHeaderIsCaseInsensitiveAndCapped(): void
    {
        $http = new FakeHttpClient([new HttpResponse(429, '', ['rEtRy-AfTeR' => '120']),
            FakeHttpClient::ok(557)]);
        $pauses = [];
        $client = new VKClient($http, 'test', sleep: static function (float $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        });
        $client->sendMessage(2001, 'Приз');
        self::assertSame([30.0], $pauses);
    }

    public function testRetryAfterHttpDateIsSupported(): void
    {
        $http = new FakeHttpClient([new HttpResponse(429, '', [
            'Retry-After' => gmdate('D, d M Y H:i:s', time() + 5) . ' GMT',
        ]), FakeHttpClient::ok(557)]);
        $pauses = [];
        $client = new VKClient($http, 'test', sleep: static function (float $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        });
        $client->sendMessage(2001, 'Приз');
        self::assertGreaterThanOrEqual(4, $pauses[0]);
        self::assertLessThanOrEqual(5, $pauses[0]);
    }

    public function testRateLimitEventuallyRaisesTypedException(): void
    {
        $http = new FakeHttpClient(array_fill(0, 3, FakeHttpClient::error(6)));
        $client = new VKClient($http, 'test', sleep: static fn () => null);
        $this->expectException(RateLimitException::class);
        try {
            $client->getDialogs();
        } finally {
            self::assertCount(3, $http->calls);
        }
    }

    #[DataProvider('httpErrors')]
    public function testPermanentHttpStatusIsNotRetried(int $status, string $type): void
    {
        $http = new FakeHttpClient([new HttpResponse($status, '')]);
        $client = new VKClient($http, 'test');
        $this->expectException($type);
        try {
            $client->getDialogs();
        } finally {
            self::assertCount(1, $http->calls);
        }
    }

    public static function httpErrors(): array
    {
        return [[401, AuthenticationException::class], [403, AccessDeniedException::class],
            [400, ApiException::class]];
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidArgumentsFailBeforeHttp(string $method, array $arguments): void
    {
        $http = new FakeHttpClient([]);
        $client = new VKClient($http, 'test');
        $this->expectException(InvalidArgumentException::class);
        try {
            $client->$method(...$arguments);
        } finally {
            self::assertCount(0, $http->calls);
        }
    }

    public static function invalidRequests(): array
    {
        return [
            ['getUser', [0]], ['getDialogs', [-1]], ['getDialogs', [0, 0]],
            ['getDialogs', [0, 201]], ['sendMessage', [0, 'Приз']], ['sendMessage', [2001, ' ']],
            ['sendMessage', [2001, 'Приз', ['access_token' => 'injected']]],
            ['sendMessage', [2001, 'Приз', ['v' => '1.0']]],
            ['sendMessage', [2001, 'Приз', ['peer_id' => 0]]],
            ['sendMessage', [2001, 'Приз', ['random_id' => 0]]],
            ['sendMessage', [2001, 'Приз', ['random_id' => null]]],
            ['sendMessage', [2001, 'Приз', ['peer_id' => null]]],
            ['sendMessage', [2001, 'Приз', ['attachment' => null]]],
            ['sendMessage', [2001, 'Приз', ['keyboard' => '[]']]],
            ['sendMessage', [2001, 'Приз', ['random_id' => 2147483648]]],
            ['sendMessage', [2001, 'Приз', ['attachment' => []]]],
            ['sendMessage', [2001, 'Приз', ['disable_mentions' => 2]]],
            ['sendMessage', [2001, 'Приз', ['keyboard' => '{invalid']]],
            ['sendMessage', [2001, 'Приз', ['keyboard' => 42]]],
        ];
    }

    #[DataProvider('unexpectedResponses')]
    public function testMalformedResponsesFailClearlyWithoutRetry(string $method, HttpResponse $response): void
    {
        $http = new FakeHttpClient([$response]);
        $client = new VKClient($http, 'test');
        $this->expectException(UnexpectedResponseException::class);
        try {
            match ($method) {
                'sendMessage' => $client->sendMessage(2001, 'Приз'),
                'getUser' => $client->getUser(2001),
                default => $client->getDialogs(),
            };
        } finally {
            self::assertCount(1, $http->calls);
        }
    }

    public static function unexpectedResponses(): array
    {
        return [
            ['getUser', new HttpResponse(200, '{invalid')],
            ['getUser', new HttpResponse(200, 'null')],
            ['getUser', new HttpResponse(200, '{}')],
            ['getUser', new HttpResponse(200, '{"error":{"error_code":"15","error_msg":"bad"}}')],
            ['getUser', FakeHttpClient::ok(['id' => 2001])],
            ['getUser', FakeHttpClient::ok([['id' => '2001']])],
            ['getUser', FakeHttpClient::ok([['id' => 2001, 'first_name' => 'Иван',
                'last_name' => 'Иванов', 'photo_100' => 1]])],
            ['sendMessage', FakeHttpClient::ok('557')], ['sendMessage', FakeHttpClient::ok(0)],
            ['getDialogs', FakeHttpClient::ok(['count' => -1, 'items' => []])],
            ['getDialogs', FakeHttpClient::ok(['count' => 1, 'items' => ['broken']])],
            ['getDialogs', FakeHttpClient::ok(['count' => 1, 'items' => [[
                'conversation' => ['peer' => 'broken'],
            ]]])],
            ['getDialogs', FakeHttpClient::ok(['count' => 1, 'items' => [[
                'conversation' => ['peer' => ['id' => 2001, 'type' => 'user']],
                'last_message' => 'broken',
            ]]])],
            ['getDialogs', FakeHttpClient::ok(['count' => 1, 'items' => [[
                'conversation' => ['peer' => ['id' => 2001, 'type' => 'unknown']],
            ]]])],
        ];
    }

    public function testTokenDebugOutputIsRedacted(): void
    {
        ob_start();
        var_dump(new AccessToken('secret-token'));
        $output = ob_get_clean();
        self::assertStringNotContainsString('secret-token', $output);
        self::assertStringContainsString('[REDACTED]', $output);
    }

    public function testEmptyTokenIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AccessToken(' ');
    }

    public function testInvalidVersionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VKClient(new FakeHttpClient([]), 'test', 'invalid');
    }

    public function testDialogCollectionRejectsRawArrays(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DialogCollection(1, [['id' => 2001]]);
    }
}
