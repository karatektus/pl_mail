<?php

namespace App\Twig;

use App\Domain\Enum\MailboxSpecialUse;
use App\Repository\MessageThreadRepository;
use Symfony\Bundle\SecurityBundle\Security;

class SidebarCounts
{
    private ?array $specialUseCounts = null;
    private ?int $starredCount = null;

    public function __construct(
        private MessageThreadRepository $threadRepository,
        private Security                $security,
    ) {}

    public function forSpecialUse(MailboxSpecialUse $specialUse): int
    {
        $value = array_find($this->getSpecialUseCounts(), fn(array $value) => $value['specialUse'] === $specialUse);

        return $value['unreadCount'] ?? 0;
    }

    public function forStarred(): int
    {
        if ($this->starredCount === null) {
            $user = $this->security->getUser();

            if ($user === null) {
                $this->starredCount = 0;
            } else {
                $this->starredCount = $this->threadRepository->countUnreadForStarred($user);
            }
        }

        return $this->starredCount;
    }

    private function getSpecialUseCounts(): array
    {
        if ($this->specialUseCounts === null) {
            $user = $this->security->getUser();

            if ($user === null) {
                $this->specialUseCounts = [];
            } else {
                $this->specialUseCounts = $this->threadRepository->countUnreadPerSpecialUse($user);
            }
        }

        return $this->specialUseCounts;
    }
}
