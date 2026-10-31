<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\CategoryFiling;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Mail\InboxCategory;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Jmap\Query\CompiledFilter;
use App\Jmap\Query\EmailFilterCompiler;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\InboxCategoryRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Rule\MailRuleRepository;
use App\Service\Rule\RuleCategoryFiler;
use App\Service\Rule\RuleConditions;
use App\Service\Rule\RuleMessageCheck;
use App\Tests\Support\Mail\BuildsThreaderWithoutDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * "Test my filters on this message" reports what the engine would do, in the
 * engine's order, and does none of it.
 *
 * Matching itself is Postgres's (MessageRepository::matchingIds), so it is
 * stubbed here by subject: what is under test is the walk — order, account
 * scope, Spam, stop-after — and the File in category verdict, which goes
 * through RuleCategoryFiler::check() and must agree with what file() would
 * decide.
 */
final class RuleMessageCheckTest extends TestCase
{
    use BuildsThreaderWithoutDatabase;

    private const string PASS = 'spf=pass smtp.mailfrom=cyberveille.eu; dkim=pass header.d=cyberveille.eu; dmarc=pass action=none header.from=cyberveille.eu';

    private User $owner;
    private InboxCategory $newsletter;
    private Label $spam;

    protected function setUp(): void
    {
        $this->owner = new User();

        $this->spam       = new Label();
        $this->spam->usr  = $this->owner;
        $this->spam->name = 'Spam';
        $this->spam->role = LabelRole::Spam;
        new \ReflectionProperty(Label::class, 'id')->setValue($this->spam, 9);

        $this->newsletter       = new InboxCategory();
        $this->newsletter->usr  = $this->owner;
        $this->newsletter->name = 'Newsletter';
    }

    public function testEachRuleIsReportedInOrderWithWhatItWouldDo(): void
    {
        $message = $this->message(self::PASS);

        $result = $this->check($message, [
            $this->rule('Invoices', 'invoice', [['type' => 'applyLabel', 'labelId' => 1]]),
            $this->rule('CyberLettre', 'CyberLettre', [['type' => 'fileInCategory', 'categoryId' => 7]]),
        ]);

        self::assertSame(['no_match', 'matches'], array_column($result['rules'], 'state'));
        self::assertSame([], $result['rules'][0]['actions'], 'a rule that does not match does nothing');
        self::assertSame(
            [['type' => 'fileInCategory', 'filing' => CategoryFiling::Filed, 'category' => 'Newsletter']],
            $result['rules'][1]['actions'],
        );
        self::assertNull($message->ruleCategory, 'testing a filter filed the message');
    }

    /** No sender check at all — your own sent mail — files like any other (#405). */
    public function testAMatchFromAnUnverifiedSenderWouldStillFile(): void
    {
        $result = $this->check($this->message(null), [
            $this->rule('CyberLettre', 'CyberLettre', [['type' => 'fileInCategory', 'categoryId' => 7]]),
        ]);

        self::assertSame('matches', $result['rules'][0]['state']);
        self::assertSame(CategoryFiling::Filed, $result['rules'][0]['actions'][0]['filing']);
    }

    /**
     * Mail in Spam is left out of every filter that does not name Spam, and
     * the test says so rather than "does not match" — the conditions may well
     * match, and the reader needs to know which of the two it was.
     */
    public function testAMessageInSpamIsSkippedUnlessTheFilterNamesSpam(): void
    {
        $message = $this->message(self::PASS);
        $message->addLabel($this->spam);

        $aboutSpam             = $this->rule('Spam newsletters', 'CyberLettre', [['type' => 'star']]);
        $aboutSpam->conditions = ['operator' => 'AND', 'conditions' => [['subject' => 'CyberLettre'], ['hasLabel' => 9]]];

        $result = $this->check($message, [
            $this->rule('CyberLettre', 'CyberLettre', [['type' => 'fileInCategory', 'categoryId' => 7]]),
            $aboutSpam,
        ]);

        self::assertSame(['spam', 'matches'], array_column($result['rules'], 'state'));
        self::assertSame([], $result['rules'][0]['actions']);
    }

    public function testRulesAfterAStopAreReportedAsNotReached(): void
    {
        $first                 = $this->rule('First', 'CyberLettre', [['type' => 'star']]);
        $first->stopProcessing = true;

        $result = $this->check($this->message(self::PASS), [
            $first,
            $this->rule('Second', 'CyberLettre', [['type' => 'markRead']]),
        ]);

        self::assertSame(['matches', 'stopped'], array_column($result['rules'], 'state'));
        self::assertSame($first, $result['rules'][1]['stoppedBy']);
    }

    public function testARuleScopedToAnotherAccountIsLeftOut(): void
    {
        $elsewhere          = $this->rule('Elsewhere', 'CyberLettre', [['type' => 'star']]);
        $elsewhere->account = new Account();

        self::assertSame([], $this->check($this->message(self::PASS), [$elsewhere])['rules']);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * @param list<MailRule> $rules
     *
     * @return array<string, mixed>
     */
    private function check(Message $message, array $rules): array
    {
        $ruleRepository = $this->createStub(MailRuleRepository::class);
        $ruleRepository->method('findEnabledForUserOrdered')->willReturn($rules);

        // "Matches" when the rule's subject condition is in the message's
        // subject — standing in for the SQL this cannot run.
        $messages = $this->createStub(MessageRepository::class);
        $messages->method('matchingIds')->willReturnCallback(
            static fn (array $ids, CompiledFilter $filter): array => str_contains(
                (string) $message->subject,
                trim((string) (array_values($filter->parameters)[0] ?? ''), '%'),
            ) ? $ids : [],
        );

        $categories = $this->createStub(InboxCategoryRepository::class);
        $categories->method('find')->willReturn($this->newsletter);

        $labels = $this->createStub(LabelRepository::class);
        $labels->method('findBy')->willReturnCallback(
            fn (array $criteria): array => in_array(9, (array) $criteria['id'], true) ? [$this->spam] : [],
        );

        return new RuleMessageCheck(
            $ruleRepository,
            $messages,
            new RuleConditions(new EmailFilterCompiler(), $labels),
            new RuleCategoryFiler($categories, $this->threaderWithoutDatabase(), new NullLogger()),
            $categories,
            new NullLogger(),
        )->check($message);
    }

    /**
     * @param list<array<string, mixed>> $actions
     */
    private function rule(string $name, string $subject, array $actions): MailRule
    {
        $rule             = new MailRule();
        $rule->usr        = $this->owner;
        $rule->name       = $name;
        $rule->conditions = ['subject' => $subject];
        $rule->actions    = $actions;

        return $rule;
    }

    private function message(?string $authenticationResults): Message
    {
        $account                = new Account();
        $account->usr           = $this->owner;
        $account->authType      = AuthType::OAuth2->value;
        $account->oauthProvider = MailProvider::Microsoft->value;

        $message              = new Message();
        new \ReflectionProperty(Message::class, 'id')->setValue($message, 42);
        $message->account     = $account;
        $message->subject     = 'Votre CyberLettre du jour';
        $message->fromAddress = 'cyberveille@cyberveille.eu';
        $message->headers     = null === $authenticationResults ? [] : ['authentication-results' => $authenticationResults];

        return $message;
    }
}
