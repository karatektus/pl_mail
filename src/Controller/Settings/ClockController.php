<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\User\ClockFormat;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings/clock', name: 'app_settings_clock_')]
#[IsGranted('ROLE_USER')]
final class ClockController extends AbstractController
{
    /**
     * Redirects rather than answering with a stream, for the same reason the
     * locale and timezone forms do: the choice reaches every `|date` on every
     * page through the `clock` global, so there is no fragment small enough to
     * be worth swapping.
     */
    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (false === $this->isCsrfTokenValid('settings-clock', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $posted = (string) $request->request->get('clock');

        // The empty option is "follow the interface language" — the state a
        // user who has never chosen is already in, and a valid submission
        // rather than a bad one. Stored as null so it keeps following a later
        // language change; see ClockFormatResolver::chosen().
        if ('' !== $posted && null === ClockFormat::tryFrom($posted)) {
            throw $this->createNotFoundException('Unknown clock format.');
        }

        $user->setSetting(User::SETTING_CLOCK, '' === $posted ? null : $posted);

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'general']);
    }
}
