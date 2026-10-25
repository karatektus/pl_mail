<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\PlaintextSource;
use App\Domain\Exception\DecryptedMailRefused;
use App\Domain\Helper\SealedMessageStorage;
use App\Entity\Ai\AiFeature;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Handler\ForgetDecryptedMailHandler;
use App\Infrastructure\Messaging\Message\ForgetDecryptedMailMessage;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\EmbeddingStore;
use App\Service\Crypto\DecryptedMail;
use App\Service\Crypto\DecryptedMailPolicy;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Decrypted mail kept on the server, phase 3b of issue #28: what a browser
 * hands over becomes a body the server sanitised itself and attachments it
 * stored itself, searchable like any other mail — and switching the account
 * off takes all of it back out.
 *
 * And the line the models stop at: decrypted mail is theirs to read only when
 * the account says so, and from a host outside the installation's own network
 * only with a consent given for that host.
 */
final class DecryptedMailTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $this->account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'decrypted'));
        $this->account->keepDecrypted = true;
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testADecryptedMessageBecomesASanitisedSearchableBodyWithItsAttachments(): void
    {
        $message = $this->encrypted();

        $this->decryptedMail()->accept($message, $this->mime());

        self::assertSame(PlaintextSource::Client, $message->plaintextSource);
        self::assertStringContainsString('pineapple', (string) $message->bodyText);
        self::assertStringContainsString('pineapple', (string) $message->bodyHtmlSafe);
        self::assertStringNotContainsString('<script', (string) $message->bodyHtmlSafe, 'the server sanitises what it was handed');
        self::assertSame(['invoice.pdf'], array_values(array_map(static fn ($part): ?string => $part->filename, $message->messageParts->toArray())));
        self::assertTrue($message->hasAttachments);
        self::assertNotNull(static::getContainer()->get(SealedMessageStorage::class)->read((int) $message->id, SealedMessageStorage::DECRYPTED));

        self::assertSame(
            (int) $message->id,
            (int) $this->connection->fetchOne("SELECT id FROM message WHERE id = ? AND search_vector @@ plainto_tsquery('english', 'pineapple')", [$message->id]),
            'the body is in the search index',
        );
    }

    /** RFC 9788: the outside said "...", and the subject the list shows is the real one. */
    public function testAProtectedSubjectReplacesThePlaceholder(): void
    {
        $message = $this->encrypted();
        $message->subject = '...';

        $this->decryptedMail()->accept($message, "Subject: =?UTF-8?B?" . base64_encode('Kürbis plans') . "?=\r\nContent-Type: text/plain; charset=utf-8; protected-headers=\"v1\"\r\n\r\nThe pumpkin is ready.");

        self::assertSame('Kürbis plans', $message->subject);
    }

    public function testAnAccountThatDoesNotKeepDecryptedMailIsRefused(): void
    {
        $this->account->keepDecrypted = false;
        $message = $this->encrypted();

        $this->expectException(DecryptedMailRefused::class);
        $this->decryptedMail()->accept($message, $this->mime());
    }

    public function testMailThatWasNotEncryptedIsRefused(): void
    {
        $message = MailFixtures::message($this->em, $this->account);

        $this->expectException(DecryptedMailRefused::class);
        $this->decryptedMail()->accept($message, $this->mime());
    }

    public function testAnInstallationThatForbidsItKeepsNothing(): void
    {
        $policy = new DecryptedMailPolicy(static::getContainer()->get(AiSettingsRepository::class), 'forbid');

        self::assertFalse($policy->keeps($this->account));
    }

    public function testSwitchingOffTakesTheDecryptedTextBackOut(): void
    {
        $message = $this->encrypted();
        $this->decryptedMail()->accept($message, $this->mime());
        self::assertTrue(static::getContainer()->get(EmbeddingStore::class)->store((int) $message->id, [1.0, 0.0, 0.0], 'test'));

        $id = (int) $message->id;
        $this->account->keepDecrypted = false;
        $this->em->flush();

        static::getContainer()->get(ForgetDecryptedMailHandler::class)(new ForgetDecryptedMailMessage((int) $this->account->id));

        $message = $this->em->find(Message::class, $id);

        self::assertSame(PlaintextSource::None, $message->plaintextSource);
        self::assertNull($message->bodyText);
        self::assertNull($message->bodyHtmlSafe);
        self::assertCount(0, $message->messageParts);
        self::assertNull(static::getContainer()->get(SealedMessageStorage::class)->read($id, SealedMessageStorage::DECRYPTED));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message_embedding WHERE message_id = ?', [$id]));
        self::assertFalse($this->connection->fetchOne("SELECT id FROM message WHERE id = ? AND search_vector @@ plainto_tsquery('english', 'pineapple')", [$id]));
    }

    public function testTheModelsReadDecryptedMailOnlyWhenTheAccountSaysSo(): void
    {
        $message = $this->encrypted();
        $this->decryptedMail()->accept($message, $this->mime());
        $this->modelHost('http://127.0.0.1:11434');

        self::assertFalse($this->policy()->allowsAi($message, AiFeature::Categorise), 'off by default');

        $this->account->keepDecryptedAi = true;

        self::assertTrue($this->policy()->allowsAi($message, AiFeature::Categorise), 'a host on the installation\'s own network');
        self::assertTrue($this->policy()->allowsAi(MailFixtures::message($this->em, $this->account), AiFeature::Categorise), 'mail the server could always read is not affected');
    }

    public function testAModelHostOutsideTheNetworkNeedsAConsentForThatHost(): void
    {
        $message = $this->encrypted();
        $this->decryptedMail()->accept($message, $this->mime());
        $this->account->keepDecryptedAi = true;
        $this->modelHost('https://models.invalid');

        self::assertFalse($this->policy()->allowsAi($message, AiFeature::Summary), 'no consent');

        $this->account->setSetting(Account::SETTING_KEEP_DECRYPTED_AI_HOST, 'models.invalid');
        self::assertTrue($this->policy()->allowsAi($message, AiFeature::Summary), 'consent for this host');

        $this->modelHost('https://other-models.invalid');
        self::assertFalse($this->policy()->allowsAi($message, AiFeature::Summary), 'a different host is one nobody agreed to');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function encrypted(): Message
    {
        $message = MailFixtures::message($this->em, $this->account, 'Sealed');
        $message->crypto   = MessageCrypto::PgpEncrypted;
        $message->bodyText = '';
        $message->bodyHtml = '';
        $this->em->flush();

        return $message;
    }

    private function mime(): string
    {
        return "Content-Type: multipart/mixed; boundary=\"m\"\r\n\r\n"
            . "--m\r\nContent-Type: multipart/alternative; boundary=\"a\"\r\n\r\n"
            . "--a\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nThe pineapple arrives Tuesday.\r\n"
            . "--a\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>The <b>pineapple</b> arrives Tuesday.</p><script>alert(1)</script>\r\n"
            . "--a--\r\n"
            . "--m\r\nContent-Type: application/pdf; name=\"invoice.pdf\"\r\nContent-Disposition: attachment; filename=\"invoice.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . base64_encode('%PDF-1.4 not really') . "\r\n"
            . "--m--\r\n";
    }

    private function modelHost(string $baseUrl): void
    {
        $settings = static::getContainer()->get(AiSettingsRepository::class)->current();

        if (null === $settings) {
            $settings = new AiSettings();
            $this->em->persist($settings);
        }

        $settings->baseUrl = $baseUrl;
        $this->em->flush();
    }

    private function decryptedMail(): DecryptedMail
    {
        return static::getContainer()->get(DecryptedMail::class);
    }

    private function policy(): DecryptedMailPolicy
    {
        return static::getContainer()->get(DecryptedMailPolicy::class);
    }
}
