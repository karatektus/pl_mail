<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageLocation;
use App\Entity\Mail\MessagePart;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Id walks for backfills and upgrade tasks, and the finders that say which stored
 * messages a task still has to fix.
 */
class MessageBackfillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Mail in id order, a batch at a time, optionally only what arrived since
     * a given moment — the walk a backfill that re-reads mail makes.
     *
     * Keyset on the id rather than OFFSET, so the cost of a batch does not
     * grow with how far the walk has got.
     *
     * @return list<Message>
     */
    public function receivedSince(?DateTimeImmutable $since, int $afterId, int $limit): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults(max(1, $limit));

        if (null !== $since) {
            $qb->andWhere('m.receivedAt >= :since')->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        return $qb->getQuery()->getResult();
    }

    public function countReceivedSince(?DateTimeImmutable $since): int
    {
        $qb = $this->createQueryBuilder('m')->select('COUNT(m.id)');

        if (null !== $since) {
            $qb->where('m.receivedAt >= :since')->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Ids of messages that have a header bag, oldest first — the backfill
     * cursor for header normalisation.
     *
     * QueryBuilder because this is keyset pagination over a projection:
     * findBy() can neither express `id > :afterId` nor return bare ids, and
     * hydrating a batch of entities to read one column off each is exactly the
     * cost a backfill cursor exists to avoid.
     *
     * @return list<int>
     */
    public function findIdsWithHeaders(int $afterId, int $limit): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.headers IS NOT NULL')
            ->andWhere('m.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /**
     * Ids of every message, oldest first — the backfill cursor for tasks that
     * rewrite a column present on every row (address normalisation).
     *
     * Keyset over a projection, for the reason findIdsWithHeaders() gives.
     *
     * @return list<int>
     */
    public function findIdsAfter(int $afterId, int $limit): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /**
     * Every message id of an account, for a task that has to walk all of them.
     *
     * Ids rather than entities, and by arrival when the caller is replaying
     * history: the rethread backfill clears the EntityManager on every batch,
     * which would invalidate a cursor held across it.
     *
     * QueryBuilder for the projection — findBy() returns entities, which is the
     * one thing this must not do.
     *
     * @return list<int>
     */
    public function findAllIdsForAccount(int $accountId, bool $orderByArrival = false): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.account = :accountId')
            ->setParameter('accountId', $accountId);

        if (true === $orderByArrival) {
            $qb->orderBy('m.receivedAt', 'ASC');
        }

        $qb->addOrderBy('m.id', 'ASC');

        return array_map(
            static fn(array $row): int => (int) $row['id'],
            $qb->getQuery()->getArrayResult(),
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Message>
     */
    /**
     * One page of a user's message ids, ascending, for a walk that has to
     * survive being interrupted.
     *
     * Ascending id rather than date because it is the one ordering nothing can
     * change underneath a walk that takes hours: mail arriving during it gets a
     * higher id and is met by the pass still coming, and mail deleted during it
     * is simply absent. A date cursor would have to cope with both, and with
     * two messages sharing a timestamp.
     *
     * Scoped through the account, which is where ownership lives — a message
     * belongs to a user only by way of one.
     *
     * @return list<int>
     */
    public function idsForUserAfter(int $userId, ?int $afterId, int $limit): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT m.id
                  FROM message m
                  JOIN account a ON a.id = m.account_id
                 WHERE a.usr_id = :userId
                   AND (:afterId::int IS NULL OR m.id > :afterId::int)
                 ORDER BY m.id ASC
                 LIMIT :limit
            SQL,
            ['userId' => $userId, 'afterId' => $afterId, 'limit' => $limit],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * One page of an account's message ids that sit in a folder, ascending,
     * for a walk that re-dispatches itself page by page
     * (ImportImapKeywordsHandler). Keyset on id for the reason
     * idsForUserAfter() gives.
     *
     * @return list<int>
     */
    public function idsInFoldersForAccountAfter(int $accountId, int $afterId, int $limit): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.account = :accountId')
            ->andWhere('m.mailbox IS NOT NULL')
            ->andWhere('m.id > :afterId')
            ->setParameter('accountId', $accountId)
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /**
     * Messages with an HTML body but no sanitized copy — the safe-html
     * backfill's cursor.
     *
     * QueryBuilder because `bodyHtml <> ''` and `id > :afterId` are comparisons
     * findBy() cannot state. Keyset by id rather than a shrinking IS NULL
     * cursor so the scan cannot loop on rows the sanitizer legitimately leaves
     * null (whitespace-only bodies).
     *
     * @return list<Message>
     */
    public function findPendingSafeHtml(int $afterId, int $limit): array
    {
        return $this->pendingSafeHtmlQueryBuilder($afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Counted through the same builder, so the total and the walk agree. */
    public function countPendingSafeHtml(): int
    {
        return (int) $this->pendingSafeHtmlQueryBuilder(0)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Shared so the count and the walk can never disagree about the set. */
    private function pendingSafeHtmlQueryBuilder(int $afterId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id > :afterId')
            ->andWhere('m.bodyHtml IS NOT NULL')
            ->andWhere("m.bodyHtml <> ''")
            ->andWhere('m.bodyHtmlSafe IS NULL')
            ->setParameter('afterId', $afterId);
    }

    /**
     * Messages whose HTML body carries a charset declaration of its own — the
     * set the safe-html re-tag repair has to walk.
     *
     * bodyHtmlSafe is derived, so a row mangled by a declaration the parser
     * believed is repaired by sanitising it again; bodyHtml itself was never
     * wrong. Narrowed by the substring rather than the damage because the
     * damage is not something SQL can recognise, and re-sanitising a row that
     * was already right produces the same bytes it already has.
     *
     * @return list<Message>
     */
    public function findWithHtmlCharsetDeclaration(int $afterId, int $limit): array
    {
        return $this->htmlCharsetDeclarationQueryBuilder($afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Counted through the same builder, so the total and the walk agree. */
    public function countWithHtmlCharsetDeclaration(): int
    {
        return (int) $this->htmlCharsetDeclarationQueryBuilder(0)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Shared so the count and the walk can never disagree about the set. */
    private function htmlCharsetDeclarationQueryBuilder(int $afterId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id > :afterId')
            ->andWhere('m.bodyHtml IS NOT NULL')
            ->andWhere("m.bodyHtml <> ''")
            // LIKE is case-sensitive in Postgres and the tag is written every
            // way there is, so the comparison is folded rather than the tag
            // guessed at.
            ->andWhere("LOWER(m.bodyHtml) LIKE '%charset%'")
            ->setParameter('afterId', $afterId);
    }

    /**
     * Messages whose HTML body holds a form, or a stylesheet that says
     * `!important` — the set the hidden-card repair has to walk (#271).
     *
     * The sanitizer dropped a form with everything in it, and the inliner let
     * an inline `display:none` beat a stylesheet `!important`. Neither leaves a
     * mark in bodyHtmlSafe that SQL could find, because what is missing is
     * simply not there, so the walk is narrowed on the cause in the sender's
     * own copy. Loosely, as the table-link walk is: `!important` anywhere after
     * a `<style>` is far more mail than was hidden, and re-sanitising the rest
     * writes back what the sanitizer makes of it today.
     *
     * @return list<Message>
     */
    public function findWithFormOrImportantRule(int $afterId, int $limit): array
    {
        return $this->formOrImportantRuleQueryBuilder($afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Counted through the same builder, so the total and the walk agree. */
    public function countWithFormOrImportantRule(): int
    {
        return (int) $this->formOrImportantRuleQueryBuilder(0)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Shared so the count and the walk can never disagree about the set. */
    private function formOrImportantRuleQueryBuilder(int $afterId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id > :afterId')
            ->andWhere('m.bodyHtml IS NOT NULL')
            // Folded: tags and the keyword are written in every case there is.
            ->andWhere("(LOWER(m.bodyHtml) LIKE '%<form%' OR LOWER(m.bodyHtml) LIKE '%<style%!important%')")
            ->setParameter('afterId', $afterId);
    }

    /**
     * Messages whose HTML body has a table somewhere after a link opens — the
     * set the table-in-a-link repair has to walk.
     *
     * The damage is a link emptied by the old inliner's parser (see
     * Html5CssInliner), and like the charset one it is not something SQL can
     * recognise in bodyHtmlSafe: what is left is an `<a>` with nothing in it,
     * which is also what a sender's tracking anchor looks like. So the walk is
     * narrowed on the cause, in the sender's own copy, and deliberately
     * loosely — "a table after a link" rather than "a table inside one",
     * which would take a parser to decide. Re-sanitising a body that was never
     * affected writes back what it already had.
     *
     * @return list<Message>
     */
    public function findWithTableAfterLink(int $afterId, int $limit): array
    {
        return $this->tableAfterLinkQueryBuilder($afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Counted through the same builder, so the total and the walk agree. */
    public function countWithTableAfterLink(): int
    {
        return (int) $this->tableAfterLinkQueryBuilder(0)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Shared so the count and the walk can never disagree about the set. */
    private function tableAfterLinkQueryBuilder(int $afterId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id > :afterId')
            ->andWhere('m.bodyHtml IS NOT NULL')
            // Folded for the reason the charset walk folds: tags are written
            // in every case there is.
            ->andWhere("LOWER(m.bodyHtml) LIKE '%<a %<table%'")
            ->setParameter('afterId', $afterId);
    }

    /**
     * Messages whose sanitized body shows an Outlook conditional-comment
     * marker as text — the set the conditional-comment repair has to walk.
     *
     * Unlike the charset and table-link damage, this one SQL can recognise in
     * bodyHtmlSafe itself: a marker that reached the sanitizer as text comes
     * out escaped, `[endif]--&gt;` or `[if mso]&gt;`, and the sanitizer never
     * writes a comment, so a body that was rendered right holds neither. Folded
     * because Outlook writes `[ENDIF]` as often as `[endif]`. Narrowed on the
     * damage rather than on the cause, because the cause — a conditional
     * comment in bodyHtml — is in a large part of all commercial mail, nearly
     * none of which was affected (#175).
     *
     * @return list<Message>
     */
    public function findWithConditionalCommentText(int $afterId, int $limit): array
    {
        return $this->conditionalCommentTextQueryBuilder($afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Counted through the same builder, so the total and the walk agree. */
    public function countWithConditionalCommentText(): int
    {
        return (int) $this->conditionalCommentTextQueryBuilder(0)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Shared so the count and the walk can never disagree about the set. */
    private function conditionalCommentTextQueryBuilder(int $afterId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id > :afterId')
            ->andWhere('m.bodyHtml IS NOT NULL')
            ->andWhere('m.bodyHtmlSafe IS NOT NULL')
            ->andWhere("(LOWER(m.bodyHtmlSafe) LIKE '%[endif]%' OR LOWER(m.bodyHtmlSafe) LIKE '%[if %]&gt;%')")
            ->setParameter('afterId', $afterId);
    }

    /**
     * Messages whose body webklex filed as an attachment, oldest id first.
     *
     * The set MisfiledBodyDetector now keeps out of the database, found after
     * the fact. Two conditions, and each carries half the confidence:
     *
     * `multipart/*` IS IN THE LIST, and it is what the real reports turned out
     * to be. webklex hands over a whole `multipart/related` when it holds a
     * single part, so the row is not a text body at all but the MIME block
     * around one — see MisfiledBodyUnpacker. Selecting only text/plain and
     * text/html found nothing on the installation that reported this.
     *
     * A FILENAME OF EXACTLY EIGHT CHARACTERS, because that is not a filename.
     * Webklex fills an absent one with `hash("crc32c", …)` of the part, and
     * eight is what that hash measures. The hex-ness is checked in PHP rather
     * than here — a LIKE pattern for it is eight repetitions of a character
     * class and unreadable, and this predicate is only narrowing the walk.
     *
     * AN EMPTY BODY OF THE PART'S OWN TYPE, which is what makes the first
     * condition safe to act on. A nameless text part next to a body that
     * parsed fine is something else and is left alone; a nameless text part
     * next to nothing at all is the body, and the message currently renders
     * blank either way.
     *
     * EXISTS rather than a join, and that is not a preference. A
     * multipart/alternative can have both halves misfiled, so a join returns
     * the message once per matching part — and the obvious fix, SELECT
     * DISTINCT, cannot run at all here: Message carries json columns and
     * Postgres has no equality operator for json, so the query dies with
     * "could not identify an equality operator for type json". EXISTS asks the
     * question without multiplying the rows, so there is nothing to
     * de-duplicate.
     *
     * @return list<Message>
     */
    public function findWithMisfiledBodyPart(int $afterId, int $limit): array
    {
        return $this->misfiledBodyPartQueryBuilder($afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The first message stored at or after $since, or null if there is none.
     *
     * Where MessageArrivalBackfillTask starts walking. Asked once, because
     * `created_at` has no index and this reads the table; the walk itself is
     * by primary key from here.
     */
    public function firstIdStoredSince(DateTimeImmutable $since): ?int
    {
        $id = $this->createQueryBuilder('m')
            ->select('MIN(m.id)')
            ->andWhere('m.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $id ? null : (int) $id;
    }

    /**
     * Messages after $afterId that have no provider accept time yet, in id
     * order. Not every one of them can be given one — a draft has no
     * `Received:` header — so the caller advances by id and not by result.
     *
     * @return list<Message>
     */
    public function findWithoutAcceptTime(int $afterId, int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id > :afterId')
            ->andWhere('m.providerAcceptedAt IS NULL')
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Counted through the same builder, so the total and the walk agree. */
    public function countWithMisfiledBodyPart(): int
    {
        return (int) $this->misfiledBodyPartQueryBuilder(0)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * IMAP messages after $afterId whose original bytes are on disk, in id
     * order: the set RepeatedHeaderBackfillTask reads.
     *
     * Every one of them, not the ones whose body looks wrong. A repeated
     * header block is only visible in body_html for a single-part message; a
     * multipart one renders correctly and carries the doubled block in its
     * .eml all the same, and only reading the file finds it.
     *
     * @return list<Message>
     */
    public function findImapWithStoredRaw(int $afterId, int $limit): array
    {
        return $this->imapWithStoredRawQueryBuilder($afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countImapWithStoredRaw(): int
    {
        return (int) $this->imapWithStoredRawQueryBuilder(0)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Shared so the count and the walk can never disagree about the set. */
    private function imapWithStoredRawQueryBuilder(int $afterId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id > :afterId')
            ->andWhere('EXISTS (SELECT l.id FROM ' . MessageLocation::class . ' l WHERE l.message = m AND l.imapUid IS NOT NULL)')
            ->andWhere('m.rawPath IS NOT NULL')
            ->setParameter('afterId', $afterId);
    }

    /** Shared so the count and the walk can never disagree about the set. */
    private function misfiledBodyPartQueryBuilder(int $afterId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id > :afterId')
            ->andWhere(
                'EXISTS ('
                . ' SELECT p.id FROM ' . MessagePart::class . ' p'
                . ' WHERE p.message = m'
                // Sealed parts are a sealed draft's encrypted attachments,
                // never a misfiled body — see MisfiledBodyBackfillTask.
                . ' AND p.sealed = false'
                . " AND (p.contentType IN (:bodyTypes) OR p.contentType LIKE 'multipart/%')"
                . ' AND LENGTH(p.filename) = 8'
                . " AND ((p.contentType = 'text/html' AND (m.bodyHtml IS NULL OR m.bodyHtml = ''))"
                . "   OR (p.contentType = 'text/plain' AND (m.bodyText IS NULL OR m.bodyText = ''))"
                // A container can yield both bodies at once, so it only counts
                // when there is nothing to overwrite in either.
                . "   OR (p.contentType LIKE 'multipart/%'"
                . "       AND (m.bodyHtml IS NULL OR m.bodyHtml = '')"
                . "       AND (m.bodyText IS NULL OR m.bodyText = '')))"
                . ')'
            )
            ->setParameter('afterId', $afterId)
            ->setParameter('bodyTypes', ['text/plain', 'text/html']);
    }

    /**
     * Every raw-message path still pointed at by a row, as a lookup set — the
     * "keep" list for the blob sweep.
     *
     * One streamed sequential scan rather than a batched IN() per thousand
     * files: raw_path is not indexed, and indexing it to serve a maintenance
     * command would tax every write to buy nothing the rest of the year. The
     * LIKE keeps provider-scheme values (gmail://, msgraph://) out of the set,
     * since those name no local file.
     *
     * @return array<string, true>
     */
    public function findReferencedRawPaths(string $pathPrefix): array
    {
        return $this->referencedPathSet('SELECT raw_path FROM message WHERE raw_path LIKE :prefix', $pathPrefix);
    }

    /**
     * @return array<string, true>
     */
    private function referencedPathSet(string $sql, string $pathPrefix): array
    {
        $referenced = [];

        $result = $this->getEntityManager()->getConnection()->executeQuery($sql, ['prefix' => $pathPrefix.'/%']);

        foreach ($result->iterateColumn() as $path) {
            $referenced[$path] = true;
        }

        return $referenced;
    }

    /** How many rows findImapCopiesCarried() would page through. */
    /**
     * Spam that is in the archive: mail wearing Spam that also wears Archive or
     * has a copy in the server's Archive folder. What a "Mark as spam" filter
     * left behind while it archived on the server instead of moving to Junk —
     * see SpamInArchiveBackfillTask. Keyset-paged by id.
     *
     * @return list<Message>
     */
    public function findSpamInArchive(int $afterId, int $limit): array
    {
        return $this->spamInArchive($this->createQueryBuilder('m'))
            ->andWhere('m.id > :after')
            ->setParameter('after', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countSpamInArchive(): int
    {
        return (int) $this->spamInArchive($this->createQueryBuilder('m')->select('COUNT(m.id)'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function spamInArchive(QueryBuilder $qb): QueryBuilder
    {
        // EXISTS rather than joins: a message wears each label once but may
        // have several copies, and a join would count it once per copy.
        return $qb
            ->where('EXISTS (SELECT 1 FROM ' . Label::class . ' sl WHERE sl MEMBER OF m.labels AND sl.role = :spam)')
            ->andWhere($qb->expr()->orX(
                'EXISTS (SELECT 1 FROM ' . Label::class . ' al WHERE al MEMBER OF m.labels AND al.role = :archive)',
                'EXISTS (SELECT 1 FROM ' . MessageLocation::class . ' loc JOIN loc.mailbox lmb WHERE loc.message = m AND lmb.specialUse = :archiveFolder)',
            ))
            ->setParameter('spam', LabelRole::Spam)
            ->setParameter('archive', LabelRole::Archive)
            ->setParameter('archiveFolder', MailboxSpecialUse::ARCHIVE);
    }
}
