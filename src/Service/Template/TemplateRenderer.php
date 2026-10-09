<?php

declare(strict_types=1);

namespace App\Service\Template;

use App\Domain\DTO\Template\RenderedTemplate;
use App\Domain\DTO\Template\TemplateToken;
use App\Domain\Enum\Template\DateFormatPreset;
use App\Domain\Enum\Template\TemplateVariable;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Mail\SignatureProvider;
use App\Service\Mail\UserAccounts;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use IntlDateFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a template into what the composer inserts: every variable the server
 * can answer, answered.
 *
 * WHO FILLS WHAT
 * ──────────────
 * Dates, the sender and the signature are known here: they follow from the
 * clock, the From address and the settings. They are replaced with their
 * values and are ordinary text from then on.
 *
 * The recipient is not known here, and deliberately not asked for. A template
 * is usually picked before anyone has typed an address, so "resolve it now"
 * would mean resolving it to nothing most of the time. Recipient variables
 * leave this class still open — in the body as
 * `<span data-pl-var="recipient.first_name">First name</span>`, in the subject
 * as the token itself — and the compose window fills them when a recipient
 * exists, whether that is already or three minutes later
 * (assets/compose/template_variables.js). One implementation of "what is this
 * person's first name", in the one place that sees the To field change.
 *
 * The span's text is the variable's readable name, so a message sent with one
 * still open says "First name" where the name should be rather than a row of
 * braces. The window asks before sending such a message; this is what is left
 * if the answer is "send it anyway".
 *
 * THE SIGNATURE IS A BLOCK
 * ────────────────────────
 * `{{signature}}` becomes SignatureProvider's `data-pl-signature` block — the
 * same element the composer already owns — so the window's existing rule of
 * one signature per body covers a template for free: inserting replaces the
 * automatic one rather than adding a second. A template may instead name a
 * signature (`account=12`, `alias=7`); that one is pinned, see
 * SignatureProvider::block(). A named signature whose account or alias no
 * longer exists falls back to the automatic one rather than to nothing,
 * because the author asked for a signature.
 *
 * Takes strings, not a MailTemplate, so the editor's preview can render what
 * is on screen without saving it first.
 */
