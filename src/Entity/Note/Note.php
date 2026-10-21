<?php

declare(strict_types=1);

namespace App\Entity\Note;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Note\NoteRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A short note, in the manner of Google Keep: a title, free text or a checklist, a colour, pinned
 * to the top or put away, and links to the other things the user keeps here.
 *
 * User-scoped, like MailTemplate: a note belongs to the person, not to a connected mail account,
 * so it follows them to every phone and the web.
 *
 * PLAIN TEXT, NOT HTML. A note is typed on a phone keyboard and read in a list; there is no markup
 * to sanitise and nothing for a client to inject into another document. The one place text from a
 * note could reach someone else's page — a link title — is a plain string like the rest.
 *
 * LINKS ARE REFERENCES, NOT FOREIGN KEYS. An event, a Drive file, a contact and a mail each live
 * in a different id space (and a calendar event's id is an opaque JMAP string), so a link stores
 * what a client needs to open the thing — its type and its id — plus the title it had when it was
 * linked, so a list can draw "Dentist, 3 Nov" without fetching four other objects. The title is a
 * label, never authoritative; the client refreshes it when it has the real object. A link whose
 * target has since been deleted simply stops opening; nothing here cascades from the other
 * direction, because the note is the user's text and outlives the event it was written about.
 */
#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'user_note')]
// Every read is "this user's notes, all of them".
#[ORM\Index(name: 'idx_user_note_usr', columns: ['usr_id'])]
#[ORM\Index(name: 'idx_user_note_reminder', columns: ['reminder_at'])]
class Note
{
    use TimestampableTrait;

    /** The closed set of colours, by name. A client maps them to its own palette, light and dark. */
    public const array COLORS = ['red', 'orange', 'yellow', 'green', 'teal', 'blue', 'purple', 'pink', 'brown', 'gray'];

    /** What a note may link to. Matches the other areas of the app. */
    public const array LINK_TYPES = ['event', 'file', 'contact', 'mail'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    #[ORM\Column(length: 255)]
    public string $title = '';

    /** Plain text. Empty for a pure checklist. */
    #[ORM\Column(type: Types::TEXT)]
    public string $body = '';

    /**
     * The checklist, in order. Empty for a text note; a note may carry both, as Keep's may after
     * "show checkboxes" is toggled.
     *
     * @var list<array{text: string, done: bool}>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $items = [];

    /** One of self::COLORS, or null for the default card. */
    #[ORM\Column(length: 16, nullable: true)]
    public ?string $color = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $pinned = false;

    #[ORM\Column(options: ['default' => false])]
    public bool $archived = false;

    /** Null unless the note is in the bin. Kept as an instant so a purge job can use it later. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $trashedAt = null;

    /**
     * @var list<array{type: string, id: string, title: string|null}>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $links = [];

    /**
     * Keep-style labels: short names the user groups notes by, case kept as
     * typed, each at most once.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $labels = [];

    /**
     * Pictures, drawings and voice recordings on the note, in the order added:
     * `{id, kind, name, type, size, createdAt}`, `kind` one of image, drawing,
     * audio. The bytes are files beside the note (App\Service\Note\NoteMediaStorage);
     * this is the list of them.
     *
     * @var list<array{id: string, kind: string, name: string, type: string, size: int, createdAt: string}>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $media = [];

    /**
     * When to remind the user about this note, in UTC, or null for no
     * reminder. app:notes:reminders sends it (an email to themselves and a
     * notification on their devices) once it comes due.
     */
    #[ORM\Column(nullable: true)]
    public private(set) ?DateTimeImmutable $reminderAt = null;

    /** When the current reminder was sent; null while it is still to come. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $reminderSentAt = null;

    /** Set or clear the reminder. A new time is a new reminder, still to send. */
    public function remindAt(?DateTimeImmutable $at): void
    {
        if ($at?->getTimestamp() !== $this->reminderAt?->getTimestamp()) {
            $this->reminderSentAt = null;
        }

        $this->reminderAt = $at;
    }

    /**
     * Labels as stored: trimmed, one line, at most 50 characters, empty ones
     * dropped, each name once (compared without case), at most 20.
     *
     * @param array<mixed> $names
     *
     * @return list<string>
     */
    public static function cleanLabels(array $names): array
    {
        $out = [];

        foreach ($names as $name) {
            if (false === is_string($name)) {
                continue;
            }

            $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($name))), 0, 50);

            if ('' !== $name) {
                $out[mb_strtolower($name)] ??= $name;
            }
        }

        return \array_slice(array_values($out), 0, 20);
    }
}
