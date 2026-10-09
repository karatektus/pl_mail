<?php

declare(strict_types=1);

namespace App\Entity\Embeddable;

use App\Domain\Enum\Mail\CategorySource;
use Doctrine\ORM\Mapping as ORM;

/**
 * How one person wants their mail sorted into tabs.
 *
 * TWO DECISIONS, AND THEY ARE INDEPENDENT. What decides — headers or the
 * assistant — and whether that decision is allowed to disagree with the mail
 * provider's own. Neither implies the other: somebody may prefer the rules and
 * still want Gmail's categories left alone, or trust the assistant precisely
 * because they think Gmail sorts their mail badly.
 *
 * PER PERSON, NOT PER INSTALLATION, for the reason AiPreferences is: an
 * administrator decides what is AVAILABLE and a reader decides what is used on
 * their own mail. Sorting is the most visible thing this application does to a
 * mailbox without being asked, so it is the last place to take the choice away.
 *
 * A separate embeddable from AiPreferences on purpose. Only half of this is
 * about the assistant, and the provider half applies just as much on an
 * installation with no model at all — folding it in would put a setting that
 * works without AI inside the bag marked AI, where nobody would look for it.
 */
#[ORM\Embeddable]
class CategorySorting
{
    /**
     * Headers or the assistant. See {@see CategorySource}.
     *
     * Stored as its string rather than a Doctrine enum type, matching how
     * every other small choice in this schema is stored: the column survives
     * an enum case being renamed, and {@see CategorySource::from_()} reads it
     * charitably rather than throwing on a value from a future release.
     */
    #[ORM\Column(name: 'source', length: 16, options: ['default' => CategorySource::Rules->value])]
    public string $source = CategorySource::Rules->value;

    /**
     * Whether plMail's own sorting overrules the provider's.
     *
     * WHAT THIS IS ACTUALLY ABOUT IS GMAIL. A Gmail account arrives with
     * Google's own CATEGORY_* labels already on it, and plMail has always
     * treated those as authoritative — its own rules never even run. That is
     * the right default: the categories are already there, the person has
     * probably been living with them for years, and two systems disagreeing
     * about the same mailbox is worse than either.
     *
     * It is also exactly what somebody who dislikes Google's sorting cannot
     * escape, on the account where they most want to. Switching this on makes
     * plMail answer from the source above as though the account were not a
     * Gmail one.
     *
     * Costs nothing to change back and re-sorts nothing on its own: the
     * category is recomputed from stored data, so `app:backfill category` puts
     * an existing mailbox on the new answer whenever the operator chooses.
     */
    #[ORM\Column(name: 'override_provider', type: 'boolean', options: ['default' => false])]
    public bool $overrideProvider = false;

    /**
     * Whether the inbox is shown in tabs at all.
     *
     * A THIRD DECISION, AND INDEPENDENT OF THE TWO ABOVE. Those decide which
     * tab a message is filed under; this decides whether anybody is shown the
     * tabs. Some people do not want their inbox divided (#29), and for them it
     * is one list, newest first, with nothing behind a second click.
     *
     * Off changes what is DISPLAYED and nothing about what is stored. Mail is
     * still filed under a category as it arrives, by whatever the source above
     * says, so switching the tabs back on shows a sorted inbox at once rather
     * than one that has to be re-filed first. That includes the assistant: a
     * person who leaves it sorting mail into tabs they have switched off has
     * been told so on the settings card, and it is theirs to decide.
     *
     * On by default, and true on every existing row: nobody's inbox changes
     * shape because this column appeared.
     */
    #[ORM\Column(name: 'tabs', type: 'boolean', options: ['default' => true])]
    public bool $tabs = true;

    public function sourceEnum(): CategorySource
    {
        return CategorySource::from_($this->source);
    }

    /** The model's verdict is not read at all under the rules. */
    public function ignoresAi(): bool
    {
        return false === $this->sourceEnum()->usesAssistant();
    }

    /**
     * Whether the model's verdict outranks the header cascade.
     *
     * The distinction this makes is the whole feature. Before the setting, the
     * verdict was read only where the cascade had already given up — so a
     * newsletter carrying List-Unsubscribe was Promotions no matter what the
     * model made of it, and nothing said so.
     */
    public function assistantFirst(): bool
    {
        return $this->sourceEnum()->usesAssistant();
    }
}
