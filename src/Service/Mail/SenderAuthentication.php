<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\SenderVerdict;
use App\Domain\Helper\AddressHelper;
use App\Domain\Helper\DomainHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;

/**
 * Did this message really come from the domain in its From line?
 *
 * plMail does not check SPF, DKIM or DMARC itself and could not: it meets a
 * message after delivery, without the connection it arrived on. The server
 * that received it did check, and wrote what it found into an
 * `Authentication-Results` header (RFC 8601). This class reads that header —
 * and the whole of its job is deciding WHICH such header to believe, because
 * the header is also something a sender can simply write into their own mail.
 *
 * WHOSE WORD COUNTS
 * ─────────────────
 * Each header opens with the name of the server that wrote it. A receiving
 * server is required to remove incoming headers that carry its own name
 * (RFC 8601 §5), so a line under the receiving server's name is that server's.
 * So the question becomes: what is the account's receiving server called?
 *
 *   Gmail      `mx.google.com`. Always, and Google strips forgeries of it.
 *
 *   Microsoft  Exchange Online writes the header WITHOUT a server name, which
 *              leaves nothing to tell its line from one a sender added. So it
 *              is believed only when it is the single Authentication-Results
 *              header on the message; two or more means somebody else wrote
 *              one, and then none is believed.
 *
 *   IMAP       A name on the same registrable domain as the account's IMAP
 *              host: `mx.example.org` for an account on `imap.example.org` is
 *              plainly the same operator. Anything else is not believed, apart
 *              from the few providers in KNOWN_RECEIVERS whose receiving
 *              servers live on another domain than their IMAP host — iCloud
 *              reads mail on `imap.mail.me.com` and signs it `*.icloud.com`.
 *              The
 *              issue that prompted this (#34) weighed learning the name from
 *              the mailbox, or asking for it in a setting, and both were
 *              turned down: a learned name is a guess a patient sender can
 *              influence, and a setting is one more thing to get wrong on the
 *              one feature that fails silently when it is.
 *
 * WHAT COUNTS AS A PASS
 * ─────────────────────
 * `dmarc=pass`, or — for the many domains that publish no DMARC record, where
 * the server reports `dmarc=none` however genuine the mail is — a DKIM or SPF
 * pass for a domain that matches the From domain. An unaligned pass proves
 * only that SOME domain sent the mail, which every spammer's own domain does.
 *
 * The believed lines are judged together, as one set of results. Gmail and
 * Microsoft write everything into one line; iCloud writes a line per method
 * — BIMI, ARC, DMARC, DKIM and SPF each under their own name (#462). Asking
 * each of those lines to pass on its own failed every iCloud message, the BIMI
 * line first, and even after that would have demanded an aligned SPF pass on
 * top of DMARC, which mail sent through a mailing service rarely has. Pooled,
 * the same message gets the same verdict whichever way its server laid it out.
 *
 * What pooling must not lose is the forgery signal of two lines that
 * disagree: one of them is not the server's, and there is no telling which. A
 * message has one DMARC result and one SPF result, so two different ones
 * among the believed lines are a Fail. DKIM is left out of that rule: a
 * message can carry several signatures and one may be broken.
 *
 * Believed lines with no DMARC, DKIM or SPF result at all — only BIMI or ARC,
 * or "none" — say nothing about the sender, and that is Unknown.
 *
 * WHAT THIS IS FOR
 * ────────────────
 * Whether mail may change a calendar without being asked (EventReconciler):
 * an `Unknown` or a `Fail` only ever means "ask the person first".
 *
 * It used to decide whether a filter could file a message into a tab the
 * reader made, too. It no longer does (#405): the reader's own sent mail has
 * no verdict, mail sent through a platform on somebody's behalf rarely aligns,
 * and a filter that silently filed a third of what it matched was worse than
 * the forgery it guarded against — which Spam is for, and filters leave Spam
 * alone (RuleConditions).
 *
 * Nothing here shows a warning or blocks a message.
 */
