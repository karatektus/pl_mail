<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User-level compose behaviour — today, one question: does a forward open
 * with its quoted original folded behind the pill, or laid out in full?
 *
 * A sibling of ClockController rather than a route on ComposeDefaults,
 * because that controller is per-ACCOUNT (read receipts, keyed into the
 * account's settings bag) and this is about the person: how someone likes
 * their compose window does not change with the mailbox that sends.
 *
 * Stored only when the answer is "open" — the absent key means the default
 * (folded), the way every setting in the user's bag reads.
 */
#[Route('/settings/compose-behavior', name: 'app_settings_compose_behavior_')]
#[IsGranted('ROLE_USER')]
final class ComposeBehaviorController extends AbstractController
{
    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (false === $this->isCsrfTokenValid('settings-compose-behavior', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $folded = '1' === (string) $request->request->get('forwardQuoteCollapsed');

        $user->setSetting(
            User::SETTING_COMPOSE_FORWARD_QUOTE_COLLAPSED,
            $folded ? null : false,
        );

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'general']);
    }
}
