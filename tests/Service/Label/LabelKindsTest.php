<?php

declare(strict_types=1);

namespace App\Tests\Service\Label;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\IncomingProtocol;
use App\Domain\Enum\Account\MailProvider;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Service\Label\LabelKinds;
use App\Service\Mail\SidebarCounts;
use App\Service\Mail\UserAccounts;
use PHPUnit\Framework\TestCase;

/**
 * What "Move to" and "Label as" offer, per account (#221 step 3): a folder
 * there is a place, anything else a tag; Gmail has places only when the
 * account says so; JMAP's mailboxes are both; with no account, a label is
 * offered where it is that kind on any of the user's accounts, and a folder of
 * one account counts as a tag on another only once it is used as one there.
 */
final class LabelKindsTest extends TestCase
{
    private LabelKinds $kinds;

    /** @var list<LabelBinding> the bindings the sidebar's read finds */
    private array $bindings = [];

    protected function setUp(): void
    {
        $sidebar = $this->createStub(SidebarCounts::class);
        $sidebar->method('isFolderOn')->willReturnCallback(fn (Label $label, Account $account): bool => [] !== array_filter(
            $this->bindings,
            static fn (LabelBinding $binding): bool => $binding->label === $label && $binding->account === $account && self::isFolder($binding),
        ));
        $sidebar->method('isFolderAnywhere')->willReturnCallback(fn (Label $label): bool => [] !== array_filter(
            $this->bindings,
            static fn (LabelBinding $binding): bool => $binding->label === $label && self::isFolder($binding),
        ));
        $sidebar->method('isTagInUseOn')->willReturnCallback(fn (Label $label, Account $account): bool => [] !== array_filter(
            $this->bindings,
            static fn (LabelBinding $binding): bool => $binding->label === $label && $binding->account === $account && false === self::isFolder($binding),
        ));

        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findForUserOrdered')->willReturnCallback(static fn (User $user): array => $user->accounts->toArray());

        $this->kinds = new LabelKinds($sidebar, new UserAccounts($accounts));
    }

    public function testOnImapAFolderIsAPlaceAndTheRestAreTags(): void
    {
        $imap   = $this->account();
        $folder = $this->label('Work', $imap, mailbox: true);
        $tag    = $this->label('Later');

        self::assertTrue($this->kinds->isPlaceOn($folder, $imap));
        self::assertFalse($this->kinds->isTagOn($folder, $imap));
        self::assertFalse($this->kinds->isPlaceOn($tag, $imap));
        self::assertTrue($this->kinds->isTagOn($tag, $imap));
    }

    public function testOnMicrosoftAnOutlookFolderIsAPlaceAndTheRestAreCategories(): void
    {
        $microsoft = $this->account(MailProvider::Microsoft);
        $folder    = $this->label('Work', $microsoft, graphFolder: true);
        $category  = $this->label('Later');

        self::assertTrue($this->kinds->isPlaceOn($folder, $microsoft));
        self::assertFalse($this->kinds->isTagOn($folder, $microsoft));
        self::assertFalse($this->kinds->isPlaceOn($category, $microsoft));
        self::assertTrue($this->kinds->isTagOn($category, $microsoft));
    }

    public function testOnGmailLabelsAreTagsAndPlacesOnlyWhenTheAccountSaysSo(): void
    {
        $gmail = $this->account(MailProvider::Google);
        $label = $this->label('Work');

        self::assertFalse($this->kinds->isPlaceOn($label, $gmail));
        self::assertTrue($this->kinds->isTagOn($label, $gmail));

        $gmail->gmailMoveOffersLabels = true;

        self::assertTrue($this->kinds->isPlaceOn($label, $gmail));
        self::assertTrue($this->kinds->isTagOn($label, $gmail), 'still put on with Label as');
    }

    public function testOnJmapAMailboxIsBoth(): void
    {
        $jmap = $this->account();
        $jmap->incomingProtocol = IncomingProtocol::Jmap;
        $label = $this->label('Work');

        self::assertTrue($this->kinds->isPlaceOn($label, $jmap));
        self::assertTrue($this->kinds->isTagOn($label, $jmap));
    }

