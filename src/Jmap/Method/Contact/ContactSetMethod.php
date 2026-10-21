<?php

declare(strict_types=1);

namespace App\Jmap\Method\Contact;

use App\Domain\Helper\AddressHelper;
use App\Domain\Helper\UploadStorage;
use App\Entity\Contacts\Contact;
use App\Entity\Mail\UploadedBlob;
use App\Entity\User\User;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Blob\BlobId;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Contacts\ContactRepository;
use App\Service\Contacts\ContactFields;
use App\Service\Contacts\ContactManager;
use App\Service\Contacts\ContactPhotoStore;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Contact/set": create, update and destroy address-book contacts.
 *
 * Every scalar and child-row write goes through ContactManager, the one place
 * a contact is written — the same seam the web editor uses, so the two cannot
 * drift. The two exceptions are photoPath and source on update: the manager
 * does not know the photo-blob lifecycle (a JMAP-layer concern), and its
 * update() does not take a source, so those two assignments are direct and
 * documented where they happen.
 *
 * Update semantics for the child collections are REPLACE-ALL: when "emails",
 * "phones" or "addresses" is present in the patch, the contact's existing rows
 * of that kind are deleted and the sent list becomes the whole truth. Absent
 * means untouched — there is no per-child id addressing. A destroy is a soft
 * delete (90-day trash), never a hard delete.
 *
 * A contact in a read-only address book (a shared book or a global address
 * list on a CardDAV server; Contact/get says isReadOnly) answers forbidden to
 * an update or a destroy. One in a writable synced book takes both, and the
 * change goes on to its server (ContactSyncTracker). A create always makes one
 * of plMail's own contacts.
 *
 * "photoBlobId" names a blob staged by POST /jmap/contacts/photo. It is
 * consumed once: the bytes move into the photo store and the blob row and its
 * staged file are deleted, so a replayed blobId answers notFound instead of
 * re-attaching.
 */
final class ContactSetMethod implements JmapMethod
{
    /**
     * @var list<string>
     */
    private const array ACCEPTED_ARGUMENTS = ['accountId', 'create', 'update', 'destroy'];

    /**
     * Everything a client may send inside a create object or an update patch.
     *
     * @var list<string>
     */
    private const array WRITABLE_KEYS = [
        'displayName', 'namePrefix', 'givenName', 'middleName', 'familyName', 'nameSuffix',
        'phoneticGivenName', 'phoneticMiddleName', 'phoneticFamilyName', 'nickname',
        'company', 'phoneticCompany', 'department', 'jobTitle', 'officeLocation',
        'notes', 'birthday', 'tags', 'source',
        'emails', 'phones', 'addresses', 'dates', 'relations', 'urls', 'ims', 'customFields',
        'photoBlobId',
    ];

    /**
     * The nullable scalar columns ContactManager::applyScalars() owns.
     *
     * @var list<string>
     */
    private const array SCALAR_KEYS = ContactFields::SCALARS;

    /**
     * The labelled lists, each with the key a row must have. Replace-all,
     * like emails: a present list is the whole list.
     *
     * @var array<string, list<string>>
     */
    private const array LIST_KEYS = [
        'dates'        => ['label', 'date'],
        'relations'    => ['label', 'name'],
        'urls'         => ['label', 'url'],
        'ims'          => ['service', 'handle', 'label'],
        'customFields' => ['label', 'value'],
    ];

    /**
     * @var list<string>
     */
    private const array LABELS = ['home', 'work', 'other'];

    /**
     * Phones also say mobile, the label a phone's own address book uses most.
     *
     * @var list<string>
     */
    private const array PHONE_LABELS = ['mobile', 'home', 'work', 'other'];

    /**
     * @var list<string>
     */
    private const array SOURCES = ['manual', 'import:vcf', 'import:csv'];

    /**
     * @var list<string>
     */
    private const array ADDRESS_KEYS = ['street', 'city', 'region', 'postcode', 'country'];

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly ContactRepository $contacts,
        private readonly ContactManager $manager,
        private readonly ContactPhotoStore $photos,
        private readonly UploadStorage $uploadStorage,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'Contact/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $this->rejectUnknownArguments($arguments);

