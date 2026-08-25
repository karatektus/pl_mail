<?php

declare(strict_types=1);

namespace App\Tests\Controller\Demo;

use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Repository\User\UserRepository;
use App\Infrastructure\Messaging\Handler\DemoAutoReplyHandler;
use App\Infrastructure\Messaging\Message\DemoAutoReplyMessage;
use App\Service\Demo\DemoInbox;
use App\Service\Demo\DemoMailbox;
use App\Service\Demo\DemoScenario;
use App\Service\Demo\DemoMode;
use App\Service\Demo\DemoProvisioner;
use App\Service\Demo\DemoScenarios;
use App\Domain\Helper\SignatureStorage;
use App\Service\Demo\DemoUserEraser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Demo mode end to end: the door, the button, and the clean-up after.
 *
 * Demo mode is an environment variable read through a parameter, and parameters
 * built from `%env()%` stay dynamic — so flipping $_SERVER before the kernel
 * boots is enough to get a demo container, and unsetting it afterwards gets an
 * ordinary one back. That is what lets the "switched off" half be tested at all,
 * and it is the half that matters most: /demo mints a logged-in user without
 * asking for credentials, so on a normal install it must not exist.
 */
final class DemoFlowTest extends WebTestCase
{
    protected function setUp(): void
    {
        unset($_SERVER['APP_DEMO_MODE'], $_ENV['APP_DEMO_MODE']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_DEMO_MODE'], $_ENV['APP_DEMO_MODE']);

        parent::tearDown();
    }

    private function demoClient(): KernelBrowser
    {
        $_SERVER['APP_DEMO_MODE'] = '1';
        $_ENV['APP_DEMO_MODE']    = '1';

        $client = static::createClient();

        // The provisioning limiter is keyed per IP and its counter lives in a
        // cache that outlives the test, so every test in this class — and every
        // previous run — shares one budget. Left alone, the suite starts
        // passing and then quietly starts 429ing partway down the file, which
        // reads as a bug in the door rather than in the test. Same trap, same
        // fix, as the login throttle in the Playwright suite.
        static::getContainer()
            ->get(RateLimiterFactoryInterface::class.' $demoProvisionLimiter')
            ->create('127.0.0.1')
            ->reset();

        return $client;
    }

    /**
     * Removes whatever a test provisioned, so a run does not leave throwaway
     * users behind in the test database for the next one to trip over.
     */
    private function eraseDemoUsers(): void
    {
        $container = static::getContainer();
        $mode      = $container->get(DemoMode::class);
        $eraser    = $container->get(DemoUserEraser::class);

        foreach ($container->get(UserRepository::class)->findAll() as $user) {
            if (true === $mode->ownsAddress($user->email)) {
                $eraser->erase($user);
            }
        }
    }

    /**
     * The demo visitor this test provisioned.
     */
    private function visitor(): ?\App\Entity\User\User
    {
        $container = static::getContainer();
        $mode      = $container->get(DemoMode::class);

        foreach ($container->get(UserRepository::class)->findAll() as $user) {
            if (true === $mode->ownsAddress($user->email)) {
                return $user;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- the door

    public function testTheDemoDoorDoesNotExistOnANormalInstall(): void
    {
        $client = static::createClient();

        $client->request('GET', '/demo');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * /demo/receive is behind the firewall's catch-all ROLE_USER rule, so an
     * anonymous caller is sent to the login form before routing ever resolves
     * the controller that would have 404'd. That is the stronger answer, not a
     * weaker one: the response is identical to the one any unrouted path under
     * the firewall gives, so it says nothing about whether this endpoint
     * exists.
     */
    public function testTheReceiveEndpointTellsAnAnonymousCallerNothing(): void
    {
        $client = static::createClient();

        $client->request('POST', '/demo/receive');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * The login page is where most visitors land — following a link to the
     * root with no session redirects here — so the way in has to be on it. The
     * credentials form stays behind the button for anyone returning to a
     * session they were already given.
     */
    public function testTheLoginPageOffersTheWayInOnADemo(): void
    {
        $client  = $this->demoClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href="/demo"]')->count(),
            'the login page should offer a way into the demo',
        );
    }

    public function testTheLoginPageOffersNoSuchThingOnANormalInstall(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('a[href="/demo"]')->count());
    }

    public function testArrivingAtTheDemoProvisionsAMailboxAndSignsTheVisitorIn(): void
    {
        $client    = $this->demoClient();
        $container = static::getContainer();

        $before = count($container->get(UserRepository::class)->findAll());

        $client->request('GET', '/demo');

        self::assertResponseRedirects();

        $users = $container->get(UserRepository::class)->findAll();

        self::assertCount($before + 1, $users, 'exactly one visitor should have been created');

        $visitor = null;

        foreach ($users as $user) {
            if (true === $container->get(DemoMode::class)->ownsAddress($user->email)) {
                $visitor = $user;
            }
        }

        self::assertNotNull($visitor, 'the new user should be recognisable as a demo visitor');

        // Onboarding is stamped done: the wizard opens over a backdrop that
        // swallows every click, which on a demo is a locked door in front of
        // the thing they came to see.
        self::assertNotNull($visitor->getSetting(\App\Entity\User\User::SETTING_ONBOARDING_COMPLETED_AT));
        self::assertNotNull($visitor->getSetting(DemoProvisioner::SETTING_EXPIRES_AT));

        $account = $container->get(AccountRepository::class)->findOneBy(['usr' => $visitor]);

        self::assertNotNull($account);
        self::assertSame(DemoMailbox::ACCOUNT_USERNAME, $account->username);

        $threads = $container->get(MessageThreadRepository::class)->findBy(['account' => $account]);

        $messages = $container->get(MessageRepository::class)->findBy(['account' => $account]);

        self::assertNotEmpty($threads, 'the seeded mailbox should be there');

        // Stated as a RELATION between what was seeded and what came out,
        // rather than as a total. A total has to be edited every time the
        // mailbox gains a thread — which is how this assertion came to say 13
        // on a mailbox of 14 — and an assertion that needs editing whenever the
        // fixture grows is testing the fixture, not the behaviour.
        //
        // What is actually true, and stays true however many threads are
        // added: every message is its own thread EXCEPT the conversation,
        // whose turns collapse into one.
        self::assertCount(
            count($messages) - (count(DemoMailbox::CONVERSATION) - 1),
            $threads,
            'every message should be its own thread except the conversation, which collapses',
        );

        // And the collapse is real: exactly one thread holds more than one
        // message, and it holds all of the conversation's turns.
        $multi = array_values(array_filter(
            $threads,
            static fn ($thread): bool => $thread->messageCount > 1,
        ));

        self::assertCount(1, $multi, 'the demo should contain exactly one conversation');
        self::assertSame(count(DemoMailbox::CONVERSATION), $multi[0]->messageCount);

        // The pipeline ran, which is the whole reason seeding goes through it:
        // categories are decided from headers, and a mailbox written as
        // finished rows has none decided at all. Two of the twelve carry bulk
        // headers, so more than one category must be represented.
        $categories = [];

        foreach ($messages as $message) {
            $categories[$message->category->value] = true;
        }

        self::assertGreaterThan(
            1,
            count($categories),
            'seeded mail should be categorised, not all Primary — see DemoMailbox::seed()',
        );

        // And a week worth looking at beside the mail.
        self::assertNotEmpty(
            $container->get(\App\Repository\Calendar\CalendarEventRepository::class)
                ->findBy(['usr' => $visitor]),
            'the demo should provision a calendar too',
        );

        // The visitor really is signed in — the chain must land in a mailbox
        // rather than back on the login form. Two hops: /demo redirects to the
        // app root, which redirects again to the inbox.
        $client->followRedirects();
        $client->request('GET', '/demo');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/login', (string) $client->getRequest()->getUri());

        $this->eraseDemoUsers();
    }

    /**
     * Somebody reloading must keep the mailbox they were reading. Handing them
     * a fresh one reads as the app losing their mail.
     */
    public function testASecondVisitInTheSameSessionDoesNotProvisionAgain(): void
    {
        $client    = $this->demoClient();
        $container = static::getContainer();

        $client->request('GET', '/demo');
        $after = count($container->get(UserRepository::class)->findAll());

        $client->request('GET', '/demo');

        self::assertResponseRedirects();
        self::assertCount($after, $container->get(UserRepository::class)->findAll());

        $this->eraseDemoUsers();
    }

    // -------------------------------------------------------------- the button

    public function testTheReceiveButtonDeliversTheScriptedMailInOrderAndWraps(): void
    {
        $client    = $this->demoClient();
        $container = static::getContainer();

        $client->request('GET', '/demo');
        $client->followRedirect();

        $scenarios = $container->get(DemoScenarios::class)->all();
        $messages  = $container->get(MessageRepository::class);
        $users     = $container->get(UserRepository::class);
        $accounts  = $container->get(AccountRepository::class);

        $visitor = null;

        foreach ($users->findAll() as $user) {
            if (true === $container->get(DemoMode::class)->ownsAddress($user->email)) {
                $visitor = $user;
            }
        }

        self::assertNotNull($visitor);
        $account = $accounts->findOneBy(['usr' => $visitor]);

        // The first scenario is a follow-up to a thread the seeded mailbox
        // already contains — that is the point of it, since it demonstrates
        // threading rather than just appending — so its subject is on the
        // account before a single press. Baseline it rather than assuming zero.
        $before = count($messages->findBy([
            'account' => $account,
            'subject' => $scenarios[0]->subject,
        ]));

        // One press past the end of the list, so the wrap is exercised: a
        // button that stops working is indistinguishable from one that broke.
        $presses = count($scenarios) + 1;

        for ($i = 0; $i < $presses; ++$i) {
            $client->request('POST', '/demo/receive', [
                '_token' => $this->csrfToken($client),
            ]);

            // A Turbo Stream, not a redirect. The mail is already on screen by
            // the time this returns — Mercure delivered it — so the response
            // touches only the demo bar. Redirecting instead reloaded the whole
            // page on top of mail that had just arrived, which replayed the
            // list's entry animation; see DemoController::receive().
            self::assertResponseIsSuccessful(sprintf('press %d should succeed', $i + 1));
            self::assertStringContainsString(
                'text/vnd.turbo-stream.html',
                (string) $client->getResponse()->headers->get('Content-Type'),
                'the response must be a stream or Turbo will navigate to it',
            );
        }

        $delivered = $messages->findBy([
            'account' => $account,
            'subject' => $scenarios[0]->subject,
        ]);

        // The first scenario twice on top of the baseline: once on press one,
        // once on the wrap.
        self::assertCount(
            $before + 2,
            $delivered,
            'the queue should have wrapped back to the first scenario',
        );

        $this->eraseDemoUsers();
    }

    public function testAnUnauthenticatedVisitorCannotDeliverMail(): void
    {
        $client = $this->demoClient();

        $client->request('POST', '/demo/receive');

        // The firewall, not the controller: /demo/receive is deliberately left
        // out of the PUBLIC_ACCESS rule that opens /demo.
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    // ------------------------------------------------------------ the round trip

    /**
     * The reply lands on the conversation it answers.
     *
     * This is the whole argument for the auto-reply existing: a demo instance
     * is by definition one no mail arrives at, so without it compose can only
     * ever be shown one-sided. Driving the handler directly rather than
     * pressing Send in a browser, because the delay that makes it feel like a
     * reply is a DelayStamp on a queue the test environment collects rather
     * than runs.
     */
    public function testAnAutoReplyThreadsOntoTheMailItAnswers(): void
    {
        $client    = $this->demoClient();
        $container = static::getContainer();

        $client->request('GET', '/demo');

        $visitor = $this->visitor();
        $account = $container->get(AccountRepository::class)->findOneBy(['usr' => $visitor]);

        self::assertNotNull($account);

        // Stand in for the mail the visitor sent: the sender reads its
        // Message-ID and recipient off the MIME, and this is what it would
        // have queued.
        $sent = $container->get(DemoInbox::class)->deliver(
            $account,
            new DemoScenario(
                key: 'sent',
                subject: 'Thursday at four?',
                fromName: 'You',
                fromAddress: (string) $account->email,
                bodyText: 'Does Thursday at four still work?',
            ),
        );

        $container->get(DemoAutoReplyHandler::class)(new DemoAutoReplyMessage(
            accountId: (int) $account->id,
            inReplyTo: (string) $sent->messageId,
            fromAddress: 'anna.weiss@example.com',
            fromName: 'Anna Weiß',
            subject: 'Thursday at four?',
        ));

        $reply = $container->get(MessageRepository::class)->findOneBy([
            'account'     => $account,
            'fromAddress' => 'anna.weiss@example.com',
        ]);

        self::assertNotNull($reply, 'the reply should have been delivered');
        self::assertSame('Re: Thursday at four?', $reply->subject);
        self::assertNotNull($reply->thread);
        self::assertSame(
            (int) $sent->thread?->id,
            (int) $reply->thread->id,
            'the reply belongs on the thread it answers',
        );

        $this->eraseDemoUsers();
    }

    /**
     * A queued reply outlives the configuration that queued it, so an instance
     * switched out of demo mode must not have scripted mail land in a real
     * mailbox because a job was still in flight.
     */
    public function testAnAutoReplyIsDroppedOnceDemoModeIsOff(): void
    {
        $client    = $this->demoClient();
        $container = static::getContainer();

        $client->request('GET', '/demo');

        $visitor = $this->visitor();
        $account = $container->get(AccountRepository::class)->findOneBy(['usr' => $visitor]);
        $before  = count($container->get(MessageRepository::class)->findBy(['account' => $account]));

        // The switch flips under the running job, which is the situation being
        // described. The handler is resolved fresh so it sees the new value.
        static::ensureKernelShutdown();
        unset($_SERVER['APP_DEMO_MODE'], $_ENV['APP_DEMO_MODE']);
        static::createClient();

        static::getContainer()->get(DemoAutoReplyHandler::class)(new DemoAutoReplyMessage(
            accountId: (int) $account->id,
            inReplyTo: '<whatever@plmail.invalid>',
            fromAddress: 'anna.weiss@example.com',
            fromName: 'Anna Weiß',
            subject: 'Thursday at four?',
        ));

        self::assertCount(
            $before,
            static::getContainer()->get(MessageRepository::class)->findBy(['account' => $account]),
            'nothing should have been delivered with demo mode off',
        );

        $_SERVER['APP_DEMO_MODE'] = '1';
        $_ENV['APP_DEMO_MODE']    = '1';
        static::ensureKernelShutdown();
        static::createClient();

        $this->eraseDemoUsers();
    }

    // ------------------------------------------------------------- the legal pages

    /**
     * Both notices are reachable without a session, and they are two pages.
     *
     * A privacy notice folded into the Impressum is not one — it has to be
     * reachable in its own right — and both are read by people who have no
     * account here and are deciding whether to get one.
     */
    public function testTheLegalPagesAreReadableWithoutSigningIn(): void
    {
        $client = $this->demoClient();

        foreach (['/impressum', '/datenschutz'] as $path) {
            $client->request('GET', $path);

            self::assertResponseIsSuccessful(sprintf('%s must be readable anonymously', $path));
        }
    }

    public function testTheLoginPageLinksToBothOfThem(): void
    {
        $client  = $this->demoClient();
        $crawler = $client->request('GET', '/login');

        self::assertGreaterThan(0, $crawler->filter('a[href="/impressum"]')->count());
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href="/datenschutz"]')->count(),
            'the privacy notice needs its own link, not a mention inside the Impressum',
        );
    }

    /** Neither exists on an install that is not a demo. */
    public function testTheLegalPagesDoNotExistOnANormalInstall(): void
    {
        $client = static::createClient();

        foreach (['/impressum', '/datenschutz'] as $path) {
            $client->request('GET', $path);

            self::assertResponseStatusCodeSame(404, $path.' should not exist off-demo');
        }
    }

    // ------------------------------------------------------------ the install page

    /**
     * A demo visitor must not close /install.
     *
     * InstallGuard shuts that page as soon as a user exists, and demo mode
     * mints one per arrival — so counted naively, the first stranger or crawler
     * to open /demo on a fresh demo instance took the install page away with
     * them, permanently, before the operator had made themselves an
     * administrator. Nothing announced it: the page simply 404s, which is
     * exactly what it does on a correctly installed instance.
     *
     * Asserted through the guard rather than by fetching /install, because the
     * test database already has real users in it — the predicate is the thing
     * that was wrong, and it is the thing worth pinning.
     */
    public function testADemoVisitorDoesNotCountAsTheInstallsFirstUser(): void
    {
        $client    = $this->demoClient();
        $container = static::getContainer();
        $users     = $container->get(UserRepository::class);

        $before = $users->countExcludingDemoVisitors();

        $client->request('GET', '/demo');

        self::assertNotNull($this->visitor(), 'a demo visitor should have been provisioned');

        self::assertSame(
            $before,
            $users->countExcludingDemoVisitors(),
            'provisioning a demo visitor must not change the real-user count',
        );

        // The naive count did move — which is precisely what used to close the
        // install page.
        self::assertGreaterThan(
            $before,
            $users->countAll(),
            'the visitor is still a row; it is only excluded from the install predicate',
        );

        $this->eraseDemoUsers();
    }

    // ------------------------------------------------------------- the clean-up

    /**
     * The eraser is the one destructive thing in the feature and it runs on a
     * timer, so the predicate selecting its input is checked at the point of no
     * return rather than trusted from the caller.
     */
    public function testTheEraserRefusesAnyoneTheDemoDidNotMint(): void
    {
        $client = $this->demoClient();

        $client->request('GET', '/demo');

        $realUser = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => 'e2e@plmail.test']);

        if (null === $realUser) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $this->expectException(\LogicException::class);

        static::getContainer()->get(DemoUserEraser::class)->erase($realUser);
    }

