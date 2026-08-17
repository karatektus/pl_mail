<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Entity\Mail\Message;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\MessageSourceBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Standalone "show original" and "print" views for a single message, opened
 * in a new tab from the per-message menu in the thread view.
 *
 * No raw RFC822 blob is stored, so the source view is reconstructed from the
 * persisted header map plus the decoded body.
 */
#[Route('/mail/message/{id}', name: 'app_mail_message_', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class MessageSourceController extends AbstractController
{
    public function __construct(
        private readonly MessageSourceBuilder $sourceBuilder,
    ) {}

    #[Route('/original', name: 'original', methods: ['GET'])]
    public function original(Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        return $this->render('mail/original.html.twig', [
            'message' => $message,
            'source'  => $this->sourceBuilder->build($message),
            'auth'    => $this->authenticationResults($message),
        ]);
    }

    #[Route('/print', name: 'print', methods: ['GET'])]
    public function print(Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        return $this->render('mail/print.html.twig', [
            'message' => $message,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * SPF / DKIM / DMARC verdicts, parsed out of Authentication-Results when
     * the provider recorded it.
     *
     * @return array<string, string>
     */
    private function authenticationResults(Message $message): array
    {
        $headers = $message->headers ?? [];
        $raw     = null;

        foreach ($headers as $key => $value) {
            if ('authentication-results' === strtolower((string) $key)) {
                $raw = true === is_array($value) ? implode(' ', $value) : (string) $value;

                break;
            }
        }

        if (null === $raw) {
            return [];
        }

        $results = [];

        foreach (['spf', 'dkim', 'dmarc'] as $mechanism) {
            if (1 === preg_match('/\b'.$mechanism.'=([a-z]+)/i', $raw, $matches)) {
                $results[strtoupper($mechanism)] = strtolower($matches[1]);
            }
        }

        return $results;
    }

}
