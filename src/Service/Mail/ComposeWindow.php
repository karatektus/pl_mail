<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Integration\Capability;
use App\Domain\Helper\AddressHelper;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Mail\AccountRepository;

/**
 * The questions a compose window has to answer that are not about HTTP.
 *
 * Which account it opens on, what the body starts as, which services its file
 * picker can offer, whether the recipients can actually be sent to, and which
 * list row a discard takes with it. All of it used to be private methods on
 * ComposeController, where each one was reachable only through a route — so
 * "what is the default From account" could not be asked, or tested, without
 * building a request.
 *
 * Everything here takes what it needs as an argument, including the user. The
 * controller has a session and this does not, which is the point: these are
 * decisions about a user's mail, not about whoever happens to be signed in.
 */
final readonly class ComposeWindow
{
    public function __construct(
        private AccountRepository     $accounts,
        private IntegrationRepository $integrations,
        private SignatureProvider     $signatures,
    ) {}

    /**
     * The account a new window opens on.
     *
     * Falls back deliberately rather than returning null on a mailbox whose
     * accounts predate the primary flag: an account created by anything other
     * than AccountCreator::create() — a seed, an import, a restore — never gets
     * it, and "no primary" must not read as "no account".
     *
     * The fallback is ORDERED, and that is the whole of why it is written out.
     * Unordered, findOneBy returned whichever row the database felt like, so
     * the From default could differ between two loads of the same window.
     * sortOrder is the right tiebreak rather than an arbitrary one: it IS the
     * user's own arrangement, and isPrimary is derived from position 0 of
     * exactly this ordering (AccountCreator::resequence()).
     */
    public function defaultAccountFor(User $user): ?Account
    {
        $primary = $this->accounts->findOneBy([
            'usr'       => $user,
            'isActive'  => true,
            'isPrimary' => true,
        ]);

        if (null !== $primary) {
            return $primary;
        }

        return $this->accounts->findOneBy(
            [
                'usr'      => $user,
                'isActive' => true,
            ],
            ['sortOrder' => 'ASC'],
        );
    }

    /**
     * The body a brand-new message opens with: somewhere to write, then the
     * signature.
     *
     * Empty when the sender signs with nothing — a lone `<p><br></p>` would be
     * a body that is not empty, and the editor's placeholder ("Write your
     * message…") only shows for a genuinely empty one.
     */
    public function signatureSeed(Account $account): string
    {
        $signature = $this->signatures->blockFor($account, $account->displayAddress);

        return '' === $signature ? '' : '<p><br></p>' . $signature;
    }

    /**
     * Services this user can pull files out of.
     *
     * Download rather than Browse is the test that matters: a service you can
     * list but not fetch from would open a picker that cannot attach anything.
     *
     * @return list<Integration>
     */
    public function pickerIntegrationsFor(User $user): array
    {
        return $this->integrations->findSupportingForUser($user, Capability::Download);
    }

    /**
     * The first recipient on the message that is not a usable address, or null
     * when every one of them is.
     *
     * Cc and Bcc are checked as well as To: a malformed address anywhere in the
     * envelope fails the send at the SMTP layer, and failing it here says which
     * one rather than leaving a bounce to explain it.
     */
    public function firstUnsendableAddress(Message $message): ?string
    {
        $groups = [
            $message->toAddresses ?? [],
            $message->ccAddresses ?? [],
            $message->bccAddresses ?? [],
        ];

        foreach ($groups as $addresses) {
            foreach ($addresses as $entry) {
                $address = (string) ($entry['address'] ?? '');

                if (false === AddressHelper::isValidEmail($address)) {
                    return '' === $address ? '—' : $address;
                }
            }
        }

        return null;
    }

    /**
     * The thread whose list row a discard should take with it, or null to leave
     * every row standing.
     *
     * List rows stand for threads, so an emptied thread loses its row wherever
     * it is shown. The Drafts list is the other case: its rows are there
     * because of a draft, so a conversation that just lost its last one drops
     * out of that view even though the thread lives on.
     *
     * `$draftsScope` is which view is asking, and it has to be asked because
     * the same thread must keep its row in the Inbox. It arrives here as a bool
     * rather than as the request's `scope` parameter: reading the query string
     * is the controller's half of the job, and taking a Request here would have
     * made this the one method in the class that could not be called without
     * one.
     */
    public function rowToDrop(
        ?MessageThread $thread,
        ?int $discardedId,
        int $remaining,
        bool $draftsScope,
    ): ?int {
        if (null === $thread) {
            return null;
        }

        if (0 === $remaining) {
            return $thread->id;
        }

        if (false === $draftsScope) {
            return null;
        }

        foreach ($thread->messages as $message) {
            // The discarded message can still sit in the loaded collection.
            if ($message->id === $discardedId) {
                continue;
            }

            if (true === $message->isDraft()) {
                return null;
            }
        }

        return $thread->id;
    }
}
