<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Entity\Mail\Account;
use App\Entity\Template\MailTemplate;
use App\Entity\User\User;
use App\Repository\Template\MailTemplateRepository;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Templates as the compose window meets them.
 *
 * The window itself only gains a button; the list behind it is fetched when
 * the button is pressed, and a chosen template is fetched rendered. So what
 * can be asserted without a browser is the three answers the browser is given
 * — and those are where the feature's rules live: which templates are offered
 * first, what is filled in before the browser sees it, and what "save this
 * message as a template" stores.
 *
 * The browser half — inserting at the caret, filling a first name when a
 * recipient is added, the question before sending with a placeholder still
 * open — is tests/e2e/compose-templates.spec.ts.
 */
final class ComposeTemplatePickerTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string OTHER_EMAIL = 'e2e@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheComposeWindowHasTheButtonAndTheSendQuestion(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $this->account($user, 'window@plmail.test', primary: true);

        $crawler = $client->request('GET', '/compose/new', server: ['HTTP_TURBO_FRAME' => 'compose_dock']);

        self::assertResponseIsSuccessful();

        $picker = $crawler->filter('[data-controller~="compose--template-picker"]');

        self::assertSame(1, $picker->count());
        self::assertSame('/compose/templates/panel', $picker->attr('data-compose--template-picker-panel-url-value'));
        self::assertSame('Insert template', $picker->filter('button')->first()->attr('aria-label'));

        // The sentence the window asks before sending with a placeholder open
        // is written by JavaScript, so it has to have been handed over.
        self::assertStringContainsString(
            'A template placeholder has not been filled in.',
            (string) $crawler->filter('[data-controller~="compose--compose"]')->attr('data-compose--compose-i18n-value'),
        );
    }

    /**
     * The account being written from comes first, the top level second, and
     * everything else is still there — filing a template under an account says
     * where it is usually wanted, not where it is allowed.
     */
    public function testThePanelOffersTheCurrentAccountsTemplatesFirstAndHidesNone(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $work   = $this->account($user, 'work@plmail.test', primary: true);
        $home   = $this->account($user, 'home@plmail.test');

        $this->template($user, 'For work', $work);
        $this->template($user, 'For home', $home);
        $this->template($user, 'For anyone');

        $crawler = $client->request('GET', '/compose/templates/panel', ['account' => sprintf('%d|home@plmail.test', $home->id)]);

        self::assertResponseIsSuccessful();

        $names = $crawler->filter('[data-compose--template-picker-target="item"]')->each(
            static fn ($item): string => trim($item->filter('span')->first()->text()),
        );

        self::assertSame(['For home', 'For anyone', 'For work'], $names);

        $headings = $crawler->filter('[data-compose--template-picker-target="group"] > p')->each(
            static fn ($heading): string => trim($heading->text()),
        );

        self::assertSame(['home@plmail.test', 'All accounts', 'work@plmail.test'], $headings);
    }

    public function testAnEmptyLibrarySaysSoInsteadOfDrawingAnEmptyList(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $this->account($user, 'empty@plmail.test', primary: true);

        $crawler = $client->request('GET', '/compose/templates/panel');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[data-compose--template-picker-target="item"]')->count());
        self::assertStringContainsString('No templates yet', $crawler->text());
    }

    /**
     * Rendered against the address in From — the token's address half is what
     * picks an alias — with the recipient left open for the window.
     */
    public function testAChosenTemplateArrivesFilledInExceptForTheRecipient(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'render@plmail.test', primary: true);
        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Regards</p>');
        $this->em->flush();

        $template = $this->template(
            $user,
            'Reminder',
            $account,
            '<p>Hi {{recipient.first_name}}, this is {{sender.email}}.</p><p>{{signature}}</p>',
            'Reminder for {{recipient.name}}',
        );

        $client->request('GET', sprintf('/compose/templates/%d/render', $template->id), [
            'account' => sprintf('%d|render@plmail.test', $account->id),
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['ok']);
        self::assertSame('Reminder for {{recipient.name}}', $payload['subject']);
        self::assertSame(
            '<p>Hi <span data-pl-var="recipient.first_name">First name</span>, this is render@plmail.test.</p>'
            . '<div class="pl-signature" data-pl-signature><p>Regards</p></div>',
            $payload['html'],
        );
    }

    public function testSomebodyElsesTemplateIsNotRendered(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $this->account($user, 'mine@plmail.test', primary: true);

        $other = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::OTHER_EMAIL]);

        if (null === $other) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $theirs = $this->template($other, 'Private');

        $client->request('GET', sprintf('/compose/templates/%d/render', $theirs->id));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Named after the subject and filed under the account it was written from:
     * the two guesses a person would most likely have typed themselves.
     */
    public function testSavingTheMessageAsATemplateFilesItUnderTheFromAccount(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'keep@plmail.test', primary: true);

        $client->request(
            'POST',
            '/compose/templates/save-draft',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->panelToken($client)],
            content: json_encode([
                'subject' => 'Thanks for your order',
                'body'    => '<p onclick="x()">Thanks!</p><p>{{signature}}</p>',
                'account' => sprintf('%d|keep@plmail.test', $account->id),
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        $template = static::getContainer()->get(MailTemplateRepository::class)->findOneBy(['usr' => $user]);

        self::assertNotNull($template);
        self::assertSame('Thanks for your order', $template->name);
        self::assertSame('Thanks for your order', $template->subject);
        self::assertSame($account->id, $template->account?->id);
        self::assertSame('<p>Thanks!</p><p>{{signature}}</p>', $template->body, 'sanitised, with the signature kept as a variable');
    }

    public function testAnEmptyMessageIsNotSavedAsATemplateAndTheTokenIsRequired(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $this->account($user, 'refuse@plmail.test', primary: true);

        $token = $this->panelToken($client);

        $client->request(
            'POST',
            '/compose/templates/save-draft',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            content: json_encode(['subject' => 'Nothing', 'body' => '<p><br></p>', 'account' => ''], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);

        $client->request(
            'POST',
            '/compose/templates/save-draft',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['subject' => 'No token', 'body' => '<p>Text</p>', 'account' => ''], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], static::getContainer()->get(MailTemplateRepository::class)->findBy(['usr' => $user]));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** The token the panel carries for "save this message as a template". */
    private function panelToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/compose/templates/panel');

        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-compose--template-picker-target="root"]')->attr('data-csrf');
    }

    private function template(User $user, string $name, ?Account $account = null, string $body = '<p>Hello</p>', ?string $subject = null): MailTemplate
    {
        $template          = new MailTemplate();
        $template->usr     = $user;
        $template->name    = $name;
        $template->subject = $subject;
        $template->body    = $body;
        $template->account = $account;

        $this->em->persist($template);
        $this->em->flush();

        return $template;
    }

    private function boot(KernelBrowser $client): User
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->disableReboot();

        $this->connection->beginTransaction();

        $this->connection->executeStatement('DELETE FROM mail_template WHERE usr_id = ?', [$user->id]);
        $this->connection->executeStatement('DELETE FROM template_folder WHERE usr_id = ?', [$user->id]);

        // Out of the way rather than switched off: the panel lists every
        // account, paused ones included, and the assertions name them all.
        $this->connection->executeStatement('DELETE FROM account WHERE usr_id = ?', [$user->id]);

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, string $email, bool $primary = false): Account
    {
        $account = new Account();

        $account->usr       = $user;
        $account->name      = $email;
        $account->username  = $email;
        $account->email     = $email;
        $account->authType  = 'password';
        $account->isActive  = true;
        $account->isPrimary = $primary;
        $account->sortOrder = true === $primary ? 0 : 1;
        $account->imapHost  = 'imap.example.test';

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
