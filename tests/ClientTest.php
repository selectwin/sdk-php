<?php

declare(strict_types=1);

namespace Selectwin\Test;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Selectwin\Exception\CardException;
use Selectwin\Exception\NotFoundException;
use Selectwin\SelectwinClient;

final class ClientTest extends TestCase
{
    private const KEY = 'sk_test_abc';
    private const CUST = ['firstName' => 'Ana', 'lastName' => 'Silva', 'email' => 'a@b.co'];

    /** @return array{0: SelectwinClient, 1: MockHandler} */
    private function client(array $responses, array $opts = []): array
    {
        $mock = new MockHandler($responses);
        $sw = new SelectwinClient(self::KEY, ['handler' => $mock] + $opts);
        return [$sw, $mock];
    }

    private function jsonResponse(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** Success-body parsing needs the full generated model; tolerate it here. */
    private function ignoreParse(callable $fn): void
    {
        try {
            $fn();
        } catch (CardException | NotFoundException $e) {
            throw $e;
        } catch (\Throwable) {
            // ignore
        }
    }

    public function testSendsSelectkeyHeaderAndUserAgent(): void
    {
        [$sw, $mock] = $this->client([$this->jsonResponse(200, ['id' => 'cus_1'])]);
        $this->ignoreParse(fn () => $sw->customers->retrieve('cus_1'));
        $req = $mock->getLastRequest();
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame(self::KEY, $req->getHeaderLine('selectkey'));
        $this->assertStringStartsWith('selectwin-php/', $req->getHeaderLine('User-Agent'));
    }

    public function testAddsIdempotencyKeyAndJsonBodyOnMutation(): void
    {
        [$sw, $mock] = $this->client([$this->jsonResponse(201, ['id' => 'cus_1'])]);
        $this->ignoreParse(fn () => $sw->customers->create(self::CUST));
        $req = $mock->getLastRequest();
        $this->assertSame(self::KEY, $req->getHeaderLine('selectkey'));
        $idem = $req->getHeaderLine('X-Idempotency-Key');
        $this->assertSame(32, strlen($idem));
        $this->assertStringContainsString('a@b.co', (string) $req->getBody());
    }

    public function testMaps402ToCardException(): void
    {
        [$sw] = $this->client([
            $this->jsonResponse(402, ['error' => [
                'code' => 'card_declined',
                'message' => 'no',
                'displayMessage' => 'Recusado',
                'reversible' => true,
            ]]),
        ]);
        try {
            $sw->customers->create(self::CUST);
            $this->fail('expected CardException');
        } catch (CardException $e) {
            $this->assertSame('card_declined', $e->getSelectwinCode());
            $this->assertSame('Recusado', $e->getDisplayMessage());
            $this->assertTrue($e->isReversible());
        }
    }

    public function testMaps404ToNotFound(): void
    {
        [$sw] = $this->client([$this->jsonResponse(404, ['error' => ['code' => 'not_found']])]);
        $this->expectException(NotFoundException::class);
        $sw->customers->retrieve('cus_x');
    }

    public function testRetries5xxThenSucceeds(): void
    {
        [$sw, $mock] = $this->client([
            new Response(503),
            $this->jsonResponse(201, ['id' => 'cus_1']),
        ]);
        $this->ignoreParse(fn () => $sw->customers->create(self::CUST));
        $this->assertSame(0, $mock->count()); // both responses consumed -> retried once
    }

    public function testRequiresAnApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SelectwinClient('');
    }
}
