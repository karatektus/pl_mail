<?php

declare(strict_types=1);

namespace App\Tests\Service\Template;

use App\Domain\DTO\Template\TemplateToken;
use App\Domain\Enum\Template\TemplateVariable;
use App\Service\Template\TemplateTokens;
use PHPUnit\Framework\TestCase;

/**
 * The notation template variables are written in.
 *
 * The grammar is read twice — here, and by assets/compose/template_variables.js
 * in the browser, which draws the same tokens as chips. The examples below are
 * the ones the JavaScript half is written against; a change to the pattern
 * that makes one of them fail has changed what the editor stores without
 * changing what the editor reads.
 */
final class TemplateTokensTest extends TestCase
{
    public function testABareVariableIsRead(): void
    {
        $seen = $this->read('Hi {{recipient.first_name}},');

        self::assertCount(1, $seen);
        self::assertSame(TemplateVariable::RecipientFirstName, $seen[0]->variable);
        self::assertSame([], $seen[0]->arguments);
    }

    public function testArgumentsAreReadByName(): void
    {
        $seen = $this->read('due {{date|offset=+7d|format=long}}');

        self::assertSame(['offset' => '+7d', 'format' => 'long'], $seen[0]->arguments);
    }

    /**
     * A custom date pattern may contain an equals sign of its own. Splitting on
     * every one would cut the pattern in half and store the first half.
     */
    public function testAnArgumentIsSplitOnItsFirstEqualsSignOnly(): void
    {
        $seen = $this->read("{{date|format=pattern:d=dd 'of' MMMM}}");

        self::assertSame("pattern:d=dd 'of' MMMM", $seen[0]->argument('format'));
    }

    /**
     * The body is HTML, and the sanitiser stores an apostrophe as an entity.
     * Read as text, a quoted literal in a date pattern would reach ICU as
     * `&#39;of&#39;` and be written into the mail.
     */
    public function testArgumentsInHtmlAreDecoded(): void
    {
        $seen = $this->read('{{date|format=pattern:d &#39;of&#39; MMMM}}', html: true);

        self::assertSame("pattern:d 'of' MMMM", $seen[0]->argument('format'));
    }

    /**
     * The guard against eating somebody's prose: braces around a word that is
     * not a variable are text, and stay exactly as typed.
     */
    public function testBracesAroundAnythingElseAreLeftAlone(): void
    {
        $tokens = new TemplateTokens();
        $text   = 'Use {{ curly braces }} and {{unknown.thing}} freely.';

        self::assertSame($text, $tokens->replace($text, static fn (): string => 'REPLACED'));
    }

    public function testAResolverAnsweringNullLeavesTheTokenAsWritten(): void
    {
        $tokens = new TemplateTokens();

        self::assertSame(
            'Hi {{recipient.name}}, from Alex',
            $tokens->replace(
                'Hi {{recipient.name}}, from {{sender.name}}',
                static fn (TemplateToken $token): ?string => TemplateVariable::SenderName === $token->variable ? 'Alex' : null,
            ),
        );
    }

    public function testATokenIsWrittenBackInTheFormItIsReadIn(): void
    {
        $tokens = new TemplateTokens();
        $token  = new TemplateToken(TemplateVariable::Date, ['offset' => '-14d', 'format' => 'iso']);

        self::assertSame('{{date|offset=-14d|format=iso}}', $tokens->write($token));
        self::assertEquals([$token], $this->read($tokens->write($token)));
    }

    /**
     * @return list<TemplateToken>
     */
    private function read(string $text, bool $html = false): array
    {
        $seen = [];

        new TemplateTokens()->replace(
            $text,
            static function (TemplateToken $token) use (&$seen): ?string {
                $seen[] = $token;

                return null;
            },
            $html,
        );

        return $seen;
    }
}
