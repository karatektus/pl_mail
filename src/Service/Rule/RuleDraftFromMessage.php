<?php

declare(strict_types=1);

namespace App\Service\Rule;

use App\Domain\Filter\FilterAstValidator;
use App\Domain\Filter\FilterVocabulary;
use App\Domain\Filter\InvalidFilterException;
use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\Message;
use App\Entity\Rule\MailRule;

/**
 * The filter "Create a filter from this message" opens the editor with: the
 * sender as its condition, the sender's name as its name, and no action.
 *
 * A draft, never saved here. The editor shows it, the live count says how
 * much mail it already catches, and the reader picks what it does — a tab, a
 * label, Spam — and saves it like any rule they wrote from scratch. Choosing
 * the action for them would be a guess, and a filter acts on mail nobody is
 * watching.
 *
 * `fromAddress`, not `from`, for the reason SpamFilterCreator gives: `from` is
 * a substring over address and display name, so a filter for `a@x.de` built on
 * it would also catch `ba@x.de`, and anybody could land in it by putting the
 * address in their display name. Widening it to the whole domain is one click
 * in the editor.
 */
final readonly class RuleDraftFromMessage
{
    public function __construct(
        private FilterAstValidator $validator,
    ) {}

    /** The draft, or a blank rule when the message has no usable sender. */
    public function draft(Message $message): MailRule
    {
        $rule    = new MailRule();
        $address = AddressHelper::email($message->fromAddress);

        if (false === FilterVocabulary::isSenderValue('fromAddress', $address)) {
            return $rule;
        }

        $conditions = ['operator' => 'AND', 'conditions' => [['fromAddress' => $address]]];

        // The same gate every stored condition goes through: the tree feeds a
        // SQL compiler. Built from a checked address it cannot fail today, and
        // if it ever does, a blank editor is the honest answer.
        try {
            $this->validator->validate($conditions);
        } catch (InvalidFilterException) {
            return $rule;
        }

        $name = AddressHelper::name($message->fromName);

        $rule->name       = mb_substr('' !== $name ? $name : $address, 0, 120);
        $rule->conditions = $conditions;

        return $rule;
    }
}
