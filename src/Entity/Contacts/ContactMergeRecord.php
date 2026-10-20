<?php

declare(strict_types=1);

namespace App\Entity\Contacts;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Records a merge of one or more contacts into a kept contact, with the
 * full pre-merge snapshot needed to undo it within 90 days.
 */
#[ORM\Entity]
#[ORM\Table(name: 'contacts_contact_merge_record')]
#[ORM\Index(name: 'idx_contacts_merge_owner_undo', columns: ['usr_id', 'undo_until'])]
#[ORM\HasLifecycleCallbacks]
class ContactMergeRecord
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /** Survives deletion of the kept contact via SET NULL. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'kept_contact_id', nullable: true, onDelete: 'SET NULL')]
    public private(set) ?Contact $keptContact = null;

    /** @var list<int> ids of the contacts that were merged away */
    #[ORM\Column(type: Types::JSON)]
    public array $mergedIds = [];

    /** Full pre-merge data of the merged contacts. */
    #[ORM\Column(type: Types::JSON)]
    public array $snapshot = [];

    #[ORM\Column]
    public DateTimeImmutable $mergedAt;

    /** undo() is refused after this instant; mergedAt + 90 days. */
    #[ORM\Column(name: 'undo_until')]
    public DateTimeImmutable $undoUntil;

    public static function create(User $usr, ?Contact $keptContact, array $mergedIds, array $snapshot): self
    {
        $record = new self();
        $record->usr = $usr;
        $record->keptContact = $keptContact;
        $record->mergedIds = $mergedIds;
        $record->snapshot = $snapshot;
        $record->mergedAt = new DateTimeImmutable();
        $record->undoUntil = $record->mergedAt->modify('+90 days');

        return $record;
    }
}
