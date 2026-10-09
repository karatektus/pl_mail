<?php

declare(strict_types=1);

namespace App\Service\Template;

use App\Domain\DTO\Template\RenderedTemplate;
use App\Domain\DTO\Template\TemplateToken;
use App\Domain\Enum\Template\TemplateVariable;

/**
 * Closes a rendered template's recipient variables, for a client that cannot
 * do it itself.
 *
 * THE SECOND IMPLEMENTATION OF ONE RULE, and named as such at both ends. The
 * web compose window fills recipient variables in the browser
 * (assets/compose/template_variables.js#recipientValues), because it is the
 * only thing that sees the To field change and must not put a network request
 * inside a keystroke. A JMAP client is in the opposite position: it already
 * talks to the server for every template it inserts, it has the recipient as
 * data rather than as a chip's text, and asking each native app to re-derive
 * "what is this person's first name" would make three implementations out of
 * two. So `Template/render` takes the recipient and this class answers.
 *
 * What has to stay the same in both, and is asserted on this side by
 * TemplateRecipientFillerTest against the examples the script documents:
 *
 *   - A first name is the first word of the name, or the part after the comma
 *     when the name is written surname-first.
 *   - A name that is really an address is no name.
 *   - NOTHING IS GUESSED FROM THE ADDRESS. A variable the recipient cannot
 *     answer stays open, and the caller is told which.
 *
 * A filled variable becomes plain text — in the body the marker span is
 * replaced by the escaped value, not given one — for the reason the script
 * gives: from then on it is the user's writing.
 */
final readonly class TemplateRecipientFiller
{
    private const string MARKER = '~<span data-pl-var="([a-z_.]+)">[^<]*</span>~';

    public function __construct(
        private TemplateTokens $tokens,
    ) {
    }

    /**
     * @return array<string, string> variable name → value, '' when unknown
     */
    public function values(?string $name, ?string $email): array
    {
        $name  = trim((string) $name);
        $email = trim((string) $email);

        if (true === str_contains($name, '@')) {
            $name = '';
        }

        $comma = strpos($name, ',');
        $given = false === $comma ? $name : trim(substr($name, $comma + 1));
        $full  = false === $comma ? $name : trim(sprintf('%s %s', $given, trim(substr($name, 0, $comma))));

        return [
            TemplateVariable::RecipientFirstName->value => (string) (preg_split('/\s+/u', $given)[0] ?? ''),
            TemplateVariable::RecipientName->value      => $full,
            TemplateVariable::RecipientEmail->value     => $email,
        ];
    }

    public function fill(RenderedTemplate $rendered, ?string $name, ?string $email): RenderedTemplate
    {
        $values = $this->values($name, $email);

        $html = (string) preg_replace_callback(
            self::MARKER,
            static function (array $match) use ($values): string {
                $value = $values[$match[1]] ?? '';

                return '' === $value ? $match[0] : htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            },
            $rendered->html,
        );

        $subject = $this->tokens->replace(
            $rendered->subject,
            static function (TemplateToken $token) use ($values): ?string {
                $value = $values[$token->variable->value] ?? '';

                return '' === $value ? null : $value;
            },
        );

        return new RenderedTemplate($subject, $html);
    }

    /**
     * The recipient variables still open, each once, in the order
     * TemplateVariable declares them.
     *
     * @return list<string>
     */
    public function open(RenderedTemplate $rendered): array
    {
        $found = [];

        if (false !== preg_match_all(self::MARKER, $rendered->html, $matches)) {
            foreach ($matches[1] as $name) {
                $found[$name] = true;
            }
        }

        $this->tokens->replace(
            $rendered->subject,
            static function (TemplateToken $token) use (&$found): ?string {
                if (true === $token->variable->isRecipient()) {
                    $found[$token->variable->value] = true;
                }

                return null;
            },
        );

        $open = [];

        foreach (TemplateVariable::cases() as $variable) {
            if (true === isset($found[$variable->value])) {
                $open[] = $variable->value;
            }
        }

        return $open;
    }

    /**
     * The body as text, open variables written as their tokens — a text body
     * has no span to keep a marker in, and the token is the shape a client
     * looks for in text, exactly as the web window's plain-text mode does.
     */
    public function markersAsTokens(string $html): string
    {
        return (string) preg_replace(self::MARKER, '{{$1}}', $html);
    }
}
