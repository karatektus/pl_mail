<?php

declare(strict_types=1);

namespace App\Jmap\Mail;

use App\Domain\Helper\AddressHelper;
use App\Jmap\Protocol\Exception\MethodException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The SMTP envelope a client may put on an "EmailSubmission/set" create, and
 * the one thing plMail takes from it: when the mail is allowed to leave.
 *
 * RFC 8621 §7 does not invent a scheduling property. It carries the SMTP
 * FUTURERELEASE extension (RFC 4865) through as envelope parameters —
 * `envelope.mailFrom.parameters` holding `HOLDFOR` (seconds, as a string) or
 * `HOLDUNTIL` (a date-time) — and advertises the ceiling as `maxDelayedSend`
 * in the submission accountCapabilities. That is the shape implemented here,
 * because a vendor property would have been a second way to say a thing the
 * spec already says.
 *
 * plMail has no SMTP session to pass parameters into: the release is honoured
 * by holding the messenger envelope (a DelayStamp) rather than by handing
 * HOLDFOR to a relay. That is the difference a client cannot see and the
 * reason the ceiling is ours to pick — see MAX_HOLD_SECONDS.
 *
 * **The rest of the envelope is checked, not applied.** The send pipeline
 * takes the From and the recipients from the stored Message, so an envelope
 * naming a different mailFrom or a different rcptTo describes mail this server
 * will not send. Accepting it and sending something else is the failure mode
 * the house rule exists to prevent, so a mismatch is refused by name rather
 * than dropped. `identityId` remains the way to choose the From.
 */
