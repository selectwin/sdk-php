<?php

declare(strict_types=1);

namespace Selectwin\Test;

use PHPUnit\Framework\TestCase;
use Selectwin\Exception\ApiConnectionException;
use Selectwin\Exception\ApiErrorException;
use Selectwin\Exception\AuthenticationException;
use Selectwin\Exception\CardException;
use Selectwin\Exception\ConflictException;
use Selectwin\Exception\ErrorFactory;
use Selectwin\Exception\NotFoundException;
use Selectwin\Exception\PermissionDeniedException;
use Selectwin\Exception\RateLimitException;
use Selectwin\Exception\SelectwinException;
use Selectwin\Exception\ValidationException;

final class ErrorFactoryTest extends TestCase
{
    private function env(array $error): string
    {
        return json_encode(['error' => $error]);
    }

    public function testMapsEachStatusToItsSubclass(): void
    {
        $this->assertInstanceOf(AuthenticationException::class, ErrorFactory::build(401, $this->env(['code' => 'unauthorized'])));
        $this->assertInstanceOf(PermissionDeniedException::class, ErrorFactory::build(403, $this->env([])));
        $this->assertInstanceOf(NotFoundException::class, ErrorFactory::build(404, $this->env([])));
        $this->assertInstanceOf(ConflictException::class, ErrorFactory::build(409, $this->env([])));
        $this->assertInstanceOf(ValidationException::class, ErrorFactory::build(422, $this->env([])));
        $this->assertInstanceOf(ApiErrorException::class, ErrorFactory::build(500, $this->env([])));
        $this->assertInstanceOf(ApiConnectionException::class, ErrorFactory::build(0, null));
    }

    public function testEveryErrorIsASelectwinException(): void
    {
        foreach ([400, 401, 402, 403, 404, 409, 422, 429, 500, 503] as $status) {
            $this->assertInstanceOf(SelectwinException::class, ErrorFactory::build($status, $this->env([])));
        }
    }

    public function testCardExceptionExposesDisplayMessageAndReversible(): void
    {
        $err = ErrorFactory::build(402, $this->env([
            'code' => 'card_declined',
            'displayMessage' => 'Cartão recusado',
            'reversible' => true,
        ]));
        $this->assertInstanceOf(CardException::class, $err);
        $this->assertSame('card_declined', $err->getSelectwinCode());
        $this->assertSame('Cartão recusado', $err->getDisplayMessage());
        $this->assertTrue($err->isReversible());
    }

    public function testValidationExceptionExposesParams(): void
    {
        $params = [['field' => 'email', 'message' => 'invalid']];
        $err = ErrorFactory::build(400, $this->env(['code' => 'validation_error', 'params' => $params]));
        $this->assertInstanceOf(ValidationException::class, $err);
        $this->assertSame($params, $err->getParams());
    }

    public function testRateLimitCarriesRetryAfter(): void
    {
        $err = ErrorFactory::build(429, $this->env(['code' => 'rate_limited']), null, 12.0);
        $this->assertInstanceOf(RateLimitException::class, $err);
        $this->assertSame(12.0, $err->getRetryAfter());
    }

    public function testSurvivesGarbageBodiesAndCarriesMetadata(): void
    {
        $this->assertInstanceOf(ApiErrorException::class, ErrorFactory::build(500, '<html>oops</html>'));
        $err = ErrorFactory::build(404, $this->env(['code' => 'not_found', 'message' => 'nope']), 'req_123');
        $this->assertSame('nope', $err->getMessage());
        $this->assertSame('req_123', $err->getRequestId());
        $this->assertSame(404, $err->getStatusCode());
    }
}
