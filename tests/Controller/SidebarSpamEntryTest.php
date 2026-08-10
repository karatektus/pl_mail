<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Spam appears in the sidebar when it is switched visible, and not otherwise.
 *
 * Worth its own file because the bug was that nothing connected the two at all.
 * The eye toggle in label settings has always accepted system labels — that is
 * deliberate, it is how the hidden Archive label becomes user-enableable — so a
 * user could switch Spam on, see the setting stick, and find no row anywhere.
 * The system nav is a hand-written run of anchors rather than a loop over the
 * roles, and it simply had no Spam arm: no entry, no route, no translation key.
 * A test that only checked the toggle persisted would have passed throughout.
 */
final class SidebarSpamEntryTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheSpamRowAppearsOnlyWhenTheLabelIsVisible(): void
    {
        $client = static::createClient();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->connection->beginTransaction();

        $spam = $this->spamLabelFor($user);

        // ── switched on ──────────────────────────────────────────────────────
        $spam->isVisible = true;
        $this->em->flush();

        $client->loginUser($user);
        $client->request('GET', '/mail/inbox');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'href="/mail/spam"',
            (string) $client->getResponse()->getContent(),
            'the sidebar offers Spam once its label is switched visible',
        );

        // ── switched off ─────────────────────────────────────────────────────
        $spam->isVisible = false;
        $this->em->flush();

        $client->request('GET', '/mail/inbox');

        self::assertStringNotContainsString(
            'href="/mail/spam"',
            (string) $client->getResponse()->getContent(),
            'and hides it again when it is switched off',
        );
    }

    /**
     * The view answers whether or not the sidebar links to it.
     *
     * Gating the route on a display preference would make a bookmark stop
     * working because someone tidied their sidebar — and the per-account folder
     * rows already link to this mail by label id regardless of the toggle.
     */
    public function testTheSpamViewRendersEvenWithTheRowHidden(): void
    {
        $client = static::createClient();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->connection->beginTransaction();

        $spam            = $this->spamLabelFor($user);
        $spam->isVisible = false;
        $this->em->flush();

        $client->loginUser($user);
        $client->request('GET', '/mail/spam');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    private function spamLabelFor(User $user): Label
    {
        $existing = $this->em->getRepository(Label::class)
            ->findOneBy(['usr' => $user, 'role' => LabelRole::Spam]);

        if (null !== $existing) {
            return $existing;
        }

        $label            = new Label();
        $label->usr       = $user;
        $label->name      = 'Spam';
        $label->role      = LabelRole::Spam;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }
}
