<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\InboxCategory;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use App\Twig\MessageCategoryExtension;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The message panel says where a conversation IS, and that has to be able to say
 * "in a tab you made".
 *
 * Without it the panel reads the built-in column and announces "Promotions" about
 * a conversation sitting in the reader's Shipping tab — a true statement about a
 * column nobody is looking at, which is exactly the fault the panel's own
 * comments record having had twice before.
 */
final class MessageCategoryOwnTabTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private MessageCategoryExtension $extension;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->extension  = $container->get(MessageCategoryExtension::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $container->get('security.token_storage')->setToken(
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

    public function testAConversationInTheReadersOwnTabIsReportedByThatTabsName(): void
    {
        $shipping       = new InboxCategory();
        $shipping->usr  = $this->user;
        $shipping->name = 'Shipping';
        $this->em->persist($shipping);

        $thread               = $this->thread('a notice', MessageCategory::Promotions);
        $thread->userCategory = $shipping;
        $this->em->flush();

        $report = $this->extension->report($thread->messages->first());

        self::assertNotNull($report);
        self::assertSame('Shipping', $report['own']);
        self::assertSame('promotions', $report['filed'], 'the built-in category must still be reported as it is');
    }

    /**
     * The panel for a conversation in a tab of the reader's own names that tab
     * and offers no "wrong tab" report.
     *
     * The report is evidence about plMail's own rules, and a conversation in the
     * reader's tab is there because of THEIR filter or their own drag — nothing
     * of the rules' to fix. Offered anyway, "should be Promotions" would be
     * refused as "already there" (the built-in category underneath IS
     * Promotions), and a reader looking at Shipping could not even choose the
     * answer that is true. The companion proves the form is still drawn for an
     * ordinary conversation, so "never drawn" cannot pass.
     */
    public function testTheWrongTabReportIsNotOfferedForAConversationInTheReadersOwnTab(): void
    {
        $shipping       = new InboxCategory();
        $shipping->usr  = $this->user;
        $shipping->name = 'Shipping';
        $this->em->persist($shipping);

        $own               = $this->thread('a notice', MessageCategory::Promotions);
        $own->userCategory = $shipping;
        $plain             = $this->thread('a promo', MessageCategory::Promotions);
        $this->em->flush();

        // The panel draws a CSRF token, which needs a session to live in.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        $twig = self::getContainer()->get('twig');

        $ownHtml   = $twig->render('mail/_message_details.html.twig', ['message' => $own->messages->first()]);
        $plainHtml = $twig->render('mail/_message_details.html.twig', ['message' => $plain->messages->first()]);

        self::assertStringContainsString('Shipping', $ownHtml);
        self::assertStringNotContainsString('mail--category-report', $ownHtml, 'a conversation in an own tab of the reader offers a report against the rules');
        self::assertStringContainsString('mail--category-report', $plainHtml, 'the report form vanished for an ordinary conversation');
    }

    public function testAConversationInNoOwnTabOfTheirsHasNone(): void
    {
        $thread = $this->thread('a promo', MessageCategory::Promotions);

        $report = $this->extension->report($thread->messages->first());

        self::assertNotNull($report);
        self::assertNull($report['own']);
    }
}
