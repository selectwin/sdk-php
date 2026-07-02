<?php

declare(strict_types=1);

namespace Selectwin\Test;

use PHPUnit\Framework\TestCase;
use Selectwin\Exception\SignatureVerificationException;
use Selectwin\Webhook\WebhookSignature;

final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test_123';
    private string $payload;

    protected function setUp(): void
    {
        $this->payload = json_encode([
            'id' => 'wbh_1',
            'type' => 'transaction.approved',
            'payload' => ['object' => ['id' => 'tra_1', 'amount' => 9990]],
        ]);
    }

    private function sign(string $body, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function testAcceptsValidSignatureAndParsesEvent(): void
    {
        $ev = WebhookSignature::constructEvent($this->payload, $this->sign($this->payload), self::SECRET);
        $this->assertSame('wbh_1', $ev->id);
        $this->assertSame('transaction.approved', $ev->type);
        $this->assertSame(9990, $ev->object['amount']);
    }

    public function testAcceptsRawHexWithoutPrefix(): void
    {
        $sig = substr($this->sign($this->payload), strlen('sha256='));
        $this->assertSame('wbh_1', WebhookSignature::constructEvent($this->payload, $sig, self::SECRET)->id);
    }

    public function testRejectsTamperedBodyAndWrongSecret(): void
    {
        $this->expectException(SignatureVerificationException::class);
        WebhookSignature::constructEvent($this->payload . ' ', $this->sign($this->payload), self::SECRET);
    }

    public function testRejectsWrongSecret(): void
    {
        $this->expectException(SignatureVerificationException::class);
        WebhookSignature::constructEvent($this->payload, $this->sign($this->payload), 'whsec_other');
    }

    public function testRejectsMissingSignatureAndSecret(): void
    {
        $this->assertThrows(fn () => WebhookSignature::constructEvent($this->payload, null, self::SECRET));
        $this->assertThrows(fn () => WebhookSignature::constructEvent($this->payload, '', self::SECRET));
        $this->assertThrows(fn () => WebhookSignature::constructEvent($this->payload, $this->sign($this->payload), ''));
    }

    public function testRejectsNonJsonAfterSignaturePasses(): void
    {
        $notJson = 'not json';
        $this->expectException(SignatureVerificationException::class);
        WebhookSignature::constructEvent($notJson, $this->sign($notJson), self::SECRET);
    }

    public function testV1WithinTolerance(): void
    {
        $t = 1_000_000;
        $v1 = hash_hmac('sha256', "{$t}.{$this->payload}", self::SECRET);
        $ev = WebhookSignature::constructEvent($this->payload, $this->sign($this->payload), self::SECRET, [
            'signatureV1' => "t={$t},v1={$v1}",
            'tolerance' => 300,
            'now' => fn () => $t,
        ]);
        $this->assertSame('wbh_1', $ev->id);
    }

    public function testV1OutsideToleranceRejected(): void
    {
        $t = 1_000_000;
        $v1 = hash_hmac('sha256', "{$t}.{$this->payload}", self::SECRET);
        $this->expectException(SignatureVerificationException::class);
        WebhookSignature::constructEvent($this->payload, $this->sign($this->payload), self::SECRET, [
            'signatureV1' => "t={$t},v1={$v1}",
            'tolerance' => 300,
            'now' => fn () => $t + 1000,
        ]);
    }

    public function testForgedV1Rejected(): void
    {
        $this->expectException(SignatureVerificationException::class);
        WebhookSignature::constructEvent($this->payload, $this->sign($this->payload), self::SECRET, [
            'signatureV1' => 't=1000000,v1=deadbeef',
        ]);
    }

    private function assertThrows(callable $fn): void
    {
        try {
            $fn();
            $this->fail('expected SignatureVerificationException');
        } catch (SignatureVerificationException) {
            $this->addToAssertionCount(1);
        }
    }
}
