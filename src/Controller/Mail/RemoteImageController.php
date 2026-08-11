<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\RendersTurboStreams;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\TrustedImageSenderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Always show images from this sender."
 *
 * The other half of the bar — plain "Show images" — never reaches the server.
 * It cannot: the blocked body already carries every proxy URL it would need, so
 * unblocking one message is a message posted into the reading frame and nothing
 * more. Only the DURABLE choice is a request, because only the durable choice
 * is a change to anything.
 */
#[IsGranted('ROLE_USER')]
final class RemoteImageController extends AbstractController
{
    use RendersTurboStreams;

    public function __construct(
        private readonly TrustedImageSenderRepository $trustedSenders,
    ) {}

    #[Route('/mail/images/trust/{id}', name: 'app_mail_images_trust', methods: ['POST'])]
    public function trust(Message $message): Response
    {
        $user = $this->assertOwned($message);

        $this->trustedSenders->trust($user, $message->fromAddress);

        return $this->renderTurboStream('mail/_message_body.stream.html.twig', [
            'message' => $message,
        ]);
    }

    #[Route('/mail/images/distrust/{id}', name: 'app_mail_images_distrust', methods: ['POST'])]
    public function distrust(Message $message): Response
    {
        $user = $this->assertOwned($message);

        $this->trustedSenders->distrust($user, $message->fromAddress);

        return $this->renderTurboStream('mail/_message_body.stream.html.twig', [
            'message' => $message,
        ]);
    }

    /**
     * Same reasoning as AttachmentController::assertOwned — never via the
     * mailbox, which Gmail and Graph accounts do not have. The message carries
     * the account and the account carries the user.
     */
    private function assertOwned(Message $message): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User || $message->account->usr !== $user) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
