<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Crypto\ContactKeyRepository;
use App\Service\Mail\RawMessageResolver;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Crypto\Gpg;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * An attached public key that is the sender's own is not an attachment
 * (phase 6g of #28, #28 comment 6216816288): the reading pane does not list
 * it, the crypto banner says "The sender's key is attached" with its
 * fingerprint, and offers to keep it while plMail does not hold it — for a
 * message that arrived before this phase too, which is looked at the first
 * time it is shown. A colleague's key stays a normal attachment.
 *
 * Rendered through the real controller (MailController::message(), the
 * fragment the reading pane fetches), over a stored raw source.
 */
final class AttachedSenderKeyTest extends WebTestCase
{
    private const string SENDER = 'sender@attached-key.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->user    = MailFixtures::user($this->em, 'attached-key');
        $this->account = MailFixtures::account($this->em, $this->user);
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    /**
     * A message from before this phase, its key part never looked at: the
     * first render looks, does not list the key, says it in the banner, and
     * the message stops counting as carrying an attachment.
     */
    public function testTheSendersKeyIsSaidInTheBannerAndNotListed(): void
    {
        $key     = new Gpg(self::SENDER);
        $message = $this->messageWith(['key.asc' => $key->armored, 'report.pdf' => null]);

        $crawler = $this->client->request('GET', '/mail/message/' . $message->id, server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);

        self::assertResponseIsSuccessful();

        $banner = $crawler->filter('[data-testid="crypto-attached-key"]');

        self::assertCount(1, $banner);
        self::assertSame($key->fingerprint, $banner->attr('data-fingerprint'));
        self::assertSame('none', $banner->attr('data-state'));
        self::assertStringContainsString("The sender's key is attached", $crawler->filter('[data-testid="crypto-banner"]')->text());
        self::assertStringContainsString(implode(' ', str_split(strtoupper($key->fingerprint), 4)), $banner->text());
        self::assertCount(1, $banner->filter('button[value="keep"]'), 'not held yet: the banner offers to keep it');

        self::assertStringNotContainsString('key.asc', $this->attachmentList($crawler));
        self::assertStringContainsString('report.pdf', $this->attachmentList($crawler));

        $this->em->clear();
        self::assertSame($key->fingerprint, $this->em->find(MessagePart::class, $this->partId($message, 'key.asc'))?->senderKeyFingerprint);
    }

    /** Only the key: no attachment list at all, no paperclip. */
    public function testAMessageWithOnlyTheSendersKeyHasNoAttachments(): void
    {
        $message = $this->messageWith(['key.asc' => new Gpg(self::SENDER)->armored]);

        $crawler = $this->client->request('GET', '/mail/message/' . $message->id, server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);

        self::assertSame('', $this->attachmentList($crawler));

        $this->em->clear();
        self::assertFalse($this->em->find(Message::class, $message->id)?->hasAttachments);
        self::assertSame(0, $this->em->find(MessageThread::class, $message->thread?->id)?->attachmentCount);
    }

    /** A colleague's key, forwarded, is an attachment like any other. */
    public function testAColleaguesKeyStaysAnAttachment(): void
    {
        $message = $this->messageWith(['colleague.asc' => new Gpg('colleague@attached-key.test')->armored]);

        $crawler = $this->client->request('GET', '/mail/message/' . $message->id, server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);

        self::assertCount(0, $crawler->filter('[data-testid="crypto-attached-key"]'));
        self::assertStringContainsString('colleague.asc', $this->attachmentList($crawler));
    }

    /** "Keep this key" from the banner keeps it, and the banner says so. */
    public function testKeepingTheKeyFromTheBanner(): void
    {
        $key     = new Gpg(self::SENDER);
        $message = $this->messageWith(['key.asc' => $key->armored]);

        $crawler = $this->client->request('GET', '/mail/message/' . $message->id, server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        $form    = $crawler->filter('[data-testid="crypto-attached-key"] form');

        $this->client->request('POST', (string) $form->attr('action'), [
            '_token'      => $form->filter('input[name="_token"]')->attr('value'),
            'fingerprint' => $key->fingerprint,
            'action'      => 'keep',
        ], server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);

        self::assertResponseIsSuccessful();
        $stream = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Kept the sender&#039;s key.', $stream);
        self::assertStringContainsString('data-state="current"', $stream);
        self::assertSame(ContactKeyStatus::Current, static::getContainer()->get(ContactKeyRepository::class)->findCurrent($this->user, self::SENDER)?->status);
    }

    /**
     * A key inside an encrypted message is posted by the browser, and held to
     * the rule the server's own collection is: only for the From address.
     */
    public function testAKeyPostedByTheBrowserIsKeptOnlyForTheSender(): void
    {
        $message   = $this->messageWith(['key.asc' => new Gpg(self::SENDER)->armored]);
        $colleague = new Gpg('colleague@attached-key.test');

        $crawler = $this->client->request('GET', '/mail/message/' . $message->id, server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        $token   = $crawler->filter('[data-testid="crypto-attached-key"] input[name="_token"]')->attr('value');

        $this->client->request('POST', sprintf('/contact-keys/message/%d/attached-key', $message->id), [
            '_token' => $token,
            'key'    => $colleague->armored,
            'action' => 'keep',
        ], server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['ok']);
        self::assertSame([], static::getContainer()->get(ContactKeyRepository::class)->findForUser($this->user));
    }

    public function testTheBannerActionNeedsTheMessagesToken(): void
    {
        $message = $this->messageWith(['key.asc' => new Gpg(self::SENDER)->armored]);

        $this->client->request('POST', sprintf('/contact-keys/message/%d/attached-key', $message->id), ['_token' => 'forged', 'action' => 'keep']);

        self::assertResponseStatusCodeSame(403);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * A message from SENDER with these attachments, stored and raw, the way
     * the syncer leaves it — an attachment counted, nothing looked at yet.
     *
     * @param array<string, ?string> $files file name => armored key, or null for a PDF
     */
    private function messageWith(array $files): Message
    {
        $message                 = MailFixtures::message($this->em, $this->account, 'With a key');
        $message->fromAddress    = self::SENDER;
        $message->hasAttachments = true;

        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'With a key';
        $thread->normalizedSubject = 'with a key';
        $thread->lastMessageAt     = new DateTimeImmutable();
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->attachmentCount   = 1;
        $thread->messageCount      = 1;
        $this->em->persist($thread);
        $thread->addMessage($message);

        $raw = 'From: ' . self::SENDER . "\r\nSubject: With a key\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"M\"\r\n\r\n"
            . "--M\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHere is my key.\r\n";

        foreach ($files as $name => $armored) {
            $part              = new MessagePart();
            $part->contentType = null === $armored ? 'application/pdf' : 'application/pgp-keys';
            $part->filename    = $name;
            $part->disposition = 'attachment';
            $part->size        = 100;
            $message->addMessagePart($part);
            $this->em->persist($part);

            $raw .= null === $armored
                ? "--M\r\nContent-Type: application/pdf; name=\"{$name}\"\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0xLjQK\r\n"
                : "--M\r\nContent-Type: application/pgp-keys; name=\"{$name}\"\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\n\r\n" . str_replace("\n", "\r\n", trim($armored)) . "\r\n";
        }

        static::getContainer()->get(RawMessageResolver::class)->store($message, $raw . "--M--\r\n");
        $this->em->flush();

        return $message;
    }

    /** The text of the reading pane's attachment row, empty when there is none. */
    private function attachmentList(Crawler $crawler): string
    {
        $chips = $crawler->filter('a[download], [data-ui--modal-src-value*="/attachment"]');

        return trim(implode(' ', $chips->each(static fn (Crawler $chip): string => $chip->attr('download') . ' ' . $chip->attr('data-ui--modal-title-value'))));
    }

    private function partId(Message $message, string $filename): ?int
    {
        foreach ($message->messageParts as $part) {
            if ($filename === $part->filename) {
                return $part->id;
            }
        }

        return null;
    }
}
