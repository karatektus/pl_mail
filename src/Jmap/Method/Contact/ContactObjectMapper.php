<?php

declare(strict_types=1);

namespace App\Jmap\Method\Contact;

use App\Entity\Contacts\Contact;
use App\Jmap\Protocol\Exception\MethodException;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Routing\RouterInterface;

/**
 * Turns a Contacts Contact entity into the JMAP ContactObject the
 * Contact/* methods hand out, and filters one down to a requested
 * "properties" list.
 *
 * Not a JMAP method itself — it implements nothing and is deliberately not
 * registered with the app.jmap_method tag, so the MethodRegistry never sees
 * it. It exists so Contact/get, Contact/set and the autocomplete blending
 * all spell the same object the same way.
 */
final class ContactObjectMapper
{
    public function __construct(
        private readonly RouterInterface $router,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(Contact $contact): array
    {
        $emails = [];

        foreach ($contact->emails as $email) {
            $emails[] = [
                'email' => $email->email,
                'label' => $email->label,
                'isPrimary' => $email->isPrimary,
            ];
        }

        $phones = [];

        foreach ($contact->phones as $phone) {
            $phones[] = [
                'phone' => $phone->phone,
                'label' => $phone->label,
                'isPrimary' => $phone->isPrimary,
            ];
        }

        $addresses = [];

        foreach ($contact->addresses as $address) {
            $addresses[] = [
                'label' => $address->label,
                'street' => $address->street,
                'city' => $address->city,
                'region' => $address->region,
                'postcode' => $address->postcode,
                'country' => $address->country,
            ];
        }

        return [
            'id' => (string) $contact->id,
            'displayName' => $contact->displayName,
            'givenName' => $contact->givenName,
            'familyName' => $contact->familyName,
            'nickname' => $contact->nickname,
            'company' => $contact->company,
            'jobTitle' => $contact->jobTitle,
            'notes' => $contact->notes,
            'tags' => array_values($contact->tags),
            'photoUrl' => $this->photoUrl($contact),
            'emails' => $emails,
            'phones' => $phones,
            'addresses' => $addresses,
            'updatedAt' => $this->utcOrNull($contact->updatedAt),
        ];
    }

    /**
     * Narrows a ContactObject to the requested "properties". A name nobody
     * sent is refused rather than ignored: answering a properties list that
     * silently dropped an entry would let a client believe the object simply
     * has no such property.
     *
     * @param array<string,mixed> $object
     * @param list<string>|null   $properties
     *
     * @return array<string,mixed>
     */
    public function filterProperties(array $object, ?array $properties): array
    {
        if (null === $properties) {
            return $object;
        }

        foreach ($properties as $property) {
            if (false === is_string($property) || false === array_key_exists($property, $object)) {
                throw new MethodException('invalidProperties', sprintf(
                    'Unknown Contact property "%s". Known properties: %s.',
                    (string) $property,
                    implode(', ', array_keys($object)),
                ));
            }
        }

        return array_intersect_key($object, array_flip($properties));
    }

    /**
     * The photo is served by an authenticated controller route, so the URL
     * handed out is absolute — a relative one would resolve against the
     * JMAP endpoint's path and 404 on every client.
     */
    private function photoUrl(Contact $contact): ?string
    {
        if (null === $contact->photoPath) {
            return null;
        }

        return $this->router->generate(
            'jmap_contacts_photo_serve',
            ['contactId' => (string) $contact->id],
            RouterInterface::ABSOLUTE_URL,
        );
    }

    /**
     * The same UTC spelling every other JMAP method here uses for dates.
     */
    private function utcOrNull(?DateTimeImmutable $date): ?string
    {
        if (null === $date) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
