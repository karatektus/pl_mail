<?php

declare(strict_types=1);

namespace App\Jmap\Contact;

use App\Domain\Helper\UploadStorage;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Mail\UploadedBlob;
use App\Entity\User\User;
use App\Jmap\Blob\BlobId;
use App\Jmap\Protocol\Exception\MethodException;
use App\Repository\AddressBook\AddressBookRepository;
use App\Service\AddressBook\Jmap\JsContactMapper;
use App\Service\Contacts\ContactPhoto;
use App\Service\Contacts\ContactPhotoStore;
use App\Service\Contacts\VCardMapper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * plMail's contacts as RFC 9610 address books and cards (JSContact, RFC 9553).
 *
 * The other half of JsContactMapper. That class turns a remote server's card
 * into a plMail contact and back, for JmapContactsDriver; serving cards, this
 * class uses it the other way round, so a contact published here and read by
 * another plMail comes back as the contact it was. Writes then go through
 * VCardMapper::apply(), the one applier every address-book sync uses.
 *
 * **Address books.** plMail's own contacts — the ones in no synced book — are
 * one book, "plMail (address)", with the id LOCAL_BOOK; each address book mirrored from
 * CardDAV, Google, Microsoft or a JMAP server is another, by its row id. A card
 * is in exactly one. A change to a card in a mirrored book goes on to that
 * book's server through ContactSyncTracker, as an edit on the contact page does.
 *
 * **Photos** are published as a data: URI in `media`, which needs no blob
 * download route and which every client of the RFC reads; JmapContactsDriver
 * does. A photo comes in as a blob uploaded through this server's own upload
 * endpoint, or a data: URI.
 */
