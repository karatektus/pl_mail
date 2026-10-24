<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\ImapEmptyAnswer;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\GetMessagesFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException as ImapRuntimeException;

/**
 * "OK, and nothing" against every other way a command fails.
 *
 * Built from the library's own objects rather than from strings that look
 * like its messages: the whole helper rests on what webklex raises for each
 * case, and a webklex upgrade that changes one of them should fail here.
 */
final class ImapEmptyAnswerTest extends TestCase
{
    public function testACompletedCommandWithNoDataIsAnEmptyAnswer(): void
    {
        self::assertTrue(ImapEmptyAnswer::is($this->emptyAnswer()));
    }

    /** How it actually reaches a caller of Query: wrapped. */
    public function testItIsRecognisedThroughTheExceptionTheQueryWrapsItIn(): void
    {
        $empty = $this->emptyAnswer();

        self::assertTrue(ImapEmptyAnswer::is(new GetMessagesFailedException($empty->getMessage(), 0, $empty)));
    }

    /** Nothing came back because the socket died — which says nothing about the messages. */
    public function testADroppedConnectionIsNotAnAnswer(): void
    {
        $dropped = new ImapRuntimeException('empty response');

        self::assertFalse(ImapEmptyAnswer::is($dropped));
        self::assertFalse(ImapEmptyAnswer::is(new GetMessagesFailedException($dropped->getMessage(), 0, $dropped)));
    }

    public function testARefusalIsNotAnEmptyAnswer(): void
    {
        self::assertFalse(ImapEmptyAnswer::is(new ImapServerErrorException('TAG4 NO [UNAVAILABLE] try again later')));
    }

    /** Empty AND with an error beside it is a failed command, not an empty one. */
    public function testAResponseThatAlsoCarriesAnErrorIsNotAnEmptyAnswer(): void
    {
        $response = Response::empty();
        $response->addError('TAG4 BAD could not parse command');

        self::assertFalse(ImapEmptyAnswer::is(ResponseException::make($response)));
    }

    public function testAnythingElseIsNotOne(): void
    {
        self::assertFalse(ImapEmptyAnswer::is(new \LogicException("Command failed to process:\nCauses:\n\t- Empty response\n")));
    }

    private function emptyAnswer(): ResponseException
    {
        // What FETCH returns when the server sent the tagged OK and no FETCH
        // line: a Response with an empty result, not marked as allowed to be.
        $response = Response::empty()->setResult([]);

        self::assertTrue($response->failed(), 'the library calls an empty FETCH a failure — the premise of the helper');

        return ResponseException::make($response);
    }
}
