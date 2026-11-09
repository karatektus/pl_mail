<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * The buttons of the compose window's action bar that a person can put in an
 * order of their own — everything to the right of Send but ⋮, which is where
 * the ones that do not fit go and so stays last.
 *
 * **The case order is the default order**, the one the bar had before it could
 * be arranged; nothing else says what it is.
 *
 * The value is what a choice is stored as (User::SETTING_COMPOSE_ACTION_ORDER)
 * and what the button carries as `data-compose-action`, so renaming a case
 * would orphan every stored order. Add cases freely; do not rename. A case the
 * window does not draw for somebody — the assistant when it is off, the
 * service picker without a connected service — keeps its place in their order
 * all the same, so switching it on later puts it back where they had it.
 */
enum ComposeAction: string
{
    case Format = 'format';
    case Attach = 'attach';
    case Link = 'link';
    case Emoji = 'emoji';
    case Image = 'image';
    case Encrypt = 'encrypt';
    case Signature = 'signature';
    case Template = 'template';
    case Assistant = 'assistant';
    case Service = 'service';
    case Discard = 'discard';

    /**
     * A stored order made whole: the cases it names, in its order, then every
     * case it does not name, in the default order — a button added in a later
     * version lands at the end of somebody's own order rather than nowhere.
     * Anything that is not a case is passed over, so a bag written by an older
     * or newer version still reads.
     *
     * @param mixed $stored what the settings bag holds; anything but a list is "never chosen"
     *
     * @return list<self>
     */
    public static function ordered(mixed $stored): array
    {
        $order = [];

        if (true === is_array($stored)) {
            foreach ($stored as $value) {
                $case = is_string($value) ? self::tryFrom($value) : null;

                if (null !== $case && false === in_array($case, $order, true)) {
                    $order[] = $case;
                }
            }
        }

        foreach (self::cases() as $case) {
            if (false === in_array($case, $order, true)) {
                $order[] = $case;
            }
        }

        return $order;
    }
}
