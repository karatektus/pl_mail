<?php

declare(strict_types=1);

namespace App\Tests\Service\Label;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Repository\Mail\Account\AccountRepository;
use App\Service\Label\AccountFolderNode;
use App\Service\Label\AccountFolderTree;
use App\Service\Label\LabelKinds;
use App\Service\Mail\SidebarCounts;
use App\Service\Mail\UserAccounts;
use PHPUnit\Framework\TestCase;

/**
 * The folder rows under an account nest the way the server nests them (#221,
 * Adrian's report on v0.4.1: `INBOX/1_test/2_test` was drawn as `1_test`
 * beside the Inbox).
 */
final class AccountFolderTreeTest extends TestCase
{
    private AccountFolderTree $tree;

    /** @var array<int, Mailbox> label id => its folder on the account */
    private array $folders = [];

    private int $nextId = 1;

    protected function setUp(): void
    {
        $sidebar = $this->createStub(SidebarCounts::class);
        $sidebar->method('folderOf')->willReturnCallback(fn (Label $label): ?Mailbox => $this->folders[(int) $label->id] ?? null);
        $sidebar->method('isFolderOn')->willReturnCallback(fn (Label $label): bool => isset($this->folders[(int) $label->id]));

        $this->tree = new AccountFolderTree(
            $sidebar,
            new LabelKinds($sidebar, new UserAccounts($this->createStub(AccountRepository::class))),
        );
    }

    public function testAFolderUnderInboxIsUnderTheInboxRow(): void
    {
        $account = $this->imapAccount();
        $inbox   = $this->folder(LabelRole::Inbox, 'INBOX', '/');
        $sent    = $this->folder(LabelRole::Sent, 'Sent', '/');
        $first   = $this->folder(null, 'INBOX/1_test', '/');
        $second  = $this->folder(null, 'INBOX/1_test/2_test', '/');

        $roots = $this->tree->of($account, [$inbox, $sent, $first, $second]);

        self::assertSame(['INBOX' => ['1_test' => ['2_test' => []]], 'Sent' => []], self::shape($roots));
        self::assertSame(2, $roots[0]->children[0]->children[0]->depth);
    }

    /**
     * Dovecot's `INBOX.` layout: the folders are inside the Inbox there too,
     * and the Inbox is matched whatever its case.
     */
    public function testTheDelimiterAndTheCaseOfInboxComeFromTheServer(): void
    {
        $account = $this->imapAccount();
        $inbox   = $this->folder(LabelRole::Inbox, 'Inbox', '.');
        $work    = $this->folder(null, 'INBOX.Work', '.');
        $clients = $this->folder(null, 'INBOX.Work.Clients', '.');

        self::assertSame(['Inbox' => ['Work' => ['Clients' => []]]], self::shape($this->tree->of($account, [$inbox, $work, $clients])));
    }

    /**
     * Siblings keep the order they came in, and a folder whose parent is not
     * a row (hidden, or not synced) moves up to the nearest one that is.
     */
    public function testAFolderWhoseParentIsNoRowHangsFromTheNearestOneThatIs(): void
    {
        $account  = $this->imapAccount();
        $projects = $this->folder(null, 'Projects', '/');
        $deep     = $this->folder(null, 'Projects/2026/Q4', '/');
        $archive2 = $this->folder(null, 'Archive2', '/');

        self::assertSame(['Projects' => ['Q4' => []], 'Archive2' => []], self::shape($this->tree->of($account, [$projects, $archive2, $deep])));
    }

    public function testATagIsNoRow(): void
    {
        $account = $this->imapAccount();
        $inbox   = $this->folder(LabelRole::Inbox, 'INBOX', '/');
        $tag     = $this->label(null, 'Later');

        self::assertSame(['INBOX' => []], self::shape($this->tree->of($account, [$inbox, $tag])));
    }

    /**
     * No paths off IMAP: the label tree decides, for the labels that are rows.
     */
    public function testOffImapTheLabelTreeNests(): void
    {
        $account                        = new Account();
        $account->authType              = AuthType::OAuth2->value;
        $account->oauthProvider         = MailProvider::Google->value;
        $account->gmailMoveOffersLabels = true;
        (new User())->addAccount($account);

        $work            = $this->label(null, 'Work');
        $clients         = $this->label(null, 'Clients');
        $clients->parent = $work;

        self::assertSame(['Work' => ['Clients' => []]], self::shape($this->tree->of($account, [$work, $clients])));
    }

    private function imapAccount(): Account
    {
        $account = new Account();
        (new User())->addAccount($account);

        return $account;
    }

    private function folder(?LabelRole $role, string $path, string $delimiter): Label
    {
        $segments = explode($delimiter, $path);
        $label    = $this->label($role, end($segments));

        $mailbox            = new Mailbox();
        $mailbox->fullPath  = $path;
        $mailbox->delimiter = $delimiter;

        $this->folders[(int) $label->id] = $mailbox;

        return $label;
    }

    private function label(?LabelRole $role, string $name): Label
    {
        $label       = new Label();
        $label->name = $name;
        $label->role = $role;
        new \ReflectionProperty(Label::class, 'id')->setValue($label, $this->nextId++);

        return $label;
    }

    /**
     * @param list<AccountFolderNode> $nodes
     *
     * @return array<string, mixed>
     */
    private static function shape(array $nodes): array
    {
        $shape = [];

        foreach ($nodes as $node) {
            $shape[(string) $node->label->name] = self::shape($node->children);
        }

        return $shape;
    }
}
