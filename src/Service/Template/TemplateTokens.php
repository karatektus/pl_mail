<?php

declare(strict_types=1);

namespace App\Service\Template;

use App\Domain\DTO\Template\TemplateToken;
use App\Domain\Enum\Template\TemplateVariable;

/**
 * The notation variables are written in: `{{name}}` or
 * `{{name|key=value|key=value}}`.
 *
 * Text, not markup, so one notation serves the HTML body and the plain subject
 * line alike — see MailTemplate for why the body cannot hold a variable as an
 * element. assets/compose/template_variables.js reads and writes the same
 * grammar in the browser, where the editor draws each token as a chip; the two
 * have to agree, and TemplateTokensTest pins the PHP half to the examples the
 * JavaScript half is written against.
 *
 * A token whose name is not a TemplateVariable is not a token. It is left
 * exactly as written.
 */
final readonly class TemplateTokens
{
    /**
     * Name, then any number of `|argument` parts. No braces or pipes inside an
     * argument: that is what lets the pattern stay regular, and no date pattern
     * anyone writes needs either.
     */
    private const string PATTERN = '/\{\{\s*([a-z_.]+)\s*((?:\|[^|{}]*)*)\}\}/';

    /**
     * Replace every variable in `$text` with whatever `$resolve` answers for
     * it; null leaves that token as it stands.
     *
     * `$html` says the text is HTML, where an argument arrives entity-encoded
     * (a pattern with an apostrophe in it is `&#39;` by the time the sanitiser
     * has stored it) and has to be read back as what was meant.
     *
     * @param callable(TemplateToken): ?string $resolve
     */
    public function replace(string $text, callable $resolve, bool $html = false): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            function (array $match) use ($resolve, $html): string {
                $variable = TemplateVariable::tryFrom($match[1]);

                if (null === $variable) {
                    return $match[0];
                }

                $token = new TemplateToken($variable, $this->arguments($match[2], $html));

                return $resolve($token) ?? $match[0];
            },
            $text,
        );
    }

    /** Write a token back out in the canonical form. */
    public function write(TemplateToken $token): string
    {
        $parts = [$token->variable->value];

        foreach ($token->arguments as $key => $value) {
            $parts[] = sprintf('%s=%s', $key, $value);
        }

        return '{{' . implode('|', $parts) . '}}';
    }

    /**
     * @return array<string, string>
     */
    private function arguments(string $raw, bool $html): array
    {
        $arguments = [];

        foreach (explode('|', $raw) as $part) {
            if (true === $html) {
                $part = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            // On the FIRST equals sign: a custom date pattern may hold one.
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $key = trim($key);

            if ('' !== $key) {
                $arguments[$key] = trim($value);
            }
        }

        return $arguments;
    }
}
