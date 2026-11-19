<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Conversations or single emails (#615), switched from two places: the list's
 * sort menu (and its `t` key), which flips it and goes back to the list it
 * was pressed on, and Settings → Inbox → Message list, which sets it.
 *
 * A form post and a redirect, so the switch needs no script: the list drawn
 * after the redirect is the other kind, because every list reads the setting
 * when it is drawn (App\Service\Mail\MailLists).
 */
#[IsGranted('ROLE_USER')]
final class ConversationViewController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/conversation-view', name: 'app_settings_conversation_view', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'conversation-view');

        $form = $request->request;

        // Sent: Settings says which. Not sent: the list's switch, which
        // flips whatever is set now.
        $user->conversationView = true === $form->has('conversations')
            ? '1' === $form->getString('conversations')
            : false === $user->conversationView;

        $em->flush();

        return $this->redirect($this->back($form->getString('back')), Response::HTTP_SEE_OTHER);
    }

    /**
     * Where to go afterwards: the page the switch was pressed on, or the
     * setting's own card. Only a path on this site — a full URL or a
     * protocol-relative `//host` would make this an open redirect.
     */
    private function back(string $back): string
    {
        if (true === str_starts_with($back, '/') && false === str_starts_with($back, '//') && false === str_contains($back, '\\')) {
            return $back;
        }

        return $this->generateUrl('app_settings_page', ['page' => 'message-list']) . '#card-conversation-view';
    }
}
