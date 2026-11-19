<?php

declare(strict_types=1);

namespace App\Service\Mail\SenderPicture;

use App\Domain\DTO\Mail\SenderPictureView;
use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Helper\AddressHelper;
use App\Domain\Helper\SenderPictureReference;
use App\Domain\Helper\SenderPictureSubject;
use App\Entity\User\User;
use App\Repository\Contacts\ContactEmail\ContactEmailRepository;
use App\Repository\Mail\Account\AccountRepository;
use App\Repository\Mail\SenderPicture\SenderPictureRepository;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which picture a sender wears, for the reader looking (#639).
 *
 * **The order**, first match wins: another plMail user's profile picture, a
 * photo on one of the reader's contacts, the domain's BIMI logo, Libravatar or
 * Gravatar, the website's icon — SenderPictureKind's order. The reader's own
 * data outranks anything looked up, and a person outranks their company.
 *
 * **What the reader allows** is applied here, every time, not when the lookup
 * was queued. Lookups are shared by the whole server (SenderPicture), so a
 * Gravatar fetched because one user asked for Gravatar is in the table for
 * everybody, and only this check keeps it off the rows of a user who did not.
 * The administrator's switch is read here too: turning lookups off takes
 * every looked-up picture off every page at once, not only the future ones.
 *
 * **A BIMI logo only on a message that passed the sender check**
 * (Message::$senderVerified). The logo is the domain's claim about who sent
 * the mail; a forged From line must not borrow it. The other sources are
 * about a person or a website and make no such claim.
 *
 * **Never a request.** Everything here is a read of what the background job
 * stored. A sender with nothing stored gets initials now and, if the job
 * finds something, a live update in a second (SenderPictureNotifier).
 *
 * **One query per source per page**, not per row: preload() is called with a
 * page's addresses before its rows are drawn (ThreadRows does), and for() then
 * answers from memory. An address nobody preloaded is loaded on its own when
 * asked — the reading pane's handful of senders, a single redrawn row.
 */
class SenderPictures implements ResetInterface
{
    /** @var array<string, array{userId: int, avatar: string}> */
    private array $localUsers = [];

    /** @var array<string, array{id: int, photoPath: string}> */
    private array $contacts = [];

    /** @var array<string, array<string, array{id: int, file: string}>> kind value => subject => picture */
    private array $stored = [];

    /** @var array<string, true> addresses already asked about */
    private array $loaded = [];

    private ?bool $lookupsEnabled = null;

    public function __construct(
        private readonly Security                        $security,
        private readonly AccountRepository               $accounts,
        private readonly ContactEmailRepository          $contactEmails,
        private readonly SenderPictureRepository         $pictures,
        private readonly SenderPictureSettingsRepository $settings,
    ) {}

    public function reset(): void
    {
        $this->localUsers     = [];
        $this->contacts       = [];
        $this->stored         = [];
        $this->loaded         = [];
        $this->lookupsEnabled = null;
    }

    /**
     * Loads what a page of senders needs in one query per source.
     *
     * @param iterable<?string> $addresses
     */
    public function preload(iterable $addresses): void
    {
        $user = $this->reader();

        if (null === $user) {
            return;
        }

        $emails = [];

        foreach ($addresses as $address) {
            $email = AddressHelper::email($address);

            if ('' !== $email && false === isset($this->loaded[$email])) {
                $emails[$email]        = $email;
                $this->loaded[$email] = true;
            }
        }

        if ([] === $emails) {
            return;
        }

        $emails = array_values($emails);

        $this->localUsers += $this->accounts->profilePicturesByEmail($emails);
        $this->contacts   += $this->contactEmails->photosByEmail($user, $emails);

        if (false === $this->lookupsEnabled()) {
            return;
        }

        $subjectsByKind = [];

        foreach (SenderPictureKind::lookups() as $kind) {
            if (false === $user->allowsSenderPicture($kind)) {
                continue;
            }

            foreach ($emails as $email) {
                $subject = SenderPictureSubject::of($kind, $email);

                if (null !== $subject) {
                    $subjectsByKind[$kind->value][] = $subject;
                }
            }
        }

        foreach ($this->pictures->findFound($subjectsByKind) as $kind => $bySubject) {
            $this->stored[$kind] = ($this->stored[$kind] ?? []) + $bySubject;
        }
    }

    /**
     * The picture for this sender, or null for initials.
     *
     * @param bool $verified whether this message passed the sender check —
     *                       Message::$senderVerified; only that admits BIMI
     */
    public function for(?string $address, bool $verified = false): ?SenderPictureView
    {
        $user  = $this->reader();
        $email = AddressHelper::email($address);

        if (null === $user || '' === $email) {
            return null;
        }

        $this->preload([$email]);

        $local = $this->localUsers[$email] ?? null;

        if (null !== $local) {
            return new SenderPictureView(
                SenderPictureKind::LocalUser,
                SenderPictureReference::for(SenderPictureReference::USER, $local['userId'], $local['avatar']),
            );
        }

        $contact = $this->contacts[$email] ?? null;

        if (null !== $contact) {
            return new SenderPictureView(
                SenderPictureKind::Contact,
                SenderPictureReference::for(SenderPictureReference::CONTACT, $contact['id'], $contact['photoPath']),
            );
        }

        if (false === $this->lookupsEnabled()) {
            return null;
        }

        foreach (SenderPictureKind::lookups() as $kind) {
            if (SenderPictureKind::Bimi === $kind && false === $verified) {
                continue;
            }

            if (false === $user->allowsSenderPicture($kind)) {
                continue;
            }

            $subject = SenderPictureSubject::of($kind, $email);
            $stored  = null === $subject ? null : ($this->stored[$kind->value][$subject] ?? null);

            if (null !== $stored) {
                return new SenderPictureView(
                    $kind,
                    SenderPictureReference::for(SenderPictureReference::STORED, $stored['id'], $stored['file']),
                );
            }
        }

        return null;
    }

    /**
     * What a sender's avatar carries in its markup for the live update to
     * find it by — one key per lookup kind, matching what
     * SenderPictureNotifier sends. Null values are left out of the markup.
     *
     * @return array{address: ?string, domain: ?string, site: ?string}
     */
    public static function markupKeys(?string $address): array
    {
        return [
            'address' => SenderPictureSubject::addressKey($address),
            'domain'  => SenderPictureSubject::of(SenderPictureKind::Bimi, $address),
            'site'    => SenderPictureSubject::of(SenderPictureKind::Website, $address),
        ];
    }

    private function reader(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function lookupsEnabled(): bool
    {
        return $this->lookupsEnabled ??= $this->settings->lookupsEnabled();
    }
}
