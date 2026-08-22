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
 * User-level compose behaviour: whether a forward opens with its quoted
 * original folded behind the pill, and what pressing Send does to the screen.
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

        // Each control posts its own form, so a request carries one of these
        // and not the other. Reading a key that is not there and writing the
        // default back would be harmless for the boolean and wrong for the
        // radio — an absent `sendFeedback` would be stored as the default and
        // silently undo the other setting's choice on every save.
        if (true === $request->request->has('forwardQuoteCollapsed')) {
            $folded = '1' === (string) $request->request->get('forwardQuoteCollapsed');

            // Stored only when the answer is "open": the absent key means the
            // default, the way every setting in this bag reads.
            $user->setSetting(
                User::SETTING_COMPOSE_FORWARD_QUOTE_COLLAPSED,
                $folded ? null : false,
            );
        }

        if (true === $request->request->has('sendFeedback')) {
            $hold = User::SEND_FEEDBACK_HOLD === (string) $request->request->get('sendFeedback');

            // Same rule: null for the default, which is optimistic. An unknown
            // value therefore lands on the default rather than being stored,
            // so a hand-written POST cannot put the setting into a state
            // nothing renders.
            $user->setSetting(
                User::SETTING_COMPOSE_SEND_FEEDBACK,
                $hold ? User::SEND_FEEDBACK_HOLD : null,
            );
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'general']);
    }
}
