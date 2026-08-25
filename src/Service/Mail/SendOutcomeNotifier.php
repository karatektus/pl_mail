<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Message;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

/**
 * Tells the browser what became of a send, once it is actually known.
 *
 * THE PROBLEM THIS SOLVES
 *
 * A send is held for ten seconds so it can be called off. The cancel window
 * closes at eight, and the browser marked that moment by asking the server to
 * tidy up — which answered with "Message sent." So the confirmation arrived two
 * seconds before the first byte left, always, for everyone. If the send then
 * failed, nothing said so: the toast had already claimed success and there was
 * no second surface to correct it.
 *
 * Now the eight-second mark only tidies, and the outcome is published from the
 * worker at the moment there is an outcome.
 *
 * WHY IT PUBLISHES RENDERED HTML
 *
 * The alternative is a payload the browser turns into a toast itself, which
 * means a second copy of _toast.html.twig living in JavaScript and drifting
 * from the first. Rendering here reuses the partial exactly, and core--mercure
 * hands the result to Turbo.
 *
 * BEST EFFORT, AND HONESTLY SO
 *
 * This rides the live-updates stream. With the hub down the user gets no toast
 * at all — which is a confirmation missing, not a confirmation that lies, and
 * that is the right way round. The mail's own row is the durable record either
 * way: a send that worked leaves the message in Sent.
 */
final readonly class SendOutcomeNotifier
{
    public function __construct(
        private HubInterface $hub,
        private Environment  $twig,
    ) {
    }

    public function sent(Message $message): void
    {
        $this->publish($message, true);
    }

    public function failed(Message $message): void
    {
        $this->publish($message, false);
    }

    private function publish(Message $message, bool $sent): void
    {
        // Message::$account is non-nullable; Account::$usr is not, because an
        // account is created before it is filed under anybody. No topic without
        // a user, and nothing to publish to.
        $userId = $message->account->usr?->id;

        if (null === $userId) {
            return;
        }

        $this->hub->publish(new Update(
            topics: [sprintf('mail/user/%d', $userId)],
            data: json_encode([
                'type'   => 'mail.send-outcome',
                'sent'   => $sent,
                'stream' => $this->twig->render('mail/_send_outcome.stream.html.twig', [
                    'message' => $message,
                    'sent'    => $sent,
                ]),
            ]),
        ));
    }
}