        $created = [];
        $notCreated = [];
        $updated = [];
        $notUpdated = [];
        $destroyed = [];
        $notDestroyed = [];

        $this->applyCreates($context, $arguments['create'] ?? null, $created, $notCreated);
        $this->applyUpdates($context, $arguments['update'] ?? null, $updated, $notUpdated);
        $this->applyDestroys($context, $arguments['destroy'] ?? null, $destroyed, $notDestroyed);

        // ContactManager flushes on every write; this covers the photoPath and
        // source assignments and the consumed upload blobs, which bypass it.
        $this->entityManager->flush();

        return [
            'accountId' => (string) $account->id,
            'created' => 0 === count($created) ? new \stdClass() : $created,
            'notCreated' => 0 === count($notCreated) ? new \stdClass() : $notCreated,
            'updated' => 0 === count($updated) ? new \stdClass() : $updated,
            'notUpdated' => 0 === count($notUpdated) ? new \stdClass() : $notUpdated,
            'destroyed' => $destroyed,
            'notDestroyed' => 0 === count($notDestroyed) ? new \stdClass() : $notDestroyed,
        ];
    }

    /**
     * @param array<string,mixed> $created
     * @param array<string,mixed> $notCreated
     */
    private function applyCreates(JmapContext $context, mixed $create, array &$created, array &$notCreated): void
    {
        if (null === $create) {
            return;
        }

        if (false === is_array($create)) {
            throw new MethodException('invalidArguments', '"create" must be an object.');
        }

        foreach ($create as $creationId => $properties) {
            $creationId = (string) $creationId;

            if (false === is_array($properties)) {
                $notCreated[$creationId] = ['type' => 'invalidProperties', 'description' => 'Each create must be an object.'];

                continue;
            }

            try {
                $this->rejectUnknownKeys($properties);
                $this->prevalidateChildren($properties);

                $contact = $this->manager->create($context->user, $this->createData($properties));

                foreach ($this->parseEmails($properties['emails'] ?? null) as [$email, $label, $primary]) {
                    $this->manager->addEmail($contact, $email, $label, $primary);
                }

                foreach ($this->parsePhones($properties['phones'] ?? null) as [$phone, $label, $primary]) {
                    $this->manager->addPhone($contact, $phone, $label, $primary);
                }

                foreach ($this->parseAddresses($properties['addresses'] ?? null) as $addressData) {
                    $this->manager->addAddress($contact, $addressData);
                }

                $photoPath = $this->photoPathFromBlob($properties['photoBlobId'] ?? null, $context->user);

                if (null !== $photoPath) {
                    $contact->photoPath = $photoPath;
                }
            } catch (MethodException $exception) {
                $notCreated[$creationId] = $exception->toError();

                continue;
            } catch (\InvalidArgumentException $exception) {
                $notCreated[$creationId] = ['type' => 'invalidArguments', 'description' => $exception->getMessage()];

                continue;
            }

            $id = (string) $contact->id;

            // Lets a later call in the same request refer to "#creationId".
            $context->recordCreatedId($creationId, $id);

            $created[$creationId] = ['id' => $id];
        }
    }

    /**
     * @param array<string,mixed> $updated
     * @param array<string,mixed> $notUpdated
     */
    private function applyUpdates(JmapContext $context, mixed $update, array &$updated, array &$notUpdated): void
    {
        if (null === $update) {
            return;
        }

        if (false === is_array($update)) {
            throw new MethodException('invalidArguments', '"update" must be an object.');
        }

        foreach ($update as $id => $patch) {
            $id = (string) $id;
            $resolved = $context->resolveId($id) ?? $id;

            if (false === is_array($patch)) {
                $notUpdated[$id] = ['type' => 'invalidPatch', 'description' => 'Each update must be an object.'];

                continue;
            }

            $contact = $this->findOne($context, $resolved);

            if (null === $contact) {
                $notUpdated[$id] = ['type' => 'notFound', 'description' => 'No such contact.'];

                continue;
            }

            if (false === $contact->isEditable()) {
                $notUpdated[$id] = ['type' => 'forbidden', 'description' => 'This contact is in a read-only address book.'];

                continue;
            }

            try {
                $this->rejectUnknownKeys($patch);
                $this->prevalidateChildren($patch);

                $data = $this->updateData($patch);

                if ([] !== $data) {
                    $this->manager->update($contact, $data);
                }

                if (array_key_exists('source', $patch)) {
                    // ContactManager::update() does not take a source; the
                    // column is public, so this one assignment is direct.
                    $contact->source = $this->requireSource($patch['source']);
                }

                $this->replaceChildren($contact, $patch);

                if (array_key_exists('photoBlobId', $patch)) {
                    $this->replacePhoto($contact, $patch['photoBlobId'], $context->user);
                }
            } catch (MethodException $exception) {
                $notUpdated[$id] = $exception->toError();

                continue;
            } catch (\InvalidArgumentException $exception) {
                $notUpdated[$id] = ['type' => 'invalidArguments', 'description' => $exception->getMessage()];

                continue;
            }

            // null = "no properties changed beyond what the client asked for".
            $updated[$id] = null;
        }
    }

    /**
     * @param list<string>        $destroyed
     * @param array<string,mixed> $notDestroyed
     */
    private function applyDestroys(JmapContext $context, mixed $destroy, array &$destroyed, array &$notDestroyed): void
    {
        if (null === $destroy) {
            return;
        }

        if (false === is_array($destroy)) {
            throw new MethodException('invalidArguments', '"destroy" must be an array of ids.');
        }

        foreach ($destroy as $id) {
            $id = (string) $id;
            $resolved = $context->resolveId($id) ?? $id;

            $contact = $this->findOne($context, $resolved);

            if (null === $contact) {
                $notDestroyed[$id] = ['type' => 'notFound', 'description' => 'No such contact.'];

                continue;
            }

            if (false === $contact->isEditable()) {
                $notDestroyed[$id] = ['type' => 'forbidden', 'description' => 'This contact is in a read-only address book.'];

                continue;
            }

            // Soft, not hard: the row survives 90 days in the trash and
            // ContactManager::permanentPurge() reaps it later. The same call
            // the web UI's delete button makes.
            $this->manager->softDelete($contact);

            $destroyed[] = $id;
        }
    }

    /**
     * Scoped to the owner, so somebody else's contact is notFound rather than
     * forbidden — the two are distinguishable to a client, and only one of
     * them confirms that the id exists.
     */
    private function findOne(JmapContext $context, string $id): ?Contact
    {
        if (false === ctype_digit($id)) {
            return null;
        }

        return $this->contacts->findOneBy(['id' => (int) $id, 'usr' => $context->user]);
    }

    /**
     * @param array<string,mixed> $properties
     *
     * @return array<string,mixed>
     */
    private function createData(array $properties): array
    {
        $displayName = trim((string) ($properties['displayName'] ?? ''));

        if ('' === $displayName) {
            throw new MethodException('invalidProperties', 'A non-empty "displayName" is required to create a contact.');
        }

        $data = ['displayName' => $displayName];

        foreach (self::SCALAR_KEYS as $key) {
            if (array_key_exists($key, $properties)) {
                $data[$key] = $this->optionalString($properties[$key], $key);
            }
        }

        if (array_key_exists('tags', $properties)) {
            $data['tags'] = $this->requireStringList($properties['tags'], 'tags');
        }

        $data += $this->fieldData($properties);

        $data['source'] = $this->requireSource($properties['source'] ?? 'manual');

        return $data;
    }

    /**
     * @param array<string,mixed> $patch
     *
     * @return array<string,mixed>
     */
    private function updateData(array $patch): array
    {
        $data = [];

        if (array_key_exists('displayName', $patch)) {
            $displayName = trim((string) ($patch['displayName'] ?? ''));

            if ('' === $displayName) {
                throw new MethodException('invalidProperties', '"displayName" cannot be set to empty.');
            }

            $data['displayName'] = $displayName;
        }

        foreach (self::SCALAR_KEYS as $key) {
            if (array_key_exists($key, $patch)) {
                $data[$key] = $this->optionalString($patch[$key], $key);
            }
        }

        if (array_key_exists('tags', $patch)) {
            $data['tags'] = $this->requireStringList($patch['tags'], 'tags');
        }

        return $data + $this->fieldData($patch);
    }

    /**
     * The birthday and the labelled lists, checked the JMAP way — the wrong
     * shape is refused, not dropped — and then cleaned by ContactManager.
     *
     * @param array<string,mixed> $properties
     *
     * @return array<string,mixed>
     */
    private function fieldData(array $properties): array
    {
        $data = [];

        if (array_key_exists('birthday', $properties)) {
            $birthday = $this->optionalString($properties['birthday'], 'birthday');

            if (null !== $birthday && null === ContactFields::date($birthday)) {
                throw new MethodException('invalidProperties', '"birthday" must be YYYY-MM-DD, or --MM-DD without a year.');
            }

            $data['birthday'] = $birthday;
        }

        foreach (self::LIST_KEYS as $list => $keys) {
            if (false === array_key_exists($list, $properties)) {
                continue;
            }

            $rows = $properties[$list];

            if (false === is_array($rows) || false === array_is_list($rows)) {
                throw new MethodException('invalidProperties', sprintf('"%s" must be an array.', $list));
            }

            foreach ($rows as $index => $row) {
                if (false === is_array($row)) {
                    throw new MethodException('invalidProperties', sprintf('"%s" entry %d must be an object.', $list, $index));
                }

                foreach ($keys as $key) {
                    if (array_key_exists($key, $row) && false === is_string($row[$key])) {
                        throw new MethodException('invalidProperties', sprintf('"%s" entry %d "%s" must be a string.', $list, $index, $key));
                    }
                }

                if ('dates' === $list && null === ContactFields::date((string) ($row['date'] ?? ''))) {
                    throw new MethodException('invalidProperties', sprintf('"dates" entry %d needs a "date" as YYYY-MM-DD or --MM-DD.', $index));
                }
            }

            $data[$list] = $rows;
        }

        return $data;
    }

    /**
     * REPLACE-ALL: a present "emails"/"phones"/"addresses" list replaces every
     * existing row of that kind; an absent key leaves the rows alone.
     *
     * @param array<string,mixed> $patch
     */
    private function replaceChildren(Contact $contact, array $patch): void
    {
        if (array_key_exists('emails', $patch)) {
            foreach ($contact->emails as $child) {
                $this->entityManager->remove($child);
            }

            $contact->emails->clear();

            foreach ($this->parseEmails($patch['emails']) as [$email, $label, $primary]) {
                $this->manager->addEmail($contact, $email, $label, $primary);
            }
        }

        if (array_key_exists('phones', $patch)) {
            foreach ($contact->phones as $child) {
                $this->entityManager->remove($child);
            }

            $contact->phones->clear();

            foreach ($this->parsePhones($patch['phones']) as [$phone, $label, $primary]) {
                $this->manager->addPhone($contact, $phone, $label, $primary);
            }
        }

        if (array_key_exists('addresses', $patch)) {
            foreach ($contact->addresses as $child) {
                $this->entityManager->remove($child);
            }

            $contact->addresses->clear();

            foreach ($this->parseAddresses($patch['addresses']) as $addressData) {
                $this->manager->addAddress($contact, $addressData);
            }
        }
    }

    /**
     * The old photo goes first: a contact holds one photo, and the store
     * hands out a fresh random filename, so there is nothing to roll back
     * to if the new bytes turn out to be bad.
     */
    private function replacePhoto(Contact $contact, mixed $blobIdValue, User $user): void
    {
        if (null !== $contact->photoPath && '' !== $contact->photoPath) {
            $this->photos->delete($contact->photoPath);
        }

        $contact->photoPath = $this->photoPathFromBlob($blobIdValue, $user);
    }

    /**
     * Moves a staged photo blob into the photo store and consumes the blob.
     * A null blobId means "no photo", which is how an update clears one.
     */
    private function photoPathFromBlob(mixed $blobIdValue, User $user): ?string
    {
        if (null === $blobIdValue) {
            return null;
        }

        if (false === is_string($blobIdValue) || '' === trim($blobIdValue)) {
            throw new MethodException('invalidArguments', '"photoBlobId" must be a blobId from POST /jmap/contacts/photo.');
        }

        $blobId = BlobId::parse($blobIdValue);

        if (null === $blobId || false === $blobId->isUpload()) {
            throw new MethodException('invalidArguments', '"photoBlobId" must be a blobId from POST /jmap/contacts/photo.');
        }

        $blob = $this->entityManager->find(UploadedBlob::class, $blobId->id);

        if (null === $blob || null === $blob->account->usr || $blob->account->usr->id !== $user->id) {
            throw new MethodException('notFound', 'No such photo upload.');
        }

        $absolute = $this->uploadStorage->getAbsolutePath($blob->path);
        $bytes = is_file($absolute) ? file_get_contents($absolute) : false;

        if (false === $bytes || '' === $bytes) {
            throw new MethodException('invalidArguments', 'The photo upload could not be read; upload it again.');
        }

        $path = $this->photos->storeBytes($bytes, $blob->contentType, (int) $user->id);

        // Consume-once: the staged file and its row are gone, so a replayed
        // blobId answers notFound instead of re-attaching the photo.
        $this->uploadStorage->delete($blob->path);
        $this->entityManager->remove($blob);

        return $path;
    }

    /**
     * The manager validates as it writes and flushes on every call, so a bad
     * third email would leave a half-built contact behind a notCreated. Every
     * value shaped like data is checked up front; what reaches the manager
     * can then only fail on something the database says.
     *
     * @param array<string,mixed> $properties
     */
    private function prevalidateChildren(array $properties): void
    {
        foreach ($this->parseEmails($properties['emails'] ?? null) as [$email]) {
            $normalized = AddressHelper::email($email);

            if (false === AddressHelper::isValidEmail($normalized)) {
                throw new MethodException('invalidProperties', sprintf('Invalid email address: %s.', $email));
            }
        }

        foreach ($this->parsePhones($properties['phones'] ?? null) as [$phone]) {
            if ('' === (preg_replace('/\D+/', '', $phone) ?? '')) {
                throw new MethodException('invalidProperties', sprintf('Phone number has no digits: %s.', $phone));
            }
        }

        // Addresses carry no constraints beyond shape, which parseAddresses
        // already checked.
        $this->parseAddresses($properties['addresses'] ?? null);
    }

    /**
     * @return list<array{string, string, bool}>
     */
    private function parseEmails(mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        if (false === is_array($value)) {
            throw new MethodException('invalidProperties', '"emails" must be an array.');
        }

        $out = [];

        foreach (array_values($value) as $index => $entry) {
            if (false === is_array($entry)) {
                throw new MethodException('invalidProperties', sprintf('"emails" entry %d must be an object.', $index));
            }

            $email = $entry['email'] ?? null;

            if (false === is_string($email) || '' === trim($email)) {
                throw new MethodException('invalidProperties', sprintf('"emails" entry %d needs a non-empty "email".', $index));
            }

            $out[] = [
                trim($email),
                $this->requireLabel($entry['label'] ?? null, 'emails', $index),
                $this->requireBool($entry['isPrimary'] ?? null, 'emails', $index, 'isPrimary'),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{string, string, bool}>
     */
    private function parsePhones(mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        if (false === is_array($value)) {
            throw new MethodException('invalidProperties', '"phones" must be an array.');
        }

        $out = [];

        foreach (array_values($value) as $index => $entry) {
            if (false === is_array($entry)) {
                throw new MethodException('invalidProperties', sprintf('"phones" entry %d must be an object.', $index));
            }

            $phone = $entry['phone'] ?? null;

            if (false === is_string($phone) || '' === trim($phone)) {
                throw new MethodException('invalidProperties', sprintf('"phones" entry %d needs a non-empty "phone".', $index));
            }

            $out[] = [
                trim($phone),
                $this->requireLabel($entry['label'] ?? null, 'phones', $index),
                $this->requireBool($entry['isPrimary'] ?? null, 'phones', $index, 'isPrimary'),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function parseAddresses(mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        if (false === is_array($value)) {
            throw new MethodException('invalidProperties', '"addresses" must be an array.');
        }

        $out = [];

        foreach (array_values($value) as $index => $entry) {
            if (false === is_array($entry)) {
                throw new MethodException('invalidProperties', sprintf('"addresses" entry %d must be an object.', $index));
            }

            $data = ['label' => $this->requireLabel($entry['label'] ?? null, 'addresses', $index)];

            foreach (self::ADDRESS_KEYS as $key) {
                if (array_key_exists($key, $entry)) {
                    $data[$key] = $this->optionalString($entry[$key], sprintf('addresses entry %d "%s"', $index, $key));
                }
            }

            $out[] = $data;
        }

        return $out;
    }

    private function requireLabel(mixed $label, string $list, int $index): string
    {
        if (null === $label) {
            return 'other';
        }

        $labels = 'phones' === $list ? self::PHONE_LABELS : self::LABELS;

        if (false === is_string($label) || false === in_array($label, $labels, true)) {
            throw new MethodException('invalidProperties', sprintf(
                '"%s" entry %d "label" must be one of: %s.',
                $list,
                $index,
                implode(', ', $labels),
            ));
        }

        return $label;
    }

    private function requireBool(mixed $value, string $list, int $index, string $key): bool
    {
        if (null === $value) {
            return false;
        }

        if (false === is_bool($value)) {
            throw new MethodException('invalidProperties', sprintf('"%s" entry %d "%s" must be a boolean.', $list, $index, $key));
        }

        return $value;
    }

    private function optionalString(mixed $value, string $field): ?string
    {
        if (null === $value) {
            return null;
        }

        if (false === is_string($value)) {
            throw new MethodException('invalidProperties', sprintf('"%s" must be a string.', $field));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function requireStringList(mixed $value, string $field): array
    {
        if (false === is_array($value)) {
            throw new MethodException('invalidProperties', sprintf('"%s" must be an array of strings.', $field));
        }

        $out = [];

        foreach (array_values($value) as $tag) {
            if (false === is_string($tag)) {
                throw new MethodException('invalidProperties', sprintf('"%s" must be an array of strings.', $field));
            }

            $tag = trim($tag);

            if ('' !== $tag && false === in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return $out;
    }

    private function requireSource(mixed $source): string
    {
        if (false === is_string($source) || false === in_array($source, self::SOURCES, true)) {
            throw new MethodException('invalidProperties', sprintf('"source" must be one of: %s.', implode(', ', self::SOURCES)));
        }

        return $source;
    }

    /**
     * @param array<string,mixed> $properties
     */
    private function rejectUnknownKeys(array $properties): void
    {
        foreach (array_keys($properties) as $key) {
            if (false === in_array((string) $key, self::WRITABLE_KEYS, true)) {
                throw new MethodException('invalidProperties', sprintf(
                    '"%s" is not a writable Contact property. Writable: %s.',
                    $key,
                    implode(', ', self::WRITABLE_KEYS),
                ));
            }
        }
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function rejectUnknownArguments(array $arguments): void
    {
        foreach (array_keys($arguments) as $argument) {
            if (true === in_array((string) $argument, self::ACCEPTED_ARGUMENTS, true)) {
                continue;
            }

            throw new MethodException('invalidArguments', sprintf(
                '"%s" is not an argument of Contact/set. It takes: %s.',
                $argument,
                implode(', ', self::ACCEPTED_ARGUMENTS),
            ));
        }
    }
}
