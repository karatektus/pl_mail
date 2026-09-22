<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use App\Tests\Support\Mercure\RecordingHub;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\HubInterface;

/**
 * A label renamed in one tab reaches the others.
 *
 * ── The regression this closes ───────────────────────────────────────────────
 * v0.2.34 made a mail-list navigation answer with the list frame instead of a
 * whole document, which took roughly twelve database queries out of every
 * folder click — the sidebar had been rebuilt on each one. The price, written
 * into the CHANGELOG at the time: a label renamed in another tab or on another
 * device reached this tab's sidebar on the next SYNC rather than on the next
 * click. On an idle mailbox that is minutes; on an installation with no
 * accounts configured there is no sync at all, and it was never.
 *
 * LabelController::labelListsStream() had the right markup all along — it
 * re-renders the desktop sidebar, the mobile drawer and the settings list — and
 * sent it to exactly one tab, the one that had pressed the button. The same
 * markup now goes on the user's Mercure topic as well.
 *
 * ── Why these four ───────────────────────────────────────────────────────────
 * The topic is the one that cannot be got wrong quietly. Labels are a user's
 * own names for their own mail, so a topic naming the wrong user is a leak
 * rather than a bug, and nothing on screen would give it away. It is asserted
 * against the owner's id and against a stranger's, both.
 *
 * The toast is the one that would be visible and wrong: "Label updated." is for
 * whoever pressed the button, so publishing it would announce itself in every
 * other tab of theirs — and land twice in this one, beside the copy the HTTP
 * response already carried.
 *
 * The dead hub is the one that turns a supported deployment into a 500. Mercure
 * is optional in plMail; a rename has to work without it.
 */
final class LabelChangeReachesEveryTabTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private RecordingHub $hub;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Fixtures live in a transaction this test rolls back, and a rebooted
        // kernel takes the connection holding it with them.
        $this->client->disableReboot();

        $container = static::getContainer();

        // FIRST, before anything else is pulled out of the container.
        //
        // The hub is not built lazily on first publish: HubRegistry takes the
        // default hub as a constructor argument, so the first service that
        // reaches for Mercure — the response subscriber that mints the
        // subscriber cookie — instantiates it. Once it is in the container's
        // private slots the test container refuses to replace it ("already
        // initialized"), and fetching the entity manager below is already
        // enough to get there.
        $this->hub = new RecordingHub();
        $container->set(HubInterface::class, $this->hub);

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The whole point, in one request: rename a label, and the lists every
     * other tab of this user needs are already on the wire, rendered.
     */
    public function testARenamePublishesTheListsOnTheOwnersOwnTopic(): void
    {
        $label = $this->seedLabel('Receipts');

        $this->rename($label, 'Invoices');

        self::assertCount(1, $this->hub->updates, 'one mutation, one publish');

        self::assertSame(
            ['mail/user/' . $this->user->id],
            $this->hub->updates[0]->getTopics(),
            'the page subscribes to mail/user/{id} and the cookie authorises that and nothing else',
        );

        $payload = $this->payload();

        self::assertSame('labels.changed', $payload['type']);
        self::assertStringContainsString('Invoices', $payload['stream'], 'the new name, already rendered');

        // All three regions, because the receiving tab may be showing any of
        // them and a stream whose target is absent is a no-op.
        foreach (['label-list', 'label-list-drawer', 'settings-label-list'] as $target) {
            self::assertStringContainsString(
                'target="' . $target . '"',
                $payload['stream'],
                sprintf('%s is one of the three regions a label mutation refreshes', $target),
            );
        }
    }

    /**
     * Nobody else's browser, and the proof is the id that is NOT in the topic.
     *
     * The topic is built from the label owner, which the controller reads off
     * the session rather than out of the request, so it cannot be talked into
     * naming somebody else. Asserted anyway: a mistake here would show up
     * nowhere on screen. The same paragraph, for the same reason, is in
     * MercureCookieSubscriber and MercureAuthController.
     */
    public function testTheTopicAndTheNamesOnItAreTheOwnersAlone(): void
    {
        $owner = $this->user;

        // A stranger with a label of their own, so "somebody else's id" is a
        // real id a wrongly-scoped topic could plausibly have carried, and
        // "somebody else's name" is a real string that could have leaked into
        // a wrongly-scoped tree.
        $this->user = $this->seedUser();
        $stranger   = $this->user;
        $this->seedLabel('Strangers Private Label');
        $this->user = $owner;

        $label = $this->seedLabel('Receipts');

        $this->rename($label, 'Invoices');

        $topics = $this->hub->updates[0]->getTopics();

        self::assertSame(['mail/user/' . $owner->id], $topics);
        self::assertNotContains('mail/user/' . $stranger->id, $topics);

        self::assertStringNotContainsString('Strangers Private Label', $this->payload()['stream']);
    }

    /**
     * The toast belongs to whoever pressed the button.
     *
     * The two copies of the lists differ by exactly this, and this is the
     * assertion that keeps them differing — it is also the reason the tab that
     * made the change can apply its own publish without anything flickering:
     * what comes back is three `replace` actions carrying the markup already on
     * screen, and nothing that appends.
     */
    public function testTheToastStaysInTheAnswerAndOffTheWire(): void
    {
        $label = $this->seedLabel('Receipts');

        $this->rename($label, 'Invoices');

        self::assertStringNotContainsString(
            'toast-region',
            $this->payload()['stream'],
            'the other tabs pressed nothing',
        );

        self::assertStringContainsString(
            'toast-region',
            (string) $this->client->getResponse()->getContent(),
            'and this one is still told it worked',
        );
    }

    /**
     * Mercure is optional. Plenty of self-hosted installations run without a
     * hub, and on those every publish throws — so a rename that reported the
     * hub's failure as its own would make the feature unusable for them. The
     * label is already written and committed by the time anything is published.
     */
    public function testARenameStillWorksWithNoHubToPublishTo(): void
    {
        $label = $this->seedLabel('Receipts');

        $this->hub->refuse = true;

        $this->rename($label, 'Invoices');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Read back rather than re-read off $label: the request cleared the
        // entity manager on its way out, so the instance this test is holding
        // is detached and would answer from memory either way.
        $this->em->clear();

        self::assertSame(
            'Invoices',
            $this->em->find(Label::class, $label->id)?->name,
            'the rename is committed, hub or no hub',
        );
    }

    /** @return array{type: string, stream: string} */
    private function payload(): array
    {
        return json_decode(
            $this->hub->updates[0]->getData(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The rename, through the form the browser posts.
     *
     * Fetched and submitted rather than hand-built, so the CSRF token and the
     * parent and colour fields are exactly what a person's click sends — a
     * hand-built POST is answered 422 by the form, which is a green-looking way
     * to assert nothing at all.
     */
    private function rename(Label $label, string $name): void
    {
        $crawler = $this->client->request('GET', '/labels/' . $label->id . '/edit');

        $form                 = $crawler->filter('form')->form();
        $form['label[name]'] = $name;

        $this->client->submit($form);
    }
}
