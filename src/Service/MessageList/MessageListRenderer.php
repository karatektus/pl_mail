<?php

declare(strict_types=1);

namespace App\Service\MessageList;

use App\Entity\Embeddable\MessageList;

/**
 * The message list settings as the page carries them: attributes and custom
 * properties on <html> that app.css reads (#608).
 *
 * ── Why on <html> and not in the row ───────────────────────────────────────
 * A row is drawn in more places than the list page: a Turbo Stream redraws one
 * after a star or a mark-read, a live update inserts a new one, search draws
 * its own. Deciding in the row template would mean every one of those knowing
 * the user's settings, and the settings page could not change the list in
 * front of the reader without a reload. On <html>, the row carries every part
 * it might show and the stylesheet shows the ones asked for — one attribute
 * write, and every row on the page follows.
 *
 * ── Why attributes for some and properties for others ──────────────────────
 * Most choices move a row's SHAPE, and the shape is chosen in container queries
 * (the row goes to one line when the list is wide enough). A container query
 * cannot read a custom property, but a selector can read an attribute, so a
 * choice that has to meet a container query is an attribute. The four left as
 * properties are values a declaration uses directly — a tint multiplier, a bar
 * width, a display — and were properties on Appearance already; app.css did
 * not have to change for them.
 */
final readonly class MessageListRenderer
{
    /**
     * `data-list-*` name => value, every one of them, always: a missing
     * attribute would leave a rule keyed on it matching nothing, and every
     * default is spelled in MessageList rather than in the stylesheet.
     *
     * @return array<string, string>
     */
    public function attributes(MessageList $list): array
    {
        return [
            'data-list-layout'      => $list->layout->value,
            'data-list-preview'     => $list->preview->value,
            'data-list-attachments' => $list->attachments->value,
            'data-list-labels'      => $list->labels->value,
            'data-list-sender'      => $list->sender->value,
            'data-list-date'        => $list->date->value,
            'data-list-count'       => true === $list->messageCount ? 'on' : 'off',
            'data-list-replied'     => true === $list->replied ? 'on' : 'off',
            'data-list-star'        => $list->star->value,
        ];
    }

    /**
     * The custom properties, as `name:value` pairs joined for a style
     * attribute — the same shape AppearanceRenderer::cssVariables() returns,
     * so the layout appends one to the other.
     */
    public function cssVariables(MessageList $list): string
    {
        $variables = [
            '--list-corner-display' => true === $list->accountCorner ? 'block' : 'none',
            '--list-avatar-hide'    => true === $list->avatars ? '0' : '1',
            '--tab-unread-display'  => true === $list->tabUnreadCount ? 'inline' : 'none',
            '--unread-emphasis'     => self::number($list->unreadEmphasis->tintScale()),
            '--unread-bar-w'        => $list->unreadEmphasis->barWidth(),
        ];

        $parts = [];

        foreach ($variables as $name => $value) {
            $parts[] = sprintf('%s:%s', $name, $value);
        }

        return implode(';', $parts);
    }

    /** A float as CSS wants it: no trailing zeroes, and never the empty string. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
