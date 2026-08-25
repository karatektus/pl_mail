<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Job\BackgroundJob;
use App\Repository\Job\BackgroundJobRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * This user's background work, for the topbar indicator.
 *
 * A Twig function rather than a controller-rendered frame with a `src`, because
 * a frame that fetches itself costs one request per page load for every user to
 * answer "nothing is happening" — which is the answer almost every time, and
 * which broke the appearance preview's assertion that it loads no mail.
 *
 * The query is indexed on (usr_id, state) and returns at most twenty rows, so
 * it is cheaper than the request it replaces by a wide margin. The route still
 * exists: mail--jobs points the frame at it the moment something actually
 * happens, which is the only time it is worth asking.
 */
final class BackgroundJobsExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security                $security,
        private readonly BackgroundJobRepository $jobs,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('background_jobs', $this->forCurrentUser(...)),
        ];
    }

    /** @return list<BackgroundJob> */
    public function forCurrentUser(): array
    {
        $user = $this->security->getUser();

        if (null === $user) {
            return [];
        }

        return $this->jobs->findVisibleForUser($user);
    }
}
