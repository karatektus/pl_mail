<?php

declare(strict_types=1);

namespace App\Domain\DTO\Task;

/** One task list a source can see, whether or not plMail mirrors it. */
final readonly class RemoteTaskList
{
    public function __construct(
        public string  $remoteId,
        public string  $name,
        public ?string $color = null,
        public bool    $isReadOnly = false,
        public bool    $isDefault = false,
    ) {}
}
