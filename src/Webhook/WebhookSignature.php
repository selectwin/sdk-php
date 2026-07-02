<?php

declare(strict_types=1);

namespace Selectwin\Webhook;

use Selectwin\Exception\SignatureVerificationException;

/**
 * Webhook signature verification.
 *
 * Selectwin signs each delivery with `X-Selectwin-Signature: sha256=<hex>` — an
 * HMAC-SHA256 of the RAW request body using the endpoint secret (`whsec_...`). An
 * optional replay-proof scheme is sent in `X-Selectwin-Signature-v1:
 * t=<unix>,v1=<hex>` where the signed message is `<t>.<rawBody>`.
 *
 * Pass the raw body exactly as received — re-serialising the JSON breaks verification.
 */
final class WebhookSignature
{
    /**
     * Verify the signature and return the parsed {@see Event}.
     *
     * @param array{signatureV1?: ?string, tolerance?: ?int, now?: ?callable} $options
     * @throws SignatureVerificationException if the signature is missing or invalid.
     */
    public static function constructEvent(
        string $rawBody,
        ?string $signatureHeader,
        string $secret,
        array $options = [],
    ): Event {
        if ($secret === '') {
            throw new SignatureVerificationException('Missing webhook secret');
        }
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new SignatureVerificationException('Missing X-Selectwin-Signature header');
        }

        $received = str_starts_with($signatureHeader, 'sha256=')
            ? substr($signatureHeader, strlen('sha256='))
            : $signatureHeader;
        $expected = hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expected, $received)) {
            throw new SignatureVerificationException('Webhook signature verification failed');
        }

        $signatureV1 = $options['signatureV1'] ?? null;
        if (is_string($signatureV1) && $signatureV1 !== '') {
            $parsed = self::parseV1($signatureV1);
            $t = $parsed['t'] ?? null;
            $v1 = $parsed['v1'] ?? null;
            if ($t !== null && $v1 !== null) {
                $expectedV1 = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
                if (!hash_equals($expectedV1, $v1)) {
                    throw new SignatureVerificationException('Webhook v1 signature verification failed');
                }
                $tolerance = $options['tolerance'] ?? null;
                if ($tolerance !== null) {
                    $now = isset($options['now']) && is_callable($options['now'])
                        ? (int) ($options['now'])()
                        : time();
                    if (abs($now - (int) $t) > $tolerance) {
                        throw new SignatureVerificationException('Webhook timestamp is outside the tolerance window');
                    }
                }
            }
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new SignatureVerificationException('Webhook payload is not valid JSON');
        }
        return new Event($decoded);
    }

    /** @return array{t?: string, v1?: string} */
    private static function parseV1(string $header): array
    {
        $out = [];
        foreach (explode(',', $header) as $part) {
            $pos = strpos($part, '=');
            if ($pos !== false) {
                $out[trim(substr($part, 0, $pos))] = trim(substr($part, $pos + 1));
            }
        }
        return $out;
    }
}
