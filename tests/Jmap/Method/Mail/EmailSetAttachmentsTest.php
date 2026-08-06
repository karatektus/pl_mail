<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Mail;

use App\Domain\Helper\UploadStorage;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\UploadedBlob;
use App\Jmap\Blob\BlobId;
use App\Jmap\Method\Mail\EmailGetMethod;
use App\Jmap\Method\Mail\EmailSetMethod;
use App\Tests\Jmap\JmapTestCase;

/**
 * Attaching a file to a draft that already exists.
 *
 * The client that needs this is a phone composer: it autosaves, so by the time
 * the user picks a file the draft is already on the server and the only honest
 * call is an update. That used to be accepted and thrown away — the patch named
 * `attachments`, `Email/set` answered `updated`, and a following `Email/get`
 * showed an empty list — which cost the client a delete-and-recreate dance and
 * a new Email id for every file picked.
 *
 * Three decisions are worth reading before the tests:
 *
 * 1. **`attachments` is a whole value, not a patch.** The array is the complete
 *    set the draft should end up with, so a part left out of it is a part the
 *    client removed. RFC 8620 §5.3 spells a patch as `attachments/0`, and this
 *    key is the plain property.
 * 2. **A part already on the draft is kept by its `p-` blobId**, with no second
 *    upload of bytes the server is already holding. That is what makes "add one
 *    file" expressible: re-send the list you were given, plus the new blob.
 * 3. **A blobId that cannot be resolved fails the whole update**, and the draft
 *    is left exactly as it was — including the subject from the same patch. A
 *    client reading `notUpdated` must be able to assume nothing happened.
 */
final class EmailSetAttachmentsTest extends JmapTestCase
{
    private EmailSetMethod $set;
    private EmailGetMethod $get;
    private UploadStorage $uploads;
    private string $projectDir;

    /** @var list<int> every account this test staged bytes under */
    private array $bucketIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();

