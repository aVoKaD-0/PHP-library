<?php

declare(strict_types=1);

namespace VkPet\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VkPet\Exception\NetworkException;
use VkPet\Http\CurlHttpClient;

final class CurlHttpClientTest extends TestCase
{
    private static $server;
    private static string $url;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$url = 'http://' . $address;
        self::$server = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/tmp/vk-php-http.log', 'a'],
                2 => ['file', '/tmp/vk-php-http.log', 'a']],
            $pipes
        );
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.01);
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(10000);
        }
        throw new NetworkException('Local test server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    public function testRealPostEncodesUnicodeAndPreservesParameters(): void
    {
        $response = (new CurlHttpClient())->post(self::$url . '/echo', [
            'peer_id' => 2001, 'message' => 'Ваш промокод: КНИГА & 20%', 'random_id' => 71,
        ]);
        self::assertSame(200, $response->status);
        self::assertSame('curl', $response->header('X-Test-Transport'));
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('POST', $body['method']);
        self::assertSame('2001', $body['parameters']['peer_id']);
        self::assertSame('Ваш промокод: КНИГА & 20%', $body['parameters']['message']);
        self::assertSame('71', $body['parameters']['random_id']);
    }

    public function testHttpErrorIsReturnedWithRetryAfterHeader(): void
    {
        $response = (new CurlHttpClient())->post(self::$url . '/unavailable', []);
        self::assertSame(503, $response->status);
        self::assertSame('2', $response->header('retry-after'));
        self::assertNull($response->header('missing-header'));
    }

    public function testRefusedConnectionBecomesNetworkException(): void
    {
        $this->expectException(NetworkException::class);
        (new CurlHttpClient(1, 1))->post('http://127.0.0.1:1', ['access_token' => 'test']);
    }

    public function testInvalidTimeoutsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CurlHttpClient(5, 1);
    }
}
