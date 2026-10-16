<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Where the browser's sealed copies of a message live: the draft it sealed to
 * the person's own key, and the finished message it built to be sent.
 *
 * Files rather than columns, for the reason RawMessageStorage keeps raw mail
 * on disk: a message row is hydrated by every list in the app, and a column
 * holding a few megabytes of armored attachments would ride along with each
 * of them. One file per kind per message, named after the message's id alone
 * — a draft can move between accounts, its id never changes.
 *
 * Neither of those is readable by the server in any useful sense: the draft is
 * an OpenPGP message to the person's own key, and the outgoing message is
 * either the same or mail that is about to go out in the clear anyway.
 *
 * The third kind is the exception, and only by the person's choice: the
 * decrypted inner message a browser handed over for an account that keeps
 * decrypted mail (App\Service\Crypto\DecryptedMail). Kept apart from the raw
 * source, which stays the ciphertext that arrived.
 *
 * UNDER raw/, in a `sealed` directory of its own beside the accounts' ones,
 * because raw/ is a volume every container shares. The files were first kept
 * in var/sealed/, which the stock compose.yaml mounts nowhere: the web
 * container wrote the built message into its own filesystem, the send job in
 * the worker found nothing, and encrypted mail stayed in Drafts with "the
 * built message is missing" in the log. The test stack mounts the whole
 * checkout, so it could not see the difference. Avatars and signatures live
 * inside uploads/ for the same reason.
 */
readonly class SealedMessageStorage
{
    private const int FANOUT = 1000;

    public const string DRAFT     = 'draft.asc';
    public const string OUTGOING  = 'eml';
    public const string DECRYPTED = 'decrypted.eml';

    public function __construct(
        private string $projectDir,
        private string $storageDir,
    ) {
    }

    public function write(int $messageId, string $kind, string $content): void
    {
        $path = $this->path($messageId, $kind);

        if (false === is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $content);
    }

    public function read(int $messageId, string $kind): ?string
    {
        $path = $this->path($messageId, $kind);

        if (false === is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return false === $content ? null : $content;
    }

    public function delete(int $messageId, string $kind): void
    {
        $path = $this->path($messageId, $kind);

        if (true === is_file($path)) {
            unlink($path);
        }
    }

    private function path(int $messageId, string $kind): string
    {
        return sprintf(
            '%s/%s/raw/sealed/%d/%d.%s',
            $this->projectDir,
            $this->storageDir,
            intdiv($messageId, self::FANOUT),
            $messageId,
            true === in_array($kind, [self::OUTGOING, self::DECRYPTED], true) ? $kind : self::DRAFT,
        );
    }
}
