<?php

declare(strict_types=1);
namespace App\Tests\Controller\Settings;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Gmail\GmailQuotaPacer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GmailSyncWarningTest extends WebTestCase
{
    private ?Connection $db = null;
    protected function tearDown(): void
    {
        if ($this->db?->isTransactionActive()) { $this->db->rollBack(); }
        parent::tearDown();
    }

    public function testEmptyInboxAndUnifiedViewsExplainIncompleteSyncWithoutLeakingOtherOwners(): void
    {
        $client = self::createClient(); $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = self::getContainer()->get(Connection::class); $this->db->beginTransaction();
        $user = $this->user($em, 'warning-owner@example.test');
        $other = $this->user($em, 'warning-other@example.test');
        $a = $this->account($em, $user, 'synthetic-gmail@example.test');
        $b = $this->account($em, $other, 'private-other-gmail@example.test');
        $pacer = self::getContainer()->get(GmailQuotaPacer::class);
        $pacer->throttle($a, 120); $pacer->throttle($b, 120);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/mail/account/' . $a->id);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-gmail-sync-warning]')->count());
        self::assertStringContainsString('empty Inbox may be incomplete', $crawler->text());
        self::assertStringNotContainsString('private-other-gmail', $crawler->text());
        $crawler = $client->request('GET', '/mail/inbox');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-gmail-sync-warning]')->count());
        self::assertStringNotContainsString('private-other-gmail', $crawler->text());
        $crawler = $client->request('GET', '/settings?section=health');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-health-kind="gmail_sync_paused"]')->count());
        self::assertStringNotContainsString('host, the port or the password', $crawler->text());
        $client->request('GET', '/mail/account/' . $b->id);
        self::assertResponseStatusCodeSame(403);
    }

    private function user(EntityManagerInterface $em, string $email): User
    {
        $u = new User(); $u->email = $email; $u->password = 'synthetic-unusable-hash'; $u->locale = 'en'; $u->nameFirst = 'Synthetic'; $u->nameLast = 'Test';
        $em->persist($u); $em->flush(); return $u;
    }
    private function account(EntityManagerInterface $em, User $user, string $email): Account
    {
        $a = new Account(); $a->usr = $user; $a->email = $email; $a->username = $email;
        $a->authType = 'oauth2'; $a->oauthProvider = 'google'; $a->isActive = true; $a->oauthAccessToken = 'synthetic-token'; $a->oauthRefreshToken = 'synthetic-refresh';
        $em->persist($a); $em->flush(); return $a;
    }
}