final readonly class TemplateRenderer
{
    public function __construct(
        private TemplateTokens       $tokens,
        private SignatureProvider    $signatures,
        private UserAccounts         $accounts,
        private UserTimezoneResolver $timezones,
        private TranslatorInterface  $translator,
    ) {
    }

    /**
     * @param string|null $address the exact From address in use, which is what
     *                             decides between an alias's signature and the
     *                             account's; null means the account's own
     */
    public function render(
        ?string            $subject,
        string             $body,
        Account            $from,
        ?string            $address,
        User               $user,
        ?DateTimeImmutable $now = null,
    ): RenderedTemplate {
        $now = ($now ?? new DateTimeImmutable())->setTimezone($this->timezones->resolve($user));

        $html = $this->signatureParagraphs($body, $from, $address, $user);

        $html = $this->tokens->replace(
            $html,
            fn (TemplateToken $token): string => match (true) {
                true === $token->variable->isRecipient()       => $this->marker($token->variable),
                TemplateVariable::Signature === $token->variable => $this->signature($token, $from, $address, $user),
                default => htmlspecialchars(
                    (string) $this->value($token, $from, $address, $now),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8',
                ),
            },
            html: true,
        );

        $subject = $this->tokens->replace(
            (string) $subject,
            fn (TemplateToken $token): ?string => match (true) {
                // Left as the token: the window fills it, like the markers.
                true === $token->variable->isRecipient()       => null,
                // A subject line does not carry a signature.
                TemplateVariable::Signature === $token->variable => '',
                default                                        => $this->value($token, $from, $address, $now),
            },
        );

        return new RenderedTemplate(trim($subject), $html);
    }

    /**
     * One date as a template would write it — what the editor shows beside the
     * offset and format controls, so the choice is made looking at a date
     * rather than at a pattern.
     */
    public function dateExample(TemplateToken $token, User $user, ?DateTimeImmutable $now = null): string
    {
        return $this->date(
            $token,
            ($now ?? new DateTimeImmutable())->setTimezone($this->timezones->resolve($user)),
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * A paragraph that holds nothing but a signature variable becomes the
     * signature block itself, rather than a paragraph around one.
     *
     * The block is a <div>, and the natural way to write a template ends in a
     * line of its own for the signature — which the editor stores as
     * `<p>{{signature}}</p>` or `<div>{{signature}}</div>`. A div inside a p
     * is not something HTML has: the browser's parser closes the paragraph
     * early and leaves an empty one behind, so the inserted message would gain
     * a blank line above every signature. A signature in the middle of a
     * sentence still works, through the general pass; it just is not tidy,
     * and it is not what anyone writes.
     */
    private function signatureParagraphs(string $body, Account $from, ?string $address, User $user): string
    {
        return (string) preg_replace_callback(
            '~<(p|div)\b[^>]*>(?:\s|<br\s*/?>)*(\{\{\s*signature\b[^{}]*\}\})(?:\s|<br\s*/?>)*</\1>~i',
            fn (array $match): string => $this->tokens->replace(
                $match[2],
                fn (TemplateToken $token): string => $this->signature($token, $from, $address, $user),
                html: true,
            ),
            $body,
        );
    }

    private function signature(TemplateToken $token, Account $from, ?string $address, User $user): string
    {
        $named = $this->namedSignature($token, $user);

        if (null !== $named) {
            return $this->signatures->block($named, pinned: true);
        }

        return $this->signatures->blockFor($from, $address);
    }

    /**
     * The signature a token names, or null when it names none — or names one
     * that is gone, or one that is empty.
     *
     * Looked up among the user's own accounts only. The id in a token is
     * whatever was posted to the editor, and a template must not become a way
     * to read somebody else's signature by guessing a number.
     */
    private function namedSignature(TemplateToken $token, User $user): ?string
    {
        $accountId = (int) ($token->argument('account') ?? 0);
        $aliasId   = (int) ($token->argument('alias') ?? 0);

        if (0 === $accountId && 0 === $aliasId) {
            return null;
        }

        foreach ($this->accounts->all($user) as $account) {
            if ($accountId === $account->id) {
                return $this->signatures->htmlFor($account, null);
            }

            foreach ($account->aliases as $alias) {
                if ($aliasId === $alias->id) {
                    return $this->signatures->htmlFor($account, $alias->address);
                }
            }
        }

        return null;
    }

    private function marker(TemplateVariable $variable): string
    {
        return sprintf(
            '<span data-pl-var="%s">%s</span>',
            $variable->value,
            htmlspecialchars($this->translator->trans($variable->labelKey()), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }

    /** The plain value of a variable the server answers. */
    private function value(TemplateToken $token, Account $from, ?string $address, DateTimeImmutable $now): ?string
    {
        return match ($token->variable) {
            TemplateVariable::SenderName  => (string) ($from->name ?? ''),
            TemplateVariable::SenderEmail => (string) ($address ?? $from->displayAddress ?? $from->email ?? ''),
            TemplateVariable::Date        => $this->date($token, $now),
            // Never asked: render() routes these four elsewhere. Null leaves
            // the token as written, which is the honest answer to a caller
            // that asks anyway.
            TemplateVariable::RecipientFirstName,
            TemplateVariable::RecipientName,
            TemplateVariable::RecipientEmail,
            TemplateVariable::Signature   => null,
        };
    }

    /**
     * Today, moved by the token's offset and written in its format.
     *
     * An offset or a format that does not parse is ignored rather than
     * refused: the token came out of a contenteditable, and a date that is
     * today in the default format is a better answer to a typo than a template
     * that will not insert.
     */
    private function date(TemplateToken $token, DateTimeImmutable $now): string
    {
        $date = $now;

        if (1 === preg_match('/^([+-]?)(\d{1,4})([dwm])$/', (string) $token->argument('offset'), $match)) {
            $unit = match ($match[3]) {
                'd' => 'day',
                'w' => 'week',
                'm' => 'month',
            };

            $date = $now->modify(sprintf('%s%d %s', '-' === $match[1] ? '-' : '+', (int) $match[2], $unit));
        }

        $format  = (string) ($token->argument('format') ?? '');
        $preset  = DateFormatPreset::tryFrom($format) ?? DateFormatPreset::Long;
        $pattern = true === str_starts_with($format, 'pattern:')
            ? trim(substr($format, 8))
            : $preset->pattern();

        // An empty custom pattern is "no pattern", not "write nothing".
        if ('' === $pattern) {
            $pattern = null;
        }

        $formatter = new IntlDateFormatter(
            $this->translator->getLocale(),
            $preset->intlStyle(),
            IntlDateFormatter::NONE,
            $date->getTimezone(),
            null,
            $pattern,
        );

        $written = $formatter->format($date);

        return false === $written ? $date->format('Y-m-d') : $written;
    }
}
