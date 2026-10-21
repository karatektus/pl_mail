<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\SendFailure;
use App\Domain\Exception\GmailApiException;
use App\Domain\Exception\GmailPermanentException;
use App\Domain\Exception\GmailThrottledException;
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
 * Future-proof send path for Google accounts: POST the RFC822 message to the
 * Gmail API. Covered by the https://mail.google.com/ scope we already hold, and
 * Gmail files the Sent copy itself — no SMTP, no manual IMAP append.
 *
 * Through GmailApiClient, so Gmail's answer is read the way every other Gmail
 * call reads it — a quota 403 is a quota, not a permissions failure — and
 * classified here into what the next attempt should do (#125):
 *
 *   429, a 403 for quota, a 5xx other than 502/504   deferred: try again
 *   a connection that never opened                  deferred: nothing was sent
 *   any other 4xx, or a permanent 403               refused: will not work
 *   502, 504, or a network failure mid-request      unknown: may have been sent
 *
 * 502 and 504 are a gateway in front of Gmail saying it lost Gmail's answer,
 * which is the timeout case wearing a status code.
 */
class GmailApiSender implements MailDeliveryInterface, SentCopyLookupInterface
{
    public function __construct(
        private readonly GmailApiClient  $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Account $account): bool
    {
        if (AuthType::OAuth2->value !== $account->authType) {
            return false;
        }

        if (MailProvider::Google->value !== $account->oauthProvider) {
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
        } catch (SendFailureException) {
            // Logged where it was classified.
            return false;
        }

        return true;
    }

    public function deliver(Email $email, Account $account): void
    {
        // Not $email->toString(): that drops Bcc, and Gmail reads the
        // recipients from the raw message. See ApiMime.
        $raw = $this->toBase64Url(ApiMime::toString($email));

        try {
            $this->client->sendRaw($account, $raw);
        } catch (GmailThrottledException $e) {
            $this->logger->warning('GmailApiSender: Gmail is limiting sends; trying again', $this->context($account, $e));

            throw new SendDeferredException($e->getMessage(), SendFailure::RateLimited, $e->getRetryAfterSeconds(), $e);
        } catch (GmailPermanentException $e) {
            $this->logger->error('GmailApiSender: send refused', $this->context($account, $e));

            throw new SendRefusedException($e->getMessage(), SendFailure::Refused, $e);
        } catch (GmailApiException $e) {
            throw $this->classifyStatus($account, $e);
        } catch (TransportExceptionInterface $e) {
            if (true === SendTransportFailure::beforeRequest($e)) {
                $this->logger->warning('GmailApiSender: Gmail could not be reached; trying again', $this->context($account, $e));

                throw new SendDeferredException($e->getMessage(), SendFailure::Unavailable, null, $e);
            }

            $this->logger->error('GmailApiSender: the answer to a send was lost', $this->context($account, $e));

            throw new SendOutcomeUnknownException($e->getMessage(), $e);
        }
    }

    public function isInSent(Account $account, string $messageId): bool
    {
        return $this->client->hasSentMessage($account, $messageId);
    }

    private function classifyStatus(Account $account, GmailApiException $e): SendFailureException
    {
        $status = $e->getStatus();

        if (502 === $status || 504 === $status) {
            $this->logger->error('GmailApiSender: a gateway lost the answer to a send', $this->context($account, $e));

            return new SendOutcomeUnknownException($e->getMessage(), $e);
        }

        if ($status >= 500) {
            $this->logger->warning('GmailApiSender: Gmail failed on its side; trying again', $this->context($account, $e));

            return new SendDeferredException($e->getMessage(), SendFailure::Unavailable, null, $e);
        }

        $this->logger->error('GmailApiSender: send refused', $this->context($account, $e));

        return new SendRefusedException($e->getMessage(), SendFailure::Refused, $e);
    }

    /** @return array<string, mixed> */
    private function context(Account $account, \Throwable $e): array
    {
        return ['account' => $account->id, 'error' => $e->getMessage(), 'exception' => $e];
    }

    /**
     * Gmail requires URL-safe base64 (RFC 4648 §5) without padding.
     */
    private function toBase64Url(string $mime): string
    {
        $base64  = base64_encode($mime);
        $urlSafe = strtr($base64, '+/', '-_');

        return rtrim($urlSafe, '=');
    }
}
