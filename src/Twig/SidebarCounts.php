<?php

namespace App\Twig;

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

    public function forSpecialUse(string $specialUse): int
    {
        $counts = $this->getSpecialUseCounts();

        if (isset($counts[$specialUse])) {
            return $counts[$specialUse];
        }

        return 0;
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
