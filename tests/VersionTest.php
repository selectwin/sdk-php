<?php

declare(strict_types=1);

namespace Selectwin\Test;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Selectwin\SelectwinClient;

final class VersionTest extends TestCase
{
    public function testVersionComesFromComposerNotAHardcodedConstant(): void
    {
        // version() must mirror Composer's installed metadata (single source of
        // truth = the git tag / composer.json), never a hardcoded literal.
        $expected = ltrim((string) InstalledVersions::getPrettyVersion('selectwin/sdk'), 'v');
        $this->assertNotSame('', $expected);
        $this->assertSame($expected, SelectwinClient::version());
    }

    public function testVersionIsCarriedIntoTheUserAgent(): void
    {
        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"id":"cus_1"}'),
        ]);
        $sw = new SelectwinClient('sk_test_abc', ['handler' => $mock]);
        try {
            $sw->customers->retrieve('cus_1');
        } catch (\Throwable) {
            // tolerate minimal-body parse failure; we only care about the request
        }
        $ua = $mock->getLastRequest()->getHeaderLine('User-Agent');
        $this->assertSame('selectwin-php/' . SelectwinClient::version(), $ua);
    }
}
