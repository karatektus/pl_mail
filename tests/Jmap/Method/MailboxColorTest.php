<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method;

use App\Domain\Enum\Mail\LabelColor;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Jmap\Mapper\MailboxCounts;
use App\Jmap\Mapper\MailboxMapper;
use App\Jmap\Method\Mail\MailboxSetMethod;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Label\LabelRepository;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Label colour over JMAP.
 *
 * The bug these pin down was not that colour was missing — it was that asking
 * for it *appeared to work*. `Mailbox/set` create accepted a `color`, answered
 * with a created id and no error, and stored nothing; update refused it
 * outright; and `Mailbox/get` never returned it, so a client had no way to
 * discover either fact. A label created from the phone came back uncoloured
 * with nothing anywhere saying why.
 *
 * So most of what follows is about the *refusals*. A closed vocabulary that
 * accepts what it cannot store is worse than no vocabulary at all: the client
 * has been told it succeeded.
 */
final class MailboxColorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MailboxSetMethod $method;
    private MailboxMapper $mapper;
    private LabelResolver $labelResolver;
    private LabelRepository $labels;

    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->method        = $container->get(MailboxSetMethod::class);
        $this->mapper        = $container->get(MailboxMapper::class);
        $this->labelResolver = $container->get(LabelResolver::class);
        $this->labels        = $container->get(LabelRepository::class);

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

    public function testCreateStoresTheColor(): void
    {
        $result = $this->set(['create' => ['c1' => ['name' => 'Rechnungen', 'color' => 'amber']]]);

        self::assertSame([], (array) $result['notCreated']);
        self::assertSame('amber', $this->labelNamed('Rechnungen')->color);
    }

    /**
     * The original bug, as a test.
     *
     * Before the fix this create succeeded and the colour vanished. Asserting
     * `notCreated` alone would not catch a regression to that behaviour, so the
     * stored value is read back.
     */
    public function testAnUnknownColorIsRefusedRatherThanDropped(): void
    {
        $result = $this->set(['create' => ['c1' => ['name' => 'Hex', 'color' => '#ff0000']]]);

        self::assertSame([], (array) $result['created'], 'the label must not be created at all');
        self::assertArrayHasKey('c1', (array) $result['notCreated']);
        self::assertSame('invalidProperties', $result['notCreated']['c1']['type']);
        self::assertNull($this->labels->findOneBy(['usr' => $this->user, 'name' => 'Hex']));
    }

    /** The error has to name the vocabulary, or a closed set is undiscoverable. */
    public function testTheRefusalNamesTheAcceptedValues(): void
    {
        $result = $this->set(['create' => ['c1' => ['name' => 'Hex', 'color' => 'chartreuse']]]);

        $description = $result['notCreated']['c1']['description'];

        self::assertStringContainsString('chartreuse', $description);
        self::assertStringContainsString('amber', $description);
        self::assertStringContainsString('null', $description);
    }

    public function testCreateWithoutAColorLeavesItUnset(): void
    {
        $this->set(['create' => ['c1' => ['name' => 'Plain']]]);

        self::assertNull($this->labelNamed('Plain')->color);
    }

    public function testUpdateSetsTheColor(): void
    {
        $id = $this->createLabel('Reisen');

        $result = $this->set(['update' => [$id => ['color' => 'teal']]]);

        self::assertSame([], (array) $result['notUpdated']);
        self::assertSame('teal', $this->labelNamed('Reisen')->color);
    }

    /**
     * Null clears it, and that is a choice rather than an omission.
     *
     * A patch that treated null as "no change" would leave a user unable to
     * take a colour off a label from this client at all.
     */
    public function testNullClearsTheColor(): void
    {
        $id = $this->createLabel('Reisen', 'pink');

        $this->set(['update' => [$id => ['color' => null]]]);

        self::assertNull($this->labelNamed('Reisen')->color);
    }

    public function testUpdateRefusesAnUnknownColor(): void
    {
        $id = $this->createLabel('Reisen', 'pink');

        $result = $this->set(['update' => [$id => ['color' => 'rgb(1,2,3)']]]);

        self::assertArrayHasKey($id, (array) $result['notUpdated']);
        self::assertSame('pink', $this->labelNamed('Reisen')->color, 'the old colour must survive');
    }

    /**
     * A system label may be recoloured though it may not be renamed.
     *
     * Deliberate, and worth pinning because it is the one place this method
     * treats a system label as mutable. Renaming or destroying Inbox breaks the
     * invariants that hang off its role; changing the colour of its chip breaks
     * nothing, and a sidebar the user cannot colour consistently is the reason
     * to have colours at all.
     */
    public function testASystemLabelMayBeRecoloured(): void
    {
        $inbox = $this->labels->findOneBy(['usr' => $this->user, 'name' => 'Inbox'])
            ?? $this->makeLabel('Inbox');

        $binding = $this->labelResolver->binding($inbox, $this->account);
        $this->em->flush();

        $result = $this->set(['update' => [(string) $binding->id => ['color' => 'blue']]]);

        self::assertSame([], (array) $result['notUpdated']);
        self::assertSame('blue', $inbox->color);
    }

    public function testMailboxGetReportsTheColor(): void
    {
        $label   = $this->makeLabel('Farbe');
        $label->color = 'violet';
        $binding = $this->labelResolver->binding($label, $this->account);
        $this->em->flush();

        $mailbox = $this->mapper->toJmap($binding, new MailboxCounts([]));

        self::assertSame('violet', $mailbox['color']);
    }

    /** An uncoloured label reports null, not an absent key — clients patch on it. */
    public function testAnUncolouredLabelReportsNull(): void
    {
        $binding = $this->labelResolver->binding($this->makeLabel('Ohne'), $this->account);
        $this->em->flush();

        $mailbox = $this->mapper->toJmap($binding, new MailboxCounts([]));

        self::assertArrayHasKey('color', $mailbox);
        self::assertNull($mailbox['color']);
    }

    /**
     * The web form and JMAP must offer one vocabulary.
     *
     * Two lists is how a colour picked on the phone becomes one the web cannot
     * render, and neither side would report anything — the chip simply draws
     * unstyled. The form now reads the enum, so this asserts the enum stayed
     * the shape the form needs rather than duplicating its contents.
     */
    public function testTheFormAndJmapShareOneVocabulary(): void
    {
        $choices = LabelColor::choices();

        self::assertSame(count(LabelColor::cases()), count($choices));

        foreach (LabelColor::cases() as $case) {
            self::assertSame($case->value, $choices['label.color.'.$case->value] ?? null);
            self::assertSame($case, LabelColor::tryFrom($case->value));
        }
    }

    // -- helpers -----------------------------------------------------------

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function set(array $arguments): array
    {
        return $this->method->handle(
            ['accountId' => (string) $this->account->getId()] + $arguments,
            new JmapContext($this->user),
        );
    }

    /** Creates through the method under test and returns the Mailbox id. */
    private function createLabel(string $name, ?string $color = null): string
    {
        $properties = ['name' => $name];

        if (null !== $color) {
            $properties['color'] = $color;
        }

        $result = $this->set(['create' => ['c1' => $properties]]);

        return (string) $result['created']['c1']['id'];
    }

    private function labelNamed(string $name): Label
    {
        $label = $this->labels->findOneBy(['usr' => $this->user, 'name' => $name]);

        self::assertInstanceOf(Label::class, $label, sprintf('No label named "%s".', $name));

        return $label;
    }

    private function makeLabel(string $name): Label
    {
        $label       = new Label();
        $label->usr  = $this->user;
        $label->name = $name;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email = 'mailboxcolor-'.uniqid('', true).'@example.test';
        $this->user->nameFirst = 'Mailbox';
        $this->user->nameLast = 'Color';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account
            ->setUsr($this->user)
            ->setEmail('Mailbox Color')
            ->setUsername('mailboxcolor-fixture@example.test')
            ->setImapHost('localhost')
            ->setImapPort(993)
            ->setImapEncryption('ssl')
            ->setSmtpHost('localhost')
            ->setSmtpPort(587)
            ->setSmtpEncryption('starttls')
            ->setPassword('x')
            ->setAuthType('password')
            ->setIsActive(true);
        $this->em->persist($this->account);

        $this->em->flush();

        // getAccounts() is what AccountResolver scopes on, and the inverse side
        // is not populated by persisting the owning side alone.
        $this->user->addAccount($this->account);
    }
}
