<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Mail\SendOutcomeNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * What the browser is told about a send, and when.
 *
 * THE BUG THIS REPLACES
 *
 * A send is held for ten seconds so it can be called off. The browser marked
 * the end of the CANCEL window — eight seconds — by asking the server to tidy
 * up, and that answer carried "Message sent." So the confirmation arrived two
 * seconds before the first byte left, every time, for everyone.
 *
 * And it was never corrected. All three senders turn a failure into a bare
 * `false`, which SendMessageHandler treated as handled — no retry, no
 * failed-transport row, nothing — so a send that was refused ended in silence
 * behind a toast that had already claimed it worked.
 */
final class SendOutcomeNotifierTest extends TestCase
{
    public function testASuccessIsPublishedToTheOwnersOwnTopic(): void
    {
        $published = [];
        $notifier  = $this->notifier($published);

        $notifier->sent($this->message(41));

        self::assertCount(1, $published);
        self::assertSame(['mail/user/41'], $published[0]->getTopics());

        $payload = json_decode($published[0]->getData(), true);

        self::assertSame('mail.send-outcome', $payload['type']);
        self::assertTrue($payload['sent']);
    }

    /**
     * The failure is announced too, and that is the whole point: it is the case
     * that had no surface at all.
     */
    public function testAFailureIsPublishedAsWell(): void
    {
        $published = [];
        $notifier  = $this->notifier($published);

        $notifier->failed($this->message(41));

        self::assertCount(1, $published);

        $payload = json_decode($published[0]->getData(), true);

        self::assertFalse($payload['sent']);
    }

    /**
     * The markup is RENDERED, not described.
     *
     * The alternative is a payload the browser turns into a toast itself, which
     * means a second copy of _toast.html.twig in JavaScript, drifting from the
     * first. Asserting the payload carries a turbo-stream is what stops that
     * being quietly reintroduced.
     */
    public function testItCarriesAStreamTheBrowserCanApplyDirectly(): void
    {
        $published = [];
        $notifier  = $this->notifier($published, '<turbo-stream action="append" target="toast-region"></turbo-stream>');

        $notifier->sent($this->message(41));

        $payload = json_decode($published[0]->getData(), true);

        self::assertStringContainsString('<turbo-stream', $payload['stream']);
        self::assertStringContainsString('toast-region', $payload['stream']);
    }

    /**
     * An account nobody owns has no topic to publish to.
     *
     * Account::$usr is nullable because an account exists before it is filed
     * under anybody, and a Mercure topic built from a null id would be
     * `mail/user/` — a topic every user's subscription would or would not match
     * depending on the hub's pattern rules, which is not something to find out
     * in production.
     */
    public function testAnAccountWithNoOwnerPublishesNothing(): void
    {
        $published = [];
        $notifier  = $this->notifier($published);

        $message                  = new Message();
        $message->account         = new Account();
        $message->account->usr    = null;

        $notifier->sent($message);

        self::assertSame([], $published);
    }

    /** @param list<Update> $published */
    private function notifier(array &$published, string $rendered = '<turbo-stream></turbo-stream>'): SendOutcomeNotifier
    {
        // A STUB, not a mock: nothing here asserts on how the hub was called,
        // only on what came out of it, and PHPUnit is right to say so. Stubbed
        // rather than hand-rolled because HubInterface has grown methods twice
        // (getProtocolVersion, getCookieName) and a fake class here would have
        // to grow with it for no benefit to what is being tested.
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(
            static function (Update $update) use (&$published): string {
                $published[] = $update;

                return 'id';
            },
        );

        // A real Twig over an ArrayLoader rather than a mock of a concrete
        // class: what matters here is that whatever the template produces
        // reaches the payload intact, and a loader with one entry says that
        // without PHPUnit having to fake a final-ish framework class.
        $twig = new Environment(new ArrayLoader([
            'mail/_send_outcome.stream.html.twig' => $rendered,
        ]));

        return new SendOutcomeNotifier($hub, $twig);
    }

    private function message(int $userId): Message
    {
        $user = new User();

        // The id is what the topic is built from, and it is not settable.
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $userId);

        $account      = new Account();
        $account->usr = $user;

        $message          = new Message();
        $message->account = $account;
        $message->subject = 'A subject';

        return $message;
    }
}
