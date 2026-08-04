<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Calendar\EventProposal;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\Proposal\ProposalReader;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `message_event_proposal(message)` — the date this message proposes, or nothing.
 *
 * The sibling of CalendarInviteExtension and written the same way for the same
 * reasons. A function rather than a controller variable, because the thread view
 * renders one partial per message and that partial is included from three places
 * — the conversation, the single-message view, and the stream that appends a
 * message after it is sent. Threading a per-message lookup through all three
 * means every future caller has to remember to, and the one that forgets shows
 * no card and no error.
 *
 * The batching that makes this affordable is in ProposalReader: the first
 * question about any message in a conversation loads the whole conversation's
 * proposals, so a fifty-message thread costs one query rather than fifty.
 *
 * The user comes from the token rather than the template, because the partial is
 * included with `only` in places and `app.user` is not reliably in scope.
 */
final class CalendarProposalExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProposalReader $proposals,
        private readonly Security       $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('message_event_proposal', $this->forMessage(...)),
        ];
    }

    public function forMessage(Message $message): ?EventProposal
    {
        $user = $this->security->getUser();

        return $this->proposals->forMessage($message, $user instanceof User ? $user : null);
    }
}