    /**
     * Erasing a demo visitor takes their files with them.
     *
     * The rows are found through Doctrine's metadata, and a PNG under
     * var/uploads is not a row — so a blob store owned by a user is invisible
     * to that walk and has to be named explicitly. On a public demo minting a
     * throwaway visitor at a time, one file left behind per visitor is
     * unbounded growth on somebody's disk rather than untidiness.
     */
    public function testErasingAVisitorDeletesTheirSavedSignature(): void
    {
        $client = $this->demoClient();

        $client->request('GET', '/demo');
        $client->followRedirect();

        $visitor = $this->visitor();

        self::assertNotNull($visitor, 'the demo should have provisioned a visitor');

        $signatures = static::getContainer()->get(SignatureStorage::class);
        $filename   = $signatures->store((string) $visitor->id, 'not really a png, and it does not have to be');
        $path       = $signatures->pathFor((string) $visitor->id, $filename);

        self::assertFileExists($path);

        static::getContainer()->get(DemoUserEraser::class)->erase($visitor);

        self::assertFileDoesNotExist($path, 'the signature outlived the user it belonged to');
    }

    // ------------------------------------------------------------ the lockdown

    public function testAttachingARealMailboxIsRefusedOnADemo(): void
    {
        $client = $this->demoClient();

        $client->request('GET', '/demo');
        $client->followRedirect();

        $client->request('GET', '/account/new');

        self::assertResponseRedirects();
        self::assertStringContainsString(
            '/settings',
            (string) $client->getResponse()->headers->get('Location'),
        );

        $this->eraseDemoUsers();
    }

    /**
     * The token the demo bar actually rendered.
     *
     * Read out of the DOM rather than minted from the token manager, which
     * outside a request has no session to mint against. Taking it from the page
     * also tests the thing that matters — that the bar renders a usable token —
     * rather than a token the page might never have carried.
     */
    private function csrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/mail/inbox');
        $input   = $crawler->filter('#demo-bar input[name="_token"]');

        self::assertGreaterThan(0, $input->count(), 'the demo bar should render a CSRF token');

        return (string) $input->attr('value');
    }
}