final readonly class ContactCards
{
    /** The id of the book plMail's own contacts are in. */
    public const string LOCAL_BOOK = 'plmail';

    public function __construct(
        private AddressBookRepository $books,
        private JsContactMapper $mapper,
        private VCardMapper $vcards,
        private ContactPhotoStore $photos,
        private UploadStorage $uploads,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * The address books, plMail's own first.
     *
     * @return list<array<string, mixed>>
     */
    public function books(User $user): array
    {
        // Named with the address, as Stalwart names its books: another plMail
        // syncing this one lists it beside its own book, which is also plMail's.
        $list = [$this->book(self::LOCAL_BOOK, sprintf('plMail (%s)', $user->email), 0, true, true)];

        foreach ($this->books->findForUser($user) as $index => $book) {
            $list[] = $this->book((string) $book->id, $book->name, $index + 1, false, false === $book->isReadOnly);
        }

        return $list;
    }

    /**
     * A contact as a card: the managed properties, the envelope, and the photo.
     *
     * @return array<string, mixed>
     */
    public function card(Contact $contact): array
    {
        $card = array_filter($this->mapper->toCard($contact), static fn (mixed $value): bool => null !== $value);

        $photo = $this->photos->read($contact->photoPath);

        if (null !== $photo) {
            $card['media'] = ['photo' => [
                '@type'     => 'Media',
                'kind'      => 'photo',
                'uri'       => sprintf('data:%s;base64,%s', $photo->mime, base64_encode($photo->bytes)),
                'mediaType' => $photo->mime,
            ]];
        }

        return [
            '@type'          => 'Card',
            'version'        => '1.0',
            'id'             => (string) $contact->id,
            'uid'            => $contact->uid,
            'addressBookIds' => [self::bookIdOf($contact) => true],
            'updated'        => $contact->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ] + $card;
    }

    public static function bookIdOf(Contact $contact): string
    {
        return null === $contact->addressBook ? self::LOCAL_BOOK : (string) $contact->addressBook->id;
    }

    /**
     * The book a card names, as plMail's own (null) or a mirrored one this user
     * may write.
     *
     * @throws MethodException when it names none, several, or one not theirs to write
     */
    public function bookFor(User $user, mixed $addressBookIds): ?AddressBook
    {
        $ids = array_keys(array_filter(is_array($addressBookIds) ? $addressBookIds : []));

        if (1 !== count($ids)) {
            throw new MethodException('invalidProperties', 'A card is in exactly one address book here: "addressBookIds" must name one.', ['properties' => ['addressBookIds']]);
        }

        $id = (string) $ids[0];

        if (self::LOCAL_BOOK === $id) {
            return null;
        }

        foreach ($this->books->findForUser($user) as $book) {
            if ((string) $book->id === $id) {
                if (true === $book->isReadOnly) {
                    throw new MethodException('forbidden', 'That address book is read-only.');
                }

                return $book;
            }
        }

        throw new MethodException('invalidProperties', 'No such address book.', ['properties' => ['addressBookIds']]);
    }

    /**
     * A new contact from a card, persisted and flushed.
     *
     * @param array<string, mixed> $card
     */
    public function create(User $user, array $card): Contact
    {
        $book   = $this->bookFor($user, $card['addressBookIds'] ?? null);
        $parsed = $this->mapper->toParsed($card);

        $contact              = Contact::create($user, $this->vcards->displayNameOf($parsed));
        $contact->addressBook = $book;
        $contact->source      = 'jmap';

        $this->vcards->apply($parsed, $contact);

        if (true === array_key_exists('media', $card)) {
            $this->replacePhoto($user, $contact, $card['media']);
        }

        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    /**
     * A patch over the card as it is published: each property it names
     * replaced whole, the rest as they are.
     *
     * @param array<string, mixed> $patch
     */
    public function update(User $user, Contact $contact, array $patch): void
    {
        foreach (array_keys($patch) as $property) {
            if (true === str_contains((string) $property, '/')) {
                throw new MethodException('invalidPatch', sprintf('Patch paths are not supported; send the whole "%s" property.', explode('/', (string) $property)[0]));
            }
        }

        if (true === array_key_exists('uid', $patch) && $patch['uid'] !== $contact->uid) {
            throw new MethodException('invalidProperties', 'A card\'s uid cannot be changed.', ['properties' => ['uid']]);
        }

        // Moving a contact between books would move it between servers, which
        // this server has no way to do in one step; naming the book it is in is
        // what a client echoing the card back does, and is fine.
        if (true === array_key_exists('addressBookIds', $patch) && [self::bookIdOf($contact) => true] !== array_filter(is_array($patch['addressBookIds']) ? $patch['addressBookIds'] : [])) {
            throw new MethodException('invalidProperties', 'A card cannot be moved to another address book here.', ['properties' => ['addressBookIds']]);
        }

        if (false === $contact->isEditable()) {
            throw new MethodException('forbidden', 'This card is in a read-only address book.');
        }

        $merged      = array_merge($this->card($contact), $patch);
        $parsed      = $this->mapper->toParsed(array_filter($merged, static fn (mixed $value): bool => null !== $value));
        $parsed->uid = $contact->uid;

        $this->vcards->apply($parsed, $contact);

        if (true === array_key_exists('media', $patch)) {
            $this->replacePhoto($user, $contact, $patch['media']);
        }

        $this->em->flush();
    }

    /**
     * The photo a card's media names, replacing the one stored; none when
     * media is null or names no photo.
     */
    private function replacePhoto(User $user, Contact $contact, mixed $media): void
    {
        $photo = $this->photoIn($user, is_array($media) ? $media : []);

        if (null !== $contact->photoPath) {
            $this->photos->delete($contact->photoPath);
        }

        $contact->photoPath = null === $photo ? null : $this->photos->storeBytes($photo->bytes, $photo->mime, (int) $user->id);
    }

    /**
     * @param array<array-key, mixed> $media
     */
    private function photoIn(User $user, array $media): ?ContactPhoto
    {
        foreach ($media as $entry) {
            if (false === is_array($entry) || 'photo' !== ($entry['kind'] ?? null)) {
                continue;
            }

            $uri = $entry['uri'] ?? null;

            if (is_string($uri) && 1 === preg_match('#^data:([^;,]+)(;base64)?,(.*)$#s', $uri, $m)) {
                $bytes = '' !== $m[2] ? base64_decode($m[3], true) : rawurldecode($m[3]);

                if (false === $bytes || '' === $bytes) {
                    throw new MethodException('invalidProperties', 'The photo data: URI could not be read.', ['properties' => ['media']]);
                }

                return new ContactPhoto($bytes, $m[1]);
            }

            $blobId = BlobId::parse(is_string($entry['blobId'] ?? null) ? $entry['blobId'] : '');

            if (null === $blobId || false === $blobId->isUpload()) {
                throw new MethodException('invalidProperties', 'A photo is a blob uploaded here, or a data: URI.', ['properties' => ['media']]);
            }

            $blob = $this->em->find(UploadedBlob::class, $blobId->id);

            if (null === $blob || $blob->account->usr?->id !== $user->id) {
                throw new MethodException('invalidProperties', 'No such uploaded blob.', ['properties' => ['media']]);
            }

            $absolute = $this->uploads->getAbsolutePath($blob->path);
            $bytes    = is_file($absolute) ? file_get_contents($absolute) : false;

            if (false === $bytes || '' === $bytes) {
                throw new MethodException('invalidProperties', 'The uploaded photo could not be read; upload it again.', ['properties' => ['media']]);
            }

            return new ContactPhoto($bytes, $blob->contentType);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function book(string $id, string $name, int $sortOrder, bool $isDefault, bool $mayWrite): array
    {
        return [
            'id'           => $id,
            'name'         => $name,
            'description'  => null,
            'sortOrder'    => $sortOrder,
            'isDefault'    => $isDefault,
            'isSubscribed' => true,
            'shareWith'    => null,
            'myRights'     => ['mayRead' => true, 'mayWrite' => $mayWrite, 'mayShare' => false, 'mayDelete' => false],
        ];
    }
}
