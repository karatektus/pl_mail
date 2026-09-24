<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\System\UpdateChannel;
use App\Entity\System\UpdateCheck;
use App\Repository\System\UpdateCheckRepository;
use App\Service\System\Update\ImageRegistry;
use App\Service\System\Update\UpdateChecker;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Updates: which channel this installation follows, what the last check
 * of it found, and a button to check now.
 *
 * Rendered into its own Turbo Frame, like the other admin sections, and every
 * action answers with the frame again: the answer to "check now" IS the
 * re-rendered panel.
 */
#[Route('/admin/updates', name: 'app_admin_updates_')]
#[IsGranted('ROLE_ADMIN')]
final class UpdatesController extends AbstractController
{
    use ChecksCsrf;

    /**
     * A manual check this soon after the last one shows the stored answer
     * instead. GitHub's anonymous API allows sixty requests an hour per address,
     * and a button pressed repeatedly should not spend them.
     */
    private const int RECHECK_AFTER_SECONDS = 30;

    public function __construct(
        private readonly UpdateCheckRepository  $checks,
        private readonly UpdateChecker          $checker,
        private readonly ImageRegistry          $registry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * One action for GET and both forms, as the rest of the admin area does it.
     * A form submitted inside a Turbo Frame leaves the frame's src at the URL
     * it posted to, so an action with a URL of its own is one a later reload of
     * the frame would GET, and a POST-only route answers that with a 405. Here
     * every submission lands on the panel's own URL.
     *
     * `do=check` checks now; anything else posted is the channel form.
     */
    #[Route('', name: 'panel', methods: ['GET', 'POST'])]
    public function panel(Request $request): Response
    {
        $check = $this->checks->currentOrNew();

        if (false === $request->isMethod('POST')) {
            return $this->frame($check);
        }

        $this->assertCsrf($request, 'admin_updates');

        if ('check' === $request->request->get('do')) {
            return $this->frame($this->checkNow($check));
        }

        $channel = UpdateChannel::tryFrom((string) $request->request->get('channel'));

        // An unknown value is ignored rather than defaulted: "keep what I had"
        // is the only answer that cannot lose a choice.
        if (null === $channel) {
            return $this->frame($check);
        }

        // A new channel is checked at once: the answer on the page was about
        // the old one.
        $changed        = $this->checker->channel($check) !== $channel;
        $check->channel = $channel;

        $this->entityManager->persist($check);
        $this->entityManager->flush();

        return $this->frame(true === $changed ? $this->checker->check() : $check, saved: true);
    }

    private function checkNow(UpdateCheck $check): UpdateCheck
    {
        $recent = new DateTimeImmutable(sprintf('-%d seconds', self::RECHECK_AFTER_SECONDS));

        if (null !== $check->checkedAt && $check->checkedAt >= $recent) {
            return $check;
        }

        return $this->checker->check();
    }

    private function frame(UpdateCheck $check, bool $saved = false): Response
    {
        return $this->render('admin/updates/_frame.html.twig', [
            'check'   => $check,
            'channel' => $this->checker->channel($check),
            // Whether an administrator chose it, or it follows the build.
            'chosen'  => null !== $check->channel,
            'status'  => $this->checker->status($check),
            'image'   => $this->registry->image(),
            'saved'   => $saved,
        ]);
    }
}
