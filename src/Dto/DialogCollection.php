<?php

declare(strict_types=1);

namespace VkPet\Dto;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

final class DialogCollection implements IteratorAggregate, Countable
{
    public function __construct(public readonly int $total, private array $dialogs)
    {
        if ($total < 0) {
            throw new InvalidArgumentException('Total must not be negative.');
        }
        foreach ($dialogs as $dialog) {
            if (!$dialog instanceof Dialog) {
                throw new InvalidArgumentException('Expected Dialog objects.');
            }
        }
    }

    public function items(): array
    {
        return $this->dialogs;
    }

    public function count(): int
    {
        return count($this->dialogs);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->dialogs);
    }
}
