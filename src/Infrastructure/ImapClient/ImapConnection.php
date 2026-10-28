<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

use App\Domain\Interface\DeadlineClosable;
use App\Infrastructure\ImapClient\Exception\ImapAuthenticationException;
use App\Infrastructure\ImapClient\Exception\ImapCommandException;
use App\Infrastructure\ImapClient\Exception\ImapConnectionException;
use App\Infrastructure\ImapClient\Exception\ImapException;
use App\Infrastructure\ImapClient\Exception\ImapProtocolException;
use App\Infrastructure\ImapClient\Exception\ImapTimeoutException;

/**
 * One conversation with an IMAP server: open, secured, logged in, and then
 * one command at a time.
 *
 * Written as a blocking client and run as a non-blocking one. Every wait goes
 * through the ImapLoop, so inside a task on that loop a slow server suspends
 * only its own task, and outside one the loop runs until the wait is over.
 * See ImapLoop.
 *
 * ONE COMMAND AT A TIME
 * ─────────────────────
 * IMAP allows several commands in flight on one connection. This does not:
 * the commands plMail sends depend on each other's answers, and pipelining
 * would make every caller reason about responses interleaving. A second
 * command started while one is running is a bug and is refused as one.
 *
 * WHAT A FAILURE LEAVES
 * ─────────────────────
 * A command the server refuses (NO, BAD) leaves the connection usable. A
 * timeout, a protocol error or a closed socket leaves it closed: once an
 * answer is missing or unreadable, the two sides no longer agree on where the
 * conversation is, and nothing said after that can be trusted.
 */
final class ImapConnection implements DeadlineClosable
{
    public const float DEFAULT_TIMEOUT_SECONDS = 30.0;

    /** How long a LOGOUT is given on the way out. Not worth waiting longer for. */
    private const float LOGOUT_SECONDS = 2.0;

    private readonly ImapResponseReader $reader;

    private int $sequence = 0;

    /** Set while a command, IDLE included, is waiting for its completion. */
    private bool $busy = false;

    /** What the server said when it said BYE, for the error when it then hangs up. */
    private ?string $farewell = null;

    /** @var list<string> upper-cased */
    public private(set) array $capabilities = [];

    public private(set) bool $isAuthenticated = false;

    public private(set) bool $isClosed = false;

    private function __construct(
        private readonly ImapSocket $socket,
        public readonly ImapEndpoint $endpoint,
        public readonly float $timeoutSeconds,
    ) {
        $this->reader = new ImapResponseReader();
    }

    /**
     * Connect, secure the connection as the endpoint asks, and read the
     * greeting. The result is ready to authenticate, or already
     * authenticated if the server greeted with PREAUTH.
     */
    public static function open(ImapLoop $loop, ImapEndpoint $endpoint, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): self
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $socket   = ImapSocket::connect($loop, $endpoint->host, $endpoint->port, $deadline);

