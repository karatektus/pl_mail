<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\Label\Label;
use App\Entity\User\User;
use App\Repository\Label\LabelRepository;
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

        foreach ($flags as $flag) {
            if (false === ImapKeyword::isKeyword($flag)) {
                continue;
            }

            $name = ImapKeyword::decode($flag);

            if (null !== $name) {
                $names[$flag] = $name;
            }
        }

        return $names;
    }

    /**
     * The label of this full name for this user; made when $create and it is
     * not there yet, null otherwise.
     */
    public function label(string $fullName, User $user, bool $create): ?Label
    {
        $parent = null;
        $label  = null;

        foreach (explode('/', $fullName) as $segment) {
            if ('' === trim($segment)) {
                continue;
            }

            $label = $this->labels->findOneChildByName($user, $parent, $segment);

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
