<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\ConnectionTestResult;
use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Helper\MailServerHost;
use App\Entity\Mail\Account;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private readonly TranslatorInterface   $translator,
        private readonly LoggerInterface       $logger,
    ) {
    }

    public function test(Account $account): ConnectionTestResult
    {
        [$imapOk, $imapMessage] = $this->probeImap($account);
        [$smtpOk, $smtpMessage] = $this->probeSmtp($account);

        return new ConnectionTestResult(
            $imapOk,
            $imapMessage,
            $this->target($account->imapHost, $account->imapPort, $account->imapEncryption),
            $smtpOk,
            $smtpMessage,
            $this->target($account->smtpHost, $account->smtpPort, $account->smtpEncryption),
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

            return [true, $this->translator->trans('account.test.result.imap_ok', ['%count%' => count($folders)])];
        } catch (Throwable $e) {
            return [false, $this->describe($e, $account, 'imap', $account->imapEncryption, $account->imapPort)];
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
        $host = $account->smtpHost;

        if (null === $host || '' === trim($host)) {
            return [null, $this->translator->trans('account.test.result.smtp_none')];
        }

        $transport = null;

        try {
            $transport = Transport::fromDsn($this->dsnFactory->forAccount($account));

            if (false === $transport instanceof SmtpTransport) {
                return [false, $this->translator->trans('account.test.result.failed')];
            }

            $stream = $transport->getStream();

            if (true === $stream instanceof SocketStream) {
                $stream->setTimeout(self::TIMEOUT_SECONDS);
            }

            // start() performs the full connect + EHLO + TLS + AUTH handshake,
            // which is exactly what we want to verify.
            $transport->start();

            return [true, $this->translator->trans('account.test.result.smtp_ok')];
        } catch (Throwable $e) {
            return [false, $this->describe($e, $account, 'smtp', $account->smtpEncryption, $account->smtpPort)];
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
     * A category the user can act on, never the server's own words.
     *
     * This used to hand back the whole exception chain, which is the remote
     * server talking: its banner, its software and version, the resolved
     * address, and whatever else it chose to say — echoed to anyone who could
     * type a host into the form, which made the tester a banner grabber for any
     * address the server can reach. The chain still matters to whoever fixes
     * the problem, so it goes to the log (redacted), where an admin can read it.
     *
     * Webklex wraps the real cause in a generic ConnectionFailedException, and
     * Symfony's mailer nests transport exceptions the same way — so the useful
     * signal is one or two levels down, and the whole chain is what is sorted.
     */
    private function describe(Throwable $e, Account $account, string $protocol, ?string $encryption, ?int $port): string
    {
        $parts   = [];
        $current = $e;
        $depth   = 0;

        while (null !== $current && $depth < 4) {
            $parts[] = $current::class . ': ' . trim($current->getMessage());
            $current = $current->getPrevious();
            $depth++;
        }

        $chain = $this->dsnFactory->redact(implode(' — ', $parts), $account);

        $this->logger->info('Connection test failed', ['protocol' => $protocol, 'detail' => $chain]);

        $message = $this->translator->trans('account.test.result.' . $this->category($chain, $account, $protocol));
        $hint    = $this->portHint($encryption, $port);

        return null === $hint ? $message : $message . ' ' . $hint;
    }

    /**
     * Sorted by the words the libraries use, most specific first: a failed
     * certificate check also mentions the socket it failed on, and a refused
     * login also mentions the connection it was refused over.
     */
    private function category(string $chain, Account $account, string $protocol): string
    {
        $host = 'imap' === $protocol ? $account->imapHost : $account->smtpHost;

        if (false === MailServerHost::isValid($host)) {
            return 'host_invalid';
        }

        $text = strtolower($chain);

        return match (true) {
            $this->mentions($text, ['timed out', 'timeout'])                                                   => 'timeout',
            $this->mentions($text, ['certificate', 'ssl operation', 'crypto', 'handshake', 'openssl'])         => 'tls',
            $this->mentions($text, ['auth', 'login', 'credential', 'password', '535 ', 'invalid user'])         => 'auth',
            $this->mentions($text, ['getaddrinfo', 'name or service', 'resolve', 'refused', 'unable to connect',
                'no route', 'unreachable', 'could not be established', 'connection failed', 'setup failed'])                   => 'unreachable',
            default                                                                                          => 'failed',
        };
    }

    /**
     * @param list<string> $needles
     */
    private function mentions(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (true === str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
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
            return $this->translator->trans('account.test.hint.expects_starttls', ['%port%' => $port]);
        }

        if ('ssl' !== $normalised && in_array($port, [465, 993], true)) {
            return $this->translator->trans('account.test.hint.expects_ssl', ['%port%' => $port]);
        }

        if ('none' === $normalised) {
            return $this->translator->trans('account.test.hint.no_encryption');
        }

        return null;
    }
}
