<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\Mail\InboxCategory;
use App\Entity\User\User;
use App\Repository\Rule\MailRuleRepository;
use App\Service\Rule\RuleActionExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The "File in category" action, from the browser's end.
 *
 * Three promises, and the last is the one that makes the feature safe to ship
 * to people who never asked for it:
 *
 *  - A saved filter keeps the category it files into, and the editor publishes
 *    it back — an action that is stored but not published resets itself the next
 *    time the filter is opened.
 *  - Another person's category cannot be stored, whatever the posted id says.
 *  - Somebody with no categories of their own sees the editor EXACTLY as it was
 *    before this action existed: the action is not offered, because there is
 *    nothing for it to file into.
 */
final class RuleFileInCategoryTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MailRuleRepository $rules;
    private User $user;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testASavedFilterKeepsItsCategoryAndTheEditorPublishesItBack(): void
    {
        $client   = $this->signIn();
        $shipping = $this->category($this->user, 'Shipping');

        $client->request('POST', '/settings/filters/save', [
            '_token'     => $this->saveToken($client),
            'name'       => 'Carriers',
            'conditions' => json_encode(['fromDomain' => 'mondialrelay.fr'], JSON_THROW_ON_ERROR),
            'actions'    => json_encode([['type' => 'fileInCategory', 'categoryId' => $shipping->id]], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();

        $rules = $this->rules->findForUserOrdered($this->user);

        self::assertCount(1, $rules);
        self::assertSame([['type' => 'fileInCategory', 'categoryId' => $shipping->id]], $rules[0]->actions);

        $crawler = $client->request('GET', '/settings/filters/' . $rules[0]->id . '/edit');

        self::assertResponseIsSuccessful();

        $published = json_decode(
            (string) $crawler->filter('[data-rules--rule-builder-actions-value]')->first()->attr('data-rules--rule-builder-actions-value'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame($shipping->id, $published[0]['categoryId'] ?? null);

        // And the editor knows which categories there are to choose from.
        $categories = json_decode(
            (string) $crawler->filter('[data-rules--rule-builder-categories-value]')->first()->attr('data-rules--rule-builder-categories-value'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame([['id' => $shipping->id, 'name' => 'Shipping']], $categories);
    }

    public function testSomebodyElsesCategoryCannotBeStored(): void
    {
        $client   = $this->signIn();
        $strangers = $this->category($this->seedUser('stranger'), 'Not yours');
        // One of the reader's own, so the editor offers the action at all and a
        // refusal cannot be mistaken for "the action does not exist".
        $this->category($this->user, 'Shipping');

        $client->request('POST', '/settings/filters/save', [
            '_token'     => $this->saveToken($client),
            'name'       => 'Borrowed',
            'conditions' => '{}',
            'actions'    => json_encode([['type' => 'fileInCategory', 'categoryId' => $strangers->id]], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->rules->findForUserOrdered($this->user), 'a filter filing into another person\'s category was stored');
    }

    public function testTheActionIsOfferedOnlyToSomebodyWhoHasACategory(): void
    {
        $client = $this->signIn();

        $without = $this->offeredActionTypes($client);

        self::assertNotContains('fileInCategory', $without, 'the action is offered with no category to file into');
        self::assertSame(
            array_values(array_diff(RuleActionExecutor::TYPES, ['fileInCategory'])),
            $without,
            'the editor changed for somebody who has no categories',
        );

        $this->category($this->user, 'Shipping');

        self::assertContains('fileInCategory', $this->offeredActionTypes($client));
    }

    public function testTheSentenceUnderTheEditorNamesTheCategory(): void
    {
        $client   = $this->signIn();
        $shipping = $this->category($this->user, 'Shipping');
        $this->saveToken($client);

        $client->jsonRequest('POST', '/settings/filters/preview', [
            'conditions' => ['fromDomain' => 'mondialrelay.fr'],
            'actions'    => [['type' => 'fileInCategory', 'categoryId' => $shipping->id]],
        ]);

        self::assertResponseIsSuccessful();

        $answer = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($answer['ok']);
        self::assertStringContainsString('Shipping', (string) $answer['description']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function offeredActionTypes(KernelBrowser $client): array
    {
        $crawler = $client->request('GET', '/settings/filters/new');

        $i18n = json_decode(
            (string) $crawler->filter('[data-rules--rule-builder-i18n-value]')->first()->attr('data-rules--rule-builder-i18n-value'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $i18n['actionTypes'];
    }

    private function saveToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/settings/filters/new');

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    private function category(User $owner, string $name): InboxCategory
    {
        $category       = new InboxCategory();
        $category->usr  = $owner;
        $category->name = $name;
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->rules      = $container->get(MailRuleRepository::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser('reader');
        $client->loginUser($this->user);

        return $client;
    }

    private function seedUser(string $tag): User
    {
        $user            = new User();
        $user->email     = 'rule-category-' . $tag . '-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Rule';
        $user->nameLast  = 'Category';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
