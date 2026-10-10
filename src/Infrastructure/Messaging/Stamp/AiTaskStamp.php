<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class AiTaskStamp implements StampInterface
{
    public function __construct(public string $id) {}
}
