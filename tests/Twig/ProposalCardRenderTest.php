<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Calendar\EventProposal;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\Proposal\ProposalReader;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * The proposal card, actually rendered.
 *
 * A template test for the reason InviteCardRenderTest gives: what is left to go
 * wrong here is not in the PHP. The card reads an entity through Twig's runtime
 * name resolution, and a property renamed or turned into a method leaves every
 * one of those expressions rendering an empty string — valid Twig, valid PHP, a
 * card with a blank date and two buttons, and a green suite. `lint:twig` cannot
 * see it either; it parses.
 *
 * The assertions are about the two things a person acts on: the quoted sentence,
 * which is the only reason the guess can be judged at all, and the pair of
 * forms, which is the only way to answer it. A card that offered one button, or
 * quoted nothing, would still render.
 */
final class ProposalCardRenderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ProposalReader $reader;
    private Environment $twig;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->reader     = $container->get(ProposalReader::class);
        $this->twig       = $container->get(Environment::class);

        // The card puts a CSRF token in both forms, and the token manager reads
        // the session off the request stack — which is empty outside a real
        // request. Pushing one is what makes the template renderable here.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);

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

    public function testTheCardOffersBothAnswers(): void
    {
        $html = $this->render();

        self::assertStringContainsString('Add to calendar', $html);
        self::assertStringContainsString('Not an event', $html);

        // One form per answer: a submit button's value only travels when it is
        // the button that was pressed, so the two answers are two posts to two
        // routes rather than one form with a name on each button.
        self::assertSame(2, substr_count($html, '<form method="post"'));
        self::assertSame(1, substr_count($html, '/accept"'));
        self::assertSame(1, substr_count($html, '/dismiss"'));
    }

    /** Every answer carries a CSRF token, or the post is refused. */
    public function testEveryAnswerIsTokened(): void
    {
        self::assertSame(2, substr_count($this->render(), 'name="_token"'));
    }

    /**
     * The evidence, verbatim. This is the difference between a guess somebody
     * can judge in a second and a bare date with an Add button next to it.
     */
    public function testTheCardQuotesTheSentenceItWasReadFrom(): void
    {
        self::assertStringContainsString(
            'Termin wie vereinbart: 04.08.2026 um 14 Uhr',
            $this->render(),
        );
    }

    public function testTheCardNamesTheAppointmentAndItsHours(): void
    {
        $html = $this->render();

        self::assertStringContainsString('Probearbeit', $html);
        self::assertStringContainsString('Tue, 4 Aug 2026', $html);
        self::assertStringContainsString('2:00 pm', $html);
        self::assertStringContainsString('4:00 pm', $html);
    }

    /** Nothing has been added yet, and the card has to say so. */
    public function testTheCardSaysNothingHasBeenAddedYet(): void
    {
        self::assertStringContainsString('nothing has been added', $this->render());
    }

    /**
     * The card is drawn from whatever message a template was handed, so the
     * account's owner is the authorisation and the route is not.
     */
    public function testAProposalOnSomebodyElsesMailIsNotReadable(): void
    {
        $stranger            = new User();
        $stranger->email     = 'stranger-' . uniqid('', true) . '@example.test';
        $stranger->nameFirst = 'Stranger';
        $stranger->nameLast  = 'Fixture';
        $stranger->roles     = ['ROLE_USER'];
        $stranger->password  = 'x';
        $this->em->persist($stranger);
        $this->em->flush();

        $proposal = $this->proposal();

        self::assertNull($this->reader->forMessage($proposal->message, $stranger));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function render(): string
    {
        return $this->twig->render(
            'calendar/_proposal_card.html.twig',
            ['proposal' => $this->proposal()],
        );
    }

    private function proposal(): EventProposal
    {
        $utc = new DateTimeZone('UTC');

        $message                 = new Message();
        $message->account        = $this->account;
        $message->messageId      = uniqid('card-', true) . '@example.test';
        $message->subject        = 'Probearbeit';
        $message->bodyText       = 'Termin wie vereinbart: 04.08.2026 um 14 Uhr';
        $message->fromAddress    = 'someone@example.org';
        $message->toAddresses    = ['me@example.test'];
        $message->category       = MessageCategory::Primary;
        $message->hasAttachments = false;
        $message->receivedAt     = new DateTimeImmutable('2026-07-31 09:00', $utc);
        $this->em->persist($message);

        $proposal                 = new EventProposal();
        $proposal->usr            = $this->user;
        $proposal->message        = $message;
        $proposal->title          = 'Probearbeit';
        $proposal->startsAt       = new DateTimeImmutable('2026-08-04 14:00', $utc);
        $proposal->endsAt         = new DateTimeImmutable('2026-08-04 16:00', $utc);
        $proposal->timeZone       = 'UTC';
        $proposal->confidence     = 70;
        $proposal->sourceSentence = 'Termin wie vereinbart: 04.08.2026 um 14 Uhr';
        $proposal->dedupKeyHash   = str_repeat('a', 64);
        $proposal->detector       = 'prose';
        $this->em->persist($proposal);

        $this->em->flush();
        $this->reader->reset();

        return $proposal;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'card-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Card';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'me@example.test';
        $account->username       = 'me@example.test';
        $account->name           = 'Card Fixture';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        $this->em->flush();

        $this->user    = $user;
        $this->account = $account;
    }
}
