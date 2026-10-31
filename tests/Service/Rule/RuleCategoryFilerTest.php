<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\CategoryFiling;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Account;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Repository\Mail\InboxCategoryRepository;
use App\Service\Rule\RuleCategoryFiler;
use App\Tests\Support\Mail\BuildsThreaderWithoutDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * When a filter's "File in category" action files a message, and what filing
 * one changes.
 *
 * A filter is the last word on a message's tab, whatever the receiving server
 * said about the sender (#405): it used to file only a verified sender, which
 * left the reader's own sent mail and anything sent through a platform on
 * somebody's behalf where the built-in sorting put it. What it still refuses
 * is a tab that is not the rule owner's, or not there any more.
 */
final class RuleCategoryFilerTest extends TestCase
{
    use BuildsThreaderWithoutDatabase;

    private const string PASS = 'spf=pass smtp.mailfrom=mondialrelay.fr; dkim=pass header.d=mondialrelay.fr; dmarc=pass action=none header.from=mondialrelay.fr';
    private const string FAIL = 'spf=fail smtp.mailfrom=evil.example; dkim=none; dmarc=fail action=none header.from=mondialrelay.fr';

    private User $owner;
    private InboxCategory $shipping;

    protected function setUp(): void
    {
        $this->owner          = new User();
        $this->shipping       = new InboxCategory();
        $this->shipping->usr  = $this->owner;
        $this->shipping->name = 'Shipping';
    }

    public function testAMatchedMessageIsFiledAndTheThreadFollows(): void
    {
        $message = $this->message(self::PASS);

        self::assertSame(CategoryFiling::Filed, $this->file($message));

        self::assertSame($this->shipping, $message->ruleCategory);
        self::assertSame($this->shipping, $message->thread?->userCategory);
        self::assertSame(
            MessageCategory::Promotions,
            $message->thread->category,
            'filing must leave the built-in category alone: JMAP clients are served it',
        );
    }

    /**
     * A failed check and no check at all file alike. The second is every
     * message you sent yourself; the first is how an application sent through
     * a recruiting platform often reads, signed by the platform rather than by
     * the company in its From line.
     */
    public function testTheSenderCheckDoesNotStandInTheWay(): void
    {
        foreach (['failed' => self::FAIL, 'absent' => null] as $case => $results) {
            $message = $this->message($results);

            self::assertSame(CategoryFiling::Filed, $this->file($message), "a message whose sender check is {$case} was refused");
            self::assertSame($this->shipping, $message->ruleCategory);
            self::assertSame($this->shipping, $message->thread?->userCategory);
        }
    }

    public function testACategoryBelongingToSomebodyElseIsNotFiled(): void
    {
        $strangers       = new InboxCategory();
        $strangers->usr  = new User();
        $strangers->name = 'Not yours';

        $message = $this->message(self::PASS);

        self::assertSame(CategoryFiling::NoCategory, $this->file($message, category: $strangers));

        self::assertNull($message->ruleCategory, 'a rule filed mail under a category its owner does not own');
    }

    public function testAnActionWithNoCategoryOrADeletedOneFilesNothing(): void
    {
        $withoutId = $this->message(self::PASS);
        self::assertSame(CategoryFiling::NoCategory, $this->file($withoutId, action: ['type' => 'fileInCategory']));
        self::assertNull($withoutId->ruleCategory);

        $deleted = $this->message(self::PASS);
        self::assertSame(CategoryFiling::NoCategory, $this->file($deleted, category: null));
        self::assertNull($deleted->ruleCategory);
    }

    /**
     * A filter the reader wrote outranks Google's category, whether or not they
     * asked plMail's own sorting to: Google's label and plMail's rules are both
     * guesses, and a filter is an instruction.
     */
    public function testAFilterFilesGmailMailWhateverTheReaderSaidAboutGooglesSorting(): void
    {
        self::assertFalse($this->owner->categorySorting->overrideProvider, 'the default leaves Google\'s categories in charge of the rest');

        $gmail = $this->message(self::PASS);
        $gmail->gmailLabelIds = ['INBOX', 'CATEGORY_PROMOTIONS'];

        self::assertSame(CategoryFiling::Filed, $this->file($gmail), 'a filter was refused on Gmail mail');
        self::assertSame($this->shipping, $gmail->ruleCategory);
    }

    public function testAThreadTheReaderPinnedKeepsItsTabButTheMessageStillRecordsTheVerdict(): void
    {
        $message = $this->message(self::PASS);
        $message->thread->categoryPinnedAt = new \DateTimeImmutable('2026-07-28 12:30:00');

        self::assertSame(CategoryFiling::Filed, $this->file($message));

        self::assertSame($this->shipping, $message->ruleCategory, 'the verdict is a fact about the message and is recorded');
        self::assertNull($message->thread->userCategory, 'a conversation the reader dragged somewhere was moved by a filter');
    }

    public function testAnOlderMessageDoesNotMoveAConversationANewerReceivedMessagePlaced(): void
    {
        $message = $this->message(self::PASS, receivedAt: '2026-07-28 09:00:00');
        // A newer received message placed the conversation, so that one decides.
        $message->thread->categoryDecidedAt = new \DateTimeImmutable('2026-07-28 12:00:00');
        $message->thread->lastMessageAt     = new \DateTimeImmutable('2026-07-28 12:00:00');

        self::assertSame(CategoryFiling::Filed, $this->file($message));

        self::assertSame($this->shipping, $message->ruleCategory);
        self::assertNull($message->thread->userCategory);
    }

    /**
     * Your reply is newer than the message being filed, and it does not count:
     * a conversation follows what other people send you (#405). This is "Apply
     * to existing mail" on an application you have since answered.
     */
    public function testANewerReplyOfYourOwnDoesNotKeepTheConversationOutOfTheTab(): void
    {
        $message = $this->message(self::PASS, receivedAt: '2026-07-28 09:00:00');
        $message->thread->categoryDecidedAt = new \DateTimeImmutable('2026-07-28 09:00:00');
        // Your reply, at noon, moved the conversation up the list.
        $message->thread->lastMessageAt = new \DateTimeImmutable('2026-07-28 12:00:00');

        self::assertSame(CategoryFiling::Filed, $this->file($message));

        self::assertSame($this->shipping, $message->thread->userCategory, 'a reply of your own kept the conversation out of the filter\'s tab');
    }

    /**
     * The decision recorded in the plan: when two filters match, the one that
     * runs last decides. "Stop after this filter" is how an earlier one is made
     * final, and that is the engine's business, not this class's.
     */
    public function testTheLastFilterToFileAMessageDecidesItsCategory(): void
    {
        $receipts       = new InboxCategory();
        $receipts->usr  = $this->owner;
        $receipts->name = 'Receipts';

        $message = $this->message(self::PASS);

        self::assertSame(CategoryFiling::Filed, $this->file($message));
        self::assertSame(CategoryFiling::Filed, $this->file($message, category: $receipts));

        self::assertSame($receipts, $message->ruleCategory);
        self::assertSame($receipts, $message->thread?->userCategory);
    }

    /**
     * check() is "Test my filters on this message": the same answer file()
     * would give, and nothing touched — a test that filed the message would
     * be the action it claims only to describe.
     */
    public function testCheckAnswersAsFileWouldAndFilesNothing(): void
    {
        $message = $this->message(self::FAIL);

        self::assertSame(CategoryFiling::Filed, $this->file($message, check: true));
        self::assertSame(CategoryFiling::NoCategory, $this->file($message, category: null, check: true));

        self::assertNull($message->ruleCategory, 'check() filed the message');
        self::assertNull($message->thread?->userCategory);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * @param InboxCategory|false|null $category false asks for the default Shipping
     *                                           category; null means the id names
     *                                           nothing, as after a deletion
     * @param array<string,mixed>|null $action
     */
    private function file(Message $message, InboxCategory|false|null $category = false, ?array $action = null, bool $check = false): CategoryFiling
    {
        $repository = $this->createStub(InboxCategoryRepository::class);
        $repository->method('find')->willReturn(false === $category ? $this->shipping : $category);

        $filer = new RuleCategoryFiler(
            $repository,
            $this->threaderWithoutDatabase(),
            new NullLogger(),
        );

        $rule        = new MailRule();
        $rule->usr   = $this->owner;
        $rule->name  = 'Carriers';

        $action ??= ['type' => 'fileInCategory', 'categoryId' => 5];

        return true === $check ? $filer->check($rule, $message, $action) : $filer->file($rule, $message, $action);
    }

    private function message(?string $authenticationResults, string $receivedAt = '2026-07-28 12:00:00'): Message
    {
        $account                = new Account();
        $account->usr           = $this->owner;
        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = MailProvider::Microsoft->value;

        $message              = new Message();
        $message->account     = $account;
        $message->fromAddress = 'noreply@mondialrelay.fr';
        $message->subject     = 'Votre colis est disponible';
        $message->category    = MessageCategory::Promotions;
        $message->receivedAt  = new \DateTimeImmutable($receivedAt);
        $message->headers     = null === $authenticationResults ? [] : ['authentication-results' => $authenticationResults];

        $thread                    = new MessageThread();
        $thread->category          = MessageCategory::Promotions;
        $thread->lastMessageAt     = new \DateTimeImmutable($receivedAt);
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;
        $thread->attachmentCount   = 0;
        $message->thread           = $thread;

        return $message;
    }
}
