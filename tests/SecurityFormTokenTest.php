<?php

namespace VD\PHPHelper\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\Keyring;
use VD\PHPHelper\Security;

/**
 * Security::generateFormToken() / verifyFormToken(): the signed "{time}.{hmac}" a page carries
 * back with its form. What the client can send is answered with FALSE; what only the caller can
 * get wrong throws.
 */
final class SecurityFormTokenTest extends TestCase
{
    private const KEY_K = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';
    private const KEY_Z = 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz';
    private const NOW = 1_800_000_000;

    public function testATokenVerifiesWithinItsAgeWindow(): void
    {
        $token = Security::generateFormToken(self::KEY_K, now: self::NOW);

        self::assertMatchesRegularExpression('/^1800000000\.[0-9a-f]{64}$/', $token);
        self::assertTrue(Security::verifyFormToken($token, self::KEY_K, 3, 86400, now: self::NOW + 3), 'the youngest allowed');
        self::assertTrue(Security::verifyFormToken($token, self::KEY_K, 3, 86400, now: self::NOW + 86400), 'the oldest allowed');
    }

    public function testATokenTooYoungTooOldOrFromTheFutureIsRefused(): void
    {
        $token = Security::generateFormToken(self::KEY_K, now: self::NOW);

        self::assertFalse(Security::verifyFormToken($token, self::KEY_K, 3, 86400, now: self::NOW + 2), 'faster than a person');
        self::assertFalse(Security::verifyFormToken($token, self::KEY_K, 3, 86400, now: self::NOW + 86401), 'a page left open too long');
        self::assertFalse(Security::verifyFormToken($token, self::KEY_K, 0, 86400, now: self::NOW - 1), 'signed in the future');
    }

    /**
     * The default purpose makes the very token a site built by hand from generateSearchHash() with
     * the salt "form-token": a site switching to this method keeps the pages already open working.
     */
    public function testTheDefaultPurposeMatchesTheHandBuiltToken(): void
    {
        $time = (string) self::NOW;

        self::assertSame(
            $time . '.' . Security::generateSearchHash($time, self::KEY_K, 'form-token'),
            Security::generateFormToken(self::KEY_K, now: self::NOW)
        );
    }

    public function testATokenForOnePurposeDoesNotVerifyForAnother(): void
    {
        $contact = Security::generateFormToken(self::KEY_K, 'contact', self::NOW);

        self::assertTrue(Security::verifyFormToken($contact, self::KEY_K, purpose: 'contact', now: self::NOW + 10));
        self::assertFalse(Security::verifyFormToken($contact, self::KEY_K, purpose: 'signup', now: self::NOW + 10));
        self::assertFalse(Security::verifyFormToken($contact, self::KEY_K, now: self::NOW + 10), 'nor for the default one');
    }

    /** After a key rotation, the pages rendered under the previous key still verify. */
    public function testATokenSignedByAPreviousKeyStillVerifies(): void
    {
        $old = Security::generateFormToken(self::KEY_K, now: self::NOW);
        $rotated = new Keyring(self::KEY_Z, self::KEY_K);

        self::assertTrue(Security::verifyFormToken($old, $rotated, now: self::NOW + 10));
        self::assertNotSame($old, Security::generateFormToken($rotated, now: self::NOW), 'new tokens are signed by the current key');
        self::assertFalse(Security::verifyFormToken($old, self::KEY_Z, now: self::NOW + 10), 'a key that never signed it');
    }

    public function testATamperedTokenIsRefused(): void
    {
        $token = Security::generateFormToken(self::KEY_K, now: self::NOW);
        [$time, $mac] = explode('.', $token);

        self::assertFalse(Security::verifyFormToken(($time - 600) . '.' . $mac, self::KEY_K, now: self::NOW + 10), 'an older time with the same signature');
        self::assertFalse(Security::verifyFormToken($time . '.' . strrev($mac), self::KEY_K, now: self::NOW + 10));
    }

    /** @return array<string, array{0: mixed}> */
    public static function malformedTokens(): array
    {
        $mac = str_repeat('a', 64);

        return [
            'null'              => [null],
            'array'             => [['1800000000.' . $mac]],
            'int'               => [1800000000],
            'empty'             => [''],
            'no signature'      => ['1800000000'],
            'short time'        => ['18000.' . $mac],
            'uppercase hex'     => ['1800000000.' . strtoupper($mac)],
            'short signature'   => ['1800000000.' . substr($mac, 1)],
            'trailing newline'  => ['1800000000.' . $mac . "\n"],
            'leading space'     => [' 1800000000.' . $mac],
            'negative time'     => ['-1800000000.' . $mac],
        ];
    }

    #[DataProvider('malformedTokens')]
    public function testAMalformedTokenIsRefusedWithoutAnException(mixed $token): void
    {
        self::assertFalse(Security::verifyFormToken($token, self::KEY_K, 0, PHP_INT_MAX, now: self::NOW));
    }

    /** @return array<string, array{0: callable(): mixed}> */
    public static function callerMistakes(): array
    {
        return [
            'short key, generate'   => [static fn () => Security::generateFormToken('short')],
            'short key, verify'     => [static fn () => Security::verifyFormToken('garbage', 'short')],
            'empty purpose'         => [static fn () => Security::generateFormToken(self::KEY_K, '')],
            'purpose with a NUL'    => [static fn () => Security::verifyFormToken('x', self::KEY_K, purpose: "a\0b")],
            'purpose too long'      => [static fn () => Security::generateFormToken(self::KEY_K, str_repeat('p', 65))],
            'negative minimum age'  => [static fn () => Security::verifyFormToken('x', self::KEY_K, -1)],
            'maximum below minimum' => [static fn () => Security::verifyFormToken('x', self::KEY_K, 10, 9)],
        ];
    }

    /** The caller's mistakes throw even when the token itself would have been refused anyway. */
    #[DataProvider('callerMistakes')]
    public function testTheCallersMistakesThrow(callable $call): void
    {
        $this->expectException(InvalidArgumentException::class);

        $call();
    }
}
