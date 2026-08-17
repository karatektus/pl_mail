<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\TrustedImageSenderRepository;
use App\Security\Voter\OwnershipVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
 *
 * Which is also why these two carry a CSRF token and the other half needs
 * none: trusting a sender is a durable, per-user decision about whose images
 * load without asking, and a cross-site POST that made it silently would turn
 * every later message from that sender into a tracking pixel the reader had
 * never agreed to. Per-message ids, so a token minted for one message cannot
 * answer another's bar.
 */
#[IsGranted('ROLE_USER')]
final class RemoteImageController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    public function __construct(
        private readonly TrustedImageSenderRepository $trustedSenders,
    ) {}

    #[Route('/mail/images/trust/{id}', name: 'app_mail_images_trust', methods: ['POST'])]
    public function trust(Request $request, Message $message): Response
    {
        $user = $this->assertOwned($message);
        $this->assertCsrf($request, 'images-trust' . $message->id);

        $this->trustedSenders->trust($user, $message->fromAddress);

        return $this->renderTurboStream('mail/_message_body.stream.html.twig', [
            'message' => $message,
        ]);
    }

    #[Route('/mail/images/distrust/{id}', name: 'app_mail_images_distrust', methods: ['POST'])]
    public function distrust(Request $request, Message $message): Response
    {
        $user = $this->assertOwned($message);
        $this->assertCsrf($request, 'images-distrust' . $message->id);

        $this->trustedSenders->distrust($user, $message->fromAddress);

        return $this->renderTurboStream('mail/_message_body.stream.html.twig', [
            'message' => $message,
        ]);
    }

    /**
     * Authorises, and hands back the user the caller needs anyway.
     *
     * The comparison is the voter's now; what is left is the narrowing. The
     * voter has already refused anything but a User on an owned subject, so by
     * the time getUser() is read here it cannot be null — the instanceof is for
     * the type checker and for the day somebody calls this without the line
     * above it.
     */
    private function assertOwned(Message $message): User
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
