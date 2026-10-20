<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\SendFailure;
use App\Domain\Exception\SendDeferredException;
use App\Domain\Exception\SendFailureException;
use App\Domain\Exception\SendOutcomeUnknownException;
use App\Domain\Exception\SendRefusedException;
use App\Domain\Interface\MailDeliveryInterface;
use App\Entity\Mail\Account;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * SMTP sending for generic password accounts. OAuth accounts are handled by a
 * provider-API sender instead, so this deliberately declines them.
 *
 * A failure is classified by what the server last said (#125). Symfony's
 * mailer puts the SMTP reply code on the exception:
 *
 *   4xx   the server declined for now (421 busy, 450 greylisted, 452 full) —
 *         nothing was accepted, try again;
 *   5xx   the server refused (550 no such user, 552 too large, 535 bad
 *         password) — sending it unchanged will not work;
 *   none  no reply at all. A connection that could not be established sent
 *         nothing; any other silence — a timeout, a dropped connection — may
 *         have come after the server took the mail, and SMTP cannot be asked
 *         afterwards, so it is reported as possibly sent rather than sent
 *         again. That also catches a connection lost early in the dialogue,
 *         which a resend would have been safe for: the cautious answer costs a
 *         manual resend, the other one a duplicate.
 */
class SmtpMailSender implements MailDeliveryInterface
{
    private const string NEVER_CONNECTED = 'Connection could not be established';

    public function __construct(
        private readonly SmtpDsnFactory  $dsnFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(Account $account): bool
    {
        if (AuthType::OAuth2->value === $account->authType) {
            return false;
        }

        return true;
    }

    public function filesSentCopy(): bool
    {
        return false;
    }

    public function send(Email $email, Account $account): bool
    {
        try {
            $this->deliver($email, $account);
        } catch (SendFailureException) {
            return false;
        }

        return true;
    }

    public function deliver(Email $email, Account $account): void
    {
        try {
            $mailer = new Mailer(Transport::fromDsn($this->dsnFactory->forAccount($account)));
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw $this->classify($account, $e);
        }
    }

    /**
     * What a mailer failure means for the next attempt. Public so the rule can
     * be tested without an SMTP server to fail against.
     */
    public function classify(Account $account, TransportExceptionInterface $e): SendFailureException
    {
        $code    = (int) $e->getCode();
        $context = ['account' => $account->id, 'code' => $code, 'error' => $e->getMessage(), 'exception' => $e];

        if ($code >= 400 && $code < 500) {
            $this->logger->warning('SmtpMailSender: the server declined for now; trying again', $context);

            return new SendDeferredException($e->getMessage(), SendFailure::Unavailable, null, $e);
        }

        if ($code >= 500 && $code < 600) {
            $this->logger->error('SmtpMailSender: send refused', $context);

            return new SendRefusedException($e->getMessage(), SendFailure::Refused, $e);
        }

        if (true === str_starts_with($e->getMessage(), self::NEVER_CONNECTED)) {
            $this->logger->warning('SmtpMailSender: the server could not be reached; trying again', $context);

            return new SendDeferredException($e->getMessage(), SendFailure::Unavailable, null, $e);
        }

        $this->logger->error('SmtpMailSender: the connection was lost during a send', $context);

        return new SendOutcomeUnknownException($e->getMessage(), $e);
    }
}
