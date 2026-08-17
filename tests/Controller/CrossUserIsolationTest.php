<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * One signed-in user, another user's rows, over real HTTP.
 *
 * OwnershipVoterTest proves the rule in isolation; this proves it is actually
 * WIRED — that the routes ask, that the firewall runs the voter, and that a
 * refusal comes back as a refusal rather than as a page. The two are worth
 * having separately: a voter that is correct and never called looks exactly
 * like a voter that works, and only one of them keeps anybody's mail private.
 *
 * Every case here is a real id belonging to a real second user, so a route that
 * silently dropped its check would answer 200 and fail loudly rather than
 * 404-ing its way to a pass.
 */
final class CrossUserIsolationTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * Routes keyed on an owned entity, one per entity kind the voter models.
     *
     * Every case was confirmed to BITE, by stubbing OwnershipVoter to grant
     * everything and checking that all four then fail. That check is why two
     * earlier candidates are not here: `/account/{id}/alias` and
     * `/account/{id}/compose-defaults` exist only as POSTs, so they answered a
     * routing 404 and would have passed against no ownership check at all, and
     * `/settings/health/reconnect/{id}` refuses a password account before it
     * ever looks at the owner. A case that cannot fail is worse than no case:
     * it reads like coverage.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function foreignSubjects(): iterable
    {
        yield 'an account, via its mailbox'       => ['/mail/account/%d', 'account'];
        yield 'a calendar, via its settings form' => ['/settings/calendars/%d/edit', 'calendar'];
        yield 'a label, via its edit form'        => ['/labels/%d/edit', 'label'];
        yield 'a rule, via its edit form'         => ['/settings/filters/%d/edit', 'rule'];
    }

    #[DataProvider('foreignSubjects')]
    public function testAForeignSubjectIsRefused(string $template, string $key): void
    {
        [$client, $stranger] = $this->signedInWithAStranger();

        $client->request('GET', sprintf($template, $stranger[$key]));

        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [403, 404],
            sprintf('%s served another user\'s %s', $template, $key),
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * Signs in as one user and returns the ids of a SECOND user's rows.
     *
     * Both users are created here rather than reusing the seeded fixtures,
     * because the point is a pair: a test that signed in as the seeded user and
     * guessed at ids would pass on a 404 that meant "no such row" instead of
     * "not yours". Everything is rolled back in tearDown.
     *
     * @return array{KernelBrowser, array{account: int, calendar: int, label: int, rule: int}}
     */
    private function signedInWithAStranger(): array
    {
        $client = static::createClient();
        $client->disableReboot();

        $container         = static::getContainer();
        $this->em          = $container->get(EntityManagerInterface::class);
        $this->connection  = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $me       = $this->makeUser('owner');
        $stranger = $this->makeUser('stranger');

        $account = new Account();
        $account->usr            = $stranger;
        $account->email          = 'Stranger';
        $account->username       = 'stranger-' . uniqid('', true) . '@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        $label = new Label();
        $label->usr  = $stranger;
        $label->name = 'Stranger label';
        $this->em->persist($label);

        $calendar            = new Calendar();
        $calendar->usr       = $stranger;
        $calendar->name      = 'Stranger calendar';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'UTC';
        $calendar->isDefault = true;
        $this->em->persist($calendar);

        $rule = new MailRule();
        $rule->usr  = $stranger;
        $rule->name = 'Stranger rule';
        $this->em->persist($rule);

        $this->em->flush();

        $client->loginUser($me);

        return [$client, [
            'account'  => (int) $account->id,
            'calendar' => (int) $calendar->id,
            'label'    => (int) $label->id,
            'rule'     => (int) $rule->id,
        ]];
    }

    private function makeUser(string $tag): User
    {
        $user = new User();
        $user->email     = $tag . '-' . uniqid('', true) . '@example.test';
        $user->nameFirst = ucfirst($tag);
        $user->nameLast  = 'Isolation';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);

        return $user;
    }
}
