<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Helper\TimezoneHelper;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings/timezone', name: 'app_settings_timezone_')]
#[IsGranted('ROLE_USER')]
final class TimezoneController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Redirects rather than answering with a stream, for the same reason the
     * locale form does: the zone is applied to `|date` for the whole request by
     * TwigTimezoneSubscriber, so every timestamp on every page has to be drawn
     * again — there is no fragment small enough to be worth swapping.
     */
    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (false === $this->isCsrfTokenValid('settings-timezone', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $timezone = (string) $request->request->get('timezone');

        // The empty option is "follow the server default", which is what null
        // means in the column — so it is a valid submission, not a bad one.
        if ('' !== $timezone && false === TimezoneHelper::isKnown($timezone)) {
            throw $this->createNotFoundException('Unknown timezone.');
        }

        $user
            ->setTimezone('' === $timezone ? null : $timezone)
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'general']);
    }
}
