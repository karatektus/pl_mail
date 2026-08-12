<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Service\Mail\DraftPersister;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What saving a draft does to it.
 *
 * This sequence was written twice — once for the browser, once for JMAP — and
 * the copies drifted, which is how drafts written in an app stopped appearing
 * in the Drafts list. The assertions below are the things the two copies had to
 * agree on and did not: the label that files it, the flag that survives an
 * autosave, and the difference between the first save and the next one.
 */
final class DraftPersisterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private DraftPersister $drafts;

    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->drafts     = $container->get(DraftPersister::class);

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

    // ── what a saved draft becomes ────────────────────────────────────────

    public function testASavedDraftIsFiledUnderItsAccountsDraftsLabel(): void
    {
        $message = $this->draft();

        $this->drafts->save($message, $this->account);

        $roles = [];

        foreach ($message->labels as $label) {
            $roles[] = $label->role;
        }

        self::assertContains(LabelRole::Drafts, $roles);
        self::assertSame($this->account, $message->account);
    }

    /**
     * The $draft keyword is what tells every client this is unsent, and seenAt
     * is set because its own author has plainly read it — an unread badge on
     * your own draft is noise.
     */
    public function testASavedDraftIsMarkedAsADraftItsAuthorHasSeen(): void
    {
        $message = $this->draft();

        $this->drafts->save($message, $this->account);

        self::assertTrue($message->isDraft());
        self::assertNotNull($message->seenAt);
        self::assertSame($this->account->email, $message->fromAddress);
    }

    /** A draft belongs to a conversation like any other message. */
    public function testASavedDraftJoinsAConversation(): void
    {
        $message = $this->draft();

        $this->drafts->save($message, $this->account);

        self::assertNotNull($message->thread);
        self::assertTrue(
            $message->thread->messages->contains($message),
            'the conversation does not hold the draft, so its labels are derived from nothing',
        );
    }

    /**
     * Only the sync layer sanitises bodies, so an unsanitised draft renders
     * blank in the conversation until the sent copy comes back from IMAP.
     */
    public function testTheBodyIsSanitisedForRendering(): void
    {
        $message           = $this->draft();
        $message->bodyHtml = '<p>Hello<script>alert(1)</script></p>';

        $this->drafts->save($message, $this->account);

        self::assertNotNull($message->bodyHtmlSafe);
        self::assertStringNotContainsString('<script>', $message->bodyHtmlSafe);
    }

    /**
     * Autosave runs on every keystroke and comes through here, so the flag has
     * to be derived from the parts. Assigning false used to wipe it off a
     * draft that had files attached to it.
     */
    public function testSavingDoesNotForgetTheFilesADraftAlreadyHas(): void
    {
        $message = $this->draft();

        // The window forces a save before it accepts an upload, so this is the
        // real order: draft, then file, then the next keystroke's autosave.
        $this->drafts->save($message, $this->account);

        $part              = new MessagePart();
        $part->message     = $message;
        $part->contentType = 'text/plain';
        $part->filename    = 'notes.txt';
        $part->disposition = 'attachment';
        $part->isInline    = false;
        $message->addMessagePart($part);
        $this->em->persist($part);
        $this->em->flush();

        $this->drafts->save($message, $this->account);

        self::assertTrue((bool) $message->hasAttachments);
    }

    // ── first save versus autosave ────────────────────────────────────────

    /**
     * The only moment a first save can be told from an autosave of the same
     * draft, and the whole reason storeAndThread() answers with a boolean:
     * afterwards every call looks identical, and a client would be handed
     * "created" for an id it already holds.
     */
    public function testOnlyTheFirstSaveMintsTheRow(): void
    {
        $message = $this->draft();

        self::assertTrue($this->drafts->storeAndThread($message, $this->account));

        $id = $message->id;

        self::assertFalse($this->drafts->storeAndThread($message, $this->account));
        self::assertSame($id, $message->id, 'the second save wrote a second draft');
    }

    // ── the plain-text body ───────────────────────────────────────────────

    /**
     * The draft snippet in the conversation list, and the text/plain part of
     * the mail when it goes out. Only the user's own writing: the quoted
     * original underneath it is marked by ReplyDraftBuilder and cut here, or
     * every reply would preview as the message it answers.
     */
    public function testOnlyTheUsersOwnWritingBecomesThePlainTextBody(): void
    {
        $text = $this->drafts->plainTextBody(
            '<p>Yes, Tuesday works.</p><div data-quoted="1">On Monday, Katharina wrote:'
            . '<blockquote>Does Tuesday work?</blockquote></div>',
        );

        self::assertSame('Yes, Tuesday works.', $text);
    }

    /** Drafts written before data-quoted existed are still cut correctly. */
    public function testABareBlockquoteIsTreatedAsAQuotedOriginal(): void
    {
        $text = $this->drafts->plainTextBody('<p>Sounds good.</p><blockquote>The original</blockquote>');

        self::assertSame('Sounds good.', $text);
    }

    public function testLineBreaksSurviveAsLineBreaks(): void
    {
        self::assertSame("one\ntwo", $this->drafts->plainTextBody('<p>one<br>two</p>'));
    }

    /**
     * Null rather than an empty string: an empty draft has no snippet, and ''
     * would put a blank line in the conversation list where nothing belongs.
     */
    public function testABodyWithNoWritingInItHasNoPlainText(): void
    {
        self::assertNull($this->drafts->plainTextBody(null));
        self::assertNull($this->drafts->plainTextBody('   '));
        self::assertNull($this->drafts->plainTextBody('<p><br></p>'));
    }

    // ── inline images ─────────────────────────────────────────────────────

    /**
     * The round trip that decides whether an inline image survives the post.
     *
     * The compose window can only render an image it can fetch, so the editor
     * holds the attachment route; a recipient can only render one that is
     * embedded, so the wire has to hold `cid:`. Saving is where the one turns
     * into the other, and it has to happen on every save rather than only on
     * send — otherwise the autosaved draft, and the copy JMAP publishes to
     * every other client, points at a URL only a logged-in plMail session can
     * follow.
     */
    public function testAnInlineImageIsStoredAsACidReference(): void
    {
        $message = $this->draft();
        $part    = $this->inlinePart($message, 'logo-1@plmail');

        $message->bodyHtml = '<p>See:</p>'
            . '<img src="/mail/attachment/' . $part->id . '" data-cid="logo-1@plmail" style="max-width:100%">';

        $this->drafts->save($message, $this->account);

        self::assertStringContainsString('src="cid:logo-1@plmail"', (string) $message->bodyHtml);
        self::assertStringNotContainsString('/mail/attachment/', (string) $message->bodyHtml);

        // …and what the app itself renders resolves back to the route, so the
        // draft in the conversation is not a broken image.
        self::assertStringContainsString(
            '/mail/attachment/' . $part->id,
            (string) $message->bodyHtmlSafe,
        );
    }

    /**
     * Idempotent: autosave runs on every keystroke, and a body already
     * rewritten must not become `cid:cid:…` on the next pass.
     */
    public function testSavingAnAlreadyRewrittenBodyChangesNothing(): void
    {
        $message = $this->draft();
        $this->inlinePart($message, 'logo-2@plmail');

        $message->bodyHtml = '<img src="cid:logo-2@plmail" data-cid="logo-2@plmail">';

        $this->drafts->save($message, $this->account);
        $once = $message->bodyHtml;

        $this->drafts->save($message, $this->account);

        self::assertSame($once, $message->bodyHtml);
    }

    /**
     * An image the user pasted a link to is theirs, not ours. Without a
     * data-cid there is no part behind it, and rewriting it would turn a
     * working remote image into a reference to nothing.
     */
    public function testARemoteImageIsLeftAlone(): void
    {
        $message           = $this->draft();
        $message->bodyHtml = '<img src="https://example.test/banner.png">';

        $this->drafts->save($message, $this->account);

        self::assertStringContainsString('https://example.test/banner.png', (string) $message->bodyHtml);
    }

    /** The snippet is derived from the same HTML, so an image adds nothing. */
    public function testAnInlineImageDoesNotLeakIntoThePlainTextBody(): void
    {
        $message = $this->draft();
        $this->inlinePart($message, 'logo-3@plmail');

        $message->bodyHtml = '<p>Have a look.</p><img src="cid:logo-3@plmail" data-cid="logo-3@plmail">';

        $this->drafts->save($message, $this->account);

        self::assertSame('Have a look.', $message->bodyText);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function inlinePart(Message $message, string $contentId): MessagePart
    {
        $this->em->persist($message);
        $this->em->flush();

        $part              = new MessagePart();
        $part->message     = $message;
        $part->contentType = 'image/png';
        $part->filename    = 'logo.png';
        $part->disposition = 'inline';
        $part->contentId   = $contentId;
        $part->isInline    = true;
        $part->storagePath = 'inline/logo.png';

        $message->addMessagePart($part);
        $this->em->persist($part);
        $this->em->flush();

        return $part;
    }


    /**
     * The From picker exists to let somebody send as an alias, and until now it
     * did not work: the address it chose was written onto the message and then
     * overwritten with the account's own one line later, inside this class. The
     * mail went out as the primary address and nothing anywhere said so.
     */
    public function testADraftKeepsTheSenderItWasSavedWith(): void
    {
        $message = $this->draft();

        $this->drafts->save($message, $this->account, 'alias@example.test');

        self::assertSame('alias@example.test', $message->fromAddress);
    }

    /**
     * A caller with no From picker — JmapDraftWriter, and the send path before
     * a token is resolved — still gets the account's own address.
     */
    public function testADraftWithNoChosenSenderFallsBackToTheAccount(): void
    {
        $message = $this->draft();

        $this->drafts->save($message, $this->account);

        self::assertSame($this->account->email, $message->fromAddress);
    }

    private function draft(): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Persister fixture';
        $message->bodyHtml       = '<p>Body</p>';
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<persister-%s@example.test>', uniqid('', true));

        return $message;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'persister-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Draft';
        $user->nameLast  = 'Persister';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'persister-fixture-' . uniqid('', true) . '@example.test';

        $this->account                 = new Account();
        $this->account->usr            = $user;
        $this->account->email          = $email;
        $this->account->username       = $email;
        $this->account->imapHost       = 'localhost';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost       = 'localhost';
        $this->account->smtpPort       = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password       = 'x';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;
        $this->em->persist($this->account);

        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = 'INBOX';
        $mailbox->fullPath      = 'INBOX';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);

        $this->em->flush();

        $user->addAccount($this->account);
    }
}
