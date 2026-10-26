<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Entity\Contacts\Contact;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The signature of the newest mail a contact sent this person — where a
 * changed job title, a new number or a move shows up first.
 *
 * FROM THE CONTACT, NOT ABOUT THEM
 * ────────────────────────────────
 * Only mail whose sender is one of the contact's addresses. A mail TO them is
 * this person's own, and its signature is this person's; a mail that merely
 * copies them in is somebody else's. ReferenceEmailFinder answers the wider
 * question for the contact page; this one is narrower on purpose.
 *
 * ONLY THE SIGNATURE
 * ──────────────────
 * What goes to a model is the bottom of the mail and nothing above it: the
 * part after a `-- ` line when there is one, else the part after the closing
 * ("Best regards", "Viele Grüße"). A mail with neither sends nothing — taking
 * "the last few lines" instead would send the whole of a short mail, and the
 * body of somebody's mail is none of the contact editor's business.
 * SIGNATURE_LINES caps what is left.
 */
final readonly class SignatureFinder
{
    private const int SIGNATURE_LINES = 12;
    private const int MAX_CHARS       = 1200;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return array{text: string, receivedAt: ?\DateTimeImmutable, subject: ?string}|null
     */
    public function latestFor(User $user, Contact $contact): ?array
    {
        $addresses = [];
        foreach ($contact->emails as $email) {
            $addresses[] = mb_strtolower(trim($email->email));
        }
        $addresses = array_values(array_unique(array_filter($addresses)));

        if ([] === $addresses) {
            return null;
        }

        /** @var list<Message> $messages */
        $messages = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->join('m.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('LOWER(m.fromAddress) IN (:addresses)')
            ->andWhere('m.vanishedAt IS NULL')
            ->orderBy('m.receivedAt', 'DESC')
            ->setParameter('user', $user)
            ->setParameter('addresses', $addresses)
            // A few, not one: the newest can be a one-line "thanks!" sent
            // from a phone, with no signature at all.
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        foreach ($messages as $message) {
            $text = self::signature($message->bodyText ?? self::textOf($message->bodyHtmlSafe));

            if (null !== $text) {
                return ['text' => $text, 'receivedAt' => $message->receivedAt, 'subject' => $message->subject];
            }
        }

        return null;
    }

    /**
     * The signature block of a plain-text body, or null when there is none
     * worth sending — fewer than two lines left is a sign-off, not a
     * signature.
     */
    public static function signature(?string $body): ?string
    {
        if (null === $body || '' === trim($body)) {
            return null;
        }

        $lines = preg_split('/\R/u', $body) ?: [];

        // Everything from the first quoted reply down is somebody else's mail.
        $own = [];
        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '>')
                || 1 === preg_match('/^(On .+ wrote:|Am .+ schrieb .+:|Le .+ a écrit\s?:|-{2,}\s*(Original Message|Ursprüngliche Nachricht|Message d.origine)\s*-{2,}|_{10,})\s*$/iu', trim($line))) {
                break;
            }
            $own[] = rtrim($line);
        }

        $delimiter = null;
        foreach ($own as $i => $line) {
            if ('-- ' === $line || '--' === $line) {
                $delimiter = $i;
            }
        }

        // No delimiter: the last closing line, and only what is under it.
        if (null === $delimiter) {
            foreach ($own as $i => $line) {
                if (1 === preg_match(ContactTextParser::GREETING, trim($line))) {
                    $delimiter = $i;
                }
            }
        }

        if (null === $delimiter) {
            return null;
        }

        $block = \array_slice($own, $delimiter + 1);

        $block = array_values(array_filter(array_map('trim', $block), static fn (string $l): bool => '' !== $l));
        $block = \array_slice($block, -self::SIGNATURE_LINES);

        if (\count($block) < 2) {
            return null;
        }

        return mb_substr(implode("\n", $block), 0, self::MAX_CHARS);
    }

    private static function textOf(?string $html): ?string
    {
        if (null === $html || '' === trim($html)) {
            return null;
        }

        $text = preg_replace('/<(br|\/p|\/div|\/tr|\/li)[^>]*>/i', "\n", $html) ?? $html;

        return html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
