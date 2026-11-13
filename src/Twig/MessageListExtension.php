<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Embeddable\MessageList;
use App\Entity\User\User;
use App\Entity\Mail\MessageThread;
use App\Service\MessageList\MessageListRenderer;
use App\Service\MessageList\MessageListSamples;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The signed-in user's message list settings, for the layout and the settings
 * page (#608).
 *
 * Nobody signed in gets the defaults rather than nothing. An error page is
 * drawn with an empty token even for somebody who is signed in (see
 * AppearanceExtension::appearance()), and a page without the attributes would
 * have a list with no shape: every row rule in app.css is keyed on one.
 * Unlike the theme there is no cookie to fall back on, because nothing on an
 * error page is a list.
 */
final class MessageListExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security            $security,
        private readonly MessageListRenderer $renderer,
        private readonly MessageListSamples  $samples,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('message_list', $this->messageList(...)),
            new TwigFunction('message_list_attributes', $this->attributes(...)),
            new TwigFunction('message_list_vars', $this->variables(...)),
            new TwigFunction('message_list_samples', $this->sampleThreads(...)),
        ];
    }

    public function messageList(): MessageList
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->messageList : new MessageList();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->renderer->attributes($this->messageList());
    }

    public function variables(): string
    {
        return $this->renderer->cssVariables($this->messageList());
    }

    /**
     * The settings page's preview rows, arriving on the reader's own first
     * account; see MessageListSamples.
     *
     * @return list<MessageThread>
     */
    public function sampleThreads(): array
    {
        $user = $this->security->getUser();

        return $this->samples->threads($user instanceof User ? array_values($user->accounts->toArray()) : []);
    }
}
