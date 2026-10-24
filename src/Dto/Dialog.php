<?php

declare(strict_types=1);

namespace VkPet\Dto;

final class Dialog
{
    public function __construct(
        public readonly int $peerId,
        public readonly DialogType $type,
        public readonly int $unreadCount,
        public readonly ?string $lastMessage
    ) {
    }
}
