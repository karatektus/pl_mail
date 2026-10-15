<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Domain\Enum\Mail\MessageCategory;

/**
 * One tab of the inbox: either one of the five built-in categories, or one the
 * reader made themselves.
 *
 * The two live in different columns on purpose — a built-in is
 * MessageThread::$category, whose enum mapping JMAP's closed vocabulary is built
 * on, and a reader's own is MessageThread::$userCategory — so there is no single
 * type that holds both, and every surface that names "a tab" would otherwise
 * grow its own union. This is the one place that does.
 *
 * `value` is the key, and it is the ONE string every surface agrees on: the
 * `?tab=` in the URL, the `data-dnd-category` a conversation is dropped on, the
 * keys the counts endpoint patches the strip by. A built-in's key is exactly
 * what it was before custom tabs existed — a bookmark, a cached page or an open
 * tab must not change meaning — and a custom key is `c` and the category's id,
 * which no built-in name can collide with (none starts `c` and ends in digits).
 *
 * It is named `value` so the templates, which read `tabCase.value` of an enum
 * case today, read it off this unchanged.
 */
final readonly class InboxTab
{
    /** What a custom key starts with, before the category's id. */
    public const string CUSTOM_PREFIX = 'c';

    private function __construct(
        public string $value,
        public ?MessageCategory $builtIn,
        public ?int $categoryId,
        public ?string $name,
    ) {
    }

    public static function builtIn(MessageCategory $category): self
    {
        return new self($category->value, $category, null, null);
    }

    public static function custom(int $categoryId, string $name): self
    {
        return new self(self::CUSTOM_PREFIX . $categoryId, null, $categoryId, $name);
    }

    /**
     * The tab a key names, or null when it names none the reader has.
     *
     * Null rather than a throw because the key arrives in a URL anybody can
     * edit, and a stale or mistyped one is a link from before something was
     * deleted — it opens the inbox, the same answer an unknown built-in gets.
     *
     * An id is the reader's only if it is in $namesById. That lookup is the
     * ownership check: a custom key for somebody else's category, or one that
     * was deleted, simply is not a tab here.
     *
     * @param array<int,string> $namesById the reader's own categories, id => name
     */
    public static function parse(string $key, array $namesById): ?self
    {
        $category = MessageCategory::tryFrom($key);

        if (null !== $category) {
            return self::builtIn($category);
        }

        if (false === self::looksCustom($key, $match)) {
            return null;
        }

        $id = (int) $match[1];

        if (false === array_key_exists($id, $namesById)) {
            return null;
        }

        return self::custom($id, $namesById[$id]);
    }

    /**
     * Whether a key has the shape of a custom tab's — the cheap test that lets a
     * caller skip looking up the reader's categories for a key that could not
     * name one. A built-in name, an empty string and a mistyped URL are all
     * answered without touching the database.
     *
     * Digits only, and no leading sign or zero games: `c1x`, `c-12` and `C12` are
     * not keys this class ever produced, so none of them is a tab.
     *
     * @param array<int,string>|null $match filled with the regex match, so parse()
     *                                      reads the id without matching twice
     */
    public static function looksCustom(string $key, ?array &$match = null): bool
    {
        return 1 === preg_match('/^' . self::CUSTOM_PREFIX . '([1-9][0-9]*)$/', $key, $match);
    }

    public function isCustom(): bool
    {
        return null !== $this->categoryId;
    }

    /** The same tab, whatever it has since been renamed. */
    public function is(self $other): bool
    {
        return $this->value === $other->value;
    }
}
