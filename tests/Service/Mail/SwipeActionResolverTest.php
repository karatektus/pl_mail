<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\DTO\Mail\SwipeAffordance;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\SwipeAction;
use App\Domain\Enum\Mail\SwipeDirection;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Label\LabelResolver;
use App\Service\Mail\SwipeActionResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use LogicException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

/**
 * Which swipe directions mean something on a row, and where they go.
 *
 * The rule under test is parity with the row's hover strip (D4 in the
 * touch-gesture plan): the strip is the existing answer to "what may be done to
 * this row", and a swipe that offers something the strip would not is a second
 * opinion. So the strip is rendered for every cell below, and each swipe
 * direction must match it exactly: offered where the strip offers the same
 * route, and inert where the strip hides it. Under the defaults that means
 * Archive and Mark read are offered only in ordinary mail (not in Archive, the
 * bin or spam, and not on a draft), and that a row in any of those places has
 * both directions inert.
 *
 * A row is a thread or a bare message, and each is tested as a draft too,
 * because the draft flag changes the strip on its own.
 */
final class SwipeActionResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Environment $twig;
    private SwipeActionResolver $resolver;
    private LabelResolver $labels;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->twig       = $container->get(Environment::class);
        $this->resolver   = $container->get(SwipeActionResolver::class);
        $this->labels     = $container->get(LabelResolver::class);

        // The row renders CSRF-bearing controls, which read the session off the
        // request stack, so a real request has to be there (see ThreadRowReadToggleIconTest).
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);

        $this->connection->beginTransaction();
        $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnUnreadThreadInTheInboxOffersMarkReadRightAndArchiveLeft(): void
    {
        $thread = $this->seedThread(unreadCount: 1);

        $right = $this->resolver->forDirection(SwipeDirection::Right, $thread, draft: false);
        $left  = $this->resolver->forDirection(SwipeDirection::Left, $thread, draft: false);

        self::assertInstanceOf(SwipeAffordance::class, $right);
        self::assertSame(SwipeAction::ReadUnread, $right->action);
        self::assertSame('/status/thread/' . $thread->id . '/read', $right->url);
        self::assertSame(['read' => true], $right->body, 'pressing it reads the unread row');
        self::assertSame('thread_row.actions.mark_read', $right->label);
        self::assertSame('fa-solid fa-envelope-open', $right->icon);

        self::assertInstanceOf(SwipeAffordance::class, $left);
        self::assertSame(SwipeAction::Archive, $left->action);
        self::assertSame('/status/thread/' . $thread->id . '/archive', $left->url);
        self::assertSame([], $left->body);
        self::assertSame('thread_row.actions.archive', $left->label);
    }

    public function testAReadThreadOffersMarkUnreadWithTheReadBodyFalse(): void
    {
        $right = $this->resolver->forDirection(SwipeDirection::Right, $this->seedThread(unreadCount: 0), draft: false);

        self::assertInstanceOf(SwipeAffordance::class, $right);
        self::assertSame(['read' => false], $right->body, 'pressing it marks the row unread');
        self::assertSame('thread_row.actions.mark_unread', $right->label);
        self::assertSame('fa-solid fa-envelope', $right->icon);
    }

    public function testABareMessageRowUsesTheMessageRoutes(): void
    {
        $message = $this->seedMessage(unseen: true);

        $right = $this->resolver->forDirection(SwipeDirection::Right, $message, draft: false);
        $left  = $this->resolver->forDirection(SwipeDirection::Left, $message, draft: false);

        self::assertInstanceOf(SwipeAffordance::class, $right);
        self::assertSame('/status/message/' . $message->id . '/read', $right->url);
        self::assertSame(['read' => true], $right->body);
        self::assertInstanceOf(SwipeAffordance::class, $left);
        self::assertSame('/status/message/' . $message->id . '/archive', $left->url);
    }

    /**
     * Move posts the row's id to the bulk move route, which looks up thread ids, so
     * a bare message offers no Move. The thread beside it offers Move, so an absent
     * entry is the rule and not a sheet that offers nothing.
     */
    public function testMoveIsOfferedOnAThreadButNotOnABareMessage(): void
    {
        $thread  = $this->seedThread(unreadCount: 1);
        $message = $this->seedMessage(unseen: true);

        self::assertArrayHasKey('move', $this->resolver->sheet($thread, false), 'a thread offers Move');
        self::assertArrayNotHasKey('move', $this->resolver->sheet($message, false), 'a bare message offers no Move');
    }

    /**
     * Each direction does what the signed-in user's own setting says, and the two
     * do not affect each other. The expected actions are the literal choices made
     * here: left = star, right = none.
     */
    public function testEachDirectionFollowsTheSignedInUsersOwnSetting(): void
    {
        $user                   = new User();
        $user->swipeLeftAction  = SwipeAction::Star;
        $user->swipeRightAction = SwipeAction::None;
        $this->signIn($user);

        $thread = $this->seedThread(unreadCount: 1);

        $left = $this->resolver->forDirection(SwipeDirection::Left, $thread, draft: false);

        self::assertInstanceOf(SwipeAffordance::class, $left, 'presence companion: the left direction is offered');
        self::assertSame(SwipeAction::Star, $left->action);
        self::assertSame('/status/thread/' . $thread->id . '/star', $left->url);
        self::assertSame('thread_row.star', $left->label);
        self::assertNull($this->resolver->forDirection(SwipeDirection::Right, $thread, draft: false), 'right is set to none, so inert');
    }

    /**
     * With no one signed in there is no setting to read, so each direction keeps
     * its own default: right = read/unread, left = archive.
     */
    public function testWithNobodySignedInEachDirectionKeepsItsDefault(): void
    {
        $thread = $this->seedThread(unreadCount: 1);

        $right = $this->resolver->forDirection(SwipeDirection::Right, $thread, draft: false);
        $left  = $this->resolver->forDirection(SwipeDirection::Left, $thread, draft: false);

        self::assertInstanceOf(SwipeAffordance::class, $right);
        self::assertSame(SwipeAction::ReadUnread, $right->action);
        self::assertInstanceOf(SwipeAffordance::class, $left);
        self::assertSame(SwipeAction::Archive, $left->action);
    }

    /**
     * Both directions may be set to the same action (ASSUMPTION in the plan; the
     * owner may forbid it). Each offers it on its own, with its own route.
     */
    public function testTheSameActionOnBothDirectionsResolvesToItOnBoth(): void
    {
        $user                   = new User();
        $user->swipeRightAction = SwipeAction::Archive;
        $user->swipeLeftAction  = SwipeAction::Archive;
        $this->signIn($user);

        $thread = $this->seedThread(unreadCount: 1);

        $right = $this->resolver->forDirection(SwipeDirection::Right, $thread, draft: false);
        $left  = $this->resolver->forDirection(SwipeDirection::Left, $thread, draft: false);

        self::assertInstanceOf(SwipeAffordance::class, $right);
        self::assertSame('/status/thread/' . $thread->id . '/archive', $right->url);
        self::assertInstanceOf(SwipeAffordance::class, $left);
        self::assertSame('/status/thread/' . $thread->id . '/archive', $left->url);
    }

    /**
     * A setting does not lift the strip's rules: archive chosen for the right is
     * offered in the Inbox and inert in the archive, as the default was.
     */
    public function testAChosenArchiveOnTheRightIsOfferedOnlyInOrdinaryMail(): void
    {
        $user                   = new User();
        $user->swipeRightAction = SwipeAction::Archive;
        $this->signIn($user);

        $thread = $this->seedThread(unreadCount: 1);

        $inbox = $this->resolver->forDirection(SwipeDirection::Right, $thread, draft: false);

        self::assertInstanceOf(SwipeAffordance::class, $inbox, 'presence companion: in the Inbox');
        self::assertSame(SwipeAction::Archive, $inbox->action, 'the chosen action, not the default read/unread');

        $this->placeIn($thread, LabelRole::Archive);

        self::assertNull($this->resolver->forDirection(SwipeDirection::Right, $thread, draft: false));
    }

    /**
     * A stored "trash" never reaches the resolver as an action: the direction falls
     * back to its own default, so left archives, and right keeps its own setting.
     */
    public function testAStoredTrashFallsBackToTheDirectionsDefaultInTheResolver(): void
    {
        $user = new User();
        $user->setSetting(User::SETTING_SWIPE_LEFT_ACTION, 'trash');
        $user->setSetting(User::SETTING_SWIPE_RIGHT_ACTION, 'star');
        $this->signIn($user);

        $thread = $this->seedThread(unreadCount: 1);

        $left  = $this->resolver->forDirection(SwipeDirection::Left, $thread, draft: false);
        $right = $this->resolver->forDirection(SwipeDirection::Right, $thread, draft: false);

        self::assertInstanceOf(SwipeAffordance::class, $left);
        self::assertSame(SwipeAction::Archive, $left->action);
        self::assertInstanceOf(SwipeAffordance::class, $right, 'presence companion: the valid right value is still used');
        self::assertSame(SwipeAction::Star, $right->action);
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
    }

    /**
     * Presence companion to the inert cases: the same thread, in the Inbox, does
     * offer both. Without it, "inert everywhere" would pass for a resolver that
     * never resolves anything.
     */
    public function testTheSameThreadOutsideTheInboxOffersNothingAndInTheInboxOffersBoth(): void
    {
        $thread = $this->seedThread(unreadCount: 1);

        self::assertNotNull($this->resolver->forDirection(SwipeDirection::Left, $thread, draft: false));

        $this->placeIn($thread, LabelRole::Archive);

        self::assertNull($this->resolver->forDirection(SwipeDirection::Left, $thread, draft: false));
        self::assertNull($this->resolver->forDirection(SwipeDirection::Right, $thread, draft: false));
    }

    public function testADraftHasNeitherDirectionEvenInTheInbox(): void
    {
        $thread = $this->seedThread(unreadCount: 1);

        self::assertNull($this->resolver->forDirection(SwipeDirection::Left, $thread, draft: true));
        self::assertNull($this->resolver->forDirection(SwipeDirection::Right, $thread, draft: true));
    }

    public function testStarIsOfferedWhereverTheStripOffersIt(): void
    {
        // Star is not a default, but the resolver answers for any action, and the
        // strip's star is on every row in every placement. A star that vanished
        // from the bin would be a parity break a default never shows.
        $thread = $this->seedThread(unreadCount: 0);
        $this->placeIn($thread, LabelRole::Trash);

        $star = $this->resolver->for(SwipeAction::Star, $thread, draft: false);

        self::assertInstanceOf(SwipeAffordance::class, $star);
        self::assertSame('/status/thread/' . $thread->id . '/star', $star->url);
        self::assertSame('thread_row.star', $star->label, 'an unstarred row offers to star it');
    }

    public function testNoneIsInertEverywhere(): void
    {
        self::assertNull($this->resolver->for(SwipeAction::None, $this->seedThread(unreadCount: 1), draft: false));
    }

    /**
     * Parity with the strip (D4), for every placement and row kind: each swipe
     * direction offers exactly what the rendered strip offers for that row, by
     * route, and is inert where the strip has nothing.
     *
     * @param 'normal'|'archive'|'trash'|'spam' $placement
     * @param 'thread'|'message'                $kind
     */
    #[DataProvider('placements')]
    public function testEachDirectionMatchesTheStripItWouldDuplicate(string $placement, string $kind, bool $draft): void
    {
        $item = match ($kind) {
            'thread'  => $this->seedThread(unreadCount: 1, draftMessage: $draft),
            'message' => $this->seedMessage(unseen: true, draft: $draft),
        };

        $role = match ($placement) {
            'archive' => LabelRole::Archive,
            'trash'   => LabelRole::Trash,
            'spam'    => LabelRole::Spam,
            'normal'  => null,
        };

        if (null !== $role) {
            $this->placeIn($item, $role);
        }

        $html = $this->renderStrip($item, $kind);
        $cell = sprintf('%s %s %s', $placement, $kind, $draft ? 'draft' : 'mail');

        // What the strip offers, read from the rendered row: a route is present
        // exactly where its button is drawn.
        $stripRead    = $this->stripRoute($html, 'data-mail--message-row-read-url-param');
        $stripArchive = $this->stripRoute($html, 'data-mail--message-row-archive-url-param');
        $stripStar    = $this->stripRoute($html, 'data-mail--message-row-star-url-param');

        $read    = $this->resolver->for(SwipeAction::ReadUnread, $item, $draft);
        $archive = $this->resolver->for(SwipeAction::Archive, $item, $draft);
        $star    = $this->resolver->for(SwipeAction::Star, $item, $draft);

        self::assertSame(null !== $stripRead, null !== $read, 'mark read, ' . $cell);
        self::assertSame(null !== $stripArchive, null !== $archive, 'archive, ' . $cell);

        // Where offered, the swipe posts to the very route the strip's button posts to.
        if (null !== $read) {
            self::assertSame($stripRead, $read->url, 'mark read route, ' . $cell);
        }

        if (null !== $archive) {
            self::assertSame($stripArchive, $archive->url, 'archive route, ' . $cell);
        }

        // Star is on every row in every placement, so it is always offered, to the strip's route.
        self::assertNotNull($star, 'star is never inert, ' . $cell);
        self::assertSame($stripStar, $star->url, 'star route, ' . $cell);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function placements(): iterable
    {
        foreach (['normal', 'archive', 'trash', 'spam'] as $placement) {
            foreach (['thread', 'message'] as $kind) {
                foreach ([false, true] as $draft) {
                    yield sprintf('%s %s %s', $placement, $kind, $draft ? 'draft' : 'mail') => [$placement, $kind, $draft];
                }
            }
        }
    }

    /**
     * Presence plus the inert rule in one place, so a row in the bin with both
     * directions inert is shown next to the row in the Inbox that is not.
     */
    public function testAnArchivedTrashedOrSpammedRowHasBothDirectionsInert(): void
    {
        foreach ([LabelRole::Archive, LabelRole::Trash, LabelRole::Spam] as $role) {
            $thread = $this->seedThread(unreadCount: 1);
            $this->placeIn($thread, $role);

            self::assertNull($this->resolver->forDirection(SwipeDirection::Left, $thread, draft: false), $role->value . ' left');
            self::assertNull($this->resolver->forDirection(SwipeDirection::Right, $thread, draft: false), $role->value . ' right');
        }
    }

    /**
     * The sheet against the strip it mirrors (E1). For every placement, row kind
     * and draft flag, and with the signed-in user's delete-now switch both off and
     * on, each sheet entry must be offered exactly where the strip draws its
     * button, and to the same route. Move, labels and select have no strip button,
     * so they are checked against the rule written here, from the strip's own
     * conditions and the rule the plan sets for them.
     *
     * The row carries the same answer as JSON in its data-sheet attribute, so the
     * browser reads what this test reads.
     *
     * @param 'normal'|'archive'|'trash'|'spam' $placement
     * @param 'thread'|'message'                $kind
     */
    #[DataProvider('placements')]
    public function testEachSheetEntryIsOfferedExactlyWhereTheStripDrawsItsButton(string $placement, string $kind, bool $draft): void
    {
        foreach ([false, true] as $deletesNow) {
            // The persisted owner of the fixture account: the strip's account
            // query binds the signed-in user, which an unsaved User cannot do.
            $user                   = $this->account->usr;
            $user->deleteNowButtons = $deletesNow;
            $this->signIn($user);

            $item = match ($kind) {
                'thread'  => $this->seedThread(unreadCount: 1, draftMessage: $draft),
                'message' => $this->seedMessage(unseen: true, draft: $draft),
            };

            $role = match ($placement) {
                'archive' => LabelRole::Archive,
                'trash'   => LabelRole::Trash,
                'spam'    => LabelRole::Spam,
                'normal'  => null,
            };

            if (null !== $role) {
                $this->placeIn($item, $role);
            }

            $html  = $this->renderStrip($item, $kind);
            $sheet = $this->resolver->sheet($item, $draft);
            $cell  = sprintf('%s %s %s, delete-now %s', $placement, $kind, $draft ? 'draft' : 'mail', $deletesNow ? 'on' : 'off');

            foreach (self::STRIP_ATTRIBUTES as $entry => $attribute) {
                $expected = $this->sheetOffersByRule($entry, $placement, 'thread' === $kind, $draft, $deletesNow);
                $strip    = $this->stripRoute($html, $attribute);
                $offered  = array_key_exists($entry, $sheet);

                self::assertSame($expected, $offered, sprintf('"%s" offered by the sheet, %s', $entry, $cell));
                self::assertSame(null !== $strip, $offered, sprintf('"%s" drawn by the strip, %s', $entry, $cell));

                if ($offered && null !== $sheet[$entry]->url) {
                    self::assertSame($strip, $sheet[$entry]->url, sprintf('"%s" posts to the strip route, %s', $entry, $cell));
                }
            }

            foreach (self::CLIENT_ONLY as $entry) {
                $expected = $this->sheetOffersByRule($entry, $placement, 'thread' === $kind, $draft, $deletesNow);

                self::assertSame($expected, array_key_exists($entry, $sheet), sprintf('"%s" offered by the sheet, %s', $entry, $cell));
            }

            $json = (new Crawler($html))->filter('li[data-sheet]')->attr('data-sheet');

            self::assertSame(
                array_keys($sheet),
                array_keys(json_decode($json, true, flags: JSON_THROW_ON_ERROR)['entries']),
                sprintf('the row carries the same entries as JSON, %s', $cell),
            );
        }
    }

    /**
     * The strip attribute that proves each entry is drawn, and the entry's name
     * in the sheet. Snooze is drawn on each option of the snooze menu; the spam
     * menu's report URL sits on its wrapper.
     */
    private const array STRIP_ATTRIBUTES = [
        'read_unread' => 'data-mail--message-row-read-url-param',
        'archive'     => 'data-mail--message-row-archive-url-param',
        'star'        => 'data-mail--message-row-star-url-param',
        'trash'       => 'data-mail--message-row-trash-url-param',
        'restore'     => 'data-mail--message-row-restore-url-param',
        'purge'       => 'data-mail--message-row-purge-url-param',
        'snooze'      => 'data-mail--message-row-snooze-url-param',
        'spam'        => 'data-mail--spam-menu-report-url-value',
    ];

    /** The entries the strip has no button for: the sheet's own pickers and the checkbox. */
    private const array CLIENT_ONLY = ['move', 'labels', 'select'];

    /**
     * What the rule says, written from the row template's conditions (the strip
     * lines are named in SheetEntry and in the template), so the expectation is
     * not read back from the code under test.
     */
    private function sheetOffersByRule(string $entry, string $placement, bool $thread, bool $draft, bool $deletesNow): bool
    {
        $ordinary  = 'normal' === $placement;
        $discarded = 'trash' === $placement || 'spam' === $placement;

        return match ($entry) {
            'read_unread', 'archive', 'snooze' => false === $draft && $ordinary,
            'star'                             => true,
            'trash'                            => $ordinary,
            'restore'                          => false === $ordinary,
            'purge'                            => $discarded && $deletesNow,
            'spam'                             => $thread && false === $draft && false === $discarded,
            // Move posts the row's id to the bulk move route, which looks up thread ids, so a bare
            // message has no Move. Labels open for either kind.
            'move'                             => $thread && false === $draft,
            'labels'                           => false === $draft,
            'select'                           => true,
            default                            => throw new LogicException(sprintf('No parity rule for the sheet entry "%s".', $entry)),
        };
    }

    /** The route the strip draws for this attribute, or null when the strip draws no such button. */
    private function stripRoute(string $html, string $attribute): ?string
    {
        $found = (new Crawler($html))->filter('[' . $attribute . ']');

        return 0 === $found->count() ? null : $found->attr($attribute);
    }

    /**
     * The row as the browser gets it, for the strip the swipe must match. The
     * draft flag is the template's own: a thread with one draft message is a draft.
     */
    private function renderStrip(MessageThread|Message $item, string $kind): string
    {
        $variable = 'thread' === $kind ? 'thread' : 'message';

        return $this->twig->render('_partials/_thread_row.html.twig', [$variable => $item]);
    }

    private function placeIn(MessageThread|Message $item, LabelRole $role): void
    {
        $label = $this->labels->systemLabel($role, $this->account);

        if ($item instanceof MessageThread) {
            $item->addLabel($label);

            foreach ($item->messages as $message) {
                $message->addLabel($label);
            }
        } else {
            $item->addLabel($label);
        }

        $this->em->flush();
    }

    private function seedThread(int $unreadCount, bool $draftMessage = false): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Swipe fixture';
        $thread->normalizedSubject = 'swipe fixture';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-08-03');
        $thread->unreadCount       = $unreadCount;
        // The template's draft test is "a draft, and the only message": a
        // thread with one draft in it is a draft row, one with replies is not.
        $thread->messageCount      = 1;

        $message = $this->fixtureMessage($draftMessage);
        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return $thread;
    }

    private function seedMessage(bool $unseen, bool $draft = false): Message
    {
        $message = $this->fixtureMessage($draft);

        if (false === $unseen) {
            $message->seenAt = new DateTimeImmutable('2026-08-03');
        }

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function fixtureMessage(bool $draft): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Swipe fixture';
        $message->fromAddress    = 'sender@example.test';
        $message->fromName       = 'Sender';
        $message->toAddresses    = [['name' => 'Me', 'address' => 'me@example.test']];
        $message->bodyText       = 'Body';
        $message->receivedAt     = new DateTimeImmutable('2026-08-03');
        $message->hasAttachments = false;
        $message->flags          = $draft ? [MessageFlag::DRAFT->value] : [];

        return $message;
    }

    private function seedAccount(): void
    {
        $user           = new User();
        $user->email    = 'swipe-' . uniqid('', true) . '@example.test';
        $user->password = 'x';
        $user->roles    = ['ROLE_USER'];
        $user->nameFirst = 'Swipe';
        $user->nameLast  = 'Fixture';

        $this->em->persist($user);

        $this->account                 = new Account();
        $this->account->usr            = $user;
        $this->account->name           = 'Swipe fixture';
        $this->account->email          = 'swipe@example.test';
        $this->account->username       = uniqid('swipe-', true);
        $this->account->imapHost       = 'imap.example.test';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;

        $this->em->persist($this->account);
        $this->em->flush();
    }
}
