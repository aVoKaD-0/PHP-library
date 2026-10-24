# VK API client для PHP

Я сделал типизированную библиотеку для отправки сообщений, получения диалогов и пользователей. Я использовал PHP 8.1+, PSR-12 и PSR-4; HTTP-клиент и токен передаю через конструктор.

## Установка

В папке библиотеки я устанавливаю зависимости так:

```bash
composer install
```

Я назвал пакет `vk-pet/vk-client`, версия — `0.1.0`. В Packagist я его пока не публиковал. Для подключения в другой локальный проект я использую Composer path repository:

```bash
composer config repositories.vk-client path '/путь/к/PHP-библиотека'
composer require 'vk-pet/vk-client:^0.1'
```

Мне нужны PHP 8.1+ и расширения cURL/JSON. Для PHPUnit также нужны DOM, XML, XMLWriter и mbstring; в Dockerfile я установил их вместе с Composer и PCOV.

## Использование

Я создаю токен в настройках API своего сообщества с правом работы с сообщениями и передаю его через переменную окружения `VK_TOKEN`. В `.env.example` я оставил только имена настроек: библиотека сама `.env` не загружает. В `VK_USER_ID` я указываю ID своего тестового аккаунта, разрешившего сообщения сообщества.

```php
use VkPet\AccessToken;
use VkPet\Http\CurlHttpClient;
use VkPet\VKClient;

require 'vendor/autoload.php';

$client = new VKClient(new CurlHttpClient(), new AccessToken(getenv('VK_TOKEN') ?: ''));
$userId = (int) getenv('VK_USER_ID');
$user = $client->getUser($userId);
$messageId = $client->sendMessage($user->id, 'Ваш промокод: BOOK2026', ['random_id' => 71]);

foreach ($client->getDialogs() as $dialog) {
    echo $dialog->peerId . ': ' . ($dialog->lastMessage ?? '') . PHP_EOL;
}
```

Я использую новый `random_id` для новой операции и сохраняю прежний ID при её повторе. Внутренние повторы библиотека выполняет с теми же параметрами. Для беседы я передаю `options['peer_id']`, например `2000000001`. Ещё я поддержал `attachment`, `keyboard` (массив или JSON-объект) и `disable_mentions`.

Я сохранил имя `getDialogs` из задания; внутри вызываю `messages.getConversations`. По умолчанию я использую API 5.199, структуры ответов сверил с [официальной схемой VK](https://github.com/VKCOM/vk-api-schema/tree/master/messages).

## Публичный API

| Объект | Методы и данные |
|---|---|
| `VKClient` | `__construct(http, token, version = '5.199', sleep = null)`; `sendMessage(int, string, array = []): int`; `getDialogs(int = 0, int = 10): DialogCollection`; `getUser(int): User` |
| `AccessToken` | `__construct(string)`; `value(): string`; `__debugInfo()` скрывает токен |
| `CurlHttpClient` | `__construct(connectTimeout = 5, timeout = 15)`; `post(string, array): HttpResponse` |
| `HttpResponse` | `__construct(status, body, headers = [])`; `header(string): ?string`; поля `status`, `body`, `headers` |
| `User` | Конструктор и readonly-поля `id`, `firstName`, `lastName`, `avatarUrl` |
| `Dialog` | Конструктор и readonly-поля `peerId`, `type`, `unreadCount`, `lastMessage`; `DialogType`: user/chat/group |
| `DialogCollection` | `__construct(total, dialogs)`; `items(): array`; `count(): int`; `getIterator(): Traversable`; readonly `total` |

Я разрешил передавать собственную реализацию `HttpClientInterface::post`. При сетевом сбое она должна выбрасывать `NetworkException`.

## Ошибки и повторы

Я различаю `AuthenticationException`, `AccessDeniedException`, `RateLimitException`, `ApiException`, `NetworkException`, `UnexpectedResponseException` и `UserNotFoundException`. Ошибки API наследуют `ApiException` с кодом и именем метода; остальные — общий `VKException`. Неверные аргументы вызывают `InvalidArgumentException` до HTTP-запроса. Я обработал код 188 как отказ доступа по условию задания.

Я повторяю сетевые сбои, HTTP 420/429/5xx и временные ошибки VK 6/10/29: всего три попытки, паузы 0,5 и 1 секунда. `Retry-After` учитываю с пределом 30 секунд. Ошибки доступа и авторизации не повторяю. Токен передаю в теле POST, скрываю при отладке `AccessToken` и удаляю из текста исключений API.

## Тесты

```bash
composer test
composer check
composer coverage
```

Я прогнал 69 тестов на PHP 8.1.34: 199 проверок, покрытие строк — 97,02%. PSR-12 проходит без нарушений.

Я проверил успешные ответы, диалоги и пагинацию, ошибки доступа и сети, лимиты, `Retry-After`, неизменность `random_id`, неверные аргументы и повреждённые ответы. В транспортных тестах я запускаю локальный HTTP-сервер и отправляю настоящие cURL-запросы с кириллицей и спецсимволами. Реальный VK API и секретный токен этим тестам не нужны.

Я подготовил проверку на PHP 8.1 в контейнере:

```bash
docker build -t vk-client-test .
docker run --rm vk-client-test composer test
docker run --rm vk-client-test composer check
docker run --rm vk-client-test composer coverage
```

Я добавил `examples/basic.php`: `php examples/basic.php <свой VK ID>` получает пользователя и диалоги, не отправляя сообщение.
