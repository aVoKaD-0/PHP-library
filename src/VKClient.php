<?php

declare(strict_types=1);

namespace VkPet;

use Closure;
use InvalidArgumentException;
use JsonException;
use VkPet\Dto\Dialog;
use VkPet\Dto\DialogCollection;
use VkPet\Dto\DialogType;
use VkPet\Dto\User;
use VkPet\Exception\AccessDeniedException;
use VkPet\Exception\ApiException;
use VkPet\Exception\AuthenticationException;
use VkPet\Exception\NetworkException;
use VkPet\Exception\RateLimitException;
use VkPet\Exception\UnexpectedResponseException;
use VkPet\Exception\UserNotFoundException;
use VkPet\Http\HttpClientInterface;
use VkPet\Http\HttpResponse;

final class VKClient
{
    private AccessToken $token;
    private Closure $sleep;

    public function __construct(
        private HttpClientInterface $http,
        AccessToken|string $token,
        private string $version = '5.199',
        ?callable $sleep = null
    ) {
        if (!preg_match('/^\d+\.\d+$/', $version)) {
            throw new InvalidArgumentException('Invalid API version.');
        }
        $this->token = is_string($token) ? new AccessToken($token) : $token;
        $this->sleep = Closure::fromCallable($sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1000000));
        });
    }

    public function sendMessage(int $userId, string $message, array $options = []): int
    {
        $allowed = ['peer_id', 'random_id', 'attachment', 'keyboard', 'disable_mentions'];
        if ($userId <= 0 || array_diff(array_keys($options), $allowed)) {
            throw new InvalidArgumentException('Invalid recipient or unsupported message option.');
        }
        $peer = array_key_exists('peer_id', $options) ? $options['peer_id'] : $userId;
        $random = array_key_exists('random_id', $options) ? $options['random_id'] : random_int(1, 2147483647);
        if (!is_int($peer) || $peer <= 0) {
            throw new InvalidArgumentException('peer_id must be positive.');
        }
        if (!is_int($random) || $random === 0 || $random < -2147483648 || $random > 2147483647) {
            throw new InvalidArgumentException('random_id must be a nonzero int32.');
        }
        if (array_key_exists('attachment', $options) && !is_string($options['attachment'])) {
            throw new InvalidArgumentException('attachment must be a string.');
        }
        if (trim($message) === '' && trim($options['attachment'] ?? '') === '') {
            throw new InvalidArgumentException('Message or attachment is required.');
        }
        if (array_key_exists('disable_mentions', $options)) {
            if (!in_array($options['disable_mentions'], [true, false, 0, 1], true)) {
                throw new InvalidArgumentException('disable_mentions must be boolean or 0/1.');
            }
            $options['disable_mentions'] = (int) $options['disable_mentions'];
        }
        if (array_key_exists('keyboard', $options)) {
            try {
                $keyboard = $options['keyboard'];
                if (is_array($keyboard)) {
                    $keyboard = json_encode($keyboard, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                }
                if (!is_string($keyboard) || !is_object(json_decode($keyboard, false, 512, JSON_THROW_ON_ERROR))) {
                    throw new InvalidArgumentException('keyboard must be a JSON object.');
                }
                $options['keyboard'] = $keyboard;
            } catch (JsonException) {
                throw new InvalidArgumentException('keyboard contains invalid JSON.');
            }
        }
        $parameters = array_merge($options, ['peer_id' => $peer, 'message' => $message, 'random_id' => $random]);
        $result = $this->request('messages.send', $parameters);
        if (!is_int($result) || $result <= 0) {
            throw new UnexpectedResponseException('messages.send must return a positive message ID.');
        }
        return $result;
    }

    public function getUser(int $userId): User
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('userId must be positive.');
        }
        $result = $this->request('users.get', ['user_ids' => $userId, 'fields' => 'photo_100']);
        if (!is_array($result) || !array_is_list($result)) {
            throw new UnexpectedResponseException('users.get must return a list.');
        }
        if ($result === []) {
            throw new UserNotFoundException('User not found.');
        }
        $user = $result[0];
        if (
            !is_array($user) || !is_int($user['id'] ?? null) || $user['id'] <= 0
            || !is_string($user['first_name'] ?? null) || !is_string($user['last_name'] ?? null)
            || (isset($user['photo_100']) && !is_string($user['photo_100']))
        ) {
            throw new UnexpectedResponseException('Invalid user response.');
        }
        return new User($user['id'], $user['first_name'], $user['last_name'], $user['photo_100'] ?? null);
    }

    public function getDialogs(int $offset = 0, int $count = 10): DialogCollection
    {
        if ($offset < 0 || $count < 1 || $count > 200) {
            throw new InvalidArgumentException('Expected offset >= 0 and count between 1 and 200.');
        }
        $result = $this->request('messages.getConversations', ['offset' => $offset, 'count' => $count]);
        if (
            !is_array($result) || !is_int($result['count'] ?? null) || $result['count'] < 0
            || !is_array($result['items'] ?? null) || !array_is_list($result['items'])
        ) {
            throw new UnexpectedResponseException('Invalid conversations response.');
        }
        $dialogs = [];
        foreach ($result['items'] as $item) {
            if (!is_array($item) || !is_array($item['conversation'] ?? null)) {
                throw new UnexpectedResponseException('Invalid conversation object.');
            }
            $conversation = $item['conversation'];
            $peer = $conversation['peer'] ?? null;
            if (!is_array($peer) || (isset($item['last_message']) && !is_array($item['last_message']))) {
                throw new UnexpectedResponseException('Invalid dialog fields.');
            }
            $unread = $conversation['unread_count'] ?? 0;
            $last = $item['last_message']['text'] ?? null;
            $type = is_string($peer['type'] ?? null) ? DialogType::tryFrom($peer['type']) : null;
            if (
                !is_int($peer['id'] ?? null) || $type === null
                || !is_int($unread) || $unread < 0 || ($last !== null && !is_string($last))
            ) {
                throw new UnexpectedResponseException('Invalid dialog response.');
            }
            $dialogs[] = new Dialog($peer['id'], $type, $unread, $last);
        }
        return new DialogCollection($result['count'], $dialogs);
    }

    private function request(string $method, array $parameters): mixed
    {
        $parameters['access_token'] = $this->token->value();
        $parameters['v'] = $this->version;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = $this->http->post('https://api.vk.com/method/' . $method, $parameters);
            } catch (NetworkException) {
                if ($attempt === 2) {
                    throw new NetworkException('HTTP request failed after three attempts.');
                }
                $this->pause($attempt);
                continue;
            }
            if (in_array($response->status, [420, 429], true) || $response->status >= 500) {
                if ($attempt === 2) {
                    throw $this->apiError($method, $response->status, 'Temporary HTTP failure.');
                }
                $this->pause($attempt, $response);
                continue;
            }
            if ($response->status !== 200) {
                throw $this->apiError($method, $response->status, 'HTTP request rejected.');
            }
            try {
                $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new UnexpectedResponseException('VK returned invalid JSON.');
            }
            if (!is_array($data)) {
                throw new UnexpectedResponseException('Expected a JSON object.');
            }
            if (isset($data['error'])) {
                $error = $data['error'];
                if (
                    !is_array($error) || !is_int($error['error_code'] ?? null)
                    || !is_string($error['error_msg'] ?? null)
                ) {
                    throw new UnexpectedResponseException('Invalid API error response.');
                }
                if (in_array($error['error_code'], [6, 10, 29], true) && $attempt < 2) {
                    $this->pause($attempt, $response);
                    continue;
                }
                throw $this->apiError($method, $error['error_code'], $error['error_msg']);
            }
            if (!array_key_exists('response', $data)) {
                throw new UnexpectedResponseException('Missing response field.');
            }
            return $data['response'];
        }
        throw new NetworkException('Request failed.');
    }

    private function apiError(string $method, int $code, string $message): ApiException
    {
        $class = match ($code) {
            5, 27, 28, 401 => AuthenticationException::class,
            7, 15, 188, 403, 901 => AccessDeniedException::class,
            6, 29, 420, 429 => RateLimitException::class,
            default => ApiException::class,
        };
        return new $class($method, $code, str_replace($this->token->value(), '[REDACTED]', $message));
    }

    private function pause(int $attempt, ?HttpResponse $response = null): void
    {
        $delay = 0.5 * 2 ** $attempt;
        $header = $response?->header('Retry-After');
        if ($header !== null) {
            $retry = ctype_digit($header) ? (int) $header : strtotime($header) - time();
            $delay = max($delay, min(30, $retry));
        }
        ($this->sleep)($delay);
    }
}
