<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\ConnectionTestResult;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Account;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Throwable;

/**
 * Probes an account's IMAP and SMTP settings without persisting anything.
 *
 * The account passed in may be a transient entity built from unsaved form
 * input — nothing here touches the entity manager.
 */
final class ConnectionTester
{
    private const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly ImapConnectionFactory $imapFactory,
        private readonly SmtpDsnFactory        $dsnFactory,
    ) {
    }

    public function test(Account $account): ConnectionTestResult
    {
        [$imapOk, $imapMessage] = $this->probeImap($account);
        [$smtpOk, $smtpMessage] = $this->probeSmtp($account);

        return new ConnectionTestResult(
            $imapOk,
            $imapMessage,
            $this->target($account->getImapHost(), $account->getImapPort(), $account->getImapEncryption()),
            $smtpOk,
            $smtpMessage,
            $this->target($account->getSmtpHost(), $account->getSmtpPort(), $account->getSmtpEncryption()),
        );
    }

    /**
     * Human-readable echo of the settings actually used, so a failure never
     * requires guessing which values reached the probe.
     */
    private function target(?string $host, ?int $port, ?string $encryption): string
    {
        if (null === $host || '' === trim($host)) {
            return '—';
        }

        return sprintf('%s:%d (%s)', $host, (int) $port, $encryption ?? 'unset');
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function probeImap(Account $account): array
    {
        $client = null;

        try {
            $client  = $this->imapFactory->connect($account, self::TIMEOUT_SECONDS);
            $folders = $client->getFolders(false);

            return [true, sprintf('Connected — %d folders visible.', count($folders))];
        } catch (Throwable $e) {
            return [false, $this->describe($e, $account, $account->getImapEncryption(), $account->getImapPort())];
        } finally {
            if (null !== $client) {
                try {
                    $client->disconnect();
                } catch (Throwable) {
                    // Nothing useful to do — the probe result already stands.
                }
            }
        }
    }

    /**
     * @return array{0: bool|null, 1: string}
     */
    private function probeSmtp(Account $account): array
    {
        $host = $account->getSmtpHost();

        if (null === $host || '' === trim($host)) {
            return [null, 'No SMTP host configured — sending is disabled for this account.'];
        }

        $transport = null;

        try {
            $transport = Transport::fromDsn($this->dsnFactory->forAccount($account));

            if (false === $transport instanceof SmtpTransport) {
                return [false, 'Resolved transport is not SMTP.'];
            }

            $stream = $transport->getStream();

            if (true === $stream instanceof SocketStream) {
                $stream->setTimeout(self::TIMEOUT_SECONDS);
            }

            // start() performs the full connect + EHLO + TLS + AUTH handshake,
            // which is exactly what we want to verify.
            $transport->start();

            return [true, 'Connected and authenticated.'];
        } catch (Throwable $e) {
            return [false, $this->describe($e, $account, $account->getSmtpEncryption(), $account->getSmtpPort())];
        } finally {
            if (true === $transport instanceof SmtpTransport) {
                try {
                    $transport->stop();
                } catch (Throwable) {
                    // Ditto.
                }
            }
        }
    }

    /**
     * Webklex wraps the real cause in a generic ConnectionFailedException, and
     * Symfony's mailer nests transport exceptions the same way — so the useful
     * detail is always one or two levels down the chain.
     */
    private function describe(Throwable $e, Account $account, ?string $encryption, ?int $port): string
    {
        $parts   = [];
        $current = $e;
        $depth   = 0;

        while (null !== $current && $depth < 4) {
            $message = trim($current->getMessage());

            if ('' !== $message && false === in_array($message, $parts, true)) {
                $parts[] = $message;
            }

            $current = $current->getPrevious();
            $depth++;
        }

        if (count($parts) === 0) {
            $parts[] = $e::class;
        }

        $hint = $this->portHint($encryption, $port);

        if (null !== $hint) {
            $parts[] = $hint;
        }

        return $this->dsnFactory->redact(implode(' — ', $parts), $account);
    }

    /**
     * Appended only on failure: a plausible explanation rather than a hard
     * block, since unusual-but-valid port/encryption pairings do exist.
     */
    private function portHint(?string $encryption, ?int $port): ?string
    {
        if (null === $encryption || null === $port) {
            return null;
        }

        $normalised = strtolower($encryption);

        if ('ssl' === $normalised && in_array($port, [587, 143], true)) {
            return sprintf('Hint: port %d normally expects STARTTLS, not implicit SSL/TLS.', $port);
        }

        if ('ssl' !== $normalised && in_array($port, [465, 993], true)) {
            return sprintf('Hint: port %d normally expects implicit SSL/TLS.', $port);
        }

        if ('none' === $normalised) {
            return 'Hint: encryption is set to None — most providers require SSL/TLS or STARTTLS.';
        }

        return null;
    }
}