final readonly class SubmissionEnvelope
{
    /**
     * The longest hold this server will accept, in seconds — 30 days, and the
     * value the session advertises as maxDelayedSend.
     *
     * Picked rather than derived: nothing in the storage forbids a longer one,
     * but a queued send is a row in the messenger table that no UI lists and
     * no worker touches until it fires, and a year-long hold would outlive the
     * account, the alias and any memory of having asked for it. A month covers
     * every "send this on Monday" case and stays inside a retention anyone
     * reasons about.
     */
    public const int MAX_HOLD_SECONDS = 2_592_000;

    /** The FUTURERELEASE parameters understood here, in the RFC's spelling. */
    private const array HOLD_PARAMETERS = ['HOLDFOR', 'HOLDUNTIL'];

    private function __construct(
        /**
         * When the mail may leave, or null for "now" — which is both an
         * absent envelope and an explicitly zero/past hold, since RFC 4865
         * makes a hold that has already elapsed an immediate send rather than
         * an error.
         */
        public ?DateTimeImmutable $holdUntil,
    ) {
    }

    public static function immediate(): self
    {
        return new self(null);
    }

    /**
     * @param mixed        $value      the client's "envelope", or null
     * @param string       $mailFrom   the address this submission will send as
     * @param list<string> $recipients every To/Cc/Bcc address on the Email
     *
     * @throws MethodException when the envelope describes mail this server would not send
     */
    public static function parse(mixed $value, string $mailFrom, array $recipients, DateTimeImmutable $now): self
    {
        if (null === $value) {
            return self::immediate();
        }

        if (false === is_array($value)) {
            throw new MethodException('invalidProperties', '"envelope" must be an object.');
        }

        self::rejectUnknownKeys($value, ['mailFrom', 'rcptTo'], 'envelope');
        self::assertRecipients($value['rcptTo'] ?? null, $recipients);

        $sender = $value['mailFrom'] ?? null;

        if (null === $sender) {
            return self::immediate();
        }

        if (false === is_array($sender)) {
            throw new MethodException('invalidProperties', '"envelope.mailFrom" must be an object.');
        }

        self::rejectUnknownKeys($sender, ['email', 'parameters'], 'envelope.mailFrom');
        self::assertSender($sender['email'] ?? null, $mailFrom);

        return new self(self::holdUntil($sender['parameters'] ?? null, $now));
    }

    /**
     * The messenger delay this hold is worth. Never negative: a hold that
     * elapsed between parsing and dispatch is an immediate send.
     */
    public function delayMs(DateTimeImmutable $now): int
    {
        if (null === $this->holdUntil) {
            return 0;
        }

        return max(0, ($this->holdUntil->getTimestamp() - $now->getTimestamp()) * 1000);
    }

    /** When the client should expect the mail to go out. */
    public function sendAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $this->holdUntil ?? $now;
    }

    /**
     * FUTURERELEASE, read out of the mailFrom parameters.
     *
     * Parameter names are ESMTP keywords and therefore case-insensitive
     * (RFC 5321 §2.4); a client sending "holdFor" means HOLDFOR.
     */
    private static function holdUntil(mixed $parameters, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if (null === $parameters) {
            return null;
        }

        if (false === is_array($parameters)) {
            throw new MethodException('invalidProperties', '"envelope.mailFrom.parameters" must be an object or null.');
        }

        $named = [];

        foreach ($parameters as $name => $parameterValue) {
            $upper = strtoupper((string) $name);

            if (false === in_array($upper, self::HOLD_PARAMETERS, true)) {
                throw new MethodException('invalidProperties', sprintf(
                    'Unsupported envelope parameter "%s". This server accepts "%s" only.',
                    (string) $name,
                    implode('", "', self::HOLD_PARAMETERS),
                ));
            }

            $named[$upper] = $parameterValue;
        }

        if (2 === count($named)) {
            // RFC 4865 §3: the two are alternatives, and a client sending both
            // has two ideas about when this mail leaves. Picking one for it
            // would resolve that disagreement silently.
            throw new MethodException('invalidProperties', 'Send "HOLDFOR" or "HOLDUNTIL", not both.');
        }

        if (true === array_key_exists('HOLDFOR', $named)) {
            return self::holdFor($named['HOLDFOR'], $now);
        }

        if (true === array_key_exists('HOLDUNTIL', $named)) {
            return self::holdUntilDate($named['HOLDUNTIL'], $now);
        }

        return null;
    }

    private static function holdFor(mixed $value, DateTimeImmutable $now): ?DateTimeImmutable
    {
        // A string in the spec, because ESMTP parameters are text — but a
        // client that sent the number it means is not wrong about anything a
        // refusal would teach it.
        $seconds = is_int($value) ? (string) $value : $value;

        if (false === is_string($seconds) || 1 !== preg_match('/^\d+$/', $seconds)) {
            throw new MethodException('invalidProperties', '"HOLDFOR" must be a whole number of seconds.');
        }

        $seconds = (int) $seconds;

        if ($seconds > self::MAX_HOLD_SECONDS) {
            throw new MethodException('invalidProperties', sprintf(
                '"HOLDFOR" must not exceed %d seconds; see maxDelayedSend in the session.',
                self::MAX_HOLD_SECONDS,
            ));
        }

        return 0 === $seconds ? null : $now->modify(sprintf('+%d seconds', $seconds));
    }

    private static function holdUntilDate(mixed $value, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if (false === is_string($value) || '' === $value) {
            throw new MethodException('invalidProperties', '"HOLDUNTIL" must be a date-time string.');
        }

        try {
            $until = new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new MethodException('invalidProperties', sprintf('"HOLDUNTIL" is not a date-time: "%s".', $value));
        }

        $until = $until->setTimezone(new DateTimeZone('UTC'));

        if ($until <= $now) {
            return null;
        }

        if ($until->getTimestamp() - $now->getTimestamp() > self::MAX_HOLD_SECONDS) {
            throw new MethodException('invalidProperties', sprintf(
                '"HOLDUNTIL" must be within %d seconds; see maxDelayedSend in the session.',
                self::MAX_HOLD_SECONDS,
            ));
        }

        return $until;
    }

    /**
     * The envelope sender has to be the address the submission already
     * resolved to, since that is the one the mail will actually carry.
     */
    private static function assertSender(mixed $email, string $mailFrom): void
    {
        if (null === $email) {
            return;
        }

        if (false === is_string($email)) {
            throw new MethodException('invalidProperties', '"envelope.mailFrom.email" must be a string.');
        }

        if (AddressHelper::email($email) === AddressHelper::email($mailFrom)) {
            return;
        }

        throw new MethodException('forbiddenFrom', sprintf(
            'This submission sends as "%s"; choose the identityId for "%s" instead.',
            $mailFrom,
            $email,
        ));
    }

    /**
     * @param list<string> $recipients
     */
    private static function assertRecipients(mixed $rcptTo, array $recipients): void
    {
        if (null === $rcptTo) {
            return;
        }

        if (false === is_array($rcptTo)) {
            throw new MethodException('invalidProperties', '"envelope.rcptTo" must be an array of objects.');
        }

        $named = [];

        foreach ($rcptTo as $recipient) {
            if (false === is_array($recipient)) {
                throw new MethodException('invalidProperties', '"envelope.rcptTo" must contain objects with an "email".');
            }

            self::rejectUnknownKeys($recipient, ['email', 'parameters'], 'envelope.rcptTo');

            $email = $recipient['email'] ?? null;

            if (false === is_string($email) || '' === $email) {
                throw new MethodException('invalidProperties', 'Each "envelope.rcptTo" entry needs an "email".');
            }

            $parameters = $recipient['parameters'] ?? null;

            if (null !== $parameters && [] !== $parameters) {
                // DSN and friends would have to reach a relay this server does
                // not speak to directly.
                throw new MethodException('invalidProperties', 'No "envelope.rcptTo" parameters are supported.');
            }

            $named[] = AddressHelper::email($email);
        }

        $wanted = array_map(AddressHelper::email(...), $recipients);

        // Compared as sets: order is not meaningful in an envelope, and an
        // address that appears in both To and Cc is one RCPT TO either way.
        $named  = array_unique($named);
        $wanted = array_unique($wanted);

        sort($named);
        sort($wanted);

        if ($named === $wanted) {
            return;
        }

        throw new MethodException('invalidRecipients', sprintf(
            'The envelope names different recipients than the Email; this server sends to its To, Cc and Bcc ("%s").',
            implode('", "', $wanted),
        ));
    }

    /**
     * @param array<mixed>  $object
     * @param list<string>  $allowed
     */
    private static function rejectUnknownKeys(array $object, array $allowed, string $property): void
    {
        $unknown = array_diff(array_map('strval', array_keys($object)), $allowed);

        if (0 === count($unknown)) {
            return;
        }

        throw new MethodException('invalidProperties', sprintf(
            '"%s" accepts "%s"; got "%s".',
            $property,
            implode('", "', $allowed),
            implode('", "', $unknown),
        ));
    }
}
