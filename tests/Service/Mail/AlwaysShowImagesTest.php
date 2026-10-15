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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * "Always show remote images" loads them from every sender (#124) — and stops
 * where the per-sender allowlist stops.
 *
 * Three things worth pinning: that it is off for somebody who never touched
 * it, that it does not pass itself off as an allowlist entry (which would draw
 * a "Stop trusting" line for a sender nobody trusted), and that Spam outranks
 * it the way Spam outranks a trusted sender.
 */
final class AlwaysShowImagesTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private const string BODY = '<p>Newsletter</p><img src="https://cdn.example.test/pixel.png" alt="">';

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

        // MessageRenderer asks Security for the reader, as it does on a page.
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($this->user, 'main', $this->user->getRoles()),
        );
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testImagesWaitToBeAskedForUntilTheReaderSaysOtherwise(): void
    {
        self::assertFalse($this->user->alwaysShowImages);

        $render = $this->render($this->message());

        self::assertFalse($render->imagesAllowed);
        self::assertSame(1, $render->content->blocked);
        self::assertTrue($render->offersImages);
    }

    public function testSwitchedOnEverySenderIsShownWithoutTheBar(): void
    {
        $this->user->alwaysShowImages = true;

        $render = $this->render($this->message());

        self::assertTrue($render->imagesAllowed);
        self::assertSame(0, $render->content->blocked);
        self::assertSame(1, $render->content->allowed, 'shown through the proxy, not fetched by the browser');
        self::assertFalse($render->offersImages);
        self::assertFalse($render->senderTrusted, 'nobody trusted this sender, so no "Stop trusting" line');
    }

    public function testSpamStillWins(): void
    {
        $this->user->alwaysShowImages = true;

        $message = $this->message();
        $message->addLabel($this->seedLabel('Spam', LabelRole::Spam));

        $render = $this->render($message);

        self::assertFalse($render->imagesAllowed);
        self::assertTrue($render->offersImages, 'Show images is still there for one message');
    }

    private function render(Message $message): MessageRender
    {
        return static::getContainer()->get(MessageRenderer::class)->render($message);
    }

    private function message(): Message
    {
        /** @var Message $message */
        $message = $this->thread('Mit Bild')->messages->first();

        $message->bodyHtmlSafe = self::BODY;
        $message->addLabel($this->inbox);

        return $message;
    }
}
