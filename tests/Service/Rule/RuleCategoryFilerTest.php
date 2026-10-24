<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Account;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Repository\Mail\InboxCategoryRepository;
use App\Service\Mail\SenderAuthentication;
use App\Service\Rule\RuleCategoryFiler;
use App\Tests\Support\Mail\BuildsThreaderWithoutDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * When a filter's "File in category" action is allowed to file a message, and
 * what filing one changes.
 *
 * THE CONSTRAINT THIS CLASS EXISTS TO HOLD: a category the reader made, by a
 * sender they named, must not become somewhere a forgery can sit looking
 * legitimate. "Sender's domain is mondialrelay.fr" reads the From line, which
 * anybody can write, so a parcel-delivery phishing mail would otherwise land in
 * a tab called Shipping next to the real ones. The only thing standing between
 * the two is the receiving server's own authentication verdict, so the action
 * files nothing that is not a Pass — not a Fail, and not an Unknown either
 * (an account whose receiving server plMail cannot identify files nothing,
 * which is the price of the guarantee and is documented as such).
 *
 * Every refusal below is paired with a case that files, on the same fixture
 * with one thing changed, so "never files anything" cannot pass for "refuses
 * what it should".
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

    public function testAMessageFromAnAuthenticatedSenderIsFiledAndTheThreadFollows(): void
    {
        $message = $this->message(self::PASS);

        self::assertTrue($this->file($message));

        self::assertSame($this->shipping, $message->ruleCategory);
        self::assertSame($this->shipping, $message->thread?->userCategory);
        self::assertSame(
            MessageCategory::Promotions,
            $message->thread->category,
            'filing must leave the built-in category alone: JMAP clients are served it',
        );
    }

    public function testAForgedSenderIsNotFiled(): void
    {
        $message = $this->message(self::FAIL);

        self::assertFalse($this->file($message));

        self::assertNull($message->ruleCategory, 'a message that FAILED authentication was filed under a reader-made category');
        self::assertNull($message->thread?->userCategory);
    }

    public function testASenderPlMailCannotVerifyIsNotFiledEither(): void
    {
        // No Authentication-Results at all: the verdict is Unknown, and the
        // reader decided that Unknown is not good enough.
        $message = $this->message(null);

        self::assertFalse($this->file($message));

        self::assertNull($message->ruleCategory);
        self::assertNull($message->thread?->userCategory);
    }

    public function testACategoryBelongingToSomebodyElseIsNotFiled(): void
    {
        $strangers       = new InboxCategory();
        $strangers->usr  = new User();
        $strangers->name = 'Not yours';

        $message = $this->message(self::PASS);

        self::assertFalse($this->file($message, category: $strangers));

        self::assertNull($message->ruleCategory, 'a rule filed mail under a category its owner does not own');
    }

    public function testAnActionWithNoCategoryOrADeletedOneFilesNothing(): void
    {
        $withoutId = $this->message(self::PASS);
        self::assertFalse($this->file($withoutId, action: ['type' => 'fileInCategory']));
        self::assertNull($withoutId->ruleCategory);

        $deleted = $this->message(self::PASS);
        self::assertFalse($this->file($deleted, category: null));
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

        self::assertTrue($this->file($gmail), 'a filter was refused on Gmail mail');
        self::assertSame($this->shipping, $gmail->ruleCategory);
    }

    public function testAThreadTheReaderPinnedKeepsItsTabButTheMessageStillRecordsTheVerdict(): void
    {
        $message = $this->message(self::PASS);
        $message->thread->categoryPinnedAt = new \DateTimeImmutable('2026-07-28 12:30:00');

        self::assertTrue($this->file($message));

        self::assertSame($this->shipping, $message->ruleCategory, 'the verdict is a fact about the message and is recorded');
        self::assertNull($message->thread->userCategory, 'a conversation the reader dragged somewhere was moved by a filter');
    }

    public function testAnOlderMessageDoesNotMoveAConversationItsNewestMessageIsNotIn(): void
    {
        $message = $this->message(self::PASS, receivedAt: '2026-07-28 09:00:00');
        // Newer than this message, so the thread's tab is decided by that one.
        $message->thread->lastMessageAt = new \DateTimeImmutable('2026-07-28 12:00:00');

        self::assertTrue($this->file($message));

        self::assertSame($this->shipping, $message->ruleCategory);
        self::assertNull($message->thread->userCategory);
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

        self::assertTrue($this->file($message));
        self::assertTrue($this->file($message, category: $receipts));

        self::assertSame($receipts, $message->ruleCategory);
        self::assertSame($receipts, $message->thread?->userCategory);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * @param InboxCategory|false|null $category false asks for the default Shipping
     *                                           category; null means the id names
     *                                           nothing, as after a deletion
     * @param array<string,mixed>|null $action
     */
    private function file(Message $message, InboxCategory|false|null $category = false, ?array $action = null): bool
    {
        $repository = $this->createStub(InboxCategoryRepository::class);
        $repository->method('find')->willReturn(false === $category ? $this->shipping : $category);

        $filer = new RuleCategoryFiler(
            $repository,
            new SenderAuthentication(),
            $this->threaderWithoutDatabase(),
            new NullLogger(),
        );

        $rule        = new MailRule();
        $rule->usr   = $this->owner;
        $rule->name  = 'Carriers';

        return $filer->file($rule, $message, $action ?? ['type' => 'fileInCategory', 'categoryId' => 5]);
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
