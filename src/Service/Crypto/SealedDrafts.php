<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\PlaintextSource;
use App\Domain\Enum\Crypto\SignatureState;
use App\Domain\Exception\SealRefused;
use App\Domain\Helper\SealedMessageStorage;
use App\Entity\Mail\Message;
use App\Service\Mail\Mime\SealedEntityPart;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the composer sends along with a draft once the browser has sealed it.
 *
 * Two things can come with an autosave or a send, in the `sealed` fields of the
 * compose form (assets/controllers/compose/encryption_controller.js):
 *
 *   sealed[draft]   the draft as typed, encrypted in the browser to the
 *                   person's own key. Kept on disk; the row keeps no body.
 *   sealed[entity]  with a send or a schedule only: the finished message the
 *                   browser built — encrypted, signed, or plain mail from a
 *                   draft the server cannot read — with sealed[kind],
 *                   sealed[fingerprints] (whose keys it is encrypted to),
 *                   sealed[signer] (whose key signed it) and sealed[scheme]
 *                   (`pgp`, or `smime` when the keys are S/MIME certificates
 *                   and the fingerprints theirs).
 *
 * Once a draft is sealed it stays sealed: an autosave without sealed[draft]
 * does not turn it back into one the server can read, because the browser
 * that sealed it is the only thing that can open it. And every save that is
 * not a send forgets the built message, since what was built is no longer
 * what is in the window.
 */
