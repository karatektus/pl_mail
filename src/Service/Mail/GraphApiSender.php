<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\SendFailure;
use App\Domain\Exception\GraphApiException;
use App\Domain\Exception\GraphThrottledException;
use App\Domain\Exception\SendDeferredException;
use App\Domain\Exception\SendFailureException;
use App\Domain\Exception\SendOutcomeUnknownException;
use App\Domain\Exception\SendRefusedException;
use App\Domain\Helper\ApiMime;
use App\Domain\Helper\SendTransportFailure;
use App\Domain\Interface\MailDeliveryInterface;
use App\Domain\Interface\SentCopyLookupInterface;
use App\Entity\Mail\Account;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Send path for Microsoft accounts: POST the RFC822 message to Graph
 * /me/sendMail as base64 MIME. Covered by the Mail.Send scope, and Graph files
 * the Sent Items copy itself — no SMTP, no manual IMAP append.
 *
 * Posting raw MIME rather than Graph's JSON message shape is deliberate: it
 * reuses the exact MIME that MessageSendService already builds for SMTP and
 * Gmail, so there is one outgoing-message code path rather than three.
 *
 * Graph's answer is classified into what the next attempt should do (#125),
 * the same split as GmailApiSender: 429 and 503 (GraphThrottledException) and
 * other 5xx are tried again, 502/504 and a network failure mid-request may
 * have delivered the mail, any other 4xx is a refusal.
 */
class GraphApiSender implements MailDeliveryInterface, SentCopyLookupInterface
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
        if (AuthType::OAuth2->value !== $account->authType) {
            return false;
        }

        if (MailProvider::Microsoft->value !== $account->oauthProvider) {
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
        try {
            $this->deliver($email, $account);
        } catch (\Throwable $e) {
            // Every failure, as before: the callers of send() — invitations,
            // alerts, read receipts — want a yes or a no and nothing thrown.
            // deliver() has logged what it classified.
            if (false === $e instanceof SendFailureException) {
                $this->logger->error('GraphApiSender: send failed', [
                    'account'   => $account->id,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }

            return false;
        }

        return true;
    }

    public function deliver(Email $email, Account $account): void
    {
        // Not $email->toString(): that drops Bcc, and Graph reads the
        // recipients from the MIME. See ApiMime.
        $mime = ApiMime::toString($email);

        if (strlen($mime) > self::MAX_MIME_BYTES) {
            $this->logger->error('GraphApiSender: message exceeds the 4MB raw-MIME send limit', [
                'account' => $account->id,
                'bytes'   => strlen($mime),
            ]);

            throw new SendRefusedException('The message is larger than the 4 MB Outlook accepts.', SendFailure::Refused);
        }

        try {
            $this->apiClient->sendMime($account, $mime);
        } catch (GraphThrottledException $e) {
            $this->logger->warning('GraphApiSender: Outlook is limiting sends; trying again', $this->context($account, $e));

            throw new SendDeferredException($e->getMessage(), SendFailure::RateLimited, $e->getRetryAfterSeconds(), $e);
        } catch (GraphApiException $e) {
            $status = $e->getStatus();

            if (502 === $status || 504 === $status) {
                $this->logger->error('GraphApiSender: a gateway lost the answer to a send', $this->context($account, $e));

                throw new SendOutcomeUnknownException($e->getMessage(), $e);
            }

            if ($status >= 500) {
                $this->logger->warning('GraphApiSender: Outlook failed on its side; trying again', $this->context($account, $e));

                throw new SendDeferredException($e->getMessage(), SendFailure::Unavailable, null, $e);
            }

            $this->logger->error('GraphApiSender: send refused', $this->context($account, $e));

            throw new SendRefusedException($e->getMessage(), SendFailure::Refused, $e);
        } catch (TransportExceptionInterface $e) {
            if (true === SendTransportFailure::beforeRequest($e)) {
                $this->logger->warning('GraphApiSender: Outlook could not be reached; trying again', $this->context($account, $e));

                throw new SendDeferredException($e->getMessage(), SendFailure::Unavailable, null, $e);
            }

            $this->logger->error('GraphApiSender: the answer to a send was lost', $this->context($account, $e));

            throw new SendOutcomeUnknownException($e->getMessage(), $e);
        }
    }

    public function isInSent(Account $account, string $messageId): bool
    {
        return $this->apiClient->hasSentMessage($account, $messageId);
    }

    /** @return array<string, mixed> */
    private function context(Account $account, \Throwable $e): array
    {
        return ['account' => $account->id, 'error' => $e->getMessage(), 'exception' => $e];
    }
}
