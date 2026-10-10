<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Job\BackgroundJob;
use App\Domain\DTO\Mail\ImportLine;
use App\Entity\User\User;
use App\Repository\Job\BackgroundJobRepository;
use App\Service\Mail\ImportProgress;
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
        private readonly ImportProgress          $imports,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('background_jobs', $this->forCurrentUser(...)),
            new TwigFunction('mail_imports', $this->importsForCurrentUser(...)),
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

    /**
     * The accounts of the person looking that are still importing — shown in
     * the same indicator as the jobs above, and for the same reason asked
     * here rather than fetched by the page. See ImportProgress.
     *
     * @return list<ImportLine>
     */
    public function importsForCurrentUser(): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->imports->forUser($user) : [];
    }
}