final readonly class SealedDrafts
{
    /** A draft with attachments is sealed without them; this is text and inline images. */
    private const int MAX_DRAFT_BYTES = 16 * 1024 * 1024;

    /** Comfortably past what any provider here accepts (Graph: 4 MB, Gmail: 35 MB). */
    private const int MAX_ENTITY_BYTES = 48 * 1024 * 1024;

    public function __construct(
        private SealedMessageStorage  $storage,
        private SealGuard             $guard,
        private DecryptedMailPolicy   $decryptedMail,
        private MessageCryptoDetector $detector,
    ) {
    }

    /**
     * Take what the composer sealed. After DraftPersister::save(), which is
     * what gives a new draft the id its files are named after.
     *
     * @throws SealRefused when what came is not what it claims to be, or no
     *                     longer fits the draft it came with
     */
    public function accept(Request $request, Message $message, bool $sending): void
    {
        if (null === $message->id) {
            return;
        }

        $sealed = $request->request->all('sealed');
        $draft  = is_string($sealed['draft'] ?? null) ? trim($sealed['draft']) : '';

        if ('' !== $draft) {
            if (strlen($draft) > self::MAX_DRAFT_BYTES || false === str_starts_with($draft, '-----BEGIN PGP MESSAGE-----')) {
                throw new SealRefused('compose.seal.refused');
            }

            $this->storage->write($message->id, SealedMessageStorage::DRAFT, $draft);
            $message->sealedDraft = true;
        }

        if (true === $message->sealedDraft) {
            $message->crypto = MessageCrypto::PgpEncrypted;
        }

        $this->forgetBuilt($message);

        $entity = is_string($sealed['entity'] ?? null) ? $sealed['entity'] : '';

        if (false === $sending || '' === $entity) {
            $this->blankSealedBody($message);

            return;
        }

        $this->acceptBuilt(
            $message,
            $entity,
            (string) ($sealed['kind'] ?? ''),
            (string) ($sealed['scheme'] ?? SealGuard::SCHEME_PGP),
            (string) ($sealed['fingerprints'] ?? ''),
            (string) ($sealed['signer'] ?? ''),
            '1' === ($sealed['protectedSubject'] ?? null),
        );
    }

    /** The sealed draft, for the composer to open again. */
    public function draftOf(Message $message): ?string
    {
        return null === $message->id || false === $message->sealedDraft
            ? null
            : $this->storage->read($message->id, SealedMessageStorage::DRAFT);
    }

    /** The built message, for the send job to put on the wire. */
    public function entityOf(Message $message): ?string
    {
        return null === $message->id || null === $message->outgoingSeal
            ? null
            : $this->storage->read($message->id, SealedMessageStorage::OUTGOING);
    }

    /**
     * After the provider has the mail: what went out is the message now, so
     * the sealed copies go and the row says what it was sent as.
     */
    public function sent(Message $message): void
    {
        $kind  = $message->outgoingSeal['kind'] ?? null;
        $smime = SealGuard::SCHEME_SMIME === ($message->outgoingSeal['scheme'] ?? null);

        $message->crypto = match ($kind) {
            SealGuard::KIND_ENCRYPTED => true === $smime ? MessageCrypto::SmimeEncrypted : MessageCrypto::PgpEncrypted,
            SealGuard::KIND_SIGNED    => true === $smime ? MessageCrypto::SmimeSigned : MessageCrypto::PgpSigned,
            default                   => null,
        };

        if (SealGuard::KIND_SIGNED === $kind && '' !== ($message->outgoingSeal['signer'] ?? '')) {
            $message->signatureState = SignatureState::Valid;
            $message->signer         = $message->outgoingSeal['signer'];
        }

        if (null !== $message->id) {
            $this->storage->delete($message->id, SealedMessageStorage::DRAFT);
            $this->storage->delete($message->id, SealedMessageStorage::OUTGOING);
        }

        $message->sealedDraft  = false;
        $message->outgoingSeal = null;
    }

    private function acceptBuilt(Message $message, string $entity, string $kind, string $scheme, string $fingerprints, string $signer, bool $protectedSubject = false): void
    {
        if (strlen($entity) > self::MAX_ENTITY_BYTES
            || false === in_array($kind, [SealGuard::KIND_ENCRYPTED, SealGuard::KIND_SIGNED, SealGuard::KIND_PLAIN], true)
            || false === in_array($scheme, [SealGuard::SCHEME_PGP, SealGuard::SCHEME_SMIME], true)) {
            throw new SealRefused('compose.seal.refused');
        }

        try {
            $part = new SealedEntityPart($entity);
        } catch (InvalidArgumentException) {
            throw new SealRefused('compose.seal.refused');
        }

        // An encrypted message has to say so on the outside, and nothing else
        // may pass for one: the row would be marked encrypted around mail that
        // went out readable. S/MIME is read the way ingest reads it, so the
        // Sent copy is classified as what the recipient will see.
        $outside     = $this->detector->classify(self::contentTypeOf($entity), null, null);
        $isEncrypted = SealGuard::SCHEME_SMIME === $scheme
            ? MessageCrypto::SmimeEncrypted === $outside
            : 'multipart' === $part->getMediaType() && 'encrypted' === $part->getMediaSubtype()
                || 'text' === $part->getMediaType() && str_contains($entity, '-----BEGIN PGP MESSAGE-----');

        if ((SealGuard::KIND_ENCRYPTED === $kind) !== $isEncrypted) {
            throw new SealRefused('compose.seal.refused');
        }

        if (SealGuard::SCHEME_SMIME === $scheme && SealGuard::KIND_SIGNED === $kind && MessageCrypto::SmimeSigned !== $outside) {
            throw new SealRefused('compose.seal.refused');
        }

        $seal = [
            'kind'         => $kind,
            'scheme'       => $scheme,
            'recipients'   => SealGuard::recipientsOf($message),
            'fingerprints' => array_values(array_filter(array_map(
                static fn (string $fingerprint): string => strtolower(trim($fingerprint)),
                explode(',', $fingerprints),
            ), static fn (string $fingerprint): bool => 1 === preg_match('/^[0-9a-f]{40,64}$/', $fingerprint))),
            'signer'       => 1 === preg_match('/^[0-9a-f]{40,64}$/', strtolower($signer)) ? strtolower($signer) : '',
            // The subject is inside the encryption (RFC 9788 header
            // protection), so the outside says only "..." — see
            // MessageSendService::buildEmail(). Encrypted mail only: a signed
            // message's inside is readable by everyone anyway.
            'protectedSubject' => true === $protectedSubject && SealGuard::KIND_ENCRYPTED === $kind,
        ];

        // S/MIME names the person's own certificate as the signer, and it has
        // to be the one that serves the From address now: checked here and
        // again by SealGuard::refusal() when the message leaves.
        if (SealGuard::SCHEME_SMIME === $scheme && SealGuard::KIND_PLAIN !== $kind
            && null !== $this->guard->signerRefusal($message->account, (string) ($message->fromAddress ?? ''), $seal['signer'])) {
            throw new SealRefused('compose.seal.keys_changed');
        }

        if (SealGuard::KIND_ENCRYPTED === $kind) {
            $refusal = $this->guard->keyRefusal($message->account->usr, $seal['recipients'], $seal['fingerprints'], $scheme);

            if (null !== $refusal) {
                throw new SealRefused('compose.seal.keys_changed');
            }

            // An account that keeps decrypted mail keeps its own encrypted
            // mail readable too: the composer sent the text along for it.
            if (true === $this->decryptedMail->keeps($message->account)) {
                $message->plaintextSource = PlaintextSource::Client;
            } else {
                $this->blankBody($message);
            }
        } elseif (true === $message->sealedDraft) {
            // Signed or plain mail from a sealed draft goes out readable, and
            // the composer sent its text with it so the Sent copy has a body
            // to search and show. Nothing more is sealed about it.
            $message->crypto = SealGuard::KIND_SIGNED === $kind
                ? (SealGuard::SCHEME_SMIME === $scheme ? MessageCrypto::SmimeSigned : MessageCrypto::PgpSigned)
                : null;
        }

        $this->storage->write((int) $message->id, SealedMessageStorage::OUTGOING, $entity);
        $message->outgoingSeal = $seal;
    }

    private function forgetBuilt(Message $message): void
    {
        if (null !== $message->outgoingSeal && null !== $message->id) {
            $this->storage->delete($message->id, SealedMessageStorage::OUTGOING);
        }

        $message->outgoingSeal = null;
    }

    /**
     * A sealed draft keeps no body, whatever the request carried: a composer
     * that sealed the draft sends the body fields empty, and anything else is
     * not the composer.
     */
    private function blankSealedBody(Message $message): void
    {
        if (true === $message->sealedDraft) {
            $this->blankBody($message);
        }
    }

    private function blankBody(Message $message): void
    {
        $message->bodyText     = null;
        $message->bodyHtml     = null;
        $message->bodyHtmlSafe = null;
    }

    /** The entity's own Content-Type header, unfolded, with its parameters. */
    private static function contentTypeOf(string $entity): string
    {
        $end     = strpos($entity, "\r\n\r\n");
        $headers = (string) preg_replace("/\r\n[ \t]+/", ' ', substr($entity, 0, false === $end ? 0 : $end));

        return 1 === preg_match('/^content-type:\s*(.+)$/im', $headers, $match) ? trim($match[1]) : '';
    }
}
