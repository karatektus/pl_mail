<?php

declare(strict_types=1);

namespace App\Domain\DTO\Layout;

use App\Domain\Enum\Mail\SidebarRow;

/**
 * A sidebar of the user's own (#783): a name and the rows it shows, which a
 * workspace picks by id (Workspace::$sidebar). Shared — two workspaces with the
 * same sidebar show the same rows, each narrowed to its own accounts.
 *
 * ── Entries ──────────────────────────────────────────────────────────────
 *
 * Two kinds. A fixed link points at one thing:
 *
 *   {"t": "sys",   "row": "starred"}     a system row (SidebarRow), in the workspace's accounts
 *   {"t": "acc",   "acc": 12}            one account's inbox
 *   {"t": "inbox", "inbox": 3}           one of the reader's named inboxes (#265)
 *   {"t": "label", "label": 40}          a label or folder
 *   {"t": "cal",   "cal": 7}             one calendar
 *   {"t": "tasks", "list": 5}            one task list
 *   {"t": "head",  "text": "Plan"}       a heading
 *   {"t": "clock"}                       the time, the date and what is next
 *
 * A live block is whatever it holds right now, so a new label or account
 * shows up without anybody editing the sidebar:
 *
 *   {"t": "block", "b": "accounts"}      also inboxes, labels, calendars, tasklists
 *
 * Ids that stop existing (a deleted label) are not removed here: the sidebar
 * leaves out what it cannot draw, and the editor shows the gap. Removing them
 * would need every deletion to know about sidebars.
 *
 * The built-in Mail sidebar is not one of these. It is today's sidebar,
 * drawn by _partials/_sidebar.html.twig, and Workspace::SIDEBAR_MAIL names it.
 */
final readonly class Sidebar
{
    public const int MAX_ENTRIES = 60;

    public const int NAME_MAX_LENGTH = 40;

    public const array BLOCKS = ['inboxes', 'accounts', 'labels', 'calendars', 'tasklists'];

    /** @param list<array<string, int|string>> $entries */
    public function __construct(
        public string $id,
        public string $name,
        public array  $entries,
    ) {
    }

    public static function fromArray(mixed $data): ?self
    {
        if (false === is_array($data)) {
            return null;
        }

        $id   = $data['id'] ?? null;
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';

        if (false === is_string($id) || 1 !== preg_match(Workspace::ID_PATTERN, $id) || Workspace::SIDEBAR_MAIL === $id) {
            return null;
        }

        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return null;
        }

        $entries = $data['entries'] ?? [];

        if (false === is_array($entries) || false === array_is_list($entries) || count($entries) > self::MAX_ENTRIES) {
            return null;
        }

        $clean = [];

        foreach ($entries as $entry) {
            $entry = self::entry($entry);

            if (null !== $entry) {
                $clean[] = $entry;
            }
        }

        return new self($id, $name, $clean);
    }

    /**
     * One entry, or null if it is not one. Dropped rather than refusing the
     * sidebar: a single row the server no longer understands should not cost
     * somebody the rest.
     *
     * @return array<string, int|string>|null
     */
    public static function entry(mixed $entry): ?array
    {
        if (false === is_array($entry) || false === is_string($entry['t'] ?? null)) {
            return null;
        }

        return match ($entry['t']) {
            'sys'   => null !== SidebarRow::tryFrom((string) ($entry['row'] ?? '')) ? ['t' => 'sys', 'row' => (string) $entry['row']] : null,
            'acc'   => is_int($entry['acc'] ?? null) ? ['t' => 'acc', 'acc' => $entry['acc']] : null,
            'inbox' => is_int($entry['inbox'] ?? null) ? ['t' => 'inbox', 'inbox' => $entry['inbox']] : null,
            'label' => is_int($entry['label'] ?? null) ? ['t' => 'label', 'label' => $entry['label']] : null,
            'cal'   => is_int($entry['cal'] ?? null) ? ['t' => 'cal', 'cal' => $entry['cal']] : null,
            'tasks' => is_int($entry['list'] ?? null) ? ['t' => 'tasks', 'list' => $entry['list']] : null,
            'head'  => is_string($entry['text'] ?? null) && '' !== trim($entry['text']) && mb_strlen(trim($entry['text'])) <= self::NAME_MAX_LENGTH
                ? ['t' => 'head', 'text' => trim($entry['text'])]
                : null,
            'clock' => ['t' => 'clock'],
            'block' => in_array($entry['b'] ?? null, self::BLOCKS, true) ? ['t' => 'block', 'b' => (string) $entry['b']] : null,
            default => null,
        };
    }

    /** @return array{id: string, name: string, entries: list<array<string, int|string>>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'entries' => $this->entries];
    }
}
