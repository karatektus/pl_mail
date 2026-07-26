<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Message;
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
#[Route('/mail/message/{id}', name: 'app_mail_message_')]
#[IsGranted('ROLE_USER')]
final class MessageSourceController extends AbstractController
{
    #[Route('/original', name: 'original', methods: ['GET'])]
    public function original(Message $message): Response
    {
        $this->assertOwnership($message);

        return $this->render('mail/original.html.twig', [
            'message' => $message,
            'source'  => $this->buildSource($message),
            'auth'    => $this->authenticationResults($message),
        ]);
    }

    #[Route('/print', name: 'print', methods: ['GET'])]
    public function print(Message $message): Response
    {
        $this->assertOwnership($message);

        return $this->render('mail/print.html.twig', [
            'message' => $message,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * "Key: value" header lines, a blank line, then the body — i.e. the shape
     * of the original message as far as we can reproduce it.
     */
    private function buildSource(Message $message): string
    {
        $lines = [];

        foreach ($message->getHeaders() ?? [] as $key => $value) {
            foreach (true === is_array($value) ? $value : [$value] as $single) {
                $lines[] = $key.': '.$single;
            }
        }

        $body = $message->getBodyText();

        if (null === $body || '' === $body) {
            $body = $message->getBodyHtml() ?? '';
        }

        return implode("\n", $lines)."\n\n".$body;
    }

    /**
     * SPF / DKIM / DMARC verdicts, parsed out of Authentication-Results when
     * the provider recorded it.
     *
     * @return array<string, string>
     */
    private function authenticationResults(Message $message): array
    {
        $headers = $message->getHeaders() ?? [];
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

    /**
     * Gmail/Graph messages have no mailbox — the thread carries the account.
     */
    private function assertOwnership(Message $message): void
    {
        $mailbox = $message->getMailbox();
        $account = null !== $mailbox
            ? $mailbox->getAccount()
            : $message->getThread()?->getAccount();

        if (false === $account instanceof Account || $account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }
}
