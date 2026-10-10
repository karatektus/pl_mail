<?php

declare(strict_types=1);
namespace App\Tests\Controller\Settings;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GmailQuotaSettingsTest extends WebTestCase
{
    private ?Connection $db = null;
    protected function tearDown(): void
    {
        if ($this->db?->isTransactionActive()) { $this->db->rollBack(); }
        parent::tearDown();
    }

    public function testQuotaSettingsAreAdminOnlyCsrfProtectedAndRangeValidated(): void
    {
        $client = self::createClient(); $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = self::getContainer()->get(Connection::class); $this->db->beginTransaction();
        $user = new User(); $user->email = 'quota-admin@example.test'; $user->password = 'synthetic-unusable';
        $user->nameFirst = 'Synthetic'; $user->nameLast = 'Admin'; $user->locale = 'en'; $user->roles = [User::ROLE_ADMIN];
        $em->persist($user); $em->flush(); $client->loginUser($user);
        $path = '/admin/integrations/mail/google/edit';
        $crawler = $client->request('GET', $path); self::assertResponseIsSuccessful();
        self::assertSame('6000', $crawler->filter('input[name="mail_provider_config[gmailQuotaPerMinute]"]')->attr('value'));
        self::assertSame('20', $crawler->filter('input[name="mail_provider_config[gmailQuotaHeadroom]"]')->attr('value'));
        $token = (string) $crawler->filter('input[name="mail_provider_config[_token]"]')->attr('value');
        $client->request('POST', $path, ['mail_provider_config' => ['gmailQuotaPerMinute' => 9000, 'gmailQuotaHeadroom' => 25, '_token' => 'invalid']]);
        self::assertFalse($this->db->fetchOne("SELECT settings FROM mail_provider_config WHERE provider = 'google'"));
        $client->request('POST', $path, ['mail_provider_config' => ['gmailQuotaPerMinute' => 9000, 'gmailQuotaHeadroom' => 25, '_token' => $token]]);
        self::assertResponseIsSuccessful();
        $settings = json_decode((string) $this->db->fetchOne("SELECT settings FROM mail_provider_config WHERE provider = 'google'"), true);
        self::assertSame(9000, $settings['gmail.quota_per_minute']); self::assertSame(25, $settings['gmail.quota_headroom_percent']);
        $crawler = $client->request('GET', $path); $token = (string) $crawler->filter('input[name="mail_provider_config[_token]"]')->attr('value');
        $client->request('POST', $path, ['mail_provider_config' => ['gmailQuotaPerMinute' => 15001, 'gmailQuotaHeadroom' => 51, '_token' => $token]]);
        $settings = json_decode((string) $this->db->fetchOne("SELECT settings FROM mail_provider_config WHERE provider = 'google'"), true);
        self::assertSame(9000, $settings['gmail.quota_per_minute']);
        $regular = new User(); $regular->email = 'quota-regular@example.test'; $regular->password = 'synthetic-unusable';
        $regular->nameFirst = 'Synthetic'; $regular->nameLast = 'Regular'; $regular->locale = 'en';
        $em->persist($regular); $em->flush(); $client->loginUser($regular);
        $client->request('GET', $path); self::assertResponseStatusCodeSame(403);
    }
}
