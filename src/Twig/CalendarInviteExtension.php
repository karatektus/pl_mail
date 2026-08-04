<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Calendar\MessageInvite;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\InviteReader;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `message_invite(message)` — the invitation a message carries, or nothing.
 *
 * A function rather than a controller variable because the thread view renders
 * one partial per message, and that partial is included from three places: the
 * conversation, the single-message view, and the stream that appends a message
 * after it is sent. Threading a per-message lookup through all three means
 * every future caller has to remember to, and the one that forgets shows no
 * card with no error.
 *
 * The user comes from the token rather than the template for the same reason
 * IntegrationsGlobal takes it from there: the partial is included with `only`
 * in places, so `app.user` is not reliably in scope.
 */
final class CalendarInviteExtension extends AbstractExtension
{
    public function __construct(
        private readonly InviteReader $invites,
        private readonly Security     $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('message_invite', $this->forMessage(...)),
            // The three buttons, from the enum that owns their order — so the
            // card cannot offer an answer the responder would refuse.
            new TwigFunction('calendar_invite_answers', ParticipationStatus::answers(...)),
        ];
    }

    public function forMessage(Message $message): ?MessageInvite
    {
        $user = $this->security->getUser();

        return $this->invites->forMessage($message, $user instanceof User ? $user : null);
    }
}
