<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Listener;

use App\Domain\Helper\AddressHelper;
use App\Entity\Contacts\ContactEmail;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Makes an address-book email visible to the composer.
 *
 * The To/Cc/Bcc fields search the older `contact` table (the addresses the
 * mail itself has harvested), not the Contacts address book. Rather than teach
 * the composer a second source, every address-book email is mirrored into that
 * table as it is saved — whichever way it got here: an import, the editor, a
 * merge or the JMAP API all end in a ContactEmail row, so one listener covers
 * them all.
 *
 * The mirror never counts as correspondence: frequency stays where it is, so
 * an address you have actually written to still ranks above one you merely
 * stored, and is_correspondent is left alone.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class AddressBookMirrorListener
{
    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->mirror($args->getObject(), $args->getObjectManager()->getConnection());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->mirror($args->getObject(), $args->getObjectManager()->getConnection());
    }

    private function mirror(object $entity, Connection $connection): void
    {
        if (false === $entity instanceof ContactEmail) {
            return;
        }

        $email = AddressHelper::email($entity->email);

        if (false === AddressHelper::isValidEmail($email)) {
            return;
        }

        $userId = $entity->contact->usr->id;
        $name   = trim($entity->contact->displayName);

        // An unnamed contact is displayed by its address; storing that as the
        // name would only duplicate it.
        if ('' === $name || mb_strtolower($name) === $email) {
            $name = null;
        }

        $now = new \DateTimeImmutable();

        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO contact (usr_id, email, display_name, frequency, first_seen_at, last_seen_at, created_at, updated_at, is_correspondent)
            VALUES (:userId, :email, :name, 0, :now, :now, :now, :now, FALSE)
            ON CONFLICT (usr_id, email) DO UPDATE
                SET display_name = COALESCE(NULLIF(:name, ''), contact.display_name),
                    updated_at   = :now
            SQL,
            ['userId' => $userId, 'email' => $email, 'name' => $name, 'now' => $now],
            [
                'userId' => Types::INTEGER,
                'email'  => Types::STRING,
                'name'   => Types::STRING,
                'now'    => Types::DATETIME_IMMUTABLE,
            ],
        );
    }
}
