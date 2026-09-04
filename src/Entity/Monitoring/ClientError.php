<?php

declare(strict_types=1);

namespace App\Entity\Monitoring;

use App\Repository\Monitoring\ClientErrorRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Something that went wrong in a browser, counted rather than listed.
 *
 * WHY THIS IS NOT A LOG ENTRY. A server error happens once, in one request, to
 * one person. A broken line in a Stimulus controller happens on every page load
 * for every user until somebody fixes it — one afternoon of that is four
 * hundred identical rows, and a log list holding them is a log list nobody can
 * read past. So a row here is a DISTINCT FAULT, not an occurrence: the same
 * message from the same file and line increments {@see $occurrences} and moves
 * {@see $lastSeenAt} instead of adding anything.
 *
 * That also makes the useful questions answerable. "Is this still happening"
 * is lastSeenAt, "does it matter" is occurrences, and "did my fix work" is both
 * — none of which a flat list answers without somebody counting rows by eye.
 *
 * WHAT IS NOT KEPT, AND WHY THERE IS SO LITTLE OF IT. This is a self-hosted
 * mailbox and the people in it are the same people reading this panel, so the
 * usual caution about stack traces carrying user data is not the deciding
 * factor. What limits it instead is that nothing else is worth keeping: a DOM
 * snapshot or a breadcrumb trail would be pages of noise per fault, and the
 * thing that gets a browser bug fixed is a message, a file, a line and how
 * often.
 */
#[ORM\Entity(repositoryClass: ClientErrorRepository::class)]
#[ORM\Table(name: 'client_error')]
#[ORM\UniqueConstraint(name: 'uniq_client_error_fingerprint', columns: ['fingerprint'])]
#[ORM\Index(columns: ['last_seen_at'], name: 'idx_client_error_last_seen')]
class ClientError
{
    /** Kinds, and the three ways a browser tells us something is wrong. */
    public const string KIND_ERROR = 'error';

    public const string KIND_REJECTION = 'unhandledrejection';

    public const string KIND_CSP = 'csp';

    /** Long enough for a real stack, short of storing a minified bundle. */
    public const int MAX_STACK_CHARS = 4000;

    public const int MAX_MESSAGE_CHARS = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /**
     * What makes two reports the same fault.
     *
     * A hash of kind, message, source and position — deliberately NOT of the
     * stack, which differs by the path taken to the same broken line, nor of
     * the URL, since one fault in a shared controller fires on every page. Both
     * would turn one bug into a hundred rows, which is the thing this table
     * exists not to do.
     */
    #[ORM\Column(length: 40)]
    public string $fingerprint = '';

    #[ORM\Column(length: 32)]
    public string $kind = self::KIND_ERROR;

    #[ORM\Column(type: Types::TEXT)]
    public string $message = '';

    /** The script it came from, or the violated directive for a CSP report. */
    #[ORM\Column(length: 500, nullable: true)]
    public ?string $source = null;

    #[ORM\Column(nullable: true)]
    public ?int $line = null;

    /**
     * Named `columnNumber`, and not `column`, because `column` is a reserved
     * word in Postgres. The DDL can quote it; Doctrine's generated INSERT does
     * not, so the table creates cleanly and every write to it is a syntax
     * error. Same reason User::$usr is not `user`.
     */
    #[ORM\Column(nullable: true)]
    public ?int $columnNumber = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $stack = null;

    /**
     * The page it last happened on, and the browser it last happened in.
     *
     * Last rather than first, and only one of each: the point is "can I still
     * reproduce this", and the most recent answer is the one worth having. A
     * list of every URL and agent would grow without bound for a fault in code
     * that runs everywhere.
     */
    #[ORM\Column(length: 500, nullable: true)]
    public ?string $url = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $userAgent = null;

    /**
     * How many times this fault has been REPORTED, which is not the same as how
     * many times it happened.
     *
     * The browser sends at most one report per fault per page load — see
     * assets/client_errors.js — so this counts page loads that hit it rather
     * than executions. That is the deliberate trade against a page posting a
     * thousand requests about one broken line, and it is the more useful number
     * regardless: it says how many people are running into this.
     */
    #[ORM\Column]
    public int $occurrences = 1;

    #[ORM\Column]
    public DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    public DateTimeImmutable $lastSeenAt;

    public function __construct()
    {
        $this->firstSeenAt = new DateTimeImmutable();
        $this->lastSeenAt  = $this->firstSeenAt;
    }

    /** Where it came from, as one line, for a panel that has one line to give it. */
    public function origin(): string
    {
        if (null === $this->source || '' === $this->source) {
            return '';
        }

        return $this->source . (null === $this->line ? '' : ':' . $this->line);
    }
}
