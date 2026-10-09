<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\Mail\Account;
use App\Entity\Template\MailTemplate;
use App\Entity\Template\TemplateFolder;
use App\Entity\User\User;
use App\Repository\Template\MailTemplateRepository;
use App\Repository\Template\TemplateFolderRepository;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings → Templates, through its routes.
 *
 * Three claims, each with a way to go wrong that would not be seen on screen:
 *
 *   - A template is stored as sanitised HTML with its variables as `{{tokens}}`
 *     in the text. The body is the innerHTML of a contenteditable and is later
 *     injected into the compose window's own document, so script in it is
 *     script in the app; and the tokens have to come through that sanitiser
 *     untouched or every variable is lost on the first save.
 *   - Every write is the owner's. A template or folder id in a URL, and a
 *     folder key in a form field, are all things another signed-in user can
 *     type.
 *   - The tree mirrors the mail accounts, so an account with no template in it
 *     is still drawn — and has no rename or delete, because it is not a folder
 *     row at all.
 */
final class TemplateSectionTest extends WebTestCase
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

    // ── the section ──────────────────────────────────────────────────────────

    public function testTheSectionIsInTheNavAndDrawsAFolderForEachAccount(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'mirror@plmail.test');

        $crawler = $client->request('GET', '/settings?section=templates');

        self::assertResponseIsSuccessful();
        self::assertSame(
            1,
            $crawler->filter('nav a[href*="section=templates"][aria-current="page"]')->count(),
            'the nav has the section and marks it current',
        );

        $row = $crawler->filter(sprintf('#settings-template-tree [data-template-folder="account:%d"]', $account->id));

        self::assertSame(1, $row->count(), 'an account with no templates still has its folder');
        self::assertStringContainsString('mirror@plmail.test', $row->text());
        self::assertSame(
            0,
            $row->filter('form[action*="/folder/"]')->count(),
            'an account folder cannot be deleted: there is no row behind it',
        );
    }

    // ── saving ───────────────────────────────────────────────────────────────

    public function testSavingStoresTheTemplateWhereTheFormSaysAndRedrawsTheTree(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'save@plmail.test');

        $client->request('POST', '/settings/templates/save', [
            '_token'   => $this->token($client, '/settings/templates/new'),
            'id'       => '',
            'name'     => 'Invoice reminder',
            'location' => sprintf('account:%d', $account->id),
            'subject'  => 'Reminder from {{date|offset=-14d}}',
            'body'     => '<p>Hi {{recipient.first_name}},</p><p>{{signature}}</p>',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('Invoice reminder', (string) $client->getResponse()->getContent(), 'the redrawn tree has the new template');

        $template = $this->templates()->findOneBy(['usr' => $user, 'name' => 'Invoice reminder']);

        self::assertNotNull($template);
        self::assertSame($account->id, $template->account?->id);
        self::assertNull($template->folder);
        self::assertSame('Reminder from {{date|offset=-14d}}', $template->subject);
        self::assertSame(
            '<p>Hi {{recipient.first_name}},</p><p>{{signature}}</p>',
            $template->body,
            'the variables are stored as the tokens they were posted as',
        );
    }

    /**
     * The body will be injected into the compose window with the app's own
     * origin around it. Whatever the editor was persuaded to post, what is
     * stored is what the inbound-mail allow-list lets through.
     */
    public function testTheBodyIsSanitisedOnItsWayIn(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $client->request('POST', '/settings/templates/save', [
            '_token'   => $this->token($client, '/settings/templates/new'),
            'name'     => 'Hostile',
            'location' => 'root',
            'subject'  => "Two\nlines <b>bold</b>",
            'body'     => '<p onclick="alert(1)">Hi</p><script>alert(2)</script><img src="x" onerror="alert(3)">',
        ]);

        self::assertResponseIsSuccessful();

        $template = $this->templates()->findOneBy(['usr' => $user, 'name' => 'Hostile']);

        self::assertNotNull($template);
        self::assertStringNotContainsString('script', $template->body);
        self::assertStringNotContainsString('onclick', $template->body);
        self::assertStringNotContainsString('onerror', $template->body);
        self::assertStringContainsString('Hi', $template->body);
        self::assertSame('Two lines bold', $template->subject, 'a subject is one line of text');
    }

    public function testATemplateWithoutANameIsRefusedAndTheEditorKeepsWhatWasTyped(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $crawler = $client->request('POST', '/settings/templates/save', [
            '_token'   => $this->token($client, '/settings/templates/new'),
            'name'     => '   ',
            'location' => 'root',
            'body'     => '<p>Kept across the refusal</p>',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $crawler->filter('turbo-frame#template-editor .alert-danger[role="alert"]')->count());
        self::assertStringContainsString('Kept across the refusal', $crawler->filter('[data-settings--template-editor-target="body"]')->html());
        self::assertSame([], $this->templates()->findBy(['usr' => $user]));
    }

    public function testEditingMovesAndRenamesTheSameRow(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $account  = $this->account($user, 'move@plmail.test');
        $template = $this->template($user, 'Before');

        $client->request('POST', '/settings/templates/save', [
            '_token'   => $this->token($client, sprintf('/settings/templates/%d/edit', $template->id)),
            'id'       => (string) $template->id,
            'name'     => 'After',
            'location' => sprintf('account:%d', $account->id),
            'body'     => '<p>Changed</p>',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();

        $reloaded = $this->em->find(MailTemplate::class, $template->id);

        self::assertSame('After', $reloaded->name);
        self::assertSame($account->id, $reloaded->account?->id);
        self::assertCount(1, $this->templates()->findBy(['usr' => $user]), 'an edit does not make a second template');
    }

    public function testDuplicateAndDelete(): void
    {
        $client   = static::createClient();
        $user     = $this->boot($client);
        $template = $this->template($user, 'Original');

        $crawler = $client->request('GET', sprintf('/settings/templates/%d/edit', $template->id));

        $client->request('POST', sprintf('/settings/templates/%d/duplicate', $template->id), [
            '_token' => (string) $crawler->filter('#template-duplicate-form input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->templates()->findOneBy(['usr' => $user, 'name' => 'Copy of Original']));

        $client->request('POST', sprintf('/settings/templates/%d/delete', $template->id), [
            '_token' => (string) $crawler->filter('#template-delete-form input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNull($this->em->find(MailTemplate::class, $template->id));
        self::assertNotNull($this->templates()->findOneBy(['usr' => $user, 'name' => 'Copy of Original']), 'the copy is its own row');
    }

    // ── ownership ────────────────────────────────────────────────────────────

    public function testAnotherUsersTemplateCanBeNeitherOpenedNorOverwritten(): void
    {
        $client = static::createClient();
        $this->boot($client);

        $theirs = $this->template($this->otherUser(), 'Not yours');

        $client->request('GET', sprintf('/settings/templates/%d/edit', $theirs->id));
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/settings/templates/save', [
            '_token'   => $this->token($client, '/settings/templates/new'),
            'id'       => (string) $theirs->id,
            'name'     => 'Taken over',
            'location' => 'root',
            'body'     => '<p>x</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame('Not yours', $this->em->find(MailTemplate::class, $theirs->id)->name);
    }

    /**
     * The Folder dropdown posts a key. One that names somebody else's account
     * must not file the template there — nor quietly file it at the top level,
     * which would look like a save that worked.
     */
    public function testATemplateCannotBeFiledUnderSomebodyElsesAccount(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $theirAccount = $this->account($this->otherUser(), 'theirs@plmail.test');

        $client->request('POST', '/settings/templates/save', [
            '_token'   => $this->token($client, '/settings/templates/new'),
            'name'     => 'Misfiled',
            'location' => sprintf('account:%d', $theirAccount->id),
            'body'     => '<p>x</p>',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->templates()->findBy(['usr' => $user]));
    }

    // ── folders ──────────────────────────────────────────────────────────────

    public function testAFolderIsCreatedRenamedAndDeletedWithItsTemplatesKept(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'folders@plmail.test');
        $folders = static::getContainer()->get(TemplateFolderRepository::class);

        $client->request('POST', '/settings/templates/folder/save', [
            '_token'   => $this->token($client, '/settings/templates/folder/new'),
            'name'     => 'Invoices',
            'location' => sprintf('account:%d', $account->id),
        ]);

        self::assertResponseIsSuccessful();

        $folder = $folders->findOneBy(['usr' => $user, 'name' => 'Invoices']);

        self::assertNotNull($folder);
        self::assertSame($account->id, $folder->account?->id);

        // Re-read: the request above ran in the same entity manager and the
        // kernel cleared it on the way out.
        $template = $this->template(
            $this->em->find(User::class, $user->id),
            'Inside',
            $this->em->find(Account::class, $account->id),
            $folder,
        );

        $client->request('POST', '/settings/templates/folder/save', [
            '_token' => $this->token($client, sprintf('/settings/templates/folder/%d/edit', $folder->id)),
            'id'     => (string) $folder->id,
            'name'   => 'Bills',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertSame('Bills', $this->em->find(TemplateFolder::class, $folder->id)->name);

        $crawler = $client->request('GET', '/settings?section=templates');
        $delete  = $crawler->filter(sprintf('form[action$="/folder/%d/delete"]', $folder->id));

        self::assertSame(1, $delete->count(), 'a folder the user made can be deleted from its row');

        $client->request('POST', sprintf('/settings/templates/folder/%d/delete', $folder->id), [
            '_token' => (string) $delete->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();

        self::assertNull($this->em->find(TemplateFolder::class, $folder->id));

        $kept = $this->em->find(MailTemplate::class, $template->id);

        self::assertNotNull($kept, 'the template in the deleted folder is kept');
        self::assertNull($kept->folder);
        self::assertSame($account->id, $kept->account?->id, 'and stays under the account the folder was in');
    }

    // ── preview ──────────────────────────────────────────────────────────────

    public function testThePreviewRendersWhatIsOnScreenWithoutSavingIt(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);
        $this->account($user, 'preview@plmail.test');

        $client->jsonRequest('POST', '/settings/templates/preview', [
            'subject'  => 'On {{date|format=iso}}',
            'body'     => '<p>Hi {{recipient.first_name}}, from {{sender.email}}</p>',
            'location' => 'root',
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['ok']);
        self::assertMatchesRegularExpression('/^On \d{4}-\d{2}-\d{2}$/', $payload['subject']);
        self::assertStringContainsString('from preview@plmail.test', $payload['html']);
        self::assertStringContainsString('data-pl-var="recipient.first_name"', $payload['html']);
        self::assertSame([], $this->templates()->findBy(['usr' => $user]));
    }

    public function testTheDateExampleWritesTheDateBeingConfigured(): void
    {
        $client = static::createClient();
        $this->boot($client);

        $client->jsonRequest('POST', '/settings/templates/date-example', ['offset' => '+7d', 'format' => 'iso']);

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['text'],
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** The CSRF token of the form the given editor route renders. */
    private function token(KernelBrowser $client, string $editorUrl): string
    {
        $crawler = $client->request('GET', $editorUrl);

        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('turbo-frame#template-editor form input[name="_token"]')->first()->attr('value');
    }

    private function templates(): MailTemplateRepository
    {
        return static::getContainer()->get(MailTemplateRepository::class);
    }

    private function otherUser(): User
    {
        $other = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::OTHER_EMAIL]);

        if (null === $other) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        return $other;
    }

    private function template(User $user, string $name, ?Account $account = null, ?TemplateFolder $folder = null): MailTemplate
    {
        $template          = new MailTemplate();
        $template->usr     = $user;
        $template->name    = $name;
        $template->body    = '<p>Hello</p>';
        $template->account = $account;
        $template->folder  = $folder;

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

        // Whatever an earlier run or a browser session left behind: these
        // tests assert on "the user's templates", all of them.
        $this->connection->executeStatement('DELETE FROM mail_template WHERE usr_id = ?', [$user->id]);
        $this->connection->executeStatement('DELETE FROM template_folder WHERE usr_id = ?', [$user->id]);

        $this->em->createQuery(
            'UPDATE ' . Account::class . ' a SET a.isActive = false WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, string $email): Account
    {
        $account = new Account();

        $account->usr       = $user;
        $account->name      = $email;
        $account->username  = $email;
        $account->email     = $email;
        $account->authType  = 'password';
        $account->isActive  = true;
        $account->isPrimary = true;
        $account->sortOrder = 0;
        $account->imapHost  = 'imap.example.test';

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