    public function testOnPop3EveryLabelIsATag(): void
    {
        $pop3 = $this->account();
        $pop3->incomingProtocol = IncomingProtocol::Pop3;
        $label = $this->label('Work');

        self::assertFalse($this->kinds->isPlaceOn($label, $pop3));
        self::assertTrue($this->kinds->isTagOn($label, $pop3));
    }

    public function testWithNoAccountALabelIsOfferedWhereItIsThatKindOnAnyAccount(): void
    {
        $user        = new User();
        $user->email = 'kinds@example.test';
        $imap        = $this->account(user: $user);
        $gmail = $this->account(MailProvider::Google, $user);

        $work  = $this->label('Work', $imap, mailbox: true);
        $later = $this->label('Later');

        self::assertSame([$work], $this->kinds->places([$work, $later], $user));
        self::assertSame([$later], $this->kinds->tags([$work, $later], $user), 'Work is a folder on the IMAP account and not in use as a tag anywhere');
        self::assertSame([$work, $later], $this->kinds->tags([$work, $later], $user, $gmail), 'on the Gmail account itself it can become one');
        self::assertSame([$later], $this->kinds->tags([$work, $later], $user, $imap));
        self::assertSame([], $this->kinds->places([$work, $later], $user, $gmail));

        $this->bind($work, $gmail);

        self::assertSame([$work, $later], $this->kinds->tags([$work, $later], $user), 'once Work is on Gmail mail, it is a tag there');
    }

    /**
     * Adrian's report on v0.4.1: with two accounts, every folder of one was
     * listed under LABELS because it counted as a tag on the other.
     */
    public function testAFolderOfOneAccountIsNotInLabelsUntilItIsATagOnAnother(): void
    {
        $user        = new User();
        $user->email = 'labels@example.test';
        $first       = $this->account(user: $user);
        $second      = $this->account(user: $user);

        $folder = $this->label('1_test', $first, mailbox: true);
        $tag    = $this->label('Later');

        self::assertFalse($this->kinds->inLabels($folder, $user));
        self::assertTrue($this->kinds->inLabels($tag, $user), 'a label that is a folder nowhere is a tag');

        $this->bind($folder, $second);

        self::assertTrue($this->kinds->inLabels($folder, $user), 'a folder on one account and a tag on the other');
    }

    public function testFoldersInLabelsListsEveryLabel(): void
    {
        $user                  = new User();
        $user->email           = 'all@example.test';
        $user->foldersInLabels = true;
        $folder                = $this->label('1_test', $this->account(user: $user), mailbox: true);

        self::assertTrue($this->kinds->inLabels($folder, $user));
    }

    private function account(?MailProvider $provider = null, ?User $user = null): Account
    {
        $account = new Account();

        if (null !== $provider) {
            $account->authType      = AuthType::OAuth2->value;
            $account->oauthProvider = $provider->value;
        }

        ($user ?? new User())->addAccount($account);

        return $account;
    }

    private function label(string $name, ?Account $on = null, bool $mailbox = false, bool $graphFolder = false): Label
    {
        $label = new Label();
        $label->name = $name;

        if (null !== $on) {
            $binding          = new LabelBinding();
            $binding->label   = $label;
            $binding->account = $on;

            if (true === $mailbox) {
                $binding->mailbox = new Mailbox();
            }

            if (true === $graphFolder) {
                $binding->graphFolderId = 'AAMk-' . $name;
            }

            $this->bindings[] = $binding;
        }

        return $label;
    }

    /** A binding that is not a folder: the label put on this account's mail. */
    private function bind(Label $label, Account $account): void
    {
        $binding          = new LabelBinding();
        $binding->label   = $label;
        $binding->account = $account;
        $this->bindings[] = $binding;
    }

    private static function isFolder(LabelBinding $binding): bool
    {
        return null !== $binding->mailbox || null !== $binding->graphFolderId;
    }
}
