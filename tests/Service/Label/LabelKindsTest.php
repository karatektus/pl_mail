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
 * offered where it is that kind on any of the user's accounts.
 */
final class LabelKindsTest extends TestCase
{
    private LabelKinds $kinds;

    /** @var list<LabelBinding> the folder bindings the sidebar's read finds */
    private array $folders = [];

    protected function setUp(): void
    {
        $sidebar = $this->createStub(SidebarCounts::class);
        $sidebar->method('isFolderOn')->willReturnCallback(fn (Label $label, Account $account): bool => [] !== array_filter(
            $this->folders,
            static fn (LabelBinding $binding): bool => $binding->label === $label && $binding->account === $account,
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
        self::assertSame([$work, $later], $this->kinds->tags([$work, $later], $user), 'Work is a tag on the Gmail account');
        self::assertSame([$later], $this->kinds->tags([$work, $later], $user, $imap));
        self::assertSame([], $this->kinds->places([$work, $later], $user, $gmail));
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

            if (true === $mailbox || true === $graphFolder) {
                $this->folders[] = $binding;
            }
        }

        return $label;
    }
}
