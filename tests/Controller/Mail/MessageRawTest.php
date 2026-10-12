<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Service\Mail\RawMessageResolver;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The raw source a browser decrypts from comes back byte for byte, to its
 * owner only, and is never a reconstruction.
 *
 * Byte for byte is the claim: "show original" forces the bytes to UTF-8 and
 * falls back to a rebuilt message, and either would turn a correct signature
 * into a broken one and ciphertext into garbage.
 */
final class MessageRawTest extends WebTestCase
{
    public function testTheOwnerGetsTheExactBytesAndNobodyCachesThem(): void
    {
        $client     = static::createClient();
        $client->disableReboot();
        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $connection = static::getContainer()->get(Connection::class);
        $connection->beginTransaction();

        try {
            $user    = MailFixtures::user($em, 'raw');
            $message = MailFixtures::message($em, MailFixtures::account($em, $user));
            // Latin-1 and a bare CR: what "show original" would have rewritten.
            $raw     = "Subject: raw\r\nContent-Type: text/plain; charset=iso-8859-1\r\n\r\nGeb\xFChr\r\x00end";

            static::getContainer()->get(RawMessageResolver::class)->store($message, $raw);
            $em->flush();

            $client->loginUser($user);
            $client->request('GET', sprintf('/mail/message/%d/raw', $message->id));

            self::assertResponseIsSuccessful();
            self::assertSame($raw, $client->getInternalResponse()->getContent());
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('message/rfc822', $client->getResponse()->headers->get('Content-Type'));

            $client->loginUser(MailFixtures::user($em, 'stranger'));
            $client->request('GET', sprintf('/mail/message/%d/raw', $message->id));

            self::assertResponseStatusCodeSame(403);
        } finally {
            $connection->rollBack();
        }
    }

    /** No raw source is a 404, never a rebuilt message. */
    public function testAMessageWithoutARawSourceIsNotFound(): void
    {
        $client     = static::createClient();
        $client->disableReboot();
        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $connection = static::getContainer()->get(Connection::class);
        $connection->beginTransaction();

        try {
            $user    = MailFixtures::user($em, 'raw-none');
            $message = MailFixtures::message($em, MailFixtures::account($em, $user));

            $client->loginUser($user);
            $client->request('GET', sprintf('/mail/message/%d/raw', $message->id));

            self::assertResponseStatusCodeSame(404);
        } finally {
            $connection->rollBack();
        }
    }
}
