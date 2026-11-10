<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\Label\Label;
use App\Entity\User\User;
use App\Repository\Label\Label\LabelRepository;
use App\Service\Imap\ImapKeyword;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The labels a message's IMAP keywords stand for (#221 step 2).
 *
 * The read half of labels-as-keywords: a keyword another client set ("Work"),
 * or one plMail wrote ("Follow&ACA-up"), is the label of that name — found, or
 * made when it is new. Made as a plain label with no binding, which is what a
 * label made in plMail is: a tag, never a folder. System keywords never come
 * through (ImapKeyword::decode()).
 *
 * ## Capitals do not count (#487)
 *
 * IMAP keywords are atoms, and atoms compare without regard to case (RFC 3501
 * §9 and RFC 9051 §9: the grammar is case-insensitive unless a rule says
 * otherwise, and flag-keyword does not). Dovecot keeps the first spelling it
 * saw and answers `+FLAGS (paperless)` on a message that already has
 * `Paperless` with nothing new. So `paperless`, written by Paperless-ngx, is
 * the label **Paperless** the person already has, not a second one beside it.
 *
 * Folders are the other way round, which is why this does not go through
 * LabelRepository::findOneChildByName(): a case-sensitive server may really
 * hold `work` and `Work` as two folders. A keyword never names a folder, so
 * the lookup here is findNameConflict()'s — exact first, then any spelling.
 */
final readonly class KeywordLabels
{
    public function __construct(
        private LabelRepository        $labels,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<string> $flags as stored on the row (keywords among them)
     *
     * @return array<string, string> keyword => label full name, for the keywords that are labels
     */
    public static function namesIn(array $flags): array
    {
        $names = [];
        $seen  = [];

        foreach ($flags as $flag) {
            if (false === ImapKeyword::isKeyword($flag)) {
                continue;
            }

            $name = ImapKeyword::decode($flag);

            // One keyword in two spellings is one keyword; the first is kept.
            if (null !== $name && false === isset($seen[self::key($name)])) {
                $names[$flag]           = $name;
                $seen[self::key($name)] = true;
            }
        }

        return $names;
    }

    /**
     * A name as the comparison sees it: capitals folded, so two spellings of
     * one keyword are one key. For the follower's before/after diff, which
     * must agree with the lookup below or a keyword that only changed its
     * capitals would read as one label taken off and the same one put on.
     */
    public static function key(string $name): string
    {
        return mb_strtolower($name);
    }

    /**
     * The label of this full name for this user; made when $create and it is
     * not there yet, null otherwise. Each segment matches whatever its
     * capitals (see the class note); where two siblings differ only in them,
     * the one spelt exactly like the keyword wins.
     */
    public function label(string $fullName, User $user, bool $create): ?Label
    {
        $parent = null;
        $label  = null;

        foreach (explode('/', $fullName) as $segment) {
            if ('' === trim($segment)) {
                continue;
            }

            $label = $this->labels->findNameConflict($user, $parent, $segment);

            if (null === $label) {
                if (false === $create) {
                    return null;
                }

                $label         = new Label();
                $label->usr    = $user;
                $label->parent = $parent;
                $label->name   = $segment;
                $this->em->persist($label);
                $this->em->flush();
            }

            $parent = $label;
        }

        return $label;
    }
}
