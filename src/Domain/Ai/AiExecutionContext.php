<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use Symfony\Component\Uid\Uuid;

/** One logical task; callers retain this object for every attempt. */
final readonly class AiExecutionContext
{
    public string $id;
    public function __construct(?string $id = null) { $this->id = $id ?? Uuid::v4()->toRfc4122(); }
}
