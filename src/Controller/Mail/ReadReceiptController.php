<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\ReadReceiptPolicy;
use App\Service\Mail\ReadReceiptSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The "ask each time" prompt's two buttons.
 *
 * Only the Ask mode reaches a controller at all. Never sends nothing and draws
 * no prompt; Always fires from the read transition through the bus without a
 * person in the loop. This is the middle case, and it exists because the middle
 * case is the honest default for a request that might be a colleague wanting to
 * know their mail arrived and might be someone confirming the address is live.
 *
 * Sent SYNCHRONOUSLY here, unlike the automatic path. The two have opposite
 * requirements: the automatic one fires while a mailbox is being rendered and
 * must never block it, while this one is a button a person pressed and is
 * waiting on, and a confirmation that returns "done" before anything has been
 * attempted is a confirmation that can be wrong. The transports are the same
 * either way — see ReadReceiptSender.
 */
#[Route('/mail/message/{id}/read-receipt', name: 'app_mail_read_receipt_')]
#[IsGranted('ROLE_USER')]
final class ReadReceiptController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    public function __construct(
        private readonly ReadReceiptPolicy      $policy,
        private readonly ReadReceiptSender      $sender,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/send', name: 'send', methods: ['POST'])]
    public function send(Request $request, Message $message): Response
    {
        $this->assertOwned($message);
        $this->assertToken($request, $message);

        $decision = $this->policy->decide($message);

        // Re-decided rather than trusted from the rendered page. The prompt may
        // have been sitting in an open tab since before the alias was switched
        // to "never", and a POST is not evidence the setting still allows it.
        if (true === $decision->isSendable()) {
            $this->sender->send($message, $decision);
        }

        return $this->respond($message);
    }

    /**
     * "No thanks."
     *
     * Clears the request rather than remembering the refusal, because there is
     * nothing to remember: the request was per-message, it has been answered,
     * and the prompt must not come back the next time this conversation is
     * opened. It writes the same field a successful send writes, which is what
     * makes declining and sending equally final.
     */
    #[Route('/dismiss', name: 'dismiss', methods: ['POST'])]
    public function dismiss(Request $request, Message $message): Response
    {
        $this->assertOwned($message);
        $this->assertToken($request, $message);

        $message->readReceiptRequested = false;
        $this->em->flush();

        return $this->respond($message);
    }

    /**
     * Both buttons re-render the same region, which after either of them holds
     * no prompt — the flag they wrote is what the partial reads.
     */
    private function respond(Message $message): Response
    {
        return $this->renderTurboStream('mail/_read_receipt.stream.html.twig', [
            'message' => $message,
        ]);
    }

    /**
     * Per-action and per-message, so a token minted for one message cannot
     * answer another's prompt.
     */
    private function assertToken(Request $request, Message $message): void
    {
        $this->assertCsrf($request, 'read-receipt' . $message->id);
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