        $this->set        = $container->get(EmailSetMethod::class);
        $this->get        = $container->get(EmailGetMethod::class);
        $this->uploads    = $container->get(UploadStorage::class);
        $this->projectDir = (string) $container->getParameter('kernel.project_dir');
        $this->bucketIds  = [(int) $this->account->id];
    }

    /**
     * The database rolls back; the bytes on disk do not. Everything this suite
     * writes is bucketed under an account the fixture just created, which no
     * other test shares, so the whole bucket goes.
     */
    protected function tearDown(): void
    {
        foreach ($this->bucketIds as $accountId) {
            foreach (['attachments', 'uploads'] as $bucket) {
                $this->removeTree(sprintf('%s/var/%s/%d', $this->projectDir, $bucket, $accountId));
            }
        }

        parent::tearDown();
    }

    // ── Adding to a draft that already exists ─────────────────────────────

    /**
     * The whole point of the feature, asserted where the client sees it: the
     * update reports success and `Email/get` shows the file.
     */
    public function testAnAttachmentCanBeAddedToADraftThatAlreadyExists(): void
    {
        $draft = $this->draftMessage();

        $result = $this->handle(['update' => [(string) $draft->id => [
            'attachments' => [[
                'blobId' => $this->uploadedBlob('the quarterly numbers'),
                'name'   => 'notes.txt',
                'type'   => 'text/plain',
            ]],
        ]]]);

        self::assertSame([(string) $draft->id => null], (array) $result['updated'], json_encode($result['notUpdated']));

        $email = $this->email($draft);

        self::assertTrue($email['hasAttachment']);
        self::assertCount(1, $email['attachments']);
        self::assertSame('notes.txt', $email['attachments'][0]['name']);
        self::assertSame('text/plain', $email['attachments'][0]['type']);
        self::assertSame(21, $email['attachments'][0]['size']);
        self::assertSame('p-'.$this->parts($draft)[0]->id, $email['attachments'][0]['blobId']);
    }

    /**
     * The bytes are copied into attachment storage rather than referenced where
     * they were uploaded: an UploadedBlob is scratch space that `app:prune:blobs`
     * reclaims on a timer, so a draft pointing at one would lose its files days
     * later with nothing to say why.
     */
    public function testTheUploadedBytesAreCopiedIntoAttachmentStorage(): void
    {
        $draft = $this->draftMessage();

        $this->handle(['update' => [(string) $draft->id => [
            'attachments' => [['blobId' => $this->uploadedBlob('the quarterly numbers'), 'name' => 'notes.txt']],
        ]]]);

        $part = $this->parts($draft)[0];

        self::assertStringStartsWith('var/attachments/', (string) $part->storagePath);
        self::assertSame(
            'the quarterly numbers',
            file_get_contents($this->projectDir.'/'.$part->storagePath),
        );
    }

    /**
     * The bug's other half: the subject in the same patch DID apply while the
     * attachment vanished, so the two must now stand or fall together.
     */
    public function testOtherPropertiesInTheSamePatchStillApply(): void
    {
        $draft = $this->draftMessage();

        $this->handle(['update' => [(string) $draft->id => [
            'subject'     => 'Now with a file',
            'to'          => [['name' => 'Ada', 'email' => 'ada@example.test']],
            'attachments' => [['blobId' => $this->uploadedBlob('bytes'), 'name' => 'notes.txt']],
        ]]]);

        self::assertSame('Now with a file', $draft->subject);
        self::assertSame([['name' => 'Ada', 'address' => 'ada@example.test']], $draft->toAddresses);
        self::assertCount(1, $this->parts($draft));
    }

    /** A file picked without a name still has to land somewhere nameable. */
    public function testAnAttachmentWithNoNameGetsAPositionalOne(): void
    {
        $draft = $this->draftMessage();

        $this->handle(['update' => [(string) $draft->id => [
            'attachments' => [['blobId' => $this->uploadedBlob('bytes')]],
        ]]]);

        self::assertSame('attachment-0', $this->parts($draft)[0]->filename);
    }

    // ── The set is a whole value ──────────────────────────────────────────

    public function testTheSetIsReplacedWholesaleAndTheDroppedFileIsDeleted(): void
    {
        $draft = $this->draftWithAttachment('first.txt', 'one');
        $first = $this->parts($draft)[0];
        $path  = $this->projectDir.'/'.$first->storagePath;

        self::assertFileExists($path);

        $this->handle(['update' => [(string) $draft->id => [
            'attachments' => [['blobId' => $this->uploadedBlob('two'), 'name' => 'second.txt']],
        ]]]);

        $parts = $this->parts($draft);

        self::assertCount(1, $parts);
        self::assertSame('second.txt', $parts[0]->filename);
        self::assertFileDoesNotExist($path, 'the dropped attachment kept its bytes on disk');
    }

    public function testAnEmptySetRemovesEveryAttachment(): void
    {
        $draft = $this->draftWithAttachment('first.txt', 'one');

        $result = $this->handle(['update' => [(string) $draft->id => ['attachments' => []]]]);

        self::assertSame([(string) $draft->id => null], (array) $result['updated']);

        $email = $this->email($draft);

        self::assertSame([], $email['attachments']);
        self::assertFalse($email['hasAttachment']);
    }

    /**
     * An absent key is still "leave it alone" — the same rule every other draft
     * property follows, and the reason a composer can autosave a subject
     * without knowing what is attached.
     */
    public function testAPatchThatNamesNoAttachmentsLeavesThemAlone(): void
    {
        $draft = $this->draftWithAttachment('first.txt', 'one');

        $this->handle(['update' => [(string) $draft->id => ['subject' => 'Only the subject']]]);

        self::assertSame('Only the subject', $draft->subject);
        self::assertCount(1, $this->parts($draft));
        self::assertTrue((bool) $draft->hasAttachments);
    }

    // ── Keeping what is already there ─────────────────────────────────────

    /**
     * "Add a file" is expressed as "the list I was given, plus one" — so the
     * part the client re-listed has to survive as the *same* part, not be
     * re-uploaded, re-stored and handed a new id its next call would not know.
     */
    public function testAnExistingPartIsKeptByItsBlobIdWithoutBeingRewritten(): void
    {
        $draft    = $this->draftWithAttachment('first.txt', 'one');
        $existing = $this->parts($draft)[0];
        $keptId   = (int) $existing->id;
        $keptPath = (string) $existing->storagePath;

        $this->handle(['update' => [(string) $draft->id => [
            'attachments' => [
                ['blobId' => 'p-'.$keptId, 'name' => 'first.txt', 'type' => 'text/plain'],
                ['blobId' => $this->uploadedBlob('two'), 'name' => 'second.txt', 'type' => 'text/plain'],
            ],
        ]]]);

        $parts = $this->parts($draft);

        self::assertCount(2, $parts);
        self::assertSame([$keptId, $keptPath], [(int) $parts[0]->id, (string) $parts[0]->storagePath]);
        self::assertSame('second.txt', $parts[1]->filename);
        self::assertFileExists($this->projectDir.'/'.$keptPath);
    }

    /**
     * A rename is the one edit a client can make to bytes the server already
     * holds, and it reaches the wire — `filename` is what the download endpoint
     * offers and what the send path writes into the MIME part. Applying it is
     * the same rule this whole change is about: do not accept a value and drop
     * it.
     */
    public function testARenameOnAKeptPartIsApplied(): void
    {
        $draft    = $this->draftWithAttachment('first.txt', 'one');
        $existing = $this->parts($draft)[0];
        $keptPath = (string) $existing->storagePath;

        $this->handle(['update' => [(string) $draft->id => [
            'attachments' => [['blobId' => 'p-'.$existing->id, 'name' => 'renamed.txt']],
        ]]]);

        $part = $this->parts($draft)[0];

        self::assertSame('renamed.txt', $part->filename);
        self::assertSame($keptPath, (string) $part->storagePath, 'the bytes were rewritten for a rename');
    }

    /** An omitted name on a re-listed part is "leave it", not "rename it to nothing". */
    public function testAKeptPartListedByBlobIdAloneKeepsItsName(): void
    {
        $draft    = $this->draftWithAttachment('first.txt', 'one');
        $existing = $this->parts($draft)[0];

        $this->handle(['update' => [(string) $draft->id => [
            'attachments' => [['blobId' => 'p-'.$existing->id]],
        ]]]);

        self::assertSame('first.txt', $this->parts($draft)[0]->filename);
    }

    /**
     * A `p-` blob from a *different* message is not a part of this draft, so it
     * is copied rather than kept — which is how a client forwards an attachment
     * without downloading and re-uploading it.
     */
    public function testAPartOfAnotherMessageIsCopiedIntoTheDraft(): void
    {
        $source = $this->draftWithAttachment('report.pdf', 'the report');
        $part   = $this->parts($source)[0];
        $draft  = $this->draftMessage();

        $this->handle(['update' => [(string) $draft->id => [
            'attachments' => [['blobId' => 'p-'.$part->id, 'name' => 'report.pdf']],
        ]]]);

        $copied = $this->parts($draft);

        self::assertCount(1, $copied);
        self::assertNotSame((int) $part->id, (int) $copied[0]->id);
        self::assertSame('the report', file_get_contents($this->projectDir.'/'.$copied[0]->storagePath));
        self::assertCount(1, $this->parts($source), 'the source message lost its attachment');
    }

    // ── Refusals ──────────────────────────────────────────────────────────

    /**
     * The behaviour this change replaces: an unresolvable blobId used to be
     * answered with `updated` and silence. It is now `notUpdated`, which is what
     * a client can act on.
     */
    public function testAnUnknownBlobIdIsRefused(): void
    {
        $draft = $this->draftMessage();

        $result = $this->handle(['update' => [(string) $draft->id => [
            'attachments' => [['blobId' => 'u-999999', 'name' => 'notes.txt']],
        ]]]);

        $error = ((array) $result['notUpdated'])[(string) $draft->id];

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('blobId', $error['description']);
        self::assertSame([], (array) $result['updated']);
    }

    /**
     * And it changes nothing at all — not the attachment it would have replaced,
     * not the subject in the same patch. The plan is resolved before a byte is
     * written precisely so a refusal is a no-op.
     */
    public function testARefusedUpdateLeavesTheDraftExactlyAsItWas(): void
    {
        $draft = $this->draftWithAttachment('first.txt', 'one');
        $draft->subject = 'Untouched';
        $this->em->flush();

        $this->handle(['update' => [(string) $draft->id => [
            'subject'     => 'Should not stick',
            'attachments' => [
                ['blobId' => $this->uploadedBlob('two'), 'name' => 'second.txt'],
                ['blobId' => 'u-999999', 'name' => 'nope.txt'],
            ],
        ]]]);

        self::assertSame('Untouched', $draft->subject);

        $parts = $this->parts($draft);

        self::assertCount(1, $parts);
        self::assertSame('first.txt', $parts[0]->filename);
    }

    /**
     * BlobResolver filters every lookup by account, so a blobId belonging to
     * another account of the same user is as unresolvable as one that never
     * existed — and is refused in the same words, since saying which is which
     * would tell a caller whose ids are real.
     */
    public function testABlobBelongingToAnotherAccountIsRefused(): void
    {
        $draft = $this->draftMessage();
        $other = $this->uploadedBlob('someone else\'s file', account: $this->secondAccount());

        $result = $this->handle(['update' => [(string) $draft->id => [
            'attachments' => [['blobId' => $other, 'name' => 'notes.txt']],
        ]]]);

        self::assertSame('invalidProperties', ((array) $result['notUpdated'])[(string) $draft->id]['type']);
        self::assertCount(0, $this->parts($draft));
    }

    public function testAnAttachmentEntryWithoutABlobIdIsRefused(): void
    {
        $draft = $this->draftMessage();

        $result = $this->handle(['update' => [(string) $draft->id => [
            'attachments' => [['name' => 'notes.txt']],
        ]]]);

        self::assertSame('invalidProperties', ((array) $result['notUpdated'])[(string) $draft->id]['type']);
    }

    /** Not an array of body parts at all — named as such rather than ignored. */
    public function testANonArrayAttachmentsValueIsRefused(): void
    {
        $draft = $this->draftMessage();

        $result = $this->handle(['update' => [(string) $draft->id => ['attachments' => 'notes.txt']]]);

        $error = ((array) $result['notUpdated'])[(string) $draft->id];

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('EmailBodyPart', $error['description']);
    }

    /**
     * Content is editable on a draft and on nothing else. A received message's
     * attachments are a record of what arrived, and a client able to rewrite
     * them could plant a file in mail somebody else sent.
     */
    public function testAttachmentsCannotBeChangedOnAReceivedMessage(): void
    {
        $message = $this->receivedMessage();

        $result = $this->handle(['update' => [(string) $message->id => [
            'attachments' => [['blobId' => $this->uploadedBlob('bytes'), 'name' => 'planted.txt']],
        ]]]);

        self::assertSame('invalidPatch', ((array) $result['notUpdated'])[(string) $message->id]['type']);
        self::assertCount(0, $this->parts($message));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function handle(array $arguments): array
    {
        return $this->set->handle($arguments + ['accountId' => $this->accountId()], $this->context());
    }

    /**
     * The Email as a client sees it, which is where "the attachment is gone"
     * was visible and the entity was not.
     *
     * @return array<string,mixed>
     */
    private function email(Message $message): array
    {
        $result = $this->get->handle(
            ['accountId' => $this->accountId(), 'ids' => [(string) $message->id]],
            $this->context(),
        );

        self::assertCount(1, $result['list']);

        return $result['list'][0];
    }

    /** @return list<MessagePart> the draft's parts, in the order they were added */
    private function parts(Message $message): array
    {
        return array_values($message->messageParts->toArray());
    }

    /**
     * A draft that already carries a file, built through the same update this
     * suite is about — there is no other way for a JMAP client to get one, and
     * seeding a part by hand would test a shape the writer never produces.
     */
    private function draftWithAttachment(string $name, string $content): Message
    {
        $draft = $this->draftMessage();

        $result = $this->handle(['update' => [(string) $draft->id => [
            'attachments' => [['blobId' => $this->uploadedBlob($content), 'name' => $name, 'type' => 'text/plain']],
        ]]]);

        self::assertArrayHasKey((string) $draft->id, (array) $result['updated'], json_encode($result['notUpdated']));

        return $draft;
    }

    /** Stages bytes the way POST /jmap/upload does, and returns the blobId. */
    private function uploadedBlob(string $content, ?Account $account = null): string
    {
        $account ??= $this->account;

        $this->bucketIds[] = (int) $account->id;

        $blob = new UploadedBlob(
            $account,
            $this->uploads->store((int) $account->id, $content),
            'text/plain',
            strlen($content),
        );

        $this->em->persist($blob);
        $this->em->flush();

        return (string) BlobId::forUpload((int) $blob->id);
    }

    private function removeTree(string $directory): void
    {
        if (false === is_dir($directory)) {
            return;
        }

        foreach ((array) scandir($directory) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (true === is_dir($path)) {
                $this->removeTree($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