        try {
            if (ImapSecurity::Tls === $endpoint->security) {
                $socket->enableTls($endpoint->host, $endpoint->verifyCertificate, $deadline);
            }

            $connection = new self($socket, $endpoint, $timeoutSeconds);
            $connection->greet($deadline);

            if (ImapSecurity::StartTls === $endpoint->security) {
                $connection->startTls($deadline);
            }

            return $connection;
        } catch (\Throwable $e) {
            $socket->close();

            throw $e;
        }
    }

    public function supports(string $capability): bool
    {
        return true === in_array(strtoupper($capability), $this->capabilities, true);
    }

    /**
     * Log in.
     *
     * @throws ImapAuthenticationException when the server refuses the credentials
     */
    public function authenticate(ImapCredentials $credentials): void
    {
        if (true === $this->isAuthenticated) {
            return;
        }

        try {
            $result = match ($credentials->mechanism) {
                ImapAuthMechanism::Login   => $this->login($credentials),
                ImapAuthMechanism::XOAuth2 => $this->xoauth2($credentials),
            };
        } catch (ImapCommandException $e) {
            throw new ImapAuthenticationException($e->command, $e->response);
        }

        $this->isAuthenticated = true;

        // Capabilities change at login — IDLE, MOVE and the extensions that
        // matter are often only announced afterwards — and a server may send
        // the new list with its OK rather than wait to be asked.
        $this->capabilities = null !== $result->code('CAPABILITY')
            ? self::capabilityList($result->code('CAPABILITY'))
            : $this->fetchCapabilities();
    }

    /**
     * Send one command and wait for it to finish.
     *
     * @throws ImapCommandException    when the server answers NO or BAD; the connection is still usable
     * @throws ImapConnectionException when the connection fails, times out or loses the thread; it is closed
     */
    public function command(string $name, ImapArgument ...$arguments): ImapResult
    {
        return $this->execute($name, $arguments, null, microtime(true) + $this->timeoutSeconds);
    }

    /** Open a folder, read-write, or read-only with $readOnly (EXAMINE). */
    public function select(string $mailbox, bool $readOnly = false): ImapResult
    {
        return $this->command(true === $readOnly ? 'EXAMINE' : 'SELECT', ImapArgument::string($mailbox));
    }

    /**
     * Start IDLE: from now until done(), the server tells this connection
     * about changes as they happen. See ImapIdle.
     */
    public function idle(): ImapIdle
    {
        if (false === $this->supports('IDLE')) {
            throw new ImapException(sprintf('%s does not support IDLE.', $this->endpoint->host));
        }

        $this->claim();

        $tag      = $this->nextTag();
        $deadline = microtime(true) + $this->timeoutSeconds;
        $early    = [];

        try {
            $this->write(sprintf("%s IDLE\r\n", $tag), $deadline);

            while (true) {
                $response = $this->awaitResponse($deadline, 'IDLE');

                if (ImapResponseKind::Continuation === $response->kind) {
                    return new ImapIdle($this, $tag, $early);
                }

                if (true === $response->completes($tag)) {
                    throw new ImapCommandException('IDLE', $response);
                }

                $early[] = $response;
            }
        } catch (\Throwable $e) {
            $this->busy = false;

            throw $e;
        }
    }

    /**
     * Say goodbye and close. Best effort: the connection is closed afterwards
     * whatever the server made of it.
     */
    public function logout(): void
    {
        if (true === $this->isClosed) {
            return;
        }

        try {
            if (false === $this->busy) {
                $this->execute('LOGOUT', [], null, microtime(true) + self::LOGOUT_SECONDS);
            }
        } catch (ImapException) {
            // Leaving anyway.
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        $this->isClosed = true;
        $this->busy     = false;
        $this->socket->close();
    }

    public function closeForDeadline(): void
    {
        $this->socket->shutDown();
    }

    public function isOpen(): bool
    {
        return false === $this->isClosed && true === $this->socket->isOpen();
    }

    // ---------------------------------------------------------------- for ImapIdle

    /**
     * The next response, waiting until $deadline for it.
     *
     * @internal for ImapIdle, which reads outside a command
     *
     * @return ImapResponse|null null when the deadline passed first
     */
    public function readResponse(float $deadline): ?ImapResponse
    {
        while (true) {
            $response = $this->reader->next();

            if (null !== $response) {
                if ('BYE' === $response->status && ImapResponseKind::Untagged === $response->kind) {
                    $this->farewell = $response->text;
                }

                return $response;
            }

            try {
                $bytes = $this->socket->read($deadline);
            } catch (ImapConnectionException $e) {
                $this->close();

                throw null === $this->farewell
                    ? $e
                    : new ImapConnectionException(sprintf('%s said goodbye: %s', $this->endpoint->host, $this->farewell), 0, $e);
            }

            if ('' === $bytes) {
                return null;
            }

            $this->reader->feed($bytes);
        }
    }

    /**
     * A response already read off the socket, without waiting for more.
     *
     * @internal for ImapIdle
     */
    public function bufferedResponse(): ?ImapResponse
    {
        return $this->reader->next();
    }

    /** @internal for ImapIdle */
    public function write(string $bytes, float $deadline): void
    {
        try {
            $this->socket->write($bytes, $deadline);
        } catch (ImapConnectionException $e) {
            $this->close();

            throw $e;
        }
    }

    /** @internal for ImapIdle, when its IDLE has ended */
    public function release(): void
    {
        $this->busy = false;
    }

    // ---------------------------------------------------------------- the conversation

    private function greet(float $deadline): void
    {
        $greeting = $this->awaitResponse($deadline, 'the greeting');

        if (ImapResponseKind::Untagged !== $greeting->kind || false === in_array($greeting->status, ['OK', 'PREAUTH'], true)) {
            throw new ImapConnectionException(sprintf('%s refused the connection: %s', $this->endpoint->host, trim($greeting->raw)));
        }

        $this->isAuthenticated = 'PREAUTH' === $greeting->status;

        $this->capabilities = 'CAPABILITY' === $greeting->code
            ? self::capabilityList($greeting->codeData)
            : $this->fetchCapabilities();
    }

    /**
     * Upgrade to TLS. Never falls back: a server that does not offer STARTTLS
     * on a connection meant to use it is not spoken to in the clear.
     */
    private function startTls(float $deadline): void
    {
        if (false === $this->supports('STARTTLS')) {
            throw new ImapConnectionException(sprintf('%s does not offer STARTTLS.', $this->endpoint->host));
        }

        $this->execute('STARTTLS', [], null, $deadline);

        // Anything already read past the OK came before TLS and is the
        // classic STARTTLS injection: text a man in the middle slipped in to
        // be read as if the encrypted server had said it.
        if (true === $this->reader->hasPartial()) {
            throw new ImapProtocolException(sprintf('%s sent data after agreeing to STARTTLS.', $this->endpoint->host));
        }

        $this->socket->enableTls($this->endpoint->host, $this->endpoint->verifyCertificate, $deadline);

        // What the server said before TLS cannot be trusted either.
        $this->capabilities = $this->fetchCapabilities();
    }

    private function login(ImapCredentials $credentials): ImapResult
    {
        if (true === $this->supports('LOGINDISABLED')) {
            throw new ImapException(sprintf('%s does not allow LOGIN on this connection.', $this->endpoint->host));
        }

        return $this->command('LOGIN', ImapArgument::string($credentials->username), ImapArgument::string($credentials->secret));
    }

    /**
     * SASL XOAUTH2, as Google and Microsoft define it.
     *
     * With SASL-IR the token goes with the command. Without it the server
     * asks first, with an empty continuation. A refused token comes back as a
     * continuation too — base64 JSON saying why — which has to be answered
     * with an empty line before the server will send its NO.
     */
    private function xoauth2(ImapCredentials $credentials): ImapResult
    {
        $payload = base64_encode(sprintf("user=%s\x01auth=Bearer %s\x01\x01", $credentials->username, $credentials->secret));
        $sent    = $this->supports('SASL-IR');

        $arguments = [ImapArgument::atom('XOAUTH2')];

        if (true === $sent) {
            $arguments[] = ImapArgument::atom($payload);
        }

        return $this->execute(
            'AUTHENTICATE',
            $arguments,
            static function () use (&$sent, $payload): string {
                if (false === $sent) {
                    $sent = true;

                    return $payload;
                }

                return '';
            },
            microtime(true) + $this->timeoutSeconds,
        );
    }

    /** @return list<string> */
    private function fetchCapabilities(): array
    {
        $result = $this->command('CAPABILITY');

        return self::capabilityList($result->untagged('CAPABILITY')[0]->data ?? []);
    }

    /**
     * Run a command to its end.
     *
     * @param list<ImapArgument>                $arguments
     * @param (callable(ImapResponse): string)|null $onContinuation the line to send when
     *                                                              the server asks for more
     */
    private function execute(string $name, array $arguments, ?callable $onContinuation, float $deadline): ImapResult
    {
        $this->claim();

        try {
            $tag     = $this->nextTag();
            $command = new ImapCommandBuffer(sprintf('%s %s', $tag, $name));

            foreach ($arguments as $argument) {
                $command->append(' ');
                $argument->appendTo($command, $this->supports('LITERAL+'));
            }

            $pieces = $command->pieces();

            $untagged = [];

            foreach ($pieces as $index => $piece) {
                $this->write($piece, $deadline);

                if ($index === array_key_last($pieces)) {
                    break;
                }

                // A synchronising literal: the rest waits for the server's go.
                while (true) {
                    $response = $this->awaitResponse($deadline, $name);

                    if (ImapResponseKind::Continuation === $response->kind) {
                        break;
                    }

                    if (true === $response->completes($tag)) {
                        return $this->finish($name, $response, $untagged);
                    }

                    $untagged[] = $response;
                }
            }

            while (true) {
                $response = $this->awaitResponse($deadline, $name);

                if (true === $response->completes($tag)) {
                    return $this->finish($name, $response, $untagged);
                }

                if (ImapResponseKind::Continuation === $response->kind) {
                    if (null === $onContinuation) {
                        throw new ImapProtocolException(sprintf('%s asked for more of a %s command.', $this->endpoint->host, $name));
                    }

                    $this->write($onContinuation($response) . "\r\n", $deadline);

                    continue;
                }

                if (ImapResponseKind::Tagged === $response->kind) {
                    throw new ImapProtocolException(sprintf('%s answered a command that was not sent: %s', $this->endpoint->host, trim($response->raw)));
                }

                $untagged[] = $response;
            }
        } finally {
            $this->busy = false;
        }
    }

    /**
     * @param list<ImapResponse> $untagged
     */
    private function finish(string $name, ImapResponse $completion, array $untagged): ImapResult
    {
        if ('OK' !== $completion->status) {
            throw new ImapCommandException($name, $completion);
        }

        return new ImapResult($completion, $untagged);
    }

    /** A response, or the connection is given up: see the class docblock. */
    private function awaitResponse(float $deadline, string $waitingFor): ImapResponse
    {
        try {
            $response = $this->readResponse($deadline);
        } catch (ImapProtocolException $e) {
            $this->close();

            throw $e;
        }

        if (null === $response) {
            $this->close();

            throw new ImapTimeoutException(sprintf('%s did not answer %s in time.', $this->endpoint->host, $waitingFor));
        }

        return $response;
    }

    private function claim(): void
    {
        if (true === $this->isClosed) {
            throw new ImapConnectionException(sprintf('The connection to %s is closed.', $this->endpoint->host));
        }

        if (true === $this->busy) {
            throw new \LogicException('A command is already running on this connection.');
        }

        $this->busy = true;
    }

    private function nextTag(): string
    {
        return sprintf('A%d', ++$this->sequence);
    }

    /**
     * @param list<mixed> $tokens
     *
     * @return list<string>
     */
    private static function capabilityList(array $tokens): array
    {
        return array_values(array_map(
            static fn (string $token): string => strtoupper($token),
            array_filter($tokens, is_string(...)),
        ));
    }
}
