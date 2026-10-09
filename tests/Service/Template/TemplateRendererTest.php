<?php

declare(strict_types=1);

namespace App\Tests\Service\Template;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Template\TemplateRenderer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a template becomes when it is inserted.
 *
 * The claim with a bug behind it is the division of labour: the server fills
 * in dates, sender and signature, and leaves the recipient OPEN, because a
 * template is usually picked before anybody has been addressed. A renderer that
 * "helpfully" resolved recipient variables to the empty string would produce
 * "Hi ," in every message started from a template, with nothing left in the
 * body for the compose window to fill once the address was typed.
 */
final class TemplateRendererTest extends KernelTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private TemplateRenderer $renderer;
    private User $user;

    protected function setUp(): void
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->renderer   = $container->get(TemplateRenderer::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;
        $this->connection->beginTransaction();

        // A fixed zone, so "today" in the assertions is the day the test says.
        $this->user->timezone = 'UTC';
    }

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testRecipientVariablesAreLeftOpenForTheComposeWindow(): void
    {
        $rendered = $this->renderer->render(
            'For {{recipient.name}}',
            '<p>Hi {{recipient.first_name}},</p>',
            $this->account('open@plmail.test'),
            null,
            $this->user,
        );

        self::assertSame(
            '<p>Hi <span data-pl-var="recipient.first_name">First name</span>,</p>',
            $rendered->html,
            'in the body, a marker the window can find and replace',
        );
        self::assertSame(
            'For {{recipient.name}}',
            $rendered->subject,
            'in the subject, which has no markup, the token itself',
        );
    }

    /**
     * The marker has to outlive an autosave. The composer's sanitiser drops
     * every data- attribute it has not been told about, and a draft saved
     * between inserting a template and addressing it would otherwise come back
     * with "First name" as ordinary text: never filled, never warned about.
     */
    public function testTheOpenMarkerSurvivesTheComposeSanitiser(): void
    {
        $rendered = $this->renderer->render(null, '<p>Hi {{recipient.first_name}},</p>', $this->account('keep@plmail.test'), null, $this->user);

        $stored = static::getContainer()->get(MailBodySanitizer::class)->sanitizeComposedBody($rendered->html);

        self::assertStringContainsString('data-pl-var="recipient.first_name"', $stored);
    }

    public function testTheSenderIsTheAddressBeingWrittenFrom(): void
    {
        $account       = $this->account('main@plmail.test');
        $account->name = 'Alex Morgan';

        $rendered = $this->renderer->render(
            null,
            '<p>{{sender.name}} — {{sender.email}}</p>',
            $account,
            'alias@plmail.test',
            $this->user,
        );

        self::assertSame('<p>Alex Morgan — alias@plmail.test</p>', $rendered->html);
    }

    public function testADateTakesItsOffsetAndItsFormat(): void
    {
        $now = new DateTimeImmutable('2026-10-09 12:00:00 UTC');

        $rendered = $this->renderer->render(
            '{{date|format=iso}}',
            '<p>{{date|offset=+7d|format=iso}} / {{date|offset=-2w|format=iso}} / {{date|offset=+1m|format=pattern:dd.MM.yyyy}} / {{date|format=weekday}}</p>',
            $this->account('dates@plmail.test'),
            null,
            $this->user,
            $now,
        );

        self::assertSame('2026-10-09', $rendered->subject);
        self::assertSame('<p>2026-10-16 / 2026-09-25 / 09.11.2026 / Friday</p>', $rendered->html);
    }

    /**
     * An offset or format that does not parse is today in the default format,
     * not an exception: the token came out of a contenteditable, and a typo in
     * it must not make the template impossible to insert.
     */
    public function testAnUnreadableDateSettingFallsBackRatherThanFailing(): void
    {
        $now = new DateTimeImmutable('2026-10-09 12:00:00 UTC');

        $rendered = $this->renderer->render(
            null,
            '<p>{{date|offset=soon|format=nonsense}}</p>',
            $this->account('typo@plmail.test'),
            null,
            $this->user,
            $now,
        );

        self::assertSame('<p>October 9, 2026</p>', $rendered->html);
    }

    /**
     * `{{signature}}` becomes the composer's own signature block, so the
     * window's rule of one signature per body covers a template for free. And
     * a paragraph that held nothing else becomes the block, not a paragraph
     * around it — a div inside a p is split by the browser's parser into a
     * blank line above every signature.
     */
    public function testTheSignatureBecomesTheComposersOwnBlockInPlaceOfItsParagraph(): void
    {
        $account = $this->account('signed@plmail.test');
        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Alex</p>');

        $rendered = $this->renderer->render(
            'Hello {{signature}}',
            '<p>Thanks,</p><p>{{signature}}</p>',
            $account,
            null,
            $this->user,
        );

        self::assertSame(
            '<p>Thanks,</p><div class="pl-signature" data-pl-signature><p>Alex</p></div>',
            $rendered->html,
        );
        self::assertSame('Hello', $rendered->subject, 'a subject line does not carry a signature');
    }

    /**
     * A template may name the signature it signs with, and that one is pinned
     * so a change of From does not swap it. The name is looked up among the
     * user's own accounts only.
     */
    public function testANamedSignatureIsUsedAndPinnedWhateverAddressIsInFrom(): void
    {
        $from = $this->account('from@plmail.test');
        $from->setSetting(Account::SETTING_SIGNATURE, '<p>From</p>');

        $named = $this->account('named@plmail.test');
        $named->setSetting(Account::SETTING_SIGNATURE, '<p>Named</p>');
        $this->em->flush();

        $rendered = $this->renderer->render(
            null,
            sprintf('<div>{{signature|account=%d}}</div>', $named->id),
            $from,
            null,
            $this->user,
        );

        self::assertSame(
            '<div class="pl-signature" data-pl-signature data-pl-signature-pinned><p>Named</p></div>',
            $rendered->html,
        );
    }

    public function testANamedSignatureThatNoLongerExistsFallsBackToTheAutomaticOne(): void
    {
        $from = $this->account('fallback@plmail.test');
        $from->setSetting(Account::SETTING_SIGNATURE, '<p>From</p>');

        $rendered = $this->renderer->render(null, '<p>{{signature|account=2147483000}}</p>', $from, null, $this->user);

        self::assertSame('<div class="pl-signature" data-pl-signature><p>From</p></div>', $rendered->html);
    }

    /**
     * A value is text. A sender name with an angle bracket in it must arrive in
     * the compose window's document as that character, not as a tag.
     */
    public function testValuesAreEscapedIntoTheBodyAndNotIntoTheSubject(): void
    {
        $account       = $this->account('escape@plmail.test');
        $account->name = 'A <b>& B';

        $rendered = $this->renderer->render('{{sender.name}}', '<p>{{sender.name}}</p>', $account, null, $this->user);

        self::assertSame('<p>A &lt;b&gt;&amp; B</p>', $rendered->html);
        self::assertSame('A <b>& B', $rendered->subject);
    }

    private function account(string $email): Account
    {
        $account = new Account();

        $account->usr       = $this->user;
        $account->name      = $email;
        $account->username  = $email;
        $account->email     = $email;
        $account->authType  = 'password';
        $account->isActive  = true;
        $account->isPrimary = false;
        $account->sortOrder = 50;
        $account->imapHost  = 'imap.example.test';

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
