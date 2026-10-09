<?php

declare(strict_types=1);

namespace App\Tests\Service\Demo;

use App\Domain\Enum\Template\TemplateVariable;
use App\Entity\Mail\Account;
use App\Entity\Template\MailTemplate;
use App\Entity\User\User;
use App\Repository\Template\MailTemplateRepository;
use App\Repository\Template\TemplateFolderRepository;
use App\Service\Demo\DemoMode;
use App\Service\Demo\DemoTemplates;
use App\Service\Demo\DemoUserEraser;
use App\Service\Mail\UserAccounts;
use App\Service\Template\TemplateLibrary;
use App\Service\Template\TemplateRecipientFiller;
use App\Service\Template\TemplateRenderer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The templates a demo visitor is given.
 *
 * They are written by hand as strings with `{{tokens}}` in them, which is the
 * one way to write a template that nothing checks: a misspelt variable is not
 * an error anywhere, it is braces left in the text. On a public demo that is
 * the first thing a visitor sees the feature do. So every seeded template is
 * rendered here, for a recipient, and has to come out with nothing left open
 * and no brace in it.
 *
 * The other two claims are the demo's standing ones. Seeding twice gives the
 * same set rather than two of everything, and a visitor's templates go when
 * the visitor does — folders included, which reference one another and are the
 * rows a careless delete order trips over.
 */
final class DemoTemplatesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        // An address the demo would have minted, so the eraser accepts it.
        $this->user            = new User();
        $this->user->email     = sprintf('%s%s@%s', DemoMode::USER_PREFIX, bin2hex(random_bytes(6)), DemoMode::USER_DOMAIN);
        $this->user->nameFirst = 'Demo';
        $this->user->nameLast  = 'Besucher';
        $this->user->roles     = [User::ROLE_USER];
        $this->user->password  = 'x';
        $this->user->timezone  = 'UTC';
        $this->em->persist($this->user);

        $this->account            = new Account();
        $this->account->usr       = $this->user;
        $this->account->name      = 'Demo';
        $this->account->username  = 'demo@plmail.test';
        $this->account->email     = 'you@example.com';
        $this->account->authType  = 'password';
        $this->account->isActive  = true;
        $this->account->isPrimary = true;
        $this->account->imapHost  = 'imap.example.com';
        $this->em->persist($this->account);
        $this->em->flush();

        $container->get(UserAccounts::class)->reset();
    }

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEverySeededTemplateRendersWithNothingLeftOpenAndNoBraceInIt(): void
    {
        $container = static::getContainer();
        $container->get(DemoTemplates::class)->seed($this->user, $this->account);

        $renderer = $container->get(TemplateRenderer::class);
        $filler   = $container->get(TemplateRecipientFiller::class);

        $templates = $container->get(MailTemplateRepository::class)->findForUser($this->user);

        self::assertNotEmpty($templates);

        foreach ($templates as $template) {
            $rendered = $filler->fill(
                $renderer->render($template->subject, $template->body, $this->account, null, $this->user),
                'Priya Raman',
                'priya.raman@example.com',
            );

            self::assertSame([], $filler->open($rendered), sprintf('"%s" leaves a recipient variable open', $template->name));

            foreach ([$rendered->subject, $rendered->html] as $text) {
                self::assertStringNotContainsString('{{', $text, sprintf('"%s" has a token that is not a variable', $template->name));
                self::assertStringNotContainsString('}}', $text, sprintf('"%s" has a token that is not a variable', $template->name));
            }

            self::assertStringContainsString('Priya', $rendered->html, sprintf('"%s" greets nobody', $template->name));
        }
    }

    /**
     * Between them the set has to show the feature: every variable somewhere,
     * and a template in each of the three places one can sit.
     */
    public function testTheSetUsesEveryVariableAndEveryKindOfPlace(): void
    {
        $container = static::getContainer();
        $container->get(DemoTemplates::class)->seed($this->user, $this->account);

        $templates = $container->get(MailTemplateRepository::class)->findForUser($this->user);
        $all       = implode(' ', array_map(
            static fn (MailTemplate $template): string => $template->subject . ' ' . $template->body,
            $templates,
        ));

        foreach (TemplateVariable::cases() as $variable) {
            self::assertStringContainsString('{{' . $variable->value, $all, sprintf('no seeded template uses %s', $variable->value));
        }

        $tree    = $container->get(TemplateLibrary::class)->tree($this->user);
        $account = $tree->accounts[0];

        self::assertNotEmpty($tree->templates, 'something at the top level');
        self::assertNotEmpty($account->templates, 'something directly under the account');
        self::assertSame(DemoTemplates::FOLDER, $account->children[0]->name);
        self::assertNotEmpty($account->children[0]->templates, 'something in a folder inside it');
    }

    public function testSeedingTwiceGivesTheSameSetNotTwoOfEverything(): void
    {
        $container = static::getContainer();
        $seeder    = $container->get(DemoTemplates::class);

        $first = $seeder->seed($this->user, $this->account);
        $seeder->seed($this->user, $this->account);

        self::assertCount($first, $container->get(MailTemplateRepository::class)->findBy(['usr' => $this->user]));
        self::assertCount(1, $container->get(TemplateFolderRepository::class)->findBy(['usr' => $this->user]));
    }

    /**
     * The eraser finds what a user owns from the mapping and removes it row by
     * row. Folders point at one another and at the account, both of which it
     * is removing in the same flush.
     */
    public function testAVisitorsTemplatesAndFoldersGoWithTheVisitor(): void
    {
        $container = static::getContainer();
        $container->get(DemoTemplates::class)->seed($this->user, $this->account);

        // One level deeper than the seed goes, so the delete has a chain.
        $library = $container->get(TemplateLibrary::class);
        $outer   = $container->get(TemplateFolderRepository::class)->findOneBy(['usr' => $this->user]);
        $library->createFolder($this->user, 'Nested', $library->locate($this->user, sprintf('folder:%d', $outer->id)));
        $this->em->flush();

        $userId = $this->user->id;

        $container->get(DemoUserEraser::class)->erase($this->user);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mail_template WHERE usr_id = ?', [$userId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM template_folder WHERE usr_id = ?', [$userId]));
    }
}
