<?php

declare(strict_types=1);

namespace App\Domain\DTO\Task;

use App\Domain\Enum\Task\TaskView;

/**
 * Which part of the tasks the pane is showing, as it travels in its URL.
 *
 * One object for reading it and for writing it back, because every link and form
 * in the pane has to keep the list when it changes the view, and the other way round. A save answers
 * with this too, rebuilt through the router, so nothing posts a URL back to be
 * trusted.
 */
final readonly class TaskPaneQuery
{
    public function __construct(
        public TaskView   $view = TaskView::MyDay,
        public ?int       $listId = null,
    ) {}

    /**
     * Anything unknown falls back to the default rather than refusing: this is a
     * bookmarkable address, and a stale one should still open the pane.
     *
     * @param array<string, mixed> $parameters
     */
    public static function fromParameters(array $parameters): self
    {
        $listId = (int) ($parameters['list'] ?? 0);

        return new self(
            TaskView::tryFrom((string) ($parameters['view'] ?? '')) ?? TaskView::MyDay,
            $listId > 0 ? $listId : null,
        );
    }

    /** @return array<string, int|string> what path() needs, with the defaults left out */
    public function toParameters(): array
    {
        $parameters = [];

        if (TaskView::MyDay !== $this->view) {
            $parameters['view'] = $this->view->value;
        }

        if (null !== $this->listId) {
            $parameters['list'] = $this->listId;
        }

        return $parameters;
    }

    public function withList(?int $listId): self
    {
        return new self(TaskView::Lists, $listId);
    }

    public function myDay(): self
    {
        return new self(TaskView::MyDay);
    }
}
