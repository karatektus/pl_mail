<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\MailProvider;
use App\Domain\Interface\MailSenderInterface;
use App\Entity\Account;
use App\Enum\AuthType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;

/**
 * Send path for Microsoft accounts: POST the RFC822 message to Graph
 * /me/sendMail as base64 MIME. Covered by the Mail.Send scope, and Graph files
 * the Sent Items copy itself — no SMTP, no manual IMAP append.
 *
 * Posting raw MIME rather than Graph's JSON message shape is deliberate: it
 * reuses the exact MIME that MessageSendService already builds for SMTP and
 * Gmail, so there is one outgoing-message code path rather than three.
 */
class GraphApiSender implements MailSenderInterface
{
    /**
     * Graph rejects a raw-MIME /sendMail body above 4MB. Larger messages need
     * createDraft + an upload session per attachment + send, which is not yet
     * implemented — sending fails cleanly rather than silently truncating.
     */
    private const int MAX_MIME_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly GraphApiClient  $apiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Account $account): bool
    {
        if (AuthType::OAuth2->value !== $account->getAuthType()) {
            return false;
        }

        if (MailProvider::Microsoft->value !== $account->getOauthProvider()) {
            return false;
        }

        return true;
    }

    public function filesSentCopy(): bool
    {
        return true;
    }

    public function send(Email $email, Account $account): bool
    {
        $mime = $email->toString();

        if (strlen($mime) > self::MAX_MIME_BYTES) {
            $this->logger->error('GraphApiSender: message exceeds the 4MB raw-MIME send limit', [
                'account' => $account->getId(),
                'bytes'   => strlen($mime),
            ]);

            return false;
        }

        try {
            $this->apiClient->sendMime($account, $mime);
        } catch (\Throwable $e) {
            $this->logger->error('GraphApiSender: send failed', [
                'account' => $account->getId(),
                'error'   => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
