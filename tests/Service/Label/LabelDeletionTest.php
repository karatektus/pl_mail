<?php

declare(strict_types=1);

namespace App\Tests\Service\Label;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Mailbox;
use App\Service\Label\LabelDeletion;
use App\Service\Label\LabelResolver;
use App\Tests\Support\Mail\SeedsFolderLabels;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What deleting a label involves, before anything is deleted (issue #40).
 *
 * The questions the dialog asks and the job relies on: is this a folder on a
 * server, how much mail is in it, and where may that mail go.
 */
final class LabelDeletionTest extends KernelTestCase
{
    use SeedsFolderLabels;

    private Connection $connection;
    private LabelDeletion $deletion;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->labelResolver = $container->get(LabelResolver::class);
        $this->deletion      = $container->get(LabelDeletion::class);

        $this->connection->beginTransaction();

        $this->seedFolderUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** Children before parents: a server will not delete a folder with folders in it. */
    public function testTheSubtreeIsWalkedDeepestFirst(): void
    {
        $leaf   = $this->folderLabel(['Work', 'Clients', 'Acme']);
        $middle = $leaf->parent;
        $root   = $middle?->parent;

        self::assertNotNull($root);

        $names = array_map(static fn ($label): string => (string) $label->name, $this->deletion->subtree($root));

        self::assertSame(['Acme', 'Clients', 'Work'], $names);
    }

    public function testATagIsNotOnAServerAndAsksNothing(): void
    {
        $tag = $this->tagLabel('Important');

        self::assertFalse($this->deletion->isOnAServer($tag));
        self::assertSame(0, $this->deletion->mailInFolders($tag));
    }

    /**
     * A folder nested under a tag still makes the tag's delete a server one:
     * the cascade takes the nested label with it, and its folder must go too.
     */
    public function testAFolderNestedUnderTheLabelPutsTheWholeDeleteOnTheServer(): void
    {
        $leaf = $this->folderLabel(['Projects', 'Old']);
        $root = $leaf->parent;

        self::assertNotNull($root);
        self::assertTrue($this->deletion->isOnAServer($root));
    }

    public function testMailIsCountedInEveryFolderOfTheSubtree(): void
    {
        $leaf = $this->folderLabel(['Work', 'Invoices']);
        $root = $leaf->parent;

        self::assertNotNull($root);

        $this->labelResolver->bindMailbox($root, $this->mailboxFor('Work'));
        $this->em->flush();

        $this->messageIn($leaf, 'Invoice one');
        $this->messageIn($leaf, 'Invoice two');
        $this->messageIn($root, 'A work mail');

        self::assertSame(3, $this->deletion->mailInFolders($root));
        self::assertSame(2, $this->deletion->mailInFolders($leaf));
    }

    /**
     * The three system places, then other folders — never the label itself,
     * anything nested under it, or a plain tag.
     */
    public function testTheDestinationsAreSystemPlacesAndOtherFolders(): void
    {
        $leaving = $this->folderLabel(['Old', 'Nested']);
        $root    = $leaving->parent;
        $keeper  = $this->folderLabel(['Receipts']);
        $tag     = $this->tagLabel('Important');

        self::assertNotNull($root);

        $destinations = $this->deletion->destinations($root, $this->user);

        self::assertSame(['inbox', 'archive', 'trash', 'label:' . $keeper->id], array_keys($destinations));
        self::assertSame(LabelRole::Archive, $destinations['archive']->role);
        self::assertArrayNotHasKey('label:' . $tag->id, $destinations);
        self::assertArrayNotHasKey('label:' . $leaving->id, $destinations);
    }

    /** A value the dialog did not offer resolves to nothing, not to a label. */
    public function testAValueTheDialogDidNotOfferIsRefused(): void
    {
        $label = $this->folderLabel(['Old']);
        $sent  = $this->systemLabel(LabelRole::Sent);

        self::assertNull($this->deletion->destination($label, $this->user, 'label:' . $label->id));
        self::assertNull($this->deletion->destination($label, $this->user, 'label:' . $sent->id));
        self::assertNull($this->deletion->destination($label, $this->user, 'sent'));
        self::assertNull($this->deletion->destination($label, $this->user, ''));
        self::assertSame(LabelRole::Inbox, $this->deletion->destination($label, $this->user, 'inbox')?->role);
    }

    private function mailboxFor(string $path): Mailbox
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $path;
        $mailbox->fullPath      = $path;
        $mailbox->delimiter     = '/';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
