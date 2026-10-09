<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;
use App\Service\Mail\MessageRender;
use App\Service\Mail\MessageRenderer;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Mail you sent shows its pictures without asking (#37), and only that mail.
 *
 * The block on remote images keeps a stranger from learning their message was
 * opened. Asking it of your own mail is asking whether you trust yourself. The
 * part worth pinning is what counts as "your own": the Sent label, which only
 * this account's own session can put on a message — never the From line, which
 * a forged mail in the inbox fills in with your address as easily as any other.
 */
final class OwnMailImagesTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private const string BODY = '<p>Hallo</p><img src="https://cdn.example.test/banner.png" alt="">';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAMessageInSentShowsItsImages(): void
    {
        $render = $this->render($this->message(LabelRole::Sent));

        self::assertTrue($render->imagesAllowed);
        self::assertSame(0, $render->content->blocked);
        self::assertFalse($render->senderTrusted, 'no allowlist entry was involved, so no "stop trusting" bar');
    }

    public function testAnInboxMessageThatOnlyClaimsToBeFromTheReaderStaysBlocked(): void
    {
        $message              = $this->message(LabelRole::Inbox);
        $message->fromAddress = $this->account->email;

        $render = $this->render($message);

        self::assertFalse($render->imagesAllowed);
        self::assertSame(1, $render->content->blocked);
    }

    public function testSpamStillWins(): void
    {
        $message = $this->message(LabelRole::Sent);
        $message->addLabel($this->seedLabel('Spam', LabelRole::Spam));

        self::assertFalse($this->render($message)->imagesAllowed);
    }

    private function render(Message $message): MessageRender
    {
        return static::getContainer()->get(MessageRenderer::class)->render($message);
    }

    private function message(LabelRole $role): Message
    {
        /** @var Message $message */
        $message = $this->thread('Mit Bild')->messages->first();

        $message->bodyHtmlSafe = self::BODY;
        $message->addLabel(LabelRole::Inbox === $role ? $this->inbox : $this->seedLabel($role->name, $role));

        return $message;
    }
}
