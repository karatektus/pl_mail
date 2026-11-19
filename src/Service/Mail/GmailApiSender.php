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
use App\Domain\Helper\MessageIdHelper;
use App\Domain\Helper\SendTransportFailure;
use App\Domain\Interface\MailDeliveryInterface;
use App\Domain\Interface\SentCopyLookupInterface;
use App\Entity\Mail\Account;
use App\Repository\Mail\Message\MessageThreadingRepository;
use App\Service\Mail\Mime\SealedEntityPart;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Future-proof send path for Google accounts: POST the RFC822 message to the
 * Gmail API. Covered by the https://mail.google.com/ scope we already hold, and
 * Gmail files the Sent copy itself — no SMTP, no manual IMAP append.
 *
 * Except for a message the browser built (signed or encrypted): the API
 * rewrites every message it sends, and that breaks OpenPGP, so those go out
 * over Gmail's SMTP with the same token instead. See GmailSmtpDelivery (#651).
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
        private readonly GmailApiClient             $client,
        private readonly GmailSmtpDelivery          $smtp,
        private readonly LoggerInterface            $logger,
        private readonly MessageThreadingRepository $threading,
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
        // Byte for byte or not at all, and the API does not do byte for byte:
        // it parses what it is given and writes a new message (#651). Decided
        // by the body rather than by a flag from the caller, because the body
        // is the thing that must not change — whatever builds a sealed part
        // in future gets the same path without having to know about this.
        if ($email->getBody() instanceof SealedEntityPart) {
            $this->smtp->deliver($email, $account);

            return;
        }

        // Not $email->toString(): that drops Bcc, and Gmail reads the
        // recipients from the raw message. See ApiMime.
        $raw = $this->toBase64Url(ApiMime::toString($email));

        // Ahead of the try: the catches below sort out what Gmail answered,
        // and a lookup in plMail's own database is none of that.
        $threadId = $this->threadIdFor($email, $account);

        try {
            $this->sendInThread($account, $raw, $threadId);
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

    /**
     * Send into the thread, or without one when Gmail does not know it.
     *
     * The id comes from plMail's own copy of the conversation, and Gmail may
     * have dropped the thread since: every message in it deleted there, say.
     * It then refuses the whole send, 404 or 400, and the reply would be
     * marked failed for the sake of the conversation it was filed in. Sent
     * again without the id, it goes out and starts a thread, as it always
     * did. Both answers mean nothing went out, so this cannot send twice.
     */
    private function sendInThread(Account $account, string $raw, ?string $threadId): void
    {
        try {
            $this->client->sendRaw($account, $raw, $threadId);
        } catch (GmailApiException $e) {
            if (null === $threadId || false === in_array($e->getStatus(), [400, 404], true)) {
                throw $e;
            }

            $this->logger->warning('GmailApiSender: Gmail does not know the thread; sending without it', $this->context($account, $e));

            $this->client->sendRaw($account, $raw);
        }
    }

    /**
     * The Gmail thread a reply belongs in, from the mail it answers (#676).
     *
     * Read off the outgoing mail's own In-Reply-To and References rather than
     * handed in by the caller: they are what says which conversation this is,
     * for Gmail as much as for the recipient, and every way into deliver()
     * writes them. Without the id Gmail filed each reply sent from plMail in a
     * new thread, however right its headers were.
     *
     * Null for new mail, and for a reply to mail plMail has no Gmail thread
     * for (one that has not been synced, or lives on another account). Gmail
     * then starts a thread, which is all it could do anyway.
     *
     * Not on the SMTP path above: SMTP has no field for it, and Gmail threads
     * mail sent that way from its headers as it does mail it receives.
     */
    private function threadIdFor(Email $email, Account $account): ?string
    {
        $headers = $email->getHeaders();

        $answered = MessageIdHelper::normaliseList(array_merge(
            MessageIdHelper::normaliseList($headers->get('In-Reply-To')?->getBodyAsString()),
            MessageIdHelper::normaliseList($headers->get('References')?->getBodyAsString()),
        ));

        return $this->threading->findProviderThreadKeyForReply($answered, $account);
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
