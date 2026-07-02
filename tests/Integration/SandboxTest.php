<?php

declare(strict_types=1);

namespace Selectwin\Test\Integration;

use PHPUnit\Framework\TestCase;
use Selectwin\Exception\AuthenticationException;
use Selectwin\Exception\NotFoundException;
use Selectwin\Exception\SelectwinException;
use Selectwin\Pagination\Paginator;
use Selectwin\SelectwinClient;

/**
 * Sandbox integration — hits the REAL Selectwin sandbox API. Skipped unless a
 * sandbox key is present:
 *
 *   SELECTWIN_SANDBOX_KEY=sk_test_... vendor/bin/phpunit --testsuite integration
 *   (SELECTWIN_API_KEY honoured only if it starts with sk_test_; SELECTWIN_BASE_URL optional)
 */
final class SandboxTest extends TestCase
{
    private ?string $key = null;

    protected function setUp(): void
    {
        $explicit = getenv('SELECTWIN_SANDBOX_KEY') ?: '';
        $generic = getenv('SELECTWIN_API_KEY') ?: '';
        $this->key = $explicit !== '' ? $explicit : (str_starts_with($generic, 'sk_test_') ? $generic : null);
        if ($this->key === null) {
            $this->markTestSkipped('set SELECTWIN_SANDBOX_KEY to run sandbox integration tests');
        }
    }

    private function client(array $opts = []): SelectwinClient
    {
        $base = getenv('SELECTWIN_BASE_URL') ?: null;
        $o = ['max_retries' => 1, 'timeout' => 30.0, 'user_agent' => 'selectwin-php-integration-tests'] + $opts;
        if ($base) {
            $o['base_url'] = $base;
        }
        return new SelectwinClient($this->key, $o);
    }

    public function testReadOnlyListSmoke(): void
    {
        $page = $this->client()->customers->list(['limit' => 2])->first();
        $this->assertIsArray($page->getData());
        $this->assertIsBool($page->getHasMore());
    }

    public function testAutoPaginationRespectsCap(): void
    {
        $pager = $this->client()->customers->list(['limit' => 1]);
        $this->assertInstanceOf(Paginator::class, $pager);
        $this->assertLessThanOrEqual(3, count($pager->toArray(3)));
    }

    public function testInvalidKeyMapsToAuthenticationException(): void
    {
        $sw = new SelectwinClient('sk_test_this_key_is_not_valid_000', ['max_retries' => 0]);
        try {
            $sw->customers->list(['limit' => 1])->first();
            $this->fail('expected an authentication error');
        } catch (SelectwinException $e) {
            $this->assertInstanceOf(AuthenticationException::class, $e);
        }
    }

    public function testCreateRetrieveDeleteRoundTrip(): void
    {
        $sw = $this->client();
        $email = 'sdk-it-' . bin2hex(random_bytes(6)) . '@sandbox.selectwin.test';
        $created = $sw->customers->create([
            'firstName' => 'SDK',
            'lastName' => 'Integration',
            'email' => $email,
            'metadata' => ['sdkIntegrationTest' => true],
        ]);
        $id = $created->getId();
        try {
            $this->assertNotEmpty($id);
            $this->assertSame($id, $sw->customers->retrieve($id)->getId());
        } finally {
            $sw->customers->delete($id);
        }
        $this->expectException(NotFoundException::class);
        $sw->customers->retrieve($id);
    }
}
