<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Contact;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\HarvestContactsService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A spammer is not a correspondent; somebody whose mail was deleted still is.
 *
 * Harvested contacts feed autocomplete, and "somebody you write to" decides
 * the categoriser's Primary and the classification hold. A Gmail import now
 * takes in Spam, so without this every sender in it would join that list.
 */
final class HarvestSpamContactsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testSpamSendersAreNotHarvestedAndTrashSendersAre(): void
    {
        $user            = new User();
        $user->email     = 'harvest-spam-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Harvest';
        $user->nameLast  = 'Spam';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);
        $this->em->flush();

        self::getContainer()->get(HarvestContactsService::class)->harvestMessages(
            $user,
            [
                $this->from('winner@lottery.test', LabelRole::Spam),
                $this->from('colleague@work.test', LabelRole::Trash),
            ],
            'me@example.test',
        );

        $harvested = array_map(
            static fn (Contact $contact): string => $contact->email,
            $this->em->getRepository(Contact::class)->findBy(['usr' => $user]),
        );

        self::assertContains('colleague@work.test', $harvested);
        self::assertNotContains('winner@lottery.test', $harvested);
    }

    private function from(string $address, LabelRole $role): Message
    {
        $label       = new Label();
        $label->role = $role;

        $message              = new Message();
        $message->fromAddress = $address;
        $message->fromName    = 'Someone';
        $message->addLabel($label);

        return $message;
    }
}
