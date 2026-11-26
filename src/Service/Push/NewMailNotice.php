<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Entity\Mail\Message;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a browser's "new mail" notification says, for somebody who asked for
 * the sender and the subject (#760): finished strings in the reader's language,
 * which the service worker shows as they are.
 *
 * NEVER IN THE PUSH. The push stays content-free; the service worker asks for
 * this over the reader's own session when the push arrives
 * (WebPushController::inboxState()), the way it already asked whether the mail
 * is still unread. So nothing about the mail passes the push service, and mail
 * read on another device in the meantime is not named.
 *
 * ONE MESSAGE, OR WHAT ARRIVED WITH IT. The mail a push is about is the newest
 * recent unread mail, and the mail that came in the same breath: an import or a
 * sync that brought three. Not everything unread in the recent window: a letter
 * from five minutes ago would turn every later notification into "2 new
 * messages" and hide the subject of the one that just came.
 */
final readonly class NewMailNotice
{
    /** How close to the newest message another one has to arrive to count as the same delivery. */
    public const int TOGETHER_SECONDS = 60;

    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The notice for the recent unread mail a push may be about, newest first
     * as NotifyScopes::recentUnread() gives it; null for none, and the worker
     * then says "New mail" as it always did.
     *
     * @param list<Message> $recent
     *
     * @return array{title: string, body: string, url: string}|null
     */
    public function for(array $recent): ?array
    {
        if ([] === $recent) {
            return null;
        }

        $newest   = $recent[0];
        $earliest = $newest->createdAt->modify(sprintf('-%d seconds', self::TOGETHER_SECONDS));
        $together = array_values(array_filter($recent, static fn (Message $message): bool => $message->createdAt >= $earliest));

        if (1 === count($together)) {
            return [
                'title' => $this->sender($newest),
                'body'  => $this->subject($newest),
                'url'   => null === $newest->thread?->id
                    ? $this->urls->generate('app_mail_inbox')
                    : $this->urls->generate('app_mail_thread', ['id' => $newest->thread->id]),
            ];
        }

        return [
            'title' => $this->translator->trans('message.push_notice.several', ['%count%' => count($together)]),
            'body'  => implode(', ', array_values(array_unique(array_map($this->sender(...), $together)))),
            'url'   => $this->urls->generate('app_mail_inbox'),
        ];
    }

    private function sender(Message $message): string
    {
        $name = trim((string) $message->fromName);

        if ('' !== $name) {
            return $name;
        }

        $address = trim((string) $message->fromAddress);

        return '' !== $address ? $address : $this->translator->trans('message.push_notice.unknown_sender');
    }

    private function subject(Message $message): string
    {
        $subject = trim((string) $message->subject);

        return '' !== $subject ? $subject : $this->translator->trans('thread_row.no_subject');
    }
}
