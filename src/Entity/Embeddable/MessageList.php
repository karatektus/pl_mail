<?php

declare(strict_types=1);

namespace App\Entity\Embeddable;

use App\Domain\Enum\MessageList\AttachmentDisplay;
use App\Domain\Enum\MessageList\DateDisplay;
use App\Domain\Enum\MessageList\LabelDisplay;
use App\Domain\Enum\MessageList\RowLayout;
use App\Domain\Enum\MessageList\RowPreview;
use App\Domain\Enum\MessageList\RowReveal;
use App\Domain\Enum\MessageList\SenderDisplay;
use App\Domain\Enum\Theme\UnreadEmphasis;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a row in the message list shows, and how (#608).
 *
 * Settings → Inbox → Message list. Five of these lived on Appearance until
 * #608: the account corner, the sender discs, the tab counts, the preview
 * lines and the unread emphasis. They were never the look of the app — they
 * decide what a row tells you — and being on Appearance put them in every
 * exported theme, so importing somebody's colours also changed how many
 * preview lines your list had. They moved here with their values (see the
 * migration), and the JMAP Appearance object still serves and accepts them,
 * read from and written to this, so the Android and iOS apps written against
 * that object go on working. The options added in #608 are not on JMAP: a
 * phone's list is a different shape, and whether the apps follow these or
 * keep their own is for the apps to decide.
 *
 * Every default is what the list did before the option existed, so an install
 * that upgrades looks the same until somebody changes something. The one
 * exception is not a change: preview lines used to decide the one-line width
 * as a side effect, and the migration carries that over as an explicit layout.
 *
 * The row does not branch on most of these. They are written onto <html> as
 * attributes (MessageListRenderer), and app.css shows the part of the row each
 * one asks for, so the settings page can change the list under the reader's
 * eyes, and a row redrawn by a Turbo Stream needs no idea whose list it is in.
 * Attachment chips are the exception that needs data, see ThreadRows.
 */
#[ORM\Embeddable]
final class MessageList
{
    /**
     * The range of the `previewLines` count the JMAP Appearance object still
     * publishes for the apps (RowPreview::lines()), stated in the Session the
     * way it was when the count was a column of its own.
     */
    public const array RANGE_PREVIEW_LINES = ['min' => 0, 'max' => 2];

    #[ORM\Column(type: 'string', length: 16, enumType: RowLayout::class, options: ['default' => 'fit'])]
    public RowLayout $layout = RowLayout::Fit;

    #[ORM\Column(type: 'string', length: 16, enumType: RowPreview::class, options: ['default' => 'one'])]
    public RowPreview $preview = RowPreview::One;

    #[ORM\Column(type: 'string', length: 16, enumType: AttachmentDisplay::class, options: ['default' => 'icon'])]
    public AttachmentDisplay $attachments = AttachmentDisplay::Icon;

    #[ORM\Column(type: 'string', length: 16, enumType: LabelDisplay::class, options: ['default' => 'line'])]
    public LabelDisplay $labels = LabelDisplay::Line;

    #[ORM\Column(type: 'string', length: 16, enumType: SenderDisplay::class, options: ['default' => 'everyone'])]
    public SenderDisplay $sender = SenderDisplay::Everyone;

    #[ORM\Column(type: 'string', length: 16, enumType: DateDisplay::class, options: ['default' => 'smart'])]
    public DateDisplay $date = DateDisplay::Smart;

    /** The "3" after a conversation's subject. */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    public bool $messageCount = true;

    /**
     * An arrow in front of the subject when you have answered the newest mail
     * in the conversation. OFF by default: the list never drew one, and a new
     * mark on every answered row is a change nobody asked for until they come
     * here and do.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    public bool $replied = false;

    #[ORM\Column(type: 'string', length: 16, enumType: RowReveal::class, options: ['default' => 'hover'])]
    public RowReveal $star = RowReveal::Hover;

    /**
     * The sender disc — the coloured circle carrying an initial.
     *
     * "Off" does not remove the control, and cannot: the disc IS the row's
     * checkbox (the real input is sr-only behind it, and every bulk action in
     * the toolbar reads it). So off means the disc loses its colour and its
     * letter and becomes a plain outlined circle in the same slot — the
     * identity goes, the selection target and the row's geometry stay. See
     * app.css, which paints that over it rather than removing anything.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    public bool $avatars = true;

    /**
     * The account corner: a coloured triangle clipped to a row's lower-left,
     * saying which account the row arrived on.
     *
     * Optional because it is identity vocabulary, and not everybody wants their
     * list carrying it — but ON by default, because a unified list across three
     * accounts is genuinely ambiguous without it (the same Message-ID delivered
     * to three addresses puts three identical rows on screen) and the person
     * who does not want it is one click from not having it. It is drawn only in
     * a unified list on a multi-account install anyway; on one account it has
     * never appeared at all, and this setting does not change that.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    public bool $accountCorner = true;

    #[ORM\Column(type: 'string', length: 16, enumType: UnreadEmphasis::class, options: ['default' => 'standard'])]
    public UnreadEmphasis $unreadEmphasis = UnreadEmphasis::Standard;

    /**
     * The unread count beside each inbox tab's name. Not a row setting: it is
     * stored here because it is about the list, and set on the Categories page
     * beside the tabs it counts.
     *
     * ON by default, because it answers a question nothing else on the strip
     * can: the sidebar's Inbox badge says there is unread mail and not which
     * tab it is in. OPTIONAL, for the same reason the account corner is: it is
     * one more number on a strip that already carries the "3 new" pill, and
     * the person who reads the pill and the bold rows and wants nothing else
     * is one click from not having it.
     *
     * Off hides the number and nothing else. The tint on a tab's icon and the
     * pill are separate statements and stay.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    public bool $tabUnreadCount = true;

    /**
     * Every setting under the name the settings page and the backup use.
     *
     * @return array<string, string|bool>
     */
    public function toArray(): array
    {
        return [
            'layout'         => $this->layout->value,
            'preview'        => $this->preview->value,
            'attachments'    => $this->attachments->value,
            'labels'         => $this->labels->value,
            'sender'         => $this->sender->value,
            'date'           => $this->date->value,
            'messageCount'   => $this->messageCount,
            'replied'        => $this->replied,
            'star'           => $this->star->value,
            'avatars'        => $this->avatars,
            'accountCorner'  => $this->accountCorner,
            'unreadEmphasis' => $this->unreadEmphasis->value,
            'tabUnreadCount' => $this->tabUnreadCount,
        ];
    }

    /**
     * Applies whatever of toArray()'s keys the payload carries and ignores the
     * rest, so the settings page can send one field and a backup all of them.
     * A value outside a closed list keeps what is stored rather than failing:
     * a backup from a newer install may know an option this one does not.
     *
     * @param array<array-key, mixed> $data
     */
    public function applyArray(array $data): static
    {
        $this->layout         = self::pick($data, 'layout', RowLayout::class, $this->layout);
        $this->preview        = self::pick($data, 'preview', RowPreview::class, $this->preview);
        $this->attachments    = self::pick($data, 'attachments', AttachmentDisplay::class, $this->attachments);
        $this->labels         = self::pick($data, 'labels', LabelDisplay::class, $this->labels);
        $this->sender         = self::pick($data, 'sender', SenderDisplay::class, $this->sender);
        $this->date           = self::pick($data, 'date', DateDisplay::class, $this->date);
        $this->star           = self::pick($data, 'star', RowReveal::class, $this->star);
        $this->unreadEmphasis = self::pick($data, 'unreadEmphasis', UnreadEmphasis::class, $this->unreadEmphasis);

        foreach (['messageCount', 'replied', 'avatars', 'accountCorner', 'tabUnreadCount'] as $flag) {
            if (true === isset($data[$flag])) {
                $this->{$flag} = self::boolean($data[$flag]);
            }
        }

        return $this;
    }

    /**
     * The list settings as a payload from before #608 named them: the five that
     * were Appearance's, under their old keys, in a config backup or a JMAP
     * patch. The same translation the #608 migration made in the database —
     * preview lines splitting into a preview and a layout, since 1 also meant
     * "one line from 48rem" and 0 and 2 meant 75rem. Keys it does not carry
     * are left out, so applyArray() leaves those settings alone.
     *
     * @param array<array-key, mixed> $appearance
     *
     * @return array<string, mixed>
     */
    public static function fromLegacyAppearance(array $appearance): array
    {
        $list = [];

        foreach (['accountCorner' => 'accountCorner', 'listAvatars' => 'avatars', 'tabUnreadCount' => 'tabUnreadCount', 'unreadEmphasis' => 'unreadEmphasis'] as $old => $new) {
            if (true === array_key_exists($old, $appearance)) {
                $list[$new] = $appearance[$old];
            }
        }

        if (true === isset($appearance['previewLines']) && true === is_numeric($appearance['previewLines'])) {
            $lines           = (int) $appearance['previewLines'];
            $list['preview'] = RowPreview::fromLines($lines)->value;
            $list['layout']  = 1 === $lines ? RowLayout::Fit->value : RowLayout::Wide->value;
        }

        return $list;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<array-key, mixed> $data
     * @param class-string<T>         $enum
     * @param T                       $current
     *
     * @return T
     */
    private static function pick(array $data, string $key, string $enum, \BackedEnum $current): \BackedEnum
    {
        if (false === isset($data[$key]) || false === is_string($data[$key])) {
            return $current;
        }

        return $enum::tryFrom($data[$key]) ?? $current;
    }

    /**
     * A checkbox over the wire: the settings page posts "1" and "0", a backup
     * and JSON post real booleans. "0" is a non-empty string and therefore
     * true to PHP, which is the bug a plain (bool) cast would be. The same
     * reading as Appearance's, so a value means the same on both.
     */
    private static function boolean(mixed $value): bool
    {
        if (true === is_bool($value)) {
            return $value;
        }

        return false === in_array($value, ['0', 0, 'false', '', null], true);
    }
}
