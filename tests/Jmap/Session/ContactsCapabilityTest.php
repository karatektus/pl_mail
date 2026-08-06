<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Session;

use App\Jmap\Method\Contact\ContactAutocompleteMethod;
use App\Jmap\Method\MethodRegistry;
use App\Jmap\Protocol\Capability;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;

/**
 * Contacts are advertised under a vendor URN, on every account, and by a
 * capability something can actually serve.
 *
 * **Not "urn:ietf:params:jmap:contacts".** RFC 8621 has no Contacts section and
 * the JMAP Contacts draft describes an AddressBook of ContactCards a client may
 * create, update and destroy. plMail's `contact` table is written only by the
 * header harvest and has no /set of any kind, so claiming the standard URN
 * would advertise a writable address book and send the first client that
 * believed it at methods that do not exist. Push and calendars made the same
 * call, and this test asserts the vendor spelling rather than "some contacts
 * capability" — a well-meaning move to the standard URN is the edit it exists
 * to stop.
 *
 * The second claim is the deliberate difference from calendars. Both are
 * user-scoped, and calendars are nevertheless served from exactly one account
 * because a client keys every object by (accountId, id) and would otherwise
 * draw one calendar per connected account. Contact/autocomplete returns no ids
 * and no objects, so there is nothing to draw twice — and restricting it would
 * fail a client composing from its second account for no benefit at all.
 */
final class ContactsCapabilityTest extends JmapTestCase
{
    private SessionBuilder $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = self::getContainer()->get(SessionBuilder::class);
    }

    public function testTheSessionAdvertisesTheVendorContactsUrn(): void
    {
        $session = $this->sessions->build($this->user);

        self::assertArrayHasKey('urn:plmail:params:jmap:contacts', $session['capabilities']);
        self::assertArrayNotHasKey(
            'urn:ietf:params:jmap:contacts',
            $session['capabilities'],
            'the standard URN promises an address book a client may write to',
        );
    }

    /** A capability a client may not declare in "using" is a capability it cannot call. */
    public function testAClientMayDeclareItInUsing(): void
    {
        self::assertContains(Capability::CONTACTS, Capability::SUPPORTED);
    }

    /**
     * And a capability nothing can serve is worse than no capability, because a
     * client discovers it by calling. Methods reach the registry by DI tag, so
     * a class that exists, compiles and is fully tested is still unreachable if
     * autoconfiguration stops applying to its directory — which shows up as
     * unknownMethod at runtime and nowhere in a suite that calls the class
     * directly. `src/Jmap/Method/Contact/` is a new directory, so this is the
     * first thing that would have gone wrong.
     */
    public function testTheAdvertisedMethodIsRegistered(): void
    {
        $registry = self::getContainer()->get(MethodRegistry::class);

        self::assertNotNull($registry->get('Contact/autocomplete'), '"Contact/autocomplete" is advertised but not registered');
    }

    /**
     * The limits are stated rather than discovered: a client that has to find
     * the cap by watching a list stop growing will conclude the address book is
     * 50 people long.
     */
    public function testTheLimitsAreStatedInTheCapability(): void
    {
        $contacts = $this->sessions->build($this->user)['capabilities'][Capability::CONTACTS];

        self::assertSame(ContactAutocompleteMethod::MAX_LIMIT, $contacts['maxSuggestions']);
        self::assertSame(ContactAutocompleteMethod::DEFAULT_LIMIT, $contacts['defaultSuggestions']);
    }

    /**
     * Every account, unlike calendars. A client composing from any connected
     * account must be able to ask the account it is composing from.
     */
    public function testEveryAccountAdvertisesContacts(): void
    {
        $second = $this->secondAccount();

        $session = $this->sessions->build($this->user);

        foreach ([$this->accountId(), (string) $second->id] as $accountId) {
            self::assertArrayHasKey(
                Capability::CONTACTS,
                $session['accounts'][$accountId]['accountCapabilities'],
                sprintf('account %s cannot serve autocomplete it is entitled to', $accountId),
            );
        }

        self::assertArrayNotHasKey(
            Capability::CALENDARS,
            $session['accounts'][(string) $second->id]['accountCapabilities'],
            'calendars stay on one account; that difference is the point',
        );
    }

    /** A client that just wants somewhere to ask is told where. */
    public function testTheSessionNamesAContactsAccount(): void
    {
        $session = $this->sessions->build($this->user);

        self::assertSame($this->accountId(), $session['primaryAccounts'][Capability::CONTACTS]);
    }
}
