<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Infrastructure\Backup\ConfigBackupCipher;
use App\Service\Setup\PublicUrlSetting;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * One instance's configuration, gathered from the three places it lives and
 * sealed with a password the admin just typed.
 *
 * **Configuration, never mail.** Not one message, contact or calendar *event*
 * is touched here. What the file carries is what somebody decided — the
 * environment, the secrets, the provider registrations, and since document
 * version 2 the people and everything each of them set up: their mailboxes and
 * the credentials that open them, their aliases, integrations, filters, labels,
 * calendars and published links. `app:backup` remains the way to move the data;
 * this is the way to move the INSTALLATION, and the two are separate because
 * they are needed at different moments: the data restore is a disaster, the
 * config restore is a Tuesday.
 *
 * That boundary moved once, deliberately. It used to stop before users, and the
 * sentence at the end of a restore admitted what that cost: "the configuration
 * is in place, this installation still has no users." An operator who had just
 * carried a whole install to a new host was then asked to invent an
 * administrator and rebuild every mailbox by hand. A backup that cannot name
 * its own operator is a backup of a server, not of an installation.
 *
 * It is still kilobytes on an install with a handful of people — no message
 * bodies, no attachments, no event rows — so an admin can still keep it in a
 * password manager. It is also, now more than before, every credential every
 * user has.
 *
 * **Nothing is written to disk.** The document is assembled in memory, sealed
 * in memory, and handed to the controller as a string for a download response.
 * A temporary file holding a decrypted APP_ENCRYPTION_KEY would be a worse
 * exposure than anything this feature protects against, and it would survive
 * the request that made it.
 *
 * **The document carries its own format and version, and so does the
 * envelope.** They are not the same claim: the envelope's says how the bytes
 * are encrypted, and has to be readable without the password; the document's
 * says what the fields inside mean, and cannot be read until it is open. A
 * future plMail that keeps the crypto and reorganises the contents bumps one
 * and not the other, and a reader that only knows the old contents can still
 * tell that it is a plMail backup and that it cannot use it.
 */
final readonly class ConfigBackupExporter
{
    /**
     * The content schema of the decrypted document.
     *
     * **2** — added `users`: the people and everything each of them configured.
     * Nothing else moved, and that is what makes the bump cheap in both
     * directions. A version 1 document has no `users` key, the importer reads
     * an absent section as an empty one, and it plans and applies exactly as it
     * did before — see ConfigBackupImporter::open(), which refuses a document
     * from the *future* and accepts every one from the past. A version 2
     * document opened by a v0.0.20 plMail is refused outright by that same
     * check, which is the correct answer: it would otherwise restore the
     * configuration and silently drop every account in the file.
     */
    public const int DOCUMENT_VERSION = 2;

    public function __construct(
        private ConfigBackupCipher      $cipher,
        private ConfigBackupEnvironment $environment,
        private ConfigBackupFiles       $files,
        private ConfigBackupDatabase    $database,
        private ConfigBackupUsers       $users,
        private PublicUrlSetting        $publicUrl,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return [
            'format'     => ConfigBackupCipher::FORMAT,
            'version'    => self::DOCUMENT_VERSION,
            'exportedAt' => new DateTimeImmutable()->format(DATE_ATOM),
            // Which install this came from. Not a secret and not load-bearing —
            // it is shown on the review page so somebody holding two backups
            // can tell them apart, which is the whole reason it is here.
            'instance' => $this->publicUrl->current(),
            'env'      => $this->environment->export(),
            'files'    => $this->files->export(),
            'database' => $this->database->export(),
            'users'    => $this->users->export(),
        ];
    }

    /** The sealed file, ready to be sent. */
    public function export(#[SensitiveParameter] string $password): string
    {
        return $this->cipher->seal($this->document(), $password);
    }

    /**
     * `plmail-config-2026-08-06.backup`.
     *
     * A date and not a timestamp: an admin who exports twice in a day wants the
     * second file to be recognisably the same kind of thing, and the browser
     * appends "(1)" perfectly well. `.backup` rather than `.json` because it is
     * not JSON to anything that would try to open it — it is an envelope, and a
     * text editor showing base64 is the least useful thing that could happen.
     */
    public function filename(): string
    {
        return sprintf('plmail-config-%s.backup', new DateTimeImmutable()->format('Y-m-d'));
    }
}
