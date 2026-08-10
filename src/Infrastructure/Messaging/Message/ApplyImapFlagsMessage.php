<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Outgoing IMAP state propagation for messages on plain-IMAP accounts.
 *
 * $messageIds is a messageId => sourceMailboxId map captured BEFORE any DB
 * move/flush so the source folder is always the one the UID is valid in.
 *
 * $sourceUids is the other half of that address, and it is captured for the
 * same reason. The handler used to read the UID off the row when it ran, which
 * only worked while a moved row went on carrying the UID of the folder it had
 * left — the staleness that produced the trash duplicates in the first place.
 * Now that Message::relocateTo() clears it, the source UID exists nowhere but
 * here: it describes where the message was when the user acted, which is
 * precisely what an outgoing move needs and precisely what the row must stop
 * claiming.
 *
 * $destinationPath is only set for the 'move' action — used when a custom
 * location label is replaced and the propagator has already resolved which
 * folder the message must physically move to. 'archive' and 'trash' keep
 * resolving their destination inside the handler as before.
 */
readonly class ApplyImapFlagsMessage
{
    /**
     * @param array<int,int> $messageIds  messageId => sourceMailboxId
     * @param array<int,int> $sourceUids  messageId => UID in that source folder
     */
    public function __construct(
        public array   $messageIds,
        public string  $action,
        public ?string $destinationPath = null,
        public array   $sourceUids = [],
    ) {}

    /**
     * The UID this message had in its source folder when the job was queued.
     *
     * Guarded by isset() rather than read directly, because envelopes queued by
     * the previous release are still on the transport and were serialised
     * without this property at all — a readonly promoted property cannot carry
     * a class-level default, so unserialising one of those leaves it
     * uninitialised and touching it would fatal. isset() answers false for an
     * uninitialised typed property instead of throwing, so an old envelope
     * simply reports "no UID here" and the handler falls back to the row, which
     * is exactly the behaviour that envelope was queued under.
     */
    public function sourceUidFor(int $messageId): ?int
    {
        // PHPStan reads the declaration and concludes the property is always
        // initialised, which is true of every instance the constructor makes
        // and false of the ones unserialize() makes out of envelopes queued
        // before this property existed. Those are real, they are on the
        // transport now, and they are the entire reason for the check.
        // @phpstan-ignore isset.initializedProperty, identical.alwaysFalse
        if (false === isset($this->sourceUids)) {
            return null;
        }

        return $this->sourceUids[$messageId] ?? null;
    }
}
