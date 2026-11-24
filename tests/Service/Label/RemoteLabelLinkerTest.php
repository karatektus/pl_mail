<?php

declare(strict_types=1);

namespace App\Tests\Service\Label;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ApplyLabelStructureMessage;
use App\Service\Label\RemoteLabelLinker;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A label renamed on the server is renamed here, not made again (#691).
 *
 * The Gmail, Outlook-category and JMAP syncs used to find labels by name, so
 * a rename in Gmail left the old label and made a new one, both bound to the
 * same Gmail id. These state what the sync does now with the id it is given:
 * keep the label that has it, under the server's name, and tell the server
 * nothing, because the server is where the name came from.
 */
final class RemoteLabelLinkerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private RemoteLabelLinker $linker;
    private InMemoryTransport $export;

    private User $user;
    private Account $account;
    private Account $other;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->linker     = $container->get(RemoteLabelLinker::class);
        $this->export     = $container->get('messenger.transport.export');

        $this->connection->beginTransaction();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testALabelTheAccountHasNeverHadIsFoundByNameAndGetsTheId(): void
    {
        $existing = $this->label('Receipts');

        $label = $this->linker->link('gmailLabelId', 'Label_1', ['Receipts'], $this->account);

        self::assertSame($existing, $label);
        self::assertSame('Label_1', $label?->bindingFor($this->account)?->gmailLabelId);
    }

    public function testALabelRenamedOnTheServerIsRenamedInPlace(): void
    {
        $label = $this->bound('Receipts', 'Label_1');

        $linked = $this->linker->link('gmailLabelId', 'Label_1', ['Invoices'], $this->account);

        self::assertSame($label, $linked, 'the same label, not a second one');
        self::assertSame('Invoices', $label->name);
        self::assertNull($this->find('Receipts'));
        self::assertSame([], $this->structureMessages(), 'the server already has the name');
    }

    /** "Work" renamed to "Job" in Gmail renames "Work/Taxes" with it, and each comes with its id. */
    public function testAParentRenamedBeforeItsChildCarriesTheChildAlong(): void
    {
        $work  = $this->bound('Work', 'Label_1');
        $taxes = $this->bound('Work/Taxes', 'Label_2');

        $this->linker->link('gmailLabelId', 'Label_1', ['Job'], $this->account);
        $this->linker->link('gmailLabelId', 'Label_2', ['Job', 'Taxes'], $this->account);

        self::assertSame('Job', $work->fullName);
        self::assertSame('Job/Taxes', $taxes->fullName);
        self::assertSame($work, $taxes->parent);
    }

    /** A child moved to another parent on the server moves here, under a parent that is structure only. */
    public function testALabelMovedToAnotherParentFollowsIt(): void
    {
        $taxes = $this->bound('Work/Taxes', 'Label_2');

        $linked = $this->linker->link('gmailLabelId', 'Label_2', ['Home', 'Taxes'], $this->account);

        self::assertSame('Home/Taxes', $linked?->fullName);
        self::assertSame($taxes, $linked);
        self::assertNull($this->find('Home')?->bindingFor($this->account), 'the new parent is not a label on the account');
    }

    /**
     * The same label on two accounts (#690): renamed in one account's Gmail,
     * it splits there, and the other account keeps the label as it was.
     */
    public function testARenameOnOneOfTwoAccountsSplitsTheLabel(): void
    {
        $sports = $this->bound('Sports', 'Label_1');
        $this->bindTo($sports, $this->other, 'Label_9');

        $linked = $this->linker->link('gmailLabelId', 'Label_1', ['Football'], $this->account);

        self::assertNotSame($sports, $linked);
        self::assertSame('Football', $linked?->name);
        self::assertSame('Sports', $sports->name);
        self::assertNull($sports->bindingFor($this->account));
        self::assertNotNull($sports->bindingFor($this->other));
    }

    /**
     * What the name lookup left behind: the old label and the one made under
     * the new name, both bound to one Gmail id. Merged into the old one,
     * which takes the server's name; the other goes.
     */
    public function testTwoLabelsBoundToOneIdAreMergedIntoOne(): void
    {
        $old       = $this->bound('Receipts', 'Label_1');
        $duplicate = $this->bound('Invoices', 'Label_1');
        $duplicateId = $duplicate->id;

        $linked = $this->linker->link('gmailLabelId', 'Label_1', ['Invoices'], $this->account);
        $this->em->flush();

        self::assertSame($old, $linked);
        self::assertSame('Invoices', $old->name);
        self::assertNull($this->em->find(Label::class, $duplicateId), 'the duplicate is gone');
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM label_binding WHERE account_id = ? AND gmail_label_id = ?',
            [$this->account->id, 'Label_1'],
        ));
    }

    public function testTheOtherRemoteIdsGoTheSameWay(): void
    {
        $category = $this->bound('Red', null);
        $category->bindingFor($this->account)->graphCategoryId = 'cat-1';
        $this->em->flush();

        self::assertSame($category, $this->linker->link('graphCategoryId', 'cat-1', ['Urgent'], $this->account));
        self::assertSame('Urgent', $category->name);
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /** @return list<ApplyLabelStructureMessage> */
    private function structureMessages(): array
    {
        $messages = [];

        foreach ($this->export->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof ApplyLabelStructureMessage) {
                $messages[] = $envelope->getMessage();
            }
        }

        return $messages;
    }

    private function bound(string $path, ?string $gmailLabelId): Label
    {
        $label = $this->label($path);
        $this->bindTo($label, $this->account, $gmailLabelId);

        return $label;
    }

    private function bindTo(Label $label, Account $account, ?string $gmailLabelId): void
    {
        $binding               = new LabelBinding();
        $binding->label        = $label;
        $binding->account      = $account;
        $binding->gmailLabelId = $gmailLabelId;
        $label->addBinding($binding);

        $this->em->persist($binding);
        $this->em->flush();
    }

    /** A label at that path, made with its parents when they are missing. */
    private function label(string $path): Label
    {
        $parent = null;

        foreach (explode('/', $path) as $name) {
            $label = $this->find($name, $parent);

            if (null === $label) {
                $label         = new Label();
                $label->usr    = $this->user;
                $label->name   = $name;
                $label->parent = $parent;
                $parent?->addChild($label);

                $this->em->persist($label);
                $this->em->flush();
            }

            $parent = $label;
        }

        /** @var Label $parent */
        return $parent;
    }

    private function find(string $name, ?Label $parent = null): ?Label
    {
        return $this->em->getRepository(Label::class)->findOneBy(['usr' => $this->user, 'name' => $name, 'parent' => $parent]);
    }

    private function seed(): void
    {
        $this->user            = new User();
        $this->user->email     = 'remote-labels-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Remote';
        $this->user->nameLast  = 'Labels';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);

        $this->account = $this->gmailAccount('one');
        $this->other   = $this->gmailAccount('two');

        $this->em->flush();
    }

    private function gmailAccount(string $slug): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->email          = $slug . '-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = 'imap.gmail.com';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'smtp.gmail.com';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = AuthType::OAuth2->value;
        $account->oauthProvider  = MailProvider::Google->value;
        $account->mailEnabled    = true;

        $this->em->persist($account);
        $this->user->addAccount($account);

        return $account;
    }
}
