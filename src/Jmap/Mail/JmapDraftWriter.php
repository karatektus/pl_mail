<?php

declare(strict_types=1);

namespace App\Jmap\Mail;

use App\Domain\Enum\LabelRole;
use App\Domain\Enum\MessageFlag;
use App\Entity\Account;
use App\Entity\Message;
use App\Jmap\Protocol\Exception\MethodException;
use App\Repository\MailboxRepository;
use App\Service\Imap\MessageThreader;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\MailBodySanitizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a JMAP Email/set "create" object into a persisted plMail draft.
 *
 * This is the same sequence ComposeController::applyAccount() +
 * persistDraft() runs for the web composer — Drafts label, mailbox pointer,
 * sanitised body, threading, thread-label resync — reproduced here because
 * those are private controller methods. Any change to draft semantics has to
 * land in both places until they are extracted into one service.
 */
final class JmapDraftWriter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LabelResolver $labelResolver,
        private readonly MailboxRepository $mailboxRepository,
        private readonly MessageThreader $threader,
        private readonly ThreadLabelSynchronizer $threadLabelSynchronizer,
        private readonly MailBodySanitizer $bodySanitizer,
    ) {
    }

    /**
     * @param array<string,mixed> $create the JMAP Email object being created
     */
    public function create(Account $account, array $create): Message
    {
        $now = new \DateTimeImmutable();

        $message = new Message()
            ->setAccount($account)
            ->setCreatedAt($now)
            ->setSubject($this->stringOrNull($create['subject'] ?? null, 'subject'))
            ->setToAddresses($this->addresses($create['to'] ?? null, 'to'))
            ->setCcAddresses($this->addresses($create['cc'] ?? null, 'cc'))
            ->setBccAddresses($this->addresses($create['bcc'] ?? null, 'bcc'))
            ->setBodyHtml($this->body($create));

        $this->applyReplyContext($message, $create);
        $this->applyAccount($message, $account);
        $this->persistDraft($message, $account, $now);

        return $message;
    }

    /**
     * Mirrors ComposeController::applyAccount(): exactly one Drafts label, and
     * the mailbox pointer aimed at its backing folder.
     */
    private function applyAccount(Message $message, Account $account): void
    {
        $draftsLabel = $this->labelResolver->systemLabel(LabelRole::Drafts, $account);

        $message->addLabel($draftsLabel);
        $message->setMailbox($draftsLabel->bindingFor($account)?->mailbox);
    }

    /**
     * Mirrors ComposeController::persistDraft(). The sanitiser matters: only
     * the sync layer sanitises bodies, so an unsanitised draft renders blank
     * until the sent copy comes back from the provider.
     */
    private function persistDraft(Message $message, Account $account, \DateTimeImmutable $now): void
    {
        $message
            ->setFromAddress($account->getEmail())
            ->setFromName($account->getName())
            ->addFlag(MessageFlag::DRAFT)
            ->setHasAttachments(false)
            ->setSeenAt($message->getSeenAt() ?? $now)
            ->setUpdatedAt($now);

        $this->bodySanitizer->sanitize($message);
        $message->setBodyText($this->plainText($message->getBodyHtml()));

        $this->entityManager->persist($message);

        if (null === $message->getThread()) {
            $this->threader->assignThread($message, $account);
        }

        $this->threader->resyncDraftThreadSubject($message);
        $this->threadLabelSynchronizer->sync($message->getThread());

        $this->entityManager->flush();
    }

    /**
     * @param array<string,mixed> $create
     */
    private function applyReplyContext(Message $message, array $create): void
    {
        $inReplyTo = $create['inReplyTo'] ?? null;

        if (true === is_array($inReplyTo) && count($inReplyTo) > 0) {
            $message->setInReplyTo(array_values(array_map('strval', $inReplyTo)));
        }

        $references = $create['references'] ?? null;

        if (true === is_array($references) && count($references) > 0) {
            $message->setReferences(array_values(array_map('strval', $references)));
        }
    }

    /**
     * JMAP hands the body as bodyValues keyed by the partIds named in
     * htmlBody/textBody. HTML wins when both are supplied, matching the
     * composer, which is HTML-first.
     *
     * @param array<string,mixed> $create
     */
    private function body(array $create): ?string
    {
        $bodyValues = $create['bodyValues'] ?? null;

        if (false === is_array($bodyValues) || 0 === count($bodyValues)) {
            return null;
        }

        $html = $this->valueForParts($bodyValues, $create['htmlBody'] ?? null);

        if (null !== $html) {
            return $html;
        }

        $text = $this->valueForParts($bodyValues, $create['textBody'] ?? null);

        if (null === $text) {
            return null;
        }

        return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * @param array<string,mixed> $bodyValues
     */
    private function valueForParts(array $bodyValues, mixed $parts): ?string
    {
        if (false === is_array($parts)) {
            return null;
        }

        foreach ($parts as $part) {
            if (false === is_array($part)) {
                continue;
            }

            $partId = $part['partId'] ?? null;

            if (false === is_string($partId)) {
                continue;
            }

            $value = $bodyValues[$partId]['value'] ?? null;

            if (true === is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    private function plainText(?string $html): ?string
    {
        if (null === $html || '' === $html) {
            return null;
        }

        $withBreaks = (string) preg_replace('#<br\s*/?>|</p>|</div>#i', "\n", $html);

        return trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * plMail stores addresses as {name, address}; JMAP sends {name, email}.
     *
     * @return list<array{name:?string,address:string}>|null
     */
    private function addresses(mixed $value, string $property): ?array
    {
        if (null === $value) {
            return null;
        }

        if (false === is_array($value)) {
            throw new MethodException('invalidProperties', sprintf('"%s" must be an array of EmailAddress objects.', $property));
        }

        $addresses = [];

        foreach ($value as $entry) {
            if (false === is_array($entry)) {
                throw new MethodException('invalidProperties', sprintf('"%s" must contain EmailAddress objects.', $property));
            }

            $email = $entry['email'] ?? null;

            if (false === is_string($email) || '' === $email) {
                throw new MethodException('invalidProperties', sprintf('Each "%s" entry needs an "email".', $property));
            }

            $name = $entry['name'] ?? null;

            $addresses[] = [
                'name' => is_string($name) && '' !== $name ? $name : null,
                'address' => $email,
            ];
        }

        if (0 === count($addresses)) {
            return null;
        }

        return $addresses;
    }

    private function stringOrNull(mixed $value, string $property): ?string
    {
        if (null === $value) {
            return null;
        }

        if (false === is_string($value)) {
            throw new MethodException('invalidProperties', sprintf('"%s" must be a string.', $property));
        }

        return $value;
    }
}
