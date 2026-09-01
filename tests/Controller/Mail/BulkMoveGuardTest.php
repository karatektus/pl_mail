<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Repository\Label\LabelRepository;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the drop endpoints refuse.
 *
 * Both are reached by dragging, and a drag is aimed by pointer at a target a
 * couple of dozen pixels tall — so the interface is careful about which rows it
 * offers. That care is a template decision, and a template decision is not a
 * rule: the route is a URL anybody can post to, and an interface that hides a
 * control while the route behind it stays open is not a rule, it is a habit.
 * The same argument MailPlacement makes for existing at all.
 *
 * Three refusals, one per thing the sidebar quietly relies on:
 *
 *  - Sent, Drafts and Snoozed are not places mail can be filed. See
 *    LabelRole::acceptsMoves(), which the sidebar and this route both read.
 *  - A category has to be one of the five. MailController falls back to Primary
 *    on a bad `?tab=` because that is a URL somebody may have mistyped; a WRITE
 *    that quietly picks a category on the user's behalf is a different thing.
 *  - "Everything in this view" has no drag behind it and no JobKind to run
 *    under, so it must not reach the worker path.
 */
final class BulkMoveGuardTest extends WebTestCase
{
    private const string USER_EMAIL = 'e2e@plmail.test';

    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if (null !== $this->connection && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->connection = null;

        parent::tearDown();
    }

    public function testMailCannotBeMovedIntoSent(): void
    {
        $client = $this->signedIn();

        $this->post($client, 'move', ['ids' => [], 'labelId' => $this->sentLabelId()]);

        self::assertSame(
            403,
            $client->getResponse()->getStatusCode(),
            'the move route filed mail into Sent, which the sidebar offers no way to ask for',
        );
    }

    /**
     * A destination that does not exist is refused the same way one that is
     * somebody else's is — the two must not be distinguishable, or the route is
     * an existence check over every label id on the server.
     */
    public function testAnUnknownDestinationIsRefused(): void
    {
        $client = $this->signedIn();

        $this->post($client, 'move', ['ids' => [], 'labelId' => 999_999_999]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testAnUnknownCategoryIsRefusedRatherThanGuessed(): void
    {
        $client = $this->signedIn();

        $this->post($client, 'category', ['ids' => [], 'category' => 'quatsch']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * The whole-view path answers "started" and hands the work to a worker.
     * Neither drop action has a JobKind, so arriving there would throw from
     * inside JobKind::forAction() — a 500 for a request that should never have
     * got that far.
     */
    public function testADropCannotAskForTheWholeView(): void
    {
        $client = $this->signedIn();

        $this->post($client, 'category', [
            'all'      => true,
            'scope'    => 'inbox',
            'value'    => 'primary',
            'category' => 'social',
        ]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * The fixture user's Sent label, created here if they have not got one.
     *
     * System labels are made lazily — the first sync, the first archive — so a
     * freshly seeded user has none, and a test that skipped itself on that is a
     * test that never ran anywhere it mattered. Created inside the transaction
     * this case opens, so nothing is left behind for the rest of the suite to
     * trip over.
     */
    private function sentLabelId(): int
    {
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::USER_EMAIL]);

        self::assertNotNull($user);

        $labels   = static::getContainer()->get(LabelRepository::class);
        $existing = $labels->findOneByRoleForUser(LabelRole::Sent, $user);

        if (null !== $existing) {
            return (int) $existing->id;
        }

        $label       = new Label();
        $label->usr  = $user;
        $label->name = 'Sent';
        $label->role = LabelRole::Sent;

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($label);
        $em->flush();

        return (int) $label->id;
    }

    /** @param array<string, mixed> $body */
    private function post(KernelBrowser $client, string $action, array $body): void
    {
        // The `ajax` token, read the way the real caller reads it — from the
        // layout's meta tag. See ThreadStatusGuardsTest::jsonPost() for why it
        // is scraped rather than minted.
        $crawler = $client->request('GET', '/mail/inbox');
        $token   = (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');

        $client->request(
            'POST',
            '/status/bulk/' . $action,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode($body),
        );
    }

    private function signedIn(): KernelBrowser
    {
        $client = static::createClient();

        // The kernel is kept between requests so the transaction below survives
        // them. Without it Symfony reboots for the second request, the
        // connection goes with it, and the label this case creates is committed
        // for the rest of the suite to find.
        $client->disableReboot();

        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::USER_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $client->loginUser($user);

        return $client;
    }
}
