<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use App\Service\MessageList\MessageListRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Saves the message list settings, one at a time, as they are picked on
 * Settings → Inbox → Message list (#608).
 *
 * Not Appearance's endpoint, though the page works the same way: these are
 * not part of a theme, and that endpoint's payload is what a theme export is
 * made of.
 *
 * Answers with the attributes and properties the layout would now write on
 * <html>, so the page applies exactly what a reload would rather than its own
 * idea of it. The page also sets the one attribute the click changed before
 * the answer arrives, so the list follows the click without waiting for it.
 */
#[IsGranted('ROLE_USER')]
final class MessageListController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageListRenderer    $renderer,
    ) {
    }

    #[Route('/settings/message-list/save', name: 'app_message_list_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        // The layout's meta-tag token, sent as X-CSRF-Token, like Appearance's.
        $this->assertCsrf($request, 'ajax');

        /** @var User $user */
        $user = $this->getUser();

        $user->messageList->applyArray($request->toArray());
        $this->entityManager->flush();

        return $this->json([
            'settings'   => $user->messageList->toArray(),
            'attributes' => $this->renderer->attributes($user->messageList),
            'style'      => $this->renderer->cssVariables($user->messageList),
        ]);
    }
}
