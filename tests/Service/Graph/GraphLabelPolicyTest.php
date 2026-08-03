<?php

declare(strict_types=1);

namespace App\Tests\Service\Graph;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Service\Graph\GraphLabelPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Which of a message's labels Exchange is supposed to hear about, and as what.
 *
 * This class decides between the two things Exchange has — one folder, many
 * categories — and every way of getting it wrong is a push that either does
 * nothing or does something nobody asked for. Both have happened: treating
 * Snoozed as a folder sent every snooze looking for a folder id that cannot
 * exist, and the obvious repair (making it a category instead) would have
 * created a "Snoozed" master category in somebody's Outlook.
 */
final class GraphLabelPolicyTest extends TestCase
{
    private GraphLabelPolicy $policy;
    private Account $account;

    protected function setUp(): void
    {
        $this->policy  = new GraphLabelPolicy();
        $this->account = new Account();
    }

    /** Roles the provider has a folder for are folder moves. */
    public function testProviderBackedRolesArePushedAsFolders(): void
    {
        foreach ([LabelRole::Inbox, LabelRole::Sent, LabelRole::Drafts, LabelRole::Trash, LabelRole::Spam, LabelRole::Archive] as $role) {
            $label = $this->label(role: $role);

            self::assertTrue($this->policy->pushesAsFolder($label, $this->account), $role->value);
            self::assertFalse($this->policy->pushesAsCategory($label, $this->account), $role->value);
        }
    }

    /**
     * Snoozed is plMail's own: Exchange has no folder for it, so it is neither
     * a move nor — and this is the half that is easy to get wrong — a category.
     * Pushing it as one would put a label on the user's mailbox that they never
     * made and cannot explain.
     */
    public function testSnoozedIsNeitherFolderNorCategory(): void
    {
        $snoozed = $this->label(role: LabelRole::Snoozed);

        self::assertFalse($this->policy->pushesAsFolder($snoozed, $this->account));
        self::assertFalse($this->policy->pushesAsCategory($snoozed, $this->account));
    }

    /**
     * A custom label is a category unless this account has materialised it as a
     * real Exchange folder — and that is per account, since the same label can
     * be a folder on one and a category on another.
     */
    public function testACustomLabelIsACategoryUntilItHasAFolderHere(): void
    {
        $label = $this->label(name: 'Receipts');

        self::assertTrue($this->policy->pushesAsCategory($label, $this->account));
        self::assertFalse($this->policy->pushesAsFolder($label, $this->account));

        $this->bind($label, $this->account, 'AAMkAD-folder-id');

        self::assertTrue($this->policy->pushesAsFolder($label, $this->account));
        self::assertFalse($this->policy->pushesAsCategory($label, $this->account));

        // Bound on this account only: another account has never seen it.
        self::assertTrue($this->policy->pushesAsCategory($label, new Account()));
    }

    public function testCategoryNamesAreTheCustomLabelsOnly(): void
    {
        $message = $this->message(
            $this->label(role: LabelRole::Inbox),
            $this->label(role: LabelRole::Snoozed),
            $this->label(name: 'Receipts'),
            $this->label(name: 'Work/Invoices'),
        );

        self::assertSame(['Receipts', 'Work/Invoices'], $this->policy->categoryNames($message));
    }

    /**
     * An Exchange message is in exactly one folder. Where local state says
     * otherwise the push has to pick one rather than refuse, and sortOrder is
     * what decides — a message in Inbox and Archive is in the Inbox.
     */
    public function testTheExclusiveLocationIsTheHighestPriorityFolder(): void
    {
        $inbox = $this->label(role: LabelRole::Inbox, sortOrder: 0);
        $archive = $this->label(role: LabelRole::Archive, sortOrder: 5);

        $message = $this->message($archive, $inbox, $this->label(name: 'Receipts'));

        self::assertSame($inbox, $this->policy->exclusiveLocation($message));
        self::assertTrue($this->policy->hasConflictingLocations($message));
    }

    /**
     * The case that used to warn on every push: a snoozed message is not in two
     * folders, because Snoozed is not a folder at all.
     */
    public function testSnoozedBesideAFolderIsNotAConflict(): void
    {
        $message = $this->message(
            $this->label(role: LabelRole::Archive),
            $this->label(role: LabelRole::Snoozed),
        );

        self::assertFalse($this->policy->hasConflictingLocations($message));
    }

    public function testAMessageWithNoFolderLabelHasNoLocation(): void
    {
        $message = $this->message($this->label(name: 'Receipts'));

        self::assertNull($this->policy->exclusiveLocation($message));
        self::assertFalse($this->policy->hasConflictingLocations($message));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function label(?LabelRole $role = null, ?string $name = null, int $sortOrder = 0): Label
    {
        $label = new Label();
        $label->role = $role;
        $label->name = $name ?? (null === $role ? 'label' : $role->value);
        $label->sortOrder = $sortOrder;

        return $label;
    }

    private function bind(Label $label, Account $account, string $graphFolderId): void
    {
        $binding = new LabelBinding();
        $binding->account = $account;
        $binding->graphFolderId = $graphFolderId;

        $label->addBinding($binding);
    }

    private function message(Label ...$labels): Message
    {
        $message = new Message();
        $message->account = $this->account;

        foreach ($labels as $label) {
            $message->addLabel($label);
        }

        return $message;
    }
}
