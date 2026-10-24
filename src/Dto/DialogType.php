<?php

declare(strict_types=1);

namespace VkPet\Dto;

enum DialogType: string
{
    case User = 'user';
    case Chat = 'chat';
    case Group = 'group';
}
