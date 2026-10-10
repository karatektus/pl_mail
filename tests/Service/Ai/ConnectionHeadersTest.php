<?php

declare(strict_types=1);
namespace App\Tests\Service\Ai;
use App\Domain\Ai\ConnectionHeaders;
use App\Service\Ai\AiTaskContext;
use App\Service\Ai\OpenAiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ConnectionHeadersTest extends TestCase
{
    public function testEveryHttpPathUsesHeadersAndHonestUserAgentWithoutRedirects(): void
    {
        $calls=[];$stack=new RequestStack();$stack->push(new Request());$context=new AiTaskContext();$context->current=(new \App\Domain\Ai\AiExecutionContext())->id;
        $http=new MockHttpClient(function($method,$url,$options)use(&$calls):MockResponse{
            $calls[]=$options;
            if(str_ends_with($url,'/models'))return new MockResponse('{"data":[]}');
            if(str_ends_with($url,'/embeddings'))return new MockResponse('{"data":[{"index":0,"embedding":[1,2]}]}');
            if(str_contains($options['body'],'"stream":true'))return new MockResponse(["data: {\"choices\":[{\"delta\":{\"content\":\"ok\"}}]}\n\ndata: [DONE]\n\n"]);
            return new MockResponse('{"choices":[{"message":{"content":"ok"}}]}');
        });
        $client=new OpenAiClient($http,$context);$headers=['x-opencode-session'=>['mode'=>'session'],'X-Synthetic-Secret'=>'synthetic-header-value'];
        $client->probe('https://mock.test/v1',headers:$headers);
        $client->chat('https://mock.test/v1','m',[],headers:$headers);
        iterator_to_array($client->chatStream('https://mock.test/v1','m',[],headers:$headers));
        $client->embed('https://mock.test/v1','m','synthetic',headers:$headers);
        self::assertCount(4,$calls);
        foreach($calls as $options){self::assertSame(0,$options['max_redirects']);self::assertContains('User-Agent: plMail',$options['headers']);self::assertContains('x-opencode-session: '.$context->id(),$options['headers']);self::assertContains('X-Synthetic-Secret: synthetic-header-value',$options['headers']);}
        $first=$context->id();$context->current=(new \App\Domain\Ai\AiExecutionContext())->id;self::assertNotSame($first,$context->id());
    }
    public function testTransportAndInjectionHeadersAreRejected(): void
    {
        foreach([['Host'=>'bad'],['Authorization'=>'bad'],['User-Agent'=>'agent'],['X-A'=>'a', 'x-a'=>'b'],['X-A'=>"bad\r\nHost: bad"],['X-A'=>"bad\tvalue"],['bad name'=>'x']] as $headers){
            try{ConnectionHeaders::validate($headers);self::fail('Must reject unsafe headers');}catch(\InvalidArgumentException $error){self::assertNotEmpty($error->getMessage());}
        }
    }
    public function testSharedAndIndependentEmbeddingHeadersAreSeparate(): void
    {
        $s=new \App\Entity\Ai\AiSettings();$s->openAiHeaders='{"X-A":"shared"}';$s->embeddingHeaders='{"X-B":"independent"}';
        self::assertSame(['X-B'=>'independent'],$s->effectiveEmbeddingHeaders());$s->embeddingSharedConnection=true;self::assertSame(['X-A'=>'shared'],$s->effectiveEmbeddingHeaders());
    }
    public function testQueueTaskSurvivesSerializationAndWorkerRestart(): void
    {
        $context=new AiTaskContext();$middleware=new \App\Infrastructure\Messaging\Middleware\AiTaskMiddleware($context);
        $stack=$this->createStub(\Symfony\Component\Messenger\Middleware\StackInterface::class);
        $next=$this->createStub(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);$next->method('handle')->willReturnCallback(static fn($e)=>$e);$stack->method('next')->willReturn($next);
        foreach([new \App\Infrastructure\Messaging\Message\SummariseThreadMessage(1,1),new \App\Infrastructure\Messaging\Message\ClassifyMailMessage([1]),new \App\Infrastructure\Messaging\Message\EmbedMessagesMessage([1]),new \App\Infrastructure\Messaging\Message\BackfillEmbeddingsMessage(1,runId:'synthetic-opaque-run')]as $message){
            $e=$middleware->handle(new \Symfony\Component\Messenger\Envelope($message),$stack);$id=$e->last(\App\Infrastructure\Messaging\Stamp\AiTaskStamp::class)->id;
            $restored=unserialize(serialize($e));$other=new \App\Infrastructure\Messaging\Middleware\AiTaskMiddleware(new AiTaskContext());
            self::assertSame($id,$other->handle($restored,$stack)->last(\App\Infrastructure\Messaging\Stamp\AiTaskStamp::class)->id);self::assertNull($context->current);
        }
        self::assertSame(AiTaskContext::forRun('opaque'),AiTaskContext::forRun('opaque'));self::assertNotSame(AiTaskContext::forRun('opaque'),AiTaskContext::forRun('new'));
    }

    public function testLegacyFailedHandlerCarriesCorrelationWhenDeliveryIdChanges(): void
    {
        $message = new \App\Infrastructure\Messaging\Message\SummariseThreadMessage(1, 1);
        $context = new AiTaskContext('synthetic-installation-secret');
        $middleware = new \App\Infrastructure\Messaging\Middleware\AiTaskMiddleware($context);
        $handlers = new \Symfony\Component\Messenger\Handler\HandlersLocator([
            $message::class => [static function (): void { throw new \RuntimeException('Synthetic retry'); }],
        ]);
        $bus = new \Symfony\Component\Messenger\MessageBus([$middleware, new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware($handlers)]);
        $old = (new \Symfony\Component\Messenger\Envelope($message))->with(new \Symfony\Component\Messenger\Stamp\TransportMessageIdStamp('41'));
        try { $bus->dispatch($old); self::fail('Expected synthetic handler failure'); }
        catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $failure) { $retry = $failure->getEnvelope(); }
        $id = $retry->last(\App\Infrastructure\Messaging\Stamp\AiTaskStamp::class)->id;
        self::assertSame($context->forLegacyDelivery('41'), $id);
        self::assertNotSame($context->forLegacyDelivery('42'), $id);
        self::assertNull($context->current);
        $retry = $retry->withoutAll(\Symfony\Component\Messenger\Stamp\TransportMessageIdStamp::class)->with(new \Symfony\Component\Messenger\Stamp\TransportMessageIdStamp('42'));
        $serializer = new \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer();
        $restored = $serializer->decode($serializer->encode($retry));
        self::assertSame($id, $restored->last(\App\Infrastructure\Messaging\Stamp\AiTaskStamp::class)->id);
    }
}
