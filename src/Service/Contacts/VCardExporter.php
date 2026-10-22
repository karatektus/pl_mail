<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;

/**
 * Exports contacts as vCards.
 *
 * A synced contact is exported as the card its server holds, with this side's
 * edits in it, so the file keeps everything plMail has no field for; a local
 * one gets a vCard 3.0 built from its rows, photo included. See
 * VCardMapper::export().
 */
final readonly class VCardExporter
{
    public function __construct(
        private ContactRepository $contacts,
        private VCardMapper $mapper,
        private ContactPhotoStore $photos,
    ) {
    }

    public function exportOne(Contact $contact): string
    {
        return $this->mapper->export($contact, $this->photos->read($contact->photoPath));
    }

    public function exportAll(User $user): string
    {
        $contacts = $this->contacts->findBy(
            ['usr' => $user, 'trashedAt' => null],
            ['displayName' => 'ASC'],
        );

        $out = '';
        foreach ($contacts as $contact) {
            $out .= $this->exportOne($contact);
        }

        return $out;
    }
}
