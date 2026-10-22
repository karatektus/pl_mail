<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\SwipeAction;
use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings → Mail → Swipe actions: what each swipe direction does on a row.
 *
 * Two selects, one per direction, each saved on its own. The claims worth
 * pinning: a POST changes only the direction it names; a value that is not a
 * choice, trash among them, is refused and changes nothing, not even the valid
 * direction posted beside it; and the page offers exactly the four choices.
 *
 * Every refusal has its companion in the same test: the same request with a
 * valid value changes the stored choice. An empty answer alone cannot tell a
 * refusal from a form that is broken.
 */
final class SwipeActionSettingTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEachDirectionIsSavedOnItsOwn(): void
    {
        $token = $this->tokenFromPage();

        $this->client->request('POST', '/settings/swipe-actions', ['_token' => $token, 'swipeRight' => 'star']);

        $user = $this->storedUser();
        self::assertSame(SwipeAction::Star, $user->swipeRightAction, 'the posted direction is saved');
        self::assertSame(SwipeAction::Archive, $user->swipeLeftAction, 'the other one keeps its default');

        $this->client->request('POST', '/settings/swipe-actions', ['_token' => $token, 'swipeLeft' => 'none']);

        $user = $this->storedUser();
        self::assertSame(SwipeAction::None, $user->swipeLeftAction);
        self::assertSame(SwipeAction::Star, $user->swipeRightAction, 'the earlier choice is still there');
    }

    public function testAnUnknownValueIsRefusedAndTheStoredChoiceIsKept(): void
    {
        $this->storeChoices(SwipeAction::Star, SwipeAction::Archive);
        $token = $this->tokenFromPage();

        $this->client->request('POST', '/settings/swipe-actions', ['_token' => $token, 'swipeLeft' => 'trash']);

        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'trash is not a choice');
        self::assertSame(SwipeAction::Archive, $this->storedUser()->swipeLeftAction, 'the stored left choice is unchanged');

        $this->client->request('POST', '/settings/swipe-actions', ['_token' => $token, 'swipeLeft' => 'star']);

        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'presence companion: a valid value is accepted');
        self::assertSame(SwipeAction::Star, $this->storedUser()->swipeLeftAction);
    }

    public function testAnUnknownValueBesideAValidOneRefusesTheWholePost(): void
    {
        $this->storeChoices(SwipeAction::Star, SwipeAction::Archive);
        $token = $this->tokenFromPage();

        $this->client->request('POST', '/settings/swipe-actions', [
            '_token'     => $token,
            'swipeRight' => 'archive',
            'swipeLeft'  => 'trash',
        ]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $user = $this->storedUser();
        self::assertSame(SwipeAction::Star, $user->swipeRightAction, 'the valid right value was not applied either');
        self::assertSame(SwipeAction::Archive, $user->swipeLeftAction);
    }

    public function testAPostThatNamesNoDirectionChangesNothing(): void
    {
        $this->storeChoices(SwipeAction::Star, SwipeAction::None);
        $token = $this->tokenFromPage();

        $this->client->request('POST', '/settings/swipe-actions', ['_token' => $token]);

        $user = $this->storedUser();
        self::assertSame(SwipeAction::Star, $user->swipeRightAction);
        self::assertSame(SwipeAction::None, $user->swipeLeftAction);

        // Presence companion: the same route does store a posted direction.
        $this->client->request('POST', '/settings/swipe-actions', ['_token' => $token, 'swipeRight' => 'archive']);

        self::assertSame(SwipeAction::Archive, $this->storedUser()->swipeRightAction);
    }

    public function testASaveWithABadTokenChangesNothing(): void
    {
        $this->storeChoices(SwipeAction::Star, SwipeAction::Archive);

        $this->client->request('POST', '/settings/swipe-actions', ['_token' => 'not-the-token', 'swipeRight' => 'none']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(SwipeAction::Star, $this->storedUser()->swipeRightAction);
    }

    public function testEachSelectOffersExactlyTheFourChoicesAndNoTrash(): void
    {
        $crawler = $this->client->request('GET', '/settings?section=swipe-actions');

        self::assertResponseIsSuccessful();

        foreach (['swipeRight', 'swipeLeft'] as $name) {
            $values = $crawler->filter(sprintf('select[name="%s"] option', $name))
                ->each(static fn ($option): string => (string) $option->attr('value'));

            self::assertSame(['read_unread', 'archive', 'star', 'none'], $values, sprintf('%s offers exactly the four choices', $name));
        }
    }

    public function testEachSelectShowsTheChoiceTheUserHasMade(): void
    {
        $this->storeChoices(SwipeAction::Star, SwipeAction::None);

        $crawler = $this->client->request('GET', '/settings?section=swipe-actions');

        self::assertSame('star', $crawler->filter('select[name="swipeRight"] option[selected]')->attr('value'));
        self::assertSame('none', $crawler->filter('select[name="swipeLeft"] option[selected]')->attr('value'));
    }

    public function testTheNavigationListsSwipeActionsUnderMail(): void
    {
        $crawler = $this->client->request('GET', '/settings?section=swipe-actions');

        self::assertSame(
            1,
            $crawler->filter('a[href*="section=swipe-actions"][aria-current="page"]')->count(),
            'Swipe actions is a section of its own, and is the one marked as current',
        );
    }

    private function tokenFromPage(): string
    {
        $crawler = $this->client->request('GET', '/settings?section=swipe-actions');

        return (string) $crawler->filter('form[action="/settings/swipe-actions"] input[name="_token"]')->first()->attr('value');
    }

    private function storeChoices(SwipeAction $right, SwipeAction $left): void
    {
        $user = $this->em->find(User::class, $this->user->id);
        $user->swipeRightAction = $right;
        $user->swipeLeftAction  = $left;
        $this->em->flush();
        $this->em->clear();

        // The firewall holds a refreshed copy of the user per request; log in
        // again so the next request reads the stored values.
        $this->client->loginUser($this->em->find(User::class, $this->user->id));
    }

    private function storedUser(): User
    {
        $this->em->clear();

        return $this->em->find(User::class, $this->user->id);
    }
}