final readonly class SenderAuthentication
{
    private const string GMAIL_SERVER = 'mx.google.com';

    /**
     * IMAP hosts whose mail is received under a differently-named server.
     * Registrable domain of the IMAP host → registrable domain of the name in
     * the header. Short on purpose: an entry here is a claim about somebody
     * else's infrastructure, and a wrong one is a false "authentic".
     */
    private const array KNOWN_RECEIVERS = [
        'gmail.com'      => 'google.com',
        'googlemail.com' => 'google.com',
        // iCloud (#462): `imap.mail.me.com` for every iCloud, me.com and mac.com
        // address, received and checked under `*.icloud.com` names.
        'me.com'         => 'icloud.com',
    ];

    /** The methods that report on the sender; BIMI and ARC do not. */
    private const array SENDER_METHODS = ['dmarc', 'dkim', 'spf'];

    /** Methods with one result per message: two different ones is a forgery. */
    private const array SINGLE_RESULT_METHODS = ['dmarc', 'spf'];

    public function verdict(Message $message): SenderVerdict
    {
        $fromDomain = DomainHelper::registrableOfAddress(AddressHelper::email($message->fromAddress));

        if (null === $fromDomain) {
            return SenderVerdict::Unknown;
        }

        $believed = $this->believedLines($message);

        if ([] === $believed) {
            return SenderVerdict::Unknown;
        }

        $results = $this->senderResults($believed);

        if ([] === $results) {
            return SenderVerdict::Unknown;
        }

        if (true === $this->disagree($results)) {
            return SenderVerdict::Fail;
        }

        return true === $this->passes($results, $fromDomain) ? SenderVerdict::Pass : SenderVerdict::Fail;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The Authentication-Results lines written by the account's own receiving
     * server — see the class docblock for how that is decided per provider.
     *
     * @return list<string>
     */
    private function believedLines(Message $message): array
    {
        $account = $message->account;
        $raw     = $message->headers['authentication-results'] ?? null;
        $lines   = array_values(array_filter(
            array_map(
                static fn (mixed $line): string => trim((string) preg_replace('/\s+/', ' ', (string) $line)),
                (array) $raw,
            ),
            // Not a line: webklex/php-imap hands a header back as the whole value plus
            // its leading token on its own, so an IMAP message carries
            // ["mx.google.com; dkim=pass …", "mx.google.com"]. Taken for a
            // second line from the believed server, the bare name passed
            // nothing and failed every message, which kept File in category
            // from filing anything at all on an IMAP account. A real line
            // always has a `;` (RFC 8601 — "authserv-id; none" when there is
            // nothing to say) or, nameless from Microsoft, an `=`; a token with
            // neither carries no verdict either way. A library workaround: #82
            // replaces the parsing and removes it with the others.
            static fn (string $line): bool => '' !== $line && 1 === preg_match('/[;=]/', $line),
        ));

        if ([] === $lines) {
            return [];
        }

        if (true === $account->isMicrosoft()) {
            return 1 === count($lines) ? $lines : [];
        }

        return array_values(array_filter(
            $lines,
            fn (string $line): bool => $this->isBelievedServer($this->serverOf($line), $account),
        ));
    }

    /** The name a line opens with, or '' when it opens with a result instead. */
    private function serverOf(string $line): string
    {
        $first = strtolower(trim(explode(';', $line, 2)[0]));

        // "mx.example.org 1" carries a version after the name; a line that
        // opens "spf=pass …" has no name at all.
        $name = explode(' ', $first, 2)[0];

        return true === str_contains($name, '=') ? '' : $name;
    }

    private function isBelievedServer(string $server, Account $account): bool
    {
        if ('' === $server) {
            return false;
        }

        if (true === $account->isGmail()) {
            return self::GMAIL_SERVER === $server;
        }

        $host   = DomainHelper::registrable($account->imapHost);
        $domain = DomainHelper::registrable($server);

        if (null === $host || null === $domain) {
            return false;
        }

        return $domain === $host || $domain === (self::KNOWN_RECEIVERS[$host] ?? null);
    }

    /**
     * Every DMARC, DKIM and SPF result in the believed lines, lower-cased.
     * Each result is "method=result" followed by its properties, and results
     * are separated by semicolons — within a line and, here, across lines.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function senderResults(array $lines): array
    {
        $results = [];

        foreach ($lines as $line) {
            foreach (explode(';', strtolower($line)) as $result) {
                $result = trim($result);

                if (true === in_array($this->methodOf($result), self::SENDER_METHODS, true)) {
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * Two different DMARC results, or two different SPF results: one of the
     * lines they came from is not the receiving server's.
     *
     * @param list<string> $results
     */
    private function disagree(array $results): bool
    {
        $seen = [];

        foreach ($results as $result) {
            $method = (string) $this->methodOf($result);

            if (false === in_array($method, self::SINGLE_RESULT_METHODS, true)
                || 1 !== preg_match('/^[a-z]+\s*=\s*([a-z]+)/', $result, $match)) {
                continue;
            }

            if (isset($seen[$method]) && $seen[$method] !== $match[1]) {
                return true;
            }

            $seen[$method] = $match[1];
        }

        return false;
    }

    /** "dkim" for "dkim=pass header.d=…", null for anything not a result. */
    private function methodOf(string $result): ?string
    {
        return 1 === preg_match('/^([a-z][a-z0-9-]*)\s*=/', $result, $match) ? $match[1] : null;
    }

    /**
     * @param list<string> $results
     */
    private function passes(array $results, string $fromDomain): bool
    {
        foreach ($results as $result) {
            if (1 === preg_match('/^dmarc\s*=\s*pass\b/', $result)) {
                return true;
            }

            if (1 === preg_match('/^dkim\s*=\s*pass\b/', $result)
                && true === $this->aligned($result, ['header.d', 'header.i'], $fromDomain)) {
                return true;
            }

            if (1 === preg_match('/^spf\s*=\s*pass\b/', $result)
                && true === $this->aligned($result, ['smtp.mailfrom', 'smtp.helo'], $fromDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does one of the named properties of this result name the From domain?
     *
     * @param list<string> $properties
     */
    private function aligned(string $result, array $properties, string $fromDomain): bool
    {
        foreach ($properties as $property) {
            if (1 !== preg_match('/\b' . preg_quote($property, '/') . '\s*=\s*"?([^\s;"]+)/', $result, $match)) {
                continue;
            }

            // header.i is "@example.org" or "user@example.org"; smtp.mailfrom
            // may be an address or a bare domain.
            $value  = ltrim($match[1], '@');
            $domain = true === str_contains($value, '@')
                ? DomainHelper::registrableOfAddress($value)
                : DomainHelper::registrable($value);

            if ($domain === $fromDomain) {
                return true;
            }
        }

        return false;
    }
}
