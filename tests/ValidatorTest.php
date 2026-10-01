<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\DateTime;
use VD\PHPHelper\Parser;
use VD\PHPHelper\Validator;

/**
 * Object with a __toString(), used to pin the documented Stringable handling.
 */
final class ValidatorStringableFixture implements \Stringable {
    public function __construct(private readonly string $value) {}

    public function __toString(): string {
        return $this->value;
    }
}

/**
 * Object carrying a private and a public property, used by hasProperty()/emptyExceptZero().
 */
final class ValidatorPropertyFixture {
    private string $secret = 'hidden';
    public string $open = 'visible';

    /** Keeps $secret genuinely used so the fixture reflects a real class. */
    public function reveal(): string {
        return $this->secret;
    }
}

/**
 * Exposes a "virtual" property only through __get()/__isset(), which property_exists() cannot see.
 */
final class ValidatorMagicFixture {
    public function __get(string $name): string {
        return 'magic';
    }

    public function __isset(string $name): bool {
        return true;
    }
}

final class ValidatorTest extends TestCase {
    private string $savedTimezone = 'UTC';

    /** validateDate() reads naive strings in PHP's default timezone: pin it (DST-free). */
    protected function setUp(): void {
        $this->savedTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void {
        date_default_timezone_set($this->savedTimezone);
    }

    // ---------------------------------------------------------------- isHex

    public static function providerValidHex(): array {
        return [
            'digits'      => ['0123456789'],
            'lowercase'   => ['abcdef'],
            'uppercase'   => ['ABCDEF'],
            'mixed case'  => ['dEaDbEeF'],
            'single zero' => ['0'],
        ];
    }

    #[DataProvider('providerValidHex')]
    #[RequiresPhpExtension('ctype')]
    public function testIsHexAcceptsHexadecimalDigits(string $value): void {
        $this->assertTrue(Validator::isHex($value));
    }

    public static function providerInvalidHex(): array {
        return [
            'null'            => [null],
            'empty string'    => [''],
            '0x prefix'       => ['0xFF'],
            'non hex letter'  => ['g'],
            'leading space'   => [' FF'],
            'trailing space'  => ['FF '],
            'sign'            => ['+FF'],
            'decimal point'   => ['1.5'],
        ];
    }

    #[DataProvider('providerInvalidHex')]
    #[RequiresPhpExtension('ctype')]
    public function testIsHexRejectsAnythingThatIsNotPurelyHexDigits(?string $value): void {
        $this->assertFalse(Validator::isHex($value));
    }

    // -------------------------------------------------------------- isOctal

    public static function providerValidOctal(): array {
        return [
            'single digit'      => ['7'],
            'zero'              => ['0'],
            'mode 755'          => ['755'],
            'leading zero mode' => ['0700'],
            'all octal digits'  => ['01234567'],
        ];
    }

    #[DataProvider('providerValidOctal')]
    public function testIsOctalAcceptsOnlyOctalDigits(string $value): void {
        $this->assertTrue(Validator::isOctal($value));
    }

    /**
     * Regression: isOctal() round-tripped through octdec()/decoct() and compared with a LOOSE
     * `==`. octdec() silently drops invalid characters, so "+7"/" 7"/"7 "/"-0" all compared
     * equal numerically and reported as octal. File::getPermissionMode() gates chmod modes on
     * this method, so a false positive here becomes a wrong file permission.
     */
    public static function providerNonOctalThatOctdecWouldSilentlyAccept(): array {
        return [
            'plus sign'        => ['+7'],
            'leading space'    => [' 7'],
            'trailing space'   => ['7 '],
            'negative zero'    => ['-0'],
            'negative'         => ['-7'],
            // File::getPermissionMode() and File::writeFile() gate chmod modes on this method, and
            // an anchored regex would NOT close this one: PCRE's '$' also matches just before a
            // trailing newline, so /^0?[0-7]+$/ accepts "0755\n" and octdec() then returns 493.
            // The character-set test has no such hole; a mode read from a file or a form must not
            // smuggle one in.
            'trailing newline' => ["0755\n"],
            'embedded newline' => ["07\n07"],
        ];
    }

    #[DataProvider('providerNonOctalThatOctdecWouldSilentlyAccept')]
    public function testIsOctalRejectsSignAndWhitespaceInsteadOfSilentlyStrippingThem(string $value): void {
        $this->assertFalse(Validator::isOctal($value));
    }

    /**
     * Regression: octdec() raised E_DEPRECATED ("Invalid characters passed for attempted
     * conversion") for exactly the invalid input a validator exists to reject — the common
     * case. phpunit.xml sets failOnDeprecation=true, so this test fails if that returns.
     */
    public static function providerInvalidOctal(): array {
        return [
            'null'          => [null],
            'empty string'  => [''],
            'digit 8'       => ['8'],
            'digit 9'       => ['09'],
            'letter'        => ['7a'],
            '0o prefix'     => ['0o7'],
            'decimal point' => ['7.5'],
        ];
    }

    #[DataProvider('providerInvalidOctal')]
    public function testIsOctalRejectsNonOctalWithoutEmittingADeprecation(?string $value): void {
        $this->assertFalse(Validator::isOctal($value));
    }

    // --------------------------------------------------------- validateDate

    public function testValidateDateAcceptsADateMatchingTheExplicitFormat(): void {
        $this->assertTrue(Validator::validateDate('2024-02-29', 'Y-m-d'));
        $this->assertTrue(Validator::validateDate('29/02/2024', 'd/m/Y'));
    }

    public function testValidateDateRejectsOverflowAndLooseFormatting(): void {
        $this->assertFalse(Validator::validateDate('2023-02-29', 'Y-m-d'), 'not a leap year');
        $this->assertFalse(Validator::validateDate('2024-02-30', 'Y-m-d'), 'must not roll over into March');
        $this->assertFalse(Validator::validateDate('2024-1-5', 'Y-m-d'), 'format must match exactly');
        $this->assertFalse(Validator::validateDate('not-a-date', 'Y-m-d'));
        $this->assertFalse(Validator::validateDate(null, 'Y-m-d'));
        $this->assertFalse(Validator::validateDate('', 'Y-m-d'));
    }

    /**
     * Regression: a field missing from the format was borrowed from TODAY, so "2024-02" as 'Y-m'
     * became Feb 30/31 on the 30th/31st of every month and failed. The answer depended on the date.
     */
    public function testValidateDateDoesNotDependOnTheCurrentDay(): void {
        $this->assertTrue(Validator::validateDate('2024-02', 'Y-m'));
        $this->assertTrue(Validator::validateDate('29/02', 'd/m'));
    }

    /**
     * Pins the documented fallback: a null format uses DateTime's PROCESS-WIDE default.
     * The default is saved and restored so this test neither depends on nor leaks global state.
     */
    public function testValidateDateWithNullFormatUsesTheProcessWideDefaultFormat(): void {
        $previous = DateTime::getDefaultFormat();

        try {
            DateTime::setDefaultFormat('d/m/Y');
            $this->assertTrue(Validator::validateDate('29/02/2024'));
            $this->assertFalse(Validator::validateDate('2024-02-29'));

            DateTime::setDefaultFormat('Y-m-d');
            $this->assertTrue(Validator::validateDate('2024-02-29'));
            $this->assertFalse(Validator::validateDate('29/02/2024'));
        } finally {
            DateTime::setDefaultFormat($previous);
        }
    }

    // --------------------------------------------------------- validateMail

    public function testValidateMailAcceptsSyntacticallyValidAddresses(): void {
        $this->assertTrue(Validator::validateMail('user@example.com'));
        $this->assertTrue(Validator::validateMail('first.last@sub.example.co.uk'));
        $this->assertTrue(Validator::validateMail("odd!#$%&'*+-/=?^_`{|}~name@example.com"));
    }

    public function testValidateMailRejectsMalformedAddresses(): void {
        $this->assertFalse(Validator::validateMail(null));
        $this->assertFalse(Validator::validateMail(''));
        $this->assertFalse(Validator::validateMail('no-at-sign'));
        $this->assertFalse(Validator::validateMail('@example.com'));
        $this->assertFalse(Validator::validateMail('user@'));
        $this->assertFalse(Validator::validateMail('user @example.com'));
        $this->assertFalse(Validator::validateMail('user@exam ple.com'));
    }

    public static function providerMailWithControlOrWhitespace(): array {
        return [
            'trailing LF'       => ["user@example.com\n"],
            'trailing CRLF'     => ["user@example.com\r\n"],
            'injected header'   => ["user@example.com\r\nBcc: victim@example.org"],
            'trailing space'    => ['user@example.com '],
            'leading tab'       => ["\tuser@example.com"],
            'NUL byte'          => ["user@example.com\0"],
            'DEL'               => ["user@example.com\x7F"],
        ];
    }

    /**
     * Regression (header injection): the delegate's pattern ended in a bare '$', which also
     * matches before a trailing "\n", so "user@example.com\n" validated. Written into a
     * To:/Reply-To: header, that newline starts a new header line. The guard lives in Validator,
     * so it holds whatever the delegate's anchoring does.
     */
    #[DataProvider('providerMailWithControlOrWhitespace')]
    public function testValidateMailRejectsControlCharactersAndWhitespace(string $email): void {
        $this->assertFalse(Validator::validateMail($email));
    }

    // ----------------------------------------------------- validatePassword

    public function testValidatePasswordAcceptsAPasswordMeetingTheDocumentedDefaults(): void {
        $this->assertTrue(Validator::validatePassword('Abc123!x'));
        $this->assertTrue(Validator::validatePassword('LongerPassw0rd#'));
    }

    public static function providerPasswordFailingDefaults(): array {
        return [
            'null'                  => [null],
            'empty'                 => [''],
            'seven characters'      => ['Abc123!'],
            'no uppercase'          => ['abc123!xy'],
            'no lowercase'          => ['ABC123!XY'],
            'no digit'              => ['Abcdef!xy'],
            'no special character'  => ['Abc12345'],
        ];
    }

    #[DataProvider('providerPasswordFailingDefaults')]
    public function testValidatePasswordRejectsPasswordsMissingADefaultRequirement(?string $password): void {
        $this->assertFalse(Validator::validatePassword($password));
    }

    /**
     * Regression (audit): a max* rule of 0 was silently discarded and became "no maximum",
     * because filter_var() returns a FALSY int(0) for a legitimate 0 and the code tested its
     * truthiness. 'abc123456' has six digits and used to pass under maxDigits => 0.
     */
    public function testValidatePasswordHonoursAMaxRuleOfZeroInsteadOfTreatingItAsUnset(): void {
        $noMinimums = [
            'minLength' => 1, 'minLowercase' => 0, 'minUppercase' => 0,
            'minDigits' => 0, 'minSpecialChars' => 0,
        ];

        $this->assertFalse(
            Validator::validatePassword('abc123456', $noMinimums + ['maxDigits' => 0]),
            'six digits must not satisfy a documented maximum of zero digits'
        );
        $this->assertTrue(Validator::validatePassword('abcdef', $noMinimums + ['maxDigits' => 0]));
    }

    /**
     * Regression (deeper than the audit found): the upper bound of a {min,max} quantifier inside
     * a POSITIVE lookahead enforces nothing — (?=(?:.*[A-Z]){1,2}) succeeds on a password with
     * 50 uppercase letters, because matching one repetition already satisfies the lookahead.
     * Every max* rule except maxLength was a no-op for EVERY value, not just for 0.
     */
    public function testValidatePasswordEnforcesMaxCharacterClassRulesForNonZeroValuesToo(): void {
        $noMinimums = [
            'minLength' => 1, 'minLowercase' => 0, 'minUppercase' => 0,
            'minDigits' => 0, 'minSpecialChars' => 0,
        ];

        // 'ABCdef1!' carries exactly three uppercase letters.
        $this->assertFalse(Validator::validatePassword('ABCdef1!', $noMinimums + ['maxUppercase' => 1]));
        $this->assertFalse(Validator::validatePassword('ABCdef1!', $noMinimums + ['maxUppercase' => 2]));
        $this->assertTrue(Validator::validatePassword('ABCdef1!', $noMinimums + ['maxUppercase' => 3]));
        $this->assertTrue(Validator::validatePassword('ABCdef1!', $noMinimums + ['maxUppercase' => 4]));

        $this->assertFalse(Validator::validatePassword('ab!!cd', $noMinimums + ['maxSpecialChars' => 1]));
        $this->assertTrue(Validator::validatePassword('ab!!cd', $noMinimums + ['maxSpecialChars' => 2]));
    }

    /**
     * The documented way to FORBID a category: pass both bounds as 0.
     */
    public function testValidatePasswordForbidsACategoryWhenBothBoundsAreZero(): void {
        $rules = ['minSpecialChars' => 0, 'maxSpecialChars' => 0];

        $this->assertFalse(Validator::validatePassword('Abc12345!', $rules));
        $this->assertTrue(Validator::validatePassword('Abc12345', $rules));
    }

    /**
     * Regression: 'maxSpecialChars' => 0 alone contradicts the documented default
     * 'minSpecialChars' => 1. It used to be silently clamped UP to 1, so a password with one
     * special character passed under a stated maximum of zero. It must throw instead.
     */
    public function testValidatePasswordThrowsWhenAMaxRuleContradictsAMinRuleInsteadOfClampingIt(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxSpecialChars');

        Validator::validatePassword('Abc12345!', ['maxSpecialChars' => 0]);
    }

    public function testValidatePasswordThrowsWhenMaxLengthIsLowerThanMinLength(): void {
        $this->expectException(\InvalidArgumentException::class);

        Validator::validatePassword('Abc123!x', ['minLength' => 8, 'maxLength' => 4]);
    }

    /**
     * Regression: a garbage rule value silently collapsed to 0, so validatePassword('Ab1!',
     * ['minLength' => 'abc']) returned TRUE — a 4-character password passing a policy whose
     * documented default minimum is 8. A password policy must never degrade silently.
     */
    public static function providerInvalidPasswordRuleValues(): array {
        return [
            'non numeric string' => ['abc'],
            'float'              => [8.5],
            'negative'           => [-1],
            'bool'               => [true],
            'array'              => [[8]],
        ];
    }

    #[DataProvider('providerInvalidPasswordRuleValues')]
    public function testValidatePasswordThrowsOnARuleValueThatIsNotANonNegativeInteger(mixed $ruleValue): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('minLength');

        Validator::validatePassword('Ab1!', ['minLength' => $ruleValue]);
    }

    /**
     * Regression: an unknown rule key (a typo) was silently ignored, so the caller's intended
     * policy was never applied and the defaults quietly took over.
     */
    public function testValidatePasswordThrowsOnAnUnknownRuleKeyInsteadOfIgnoringIt(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('minLenght');

        Validator::validatePassword('Abc123!x', ['minLenght' => 12]);
    }

    public function testValidatePasswordAcceptsIntegerLikeStringsAndNullAsOmittedRules(): void {
        $this->assertTrue(Validator::validatePassword('Abc123!xyz', ['minLength' => '10']));
        $this->assertFalse(Validator::validatePassword('Abc123!x', ['minLength' => '10']));
        // null means "omitted" -> maxLength stays unlimited, minLength keeps its default of 8.
        $this->assertTrue(Validator::validatePassword('Abc123!x', ['maxLength' => null, 'minLength' => null]));
    }

    /**
     * Regression: the pattern counted BYTES, so 'Aé1!aaa' (7 characters, 8 bytes) satisfied the
     * documented minLength of 8 — a fail-open on a security control. The /u modifier makes the
     * quantifier count characters, as the docblock says.
     */
    public function testValidatePasswordCountsCharactersNotBytesForLength(): void {
        $sevenCharsEightBytes = 'Aé1!aaa';
        $this->assertSame(7, mb_strlen($sevenCharsEightBytes));
        $this->assertSame(8, strlen($sevenCharsEightBytes));

        $this->assertFalse(
            Validator::validatePassword($sevenCharsEightBytes),
            'a 7-character password must not satisfy a minimum of 8 characters'
        );
        $this->assertTrue(Validator::validatePassword($sevenCharsEightBytes . 'b'));
    }

    /**
     * Regression: without the /D modifier, `$` tolerates one trailing newline, so an 11-character
     * password slipped past maxLength => 10.
     */
    public function testValidatePasswordDoesNotLetATrailingNewlineSlipPastMaxLength(): void {
        $this->assertTrue(Validator::validatePassword('Abc123!xyz', ['maxLength' => 10]));
        $this->assertFalse(Validator::validatePassword("Abc123!xyz\n", ['maxLength' => 10]));
    }

    /** Documented: a line break makes the password invalid. A lone CR used to be accepted. */
    public function testValidatePasswordRejectsAnyLineBreak(): void {
        $this->assertFalse(Validator::validatePassword("Abc123!x\r"));
        $this->assertFalse(Validator::validatePassword("Abc1\n23!x"));
        $this->assertFalse(Validator::validatePassword("Abc1\r\n23!x"));
    }

    public function testValidatePasswordRejectsInvalidUtf8(): void {
        $this->assertFalse(Validator::validatePassword("Abc123!x\xFF"));
    }

    /**
     * Documented: digits are ASCII 0-9. Under the old /u regex, '\d' matched Unicode digits, so an
     * Arabic-Indic three satisfied minDigits.
     */
    public function testValidatePasswordCountsOnlyAsciiDigits(): void {
        $this->assertFalse(Validator::validatePassword("Abcdefg!\u{0663}"));
        $this->assertTrue(Validator::validatePassword("Abcdefg!3"));
        // ...but a non-ASCII digit still counts toward the length.
        $this->assertTrue(Validator::validatePassword("Abc1!\u{0663}\u{0663}\u{0663}"));
    }

    /**
     * Regression: the nested-lookahead regex hit PCRE's backtrack limit on long passwords with
     * large rules, and preg_match() then returned false — so passwords that met every rule were
     * REJECTED. Counting is linear and exact.
     */
    public function testValidatePasswordHandlesLongPasswordsWithLargeRuleValues(): void {
        $this->assertTrue(Validator::validatePassword(str_repeat('a1', 40) . 'A!', ['maxDigits' => 40]));
        $this->assertTrue(Validator::validatePassword(str_repeat('ab1', 60) . 'A!', ['maxDigits' => 60, 'minDigits' => 60]));
        $this->assertFalse(Validator::validatePassword(str_repeat('ab1', 61) . 'A!', ['maxDigits' => 60]));
        $this->assertFalse(Validator::validatePassword(str_repeat('aA1!', 5) . str_repeat('x', 3000), ['minDigits' => 6]));
    }

    /**
     * Pins the documented special-character set: it is a fixed list, NOT "any non-alphanumeric".
     */
    public function testValidatePasswordOnlyCountsTheDocumentedSpecialCharacters(): void {
        $this->assertTrue(Validator::validatePassword('Abc123!x'), '! is in the documented set');
        $this->assertTrue(Validator::validatePassword('Abc123<x'), '< is in the documented set');
        $this->assertFalse(Validator::validatePassword('Abc123~xy'), '~ is NOT in the documented set');
        $this->assertFalse(Validator::validatePassword('Abc123[xy'), '[ is NOT in the documented set');
    }

    /**
     * Pins the documented class boundaries: an accented capital is not [A-Z], so it satisfies
     * neither minUppercase nor minLowercase — it only adds length.
     */
    public function testValidatePasswordDoesNotCountAccentedLettersAsUppercaseOrLowercase(): void {
        $this->assertFalse(
            Validator::validatePassword('ÉÉÉÉ123!', ['minLowercase' => 0]),
            'É must not satisfy minUppercase'
        );
        $this->assertTrue(Validator::validatePassword('ÉAaaa123!'));
    }

    // ---------------------------------------------------------- validateJson

    public static function providerValidJson(): array {
        return [
            'object'        => ['{"a":1}'],
            'array'         => ['[1,2,3]'],
            'empty object'  => ['{}'],
            'empty array'   => ['[]'],
            'quoted string' => ['"text"'],
            'number'        => ['12.5'],
            'true'          => ['true'],
            'false'         => ['false'],
            'null literal'  => ['null'],
        ];
    }

    #[DataProvider('providerValidJson')]
    public function testValidateJsonAcceptsAnyWellFormedJsonDocument(string $json): void {
        $this->assertTrue(Validator::validateJson($json));
    }

    /**
     * Regression: the guard was `!empty($jsonString)`, and empty("0") is TRUE in PHP, so the
     * string "0" never reached json_decode() and a valid JSON number was reported malformed.
     */
    public function testValidateJsonAcceptsTheStringZeroWhichIsAValidJsonNumber(): void {
        $this->assertTrue(Validator::validateJson('0'));
        $this->assertTrue(Validator::validateJson('0.0'));
    }

    public static function providerInvalidJson(): array {
        return [
            'null'            => [null],
            'empty string'    => [''],
            'bare word'       => ['undefined'],
            'trailing comma'  => ['{"a":1,}'],
            'unclosed object' => ['{"a":1'],
            'single quotes'   => ["{'a':1}"],
            'whitespace only' => ['   '],
            'UTF-8 BOM'       => ["\xEF\xBB\xBF{}"],
            'NaN'             => ['NaN'],
            'deeper than 512' => [str_repeat('[', 600) . str_repeat(']', 600)],
        ];
    }

    #[DataProvider('providerInvalidJson')]
    public function testValidateJsonRejectsMalformedInput(?string $json): void {
        $this->assertFalse(Validator::validateJson($json));
    }

    // ------------------------------------------------------ isBase64Encoded

    public function testIsBase64EncodedAcceptsCanonicalBase64(): void {
        $this->assertTrue(Validator::isBase64Encoded(base64_encode('ABC')));
        $this->assertTrue(Validator::isBase64Encoded(base64_encode('AB')));
        $this->assertTrue(Validator::isBase64Encoded(base64_encode("\x00\xFF\xFE\x80binary")));
    }

    public function testIsBase64EncodedRejectsNonCanonicalOrUndecodableInput(): void {
        $this->assertFalse(Validator::isBase64Encoded(null));
        $this->assertFalse(Validator::isBase64Encoded(''));
        $this->assertFalse(Validator::isBase64Encoded('!!!'));
        $this->assertFalse(Validator::isBase64Encoded('QUI'), 'missing padding is not canonical');
        $this->assertFalse(Validator::isBase64Encoded("QUJ\nD"), 'embedded newline is not canonical');
    }

    // --------------------------------------------------- isBase64UrlEncoded

    /**
     * Regression (the file's worst defect): the body called base64_url_encode() and
     * base64_url_decode(), which exist neither in this package nor in PHP. EVERY non-empty input
     * raised a fatal `Error: Call to undefined function` — an Error, so a caller's
     * `catch (\Exception)` did not stop it. The method had never worked for any real input.
     */
    public function testIsBase64UrlEncodedReturnsABoolInsteadOfThrowingOnRealInput(): void {
        $encoded = Parser::base64UrlEncode('ABC');

        $this->assertIsString($encoded);
        $this->assertTrue(Validator::isBase64UrlEncoded($encoded));
    }

    public function testIsBase64UrlEncodedAcceptsWhatParserBase64UrlEncodeProduces(): void {
        foreach (['A', 'AB', 'ABC', 'ABCD', 'hello world', "\x00\x01\xFF\xFE"] as $raw) {
            $encoded = Parser::base64UrlEncode($raw);
            $this->assertIsString($encoded);
            $this->assertTrue(
                Validator::isBase64UrlEncoded($encoded),
                'round-trip must hold for ' . bin2hex($raw)
            );
        }
    }

    public function testIsBase64UrlEncodedRejectsUndecodableAndNonCanonicalInput(): void {
        $this->assertFalse(Validator::isBase64UrlEncoded(null));
        $this->assertFalse(Validator::isBase64UrlEncoded(''));
        $this->assertFalse(Validator::isBase64UrlEncoded('!!!'));
        // Standard base64 padding ("=") is not this dialect's padding ("-").
        $this->assertSame('QUI=', base64_encode('AB'));
        $this->assertFalse(Validator::isBase64UrlEncoded('QUI='));
    }

    // ----------------------------------------------------------- validateXml

    #[RequiresPhpExtension('simplexml')]
    #[RequiresPhpExtension('libxml')]
    public function testValidateXmlAcceptsWellFormedXml(): void {
        $this->assertTrue(Validator::validateXml('<a>1</a>'));
        $this->assertTrue(Validator::validateXml('<?xml version="1.0"?><root><child attr="v"/></root>'));
        $this->assertTrue(Validator::validateXml('  <a>1</a>  '), 'surrounding whitespace is trimmed');
    }

    /**
     * Regression: the signature and docblock both accept null, but trim() was called on it
     * before the empty guard, raising E_DEPRECATED. phpunit.xml sets failOnDeprecation=true, so
     * this test fails if the unguarded trim() returns.
     */
    public function testValidateXmlAcceptsNullWithoutEmittingADeprecation(): void {
        $this->assertFalse(Validator::validateXml(null));
    }

    #[RequiresPhpExtension('simplexml')]
    #[RequiresPhpExtension('libxml')]
    public function testValidateXmlRejectsMalformedXmlAndHtml5(): void {
        $this->assertFalse(Validator::validateXml(''));
        $this->assertFalse(Validator::validateXml('   '));
        $this->assertFalse(Validator::validateXml('<a>1'), 'unclosed tag');
        $this->assertFalse(Validator::validateXml('<a><b></a></b>'), 'mismatched nesting');
        $this->assertFalse(Validator::validateXml('plain text'));
        $this->assertFalse(
            Validator::validateXml('<!DOCTYPE html><html><body></body></html>'),
            'HTML5 is deliberately rejected'
        );
    }

    /**
     * Pins the documented side-effect-freedom: validateXml() used to leave
     * libxml_use_internal_errors(true) switched on for the rest of the request.
     */
    #[RequiresPhpExtension('simplexml')]
    #[RequiresPhpExtension('libxml')]
    public function testValidateXmlRestoresTheGlobalLibxmlErrorState(): void {
        $previous = libxml_use_internal_errors(false);

        try {
            Validator::validateXml('<a>1</a>');
            $this->assertFalse(libxml_use_internal_errors(), 'state must be restored to false');

            libxml_use_internal_errors(true);
            Validator::validateXml('<a>1');
            $this->assertTrue(libxml_use_internal_errors(), 'state must be restored to true');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Regression: validateXml() read the WHOLE libxml error buffer, so errors a caller had left
     * there made valid XML report invalid — and then it cleared the caller's errors.
     */
    #[RequiresPhpExtension('simplexml')]
    #[RequiresPhpExtension('libxml')]
    public function testValidateXmlIgnoresAndPreservesErrorsTheCallerAlreadyBuffered(): void {
        $previous = libxml_use_internal_errors(true);

        try {
            libxml_clear_errors();
            simplexml_load_string('<broken>');
            $callerErrors = count(libxml_get_errors());
            $this->assertGreaterThan(0, $callerErrors);

            $this->assertTrue(Validator::validateXml('<a/>'), "the caller's stale errors are not this parse's");
            $this->assertCount($callerErrors, libxml_get_errors(), "the caller's errors must survive");

            $this->assertFalse(Validator::validateXml('<a>'));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** A libxml WARNING (relative namespace URI) is not a well-formedness error. */
    #[RequiresPhpExtension('simplexml')]
    #[RequiresPhpExtension('libxml')]
    public function testValidateXmlAcceptsWellFormedXmlThatOnlyRaisesWarnings(): void {
        $this->assertTrue(Validator::validateXml('<a xmlns="relative-uri"/>'));
    }

    // ------------------------------------------------------ emptyExceptZero

    public static function providerAbsentValues(): array {
        return [
            'null'         => [null],
            'empty string' => [''],
            'NUL string'   => ["\0"],
            'empty array'  => [[]],
            'empty object' => [new \stdClass()],
        ];
    }

    #[DataProvider('providerAbsentValues')]
    public function testEmptyExceptZeroReportsAbsentValuesAsEmpty(mixed $value): void {
        $this->assertTrue(Validator::emptyExceptZero($value));
    }

    /**
     * The carve-out that gives the method its name: falsy-but-present scalars are NOT empty,
     * so a required-field guard built on this accepts a legitimately submitted 0.
     */
    public static function providerPresentButFalsyValues(): array {
        return [
            'int zero'      => [0],
            'string zero'   => ['0'],
            'float zero'    => [0.0],
            'boolean false' => [false],
        ];
    }

    #[DataProvider('providerPresentButFalsyValues')]
    public function testEmptyExceptZeroReportsFalsyScalarsAsPresent(mixed $value): void {
        $this->assertFalse(
            Validator::emptyExceptZero($value),
            'PHP empty() would call this empty; this method must not'
        );
    }

    public function testEmptyExceptZeroReportsPopulatedValuesAsPresent(): void {
        $this->assertFalse(Validator::emptyExceptZero('text'));
        $this->assertFalse(Validator::emptyExceptZero(' '), 'whitespace is a submitted value here');
        $this->assertFalse(Validator::emptyExceptZero([1]));
        $this->assertFalse(Validator::emptyExceptZero(new ValidatorPropertyFixture()));
    }

    /**
     * A Stringable with NO properties has an empty (array) cast, so it used to be "absent"
     * whatever it rendered as.
     */
    public function testEmptyExceptZeroJudgesStringablesByTheirStringForm(): void {
        $this->assertFalse(Validator::emptyExceptZero(self::propertylessStringable('yes')));
        $this->assertTrue(Validator::emptyExceptZero(self::propertylessStringable('')));
    }

    private static function propertylessStringable(string $value): \Stringable {
        return match ($value) {
            ''    => new class implements \Stringable { public function __toString(): string { return ''; } },
            'yes' => new class implements \Stringable { public function __toString(): string { return 'yes'; } },
            'no'  => new class implements \Stringable { public function __toString(): string { return 'no'; } },
        };
    }

    // ---------------------------------------------------------- hasProperty

    public function testHasPropertyFindsPropertiesOnObjectsAndKeysOnArrays(): void {
        $this->assertTrue(Validator::hasProperty('open', new ValidatorPropertyFixture()));
        $this->assertTrue(Validator::hasProperty('a', ['a' => 1]));
        $this->assertTrue(Validator::hasProperty('0', [0 => 'a']), 'int keys match by string form');
        $this->assertTrue(Validator::hasProperty('a', ['a' => null]), 'a key holding null exists');
        $this->assertTrue(Validator::hasProperty('a', (object) ['a' => null]));
    }

    /**
     * Regression: property_exists() ignores ArrayAccess offsets, so an ArrayObject — or any
     * collection — never "had" the keys it plainly holds.
     */
    public function testHasPropertySeesArrayAccessOffsets(): void {
        $collection = new \ArrayObject(['a' => 1, 'n' => null]);

        $this->assertTrue(Validator::hasProperty('a', $collection));
        $this->assertTrue(Validator::hasProperty('n', $collection));
        $this->assertFalse(Validator::hasProperty('missing', $collection));
    }

    /** SplFixedArray::offsetExists() throws a TypeError for a string offset; that must not escape. */
    public function testHasPropertyDoesNotLetAnArrayAccessImplementationThrow(): void {
        $this->assertFalse(Validator::hasProperty('a', new \SplFixedArray(3)));
    }

    /**
     * Widened from array|object: a scalar or null target (Formatter::cleanEmptyTree() hands over
     * whatever the tree holds) used to be a TypeError. It simply has no properties.
     */
    public function testHasPropertyReturnsFalseForNonContainerTargetsInsteadOfThrowing(): void {
        $this->assertFalse(Validator::hasProperty('a', null));
        $this->assertFalse(Validator::hasProperty('a', 'a'));
        $this->assertFalse(Validator::hasProperty('0', 42));
    }

    /** Documented limitation: properties served only through __get()/__isset() are not seen. */
    public function testHasPropertyDoesNotSeeMagicProperties(): void {
        $this->assertFalse(Validator::hasProperty('anything', new ValidatorMagicFixture()));
    }

    /**
     * Pins the documented visibility caveat: property_exists() reports private properties, so a
     * TRUE here does NOT mean the property is readable from the caller's scope.
     */
    public function testHasPropertyReportsPrivatePropertiesWhichAreNotAccessibleToCallers(): void {
        $target = new ValidatorPropertyFixture();

        $this->assertTrue(Validator::hasProperty('secret', $target));

        $this->expectException(\Error::class);
        /** @noinspection PhpExpressionResultUnusedInspection */
        $target->secret;
    }

    public function testHasPropertyReturnsFalseForMissingPropertiesAndEmptyTargets(): void {
        $this->assertFalse(Validator::hasProperty('missing', new ValidatorPropertyFixture()));
        $this->assertFalse(Validator::hasProperty('missing', ['a' => 1]));
        $this->assertFalse(Validator::hasProperty('a', []));
        $this->assertFalse(Validator::hasProperty('a', new \stdClass()));
    }

    // -------------------------------------------------------- isNumericArray

    public function testIsNumericArrayAcceptsArraysWhoseKeysAreAllIntegers(): void {
        $this->assertTrue(Validator::isNumericArray(['a', 'b', 'c']));
        $this->assertTrue(Validator::isNumericArray([0 => 'a', 1 => 'b']));
        $this->assertTrue(Validator::isNumericArray([5 => 'a', 2 => 'b']), 'need not be sequential');
        $this->assertTrue(Validator::isNumericArray(['1' => 'a']), 'PHP normalizes "1" to an int key');
    }

    /**
     * Regression: the body read only the FIRST key (reset() + key()), so a mixed array reported
     * TRUE and — worse — the answer flipped with insertion order for the same key set. A caller
     * branching "list of records vs single record" on this iterated 'meta' as if it were a row.
     */
    public function testIsNumericArrayInspectsEveryKeyNotJustTheFirst(): void {
        $this->assertFalse(Validator::isNumericArray([0 => 'a', 'name' => 'b']));
        $this->assertFalse(Validator::isNumericArray(['name' => 'b', 0 => 'a']));
    }

    public function testIsNumericArrayRejectsStringKeysAndTheEmptyArray(): void {
        $this->assertFalse(Validator::isNumericArray(['name' => 'b']));
        $this->assertFalse(Validator::isNumericArray(['01' => 'a']), '"01" stays a string key');
        $this->assertFalse(Validator::isNumericArray(['1.5' => 'a']));
        $this->assertFalse(Validator::isNumericArray([]), 'no keys to inspect');
    }

    // ----------------------------------------------------- isCompletelyEmpty

    public static function providerCompletelyEmptyValues(): array {
        return [
            'null'            => [null],
            'empty string'    => [''],
            'empty array'     => [[]],
            'empty object'    => [new \stdClass()],
            'boolean false'   => [false],
            'int zero'        => [0],
            'float zero'      => [0.0],
            'string zero'     => ['0'],
            'string 0.0'      => ['0.0'],
            'string 00'       => ['00'],
            'padded zero'     => [' 0 '],
            'negative zero'   => ['-0'],
            'single space'    => [' '],
            'tab and newline' => ["\t\n"],
            'undefined'       => ['undefined'],
            'null word'       => ['NULL'],
            'false word'      => ['false'],
            'empty json obj'  => ['{}'],
            'empty json arr'  => ['[]'],
            'n'               => ['N'],
            'no'              => ['no'],
            'no with spaces'  => ['  n o  '],
            'tno'             => ['TNO'],
        ];
    }

    #[DataProvider('providerCompletelyEmptyValues')]
    public function testIsCompletelyEmptyDetectsAbsencePlaceholdersAndZero(mixed $value): void {
        $this->assertTrue(Validator::isCompletelyEmpty($value));
    }

    public static function providerNotCompletelyEmptyValues(): array {
        return [
            'text'          => ['abc'],
            'int one'       => [1],
            'string one'    => ['1'],
            'boolean true'  => [true],
            'yes'           => ['yes'],
            'negative'      => [-1],
            'float'         => [0.5],
            'zero-ish text' => ['0x0'],
        ];
    }

    #[DataProvider('providerNotCompletelyEmptyValues')]
    public function testIsCompletelyEmptyReportsRealValuesAsNotEmpty(mixed $value): void {
        $this->assertFalse(Validator::isCompletelyEmpty($value));
    }

    /**
     * Regression: `(string) $value` was applied to a `mixed` parameter with no guard, so any
     * NON-empty array raised E_WARNING "Array to string conversion" and any object threw
     * `Error: Object of class X could not be converted to string`. Only EMPTY arrays/objects
     * short-circuited out earlier. failOnWarning=true makes the array case fail here.
     */
    public function testIsCompletelyEmptyHandlesNonEmptyArraysAndObjectsWithoutWarningOrThrowing(): void {
        $this->assertFalse(Validator::isCompletelyEmpty(['a']));
        $this->assertFalse(Validator::isCompletelyEmpty(['a' => ['b' => 1]]));
        $this->assertFalse(Validator::isCompletelyEmpty(new ValidatorPropertyFixture()));
        $this->assertFalse(Validator::isCompletelyEmpty(new \ArrayObject([1, 2])));
    }

    public function testIsCompletelyEmptyEvaluatesStringableObjectsByTheirStringForm(): void {
        $this->assertTrue(Validator::isCompletelyEmpty(new ValidatorStringableFixture('no')));
        $this->assertTrue(Validator::isCompletelyEmpty(new ValidatorStringableFixture('0')));
        $this->assertFalse(Validator::isCompletelyEmpty(new ValidatorStringableFixture('yes')));

        // Regression: a PROPERTY-LESS Stringable short-circuited through emptyExceptZero()'s
        // "object with no properties" rule and was empty whatever it rendered as.
        $this->assertFalse(Validator::isCompletelyEmpty(self::propertylessStringable('yes')));
        $this->assertTrue(Validator::isCompletelyEmpty(self::propertylessStringable('no')));
    }

    /**
     * Regression: all whitespace was stripped before the sentinel match — so "  n o  " was empty —
     * yet a string of ONLY whitespace collapsed to "", matched nothing, and was NOT empty.
     * Parser::getBool(" ") therefore returned TRUE.
     */
    public function testIsCompletelyEmptyTreatsWhitespaceOnlyStringsLikeTheEmptyString(): void {
        $this->assertTrue(Validator::isCompletelyEmpty(' '));
        $this->assertTrue(Validator::isCompletelyEmpty("  \r\n\t "));
        $this->assertTrue(Validator::isCompletelyEmpty(new ValidatorStringableFixture('   ')));
    }

    public function testIsCompletelyEmptyReportsAResourceAsContent(): void {
        $handle = fopen('php://memory', 'r');
        $this->assertIsResource($handle);

        try {
            $this->assertFalse(Validator::isCompletelyEmpty($handle));
        } finally {
            fclose($handle);
        }
    }

    /**
     * The documented contract that separates the two siblings, and the reason it matters:
     * Parser::getBool() and Security's `asBoolean` sanitize option are built on this, so a
     * submitted "0" must coerce to false.
     */
    public function testIsCompletelyEmptyAndEmptyExceptZeroDisagreeExactlyOnFalsyScalars(): void {
        foreach ([0, '0', 0.0, false] as $falsy) {
            $this->assertTrue(Validator::isCompletelyEmpty($falsy));
            $this->assertFalse(Validator::emptyExceptZero($falsy));
        }

        foreach ([null, '', []] as $absent) {
            $this->assertTrue(Validator::isCompletelyEmpty($absent));
            $this->assertTrue(Validator::emptyExceptZero($absent));
        }
    }

    // ------------------------------------------------------ isNegativeNumber

    public function testIsNegativeNumberDetectsALeadingMinusSign(): void {
        $this->assertTrue(Validator::isNegativeNumber('-5'));
        $this->assertTrue(Validator::isNegativeNumber(-5));
        $this->assertTrue(Validator::isNegativeNumber(-0.5));
        $this->assertTrue(Validator::isNegativeNumber('-1'), 'the ini_get("memory_limit") shape');
        $this->assertTrue(Validator::isNegativeNumber(' - 5'), 'whitespace is stripped first');
    }

    public function testIsNegativeNumberReturnsFalseWithoutALeadingMinus(): void {
        $this->assertFalse(Validator::isNegativeNumber('5'));
        $this->assertFalse(Validator::isNegativeNumber(5));
        $this->assertFalse(Validator::isNegativeNumber('128M'));
        $this->assertFalse(Validator::isNegativeNumber('1-2'), 'the minus must be leading');
        $this->assertFalse(Validator::isNegativeNumber(null));
        $this->assertFalse(Validator::isNegativeNumber(''));
        $this->assertFalse(Validator::isNegativeNumber(0));
    }

    /**
     * Pins the documented contract: this is a leading-sign test, not a numeric test.
     */
    public function testIsNegativeNumberIsASignTestNotANumericTest(): void {
        $this->assertTrue(Validator::isNegativeNumber('-abc'));
        $this->assertTrue(Validator::isNegativeNumber('-0'), 'a sign test: "-0" carries a minus');
        $this->assertFalse(Validator::isNegativeNumber(-0.0), '-0.0 is empty() in PHP');
        $this->assertFalse(Validator::isNegativeNumber('+5'));
        $this->assertFalse(Validator::isNegativeNumber('1e-3'));
    }

    /**
     * The Unicode MINUS SIGN (U+2212) is what typeset text and some number formatters emit; it
     * used to read as positive.
     */
    public function testIsNegativeNumberRecognisesTheUnicodeMinusSign(): void {
        $this->assertTrue(Validator::isNegativeNumber("\u{2212}5"));
        $this->assertTrue(Validator::isNegativeNumber(" \u{2212} 1.5"));
        $this->assertFalse(Validator::isNegativeNumber("\u{2013}5"), 'an en dash is not a minus sign');
    }

    /**
     * Regression: the second `(string) $value` site on a `mixed` parameter — an array raised
     * E_WARNING "Array to string conversion" and an object without __toString threw an Error.
     */
    public function testIsNegativeNumberHandlesArraysAndObjectsWithoutWarningOrThrowing(): void {
        $this->assertFalse(Validator::isNegativeNumber(['a']));
        $this->assertFalse(Validator::isNegativeNumber(new ValidatorPropertyFixture()));
        $this->assertTrue(Validator::isNegativeNumber(new ValidatorStringableFixture('-5')));
    }

    // ------------------------------------------------------------ validateCpf

    /**
     * Check digits verified by hand against the CPF algorithm, not by the code under test.
     */
    public function testValidateCpfAcceptsRealCpfNumbersMaskedOrBare(): void {
        $this->assertTrue(Validator::validateCpf('52998224725'));
        $this->assertTrue(Validator::validateCpf('529.982.247-25'));
        $this->assertTrue(Validator::validateCpf('111.444.777-35'));
        $this->assertTrue(Validator::validateCpf('11144477735'));
    }

    public function testValidateCpfRejectsWrongCheckDigits(): void {
        $this->assertFalse(Validator::validateCpf('52998224724'), 'last check digit mutated');
        $this->assertFalse(Validator::validateCpf('52998224735'), 'first check digit mutated');
        $this->assertFalse(Validator::validateCpf('11144477731'));
    }

    /**
     * Regression: input shorter than 11 digits was zero-padded on the LEFT and then checksum-
     * validated, so validateCpf('191') returned TRUE — '191' became the checksum-valid
     * '00000000191'. A CPF validator returning true for a 3-character string persists garbage
     * into an LGPD-regulated identity field.
     */
    public function testValidateCpfRejectsShortInputInsteadOfZeroPaddingItToAValidCpf(): void {
        $this->assertTrue(
            Validator::validateCpf('00000000191'),
            'the padded form really is a checksum-valid CPF, which is what made the bug silent'
        );
        $this->assertFalse(Validator::validateCpf('191'), 'three digits is not a CPF');
        $this->assertFalse(Validator::validateCpf('0000000019'), 'ten digits is not a CPF');
    }

    public function testValidateCpfRejectsRepeatedDigitsAndWrongLength(): void {
        foreach (range(0, 9) as $digit) {
            $this->assertFalse(
                Validator::validateCpf(str_repeat((string) $digit, 11)),
                'all-same-digit CPFs pass the checksum but are not valid'
            );
        }

        $this->assertFalse(Validator::validateCpf(''));
        $this->assertFalse(Validator::validateCpf('529982247251'), 'twelve digits');
        $this->assertFalse(Validator::validateCpf('not a cpf'));
    }

    /**
     * Regression: every non-digit was discarded before validation, so these returned TRUE and a
     * caller storing the raw input persisted the garbage along with the CPF.
     */
    public function testValidateCpfRejectsCharactersOutsideTheMask(): void {
        $this->assertFalse(Validator::validateCpf('52998224725abc'));
        $this->assertFalse(Validator::validateCpf('CPF: 529.982.247-25'));
        $this->assertFalse(Validator::validateCpf("52998224725\0"));
        $this->assertFalse(Validator::validateCpf("\u{0665}2998224725"), 'an Arabic-Indic five is not a 5');
    }

    public function testValidateCpfAcceptsWhitespaceAndSlashAsSeparators(): void {
        $this->assertTrue(Validator::validateCpf(' 529 982 247 25 '));
        $this->assertTrue(Validator::validateCpf('529.982.247/25'));
    }

    // ----------------------------------------------------------- validateCnpj

    /**
     * Regression: the computed digits were compared against the caller's RAW argument while the
     * digits were derived from the NORMALIZED one, so every correctly masked CNPJ — the form
     * humans type — was rejected, even though the near-identically documented validateCpf
     * accepted masks. Check digits verified by hand.
     */
    public function testValidateCnpjAcceptsMaskedCnpjNotJustBareDigits(): void {
        $this->assertTrue(Validator::validateCnpj('11222333000181'));
        $this->assertTrue(
            Validator::validateCnpj('11.222.333/0001-81'),
            'the standard human-written mask must be accepted'
        );
        $this->assertTrue(Validator::validateCnpj('04252011000110'));
        $this->assertTrue(Validator::validateCnpj('04.252.011/0001-10'));
    }

    public function testValidateCnpjRejectsWrongCheckDigits(): void {
        $this->assertFalse(Validator::validateCnpj('11222333000182'), 'last check digit mutated');
        $this->assertFalse(Validator::validateCnpj('11222333000191'), 'first check digit mutated');
        $this->assertFalse(Validator::validateCnpj('11.222.333/0001-82'));
        $this->assertFalse(Validator::validateCnpj('04252011000111'));
    }

    /**
     * Regression: there was no repeated-digit guard, so '00000000000000' satisfied the checksum
     * and returned TRUE — while the sibling validateCpf explicitly guarded the same case.
     */
    public function testValidateCnpjRejectsAllSameDigitNumbersEvenWhenTheChecksumPasses(): void {
        foreach (range(0, 9) as $digit) {
            $this->assertFalse(
                Validator::validateCnpj(str_repeat((string) $digit, 14)),
                'all-same-digit CNPJs are not valid'
            );
        }
    }

    public function testValidateCnpjRejectsWrongLengthAndGarbage(): void {
        $this->assertFalse(Validator::validateCnpj(''));
        $this->assertFalse(Validator::validateCnpj('1122233300018'), 'thirteen digits');
        $this->assertFalse(Validator::validateCnpj('112223330001811'), 'fifteen digits');
        $this->assertFalse(Validator::validateCnpj('not a cnpj'));
    }

    /**
     * Regression: the alphanumeric CNPJ (IN RFB 2.229/2024) is issued from July 2026 and was
     * rejected outright. '12.ABC.345/01DE-35' is the Receita Federal's own published example;
     * 'AB12345C000109' was computed by hand (char value = ASCII - 48, same weights as the numeric
     * CNPJ; its first check digit exercises the "remainder < 2 gives 0" branch).
     */
    public function testValidateCnpjAcceptsTheAlphanumericFormat(): void {
        $this->assertTrue(Validator::validateCnpj('12ABC34501DE35'));
        $this->assertTrue(Validator::validateCnpj('12.ABC.345/01DE-35'));
        $this->assertTrue(Validator::validateCnpj('AB12345C000109'));
        $this->assertTrue(Validator::validateCnpj('AB.123.45C/0001-09'));
    }

    public function testValidateCnpjRejectsAlphanumericValuesWithWrongCheckDigitsOrShape(): void {
        $this->assertFalse(Validator::validateCnpj('12ABC34501DE36'), 'last check digit mutated');
        $this->assertFalse(Validator::validateCnpj('12ABC34501DE45'), 'first check digit mutated');
        $this->assertFalse(Validator::validateCnpj('12ABC34501DF35'), 'a base letter mutated');
        $this->assertFalse(Validator::validateCnpj('12ABC34501DE3A'), 'check digits are always numeric');
        $this->assertFalse(Validator::validateCnpj('12abc34501de35'), 'letters are uppercase, as issued');
        $this->assertFalse(Validator::validateCnpj('12ÁBC34501DE35'), 'only A-Z');
    }

    /**
     * Regression: every character outside 0-9 used to be discarded, so a CNPJ with a prefix or a
     * stray letter validated and the caller persisted the garbage along with it.
     */
    public function testValidateCnpjRejectsCharactersOutsideTheMask(): void {
        $this->assertFalse(Validator::validateCnpj('CNPJ 11.222.333/0001-81'));
        $this->assertFalse(Validator::validateCnpj('11.222.333/0001-81x'));
        $this->assertFalse(Validator::validateCnpj("11222333000181\0"));
        $this->assertTrue(Validator::validateCnpj(' 11 222 333 0001 81 '), 'whitespace is a separator');
    }

    // ----------------------------------------------------------- validateHtml

    public static function providerMarkup(): array {
        return [
            'paragraph'             => ['<p>hi</p>'],
            'void tag'              => ['<br>'],
            'self-closing'          => ['<br/>'],
            'inline'                => ['text with <b>bold</b>'],
            'script'                => ['<script>alert(1)</script>'],
            'attributes'            => ['<a href="x" title=\'y\'>z</a>'],
            'event handler'         => ['<img src=x onerror=alert(1)>'],
            'multi-line tag'        => ["<div\nclass=\"x\">"],
            'uppercase'             => ['<DIV>'],
            'closing tag alone'     => ['text</p>'],
            'custom element'        => ['<my-widget>'],
            'comment'               => ['<!-- note -->'],
            'doctype'               => ['<!DOCTYPE html>'],
            'CDATA'                 => ['<![CDATA[x]]>'],
            'processing instr.'     => ['<?xml version="1.0"?>'],
        ];
    }

    #[DataProvider('providerMarkup')]
    public function testValidateHtmlDetectsMarkup(string $text): void {
        $this->assertTrue(Validator::validateHtml($text));
    }

    public static function providerNoMarkup(): array {
        return [
            'null'                  => [null],
            'empty'                 => [''],
            'plain text'            => ['plain text'],
            'spaced comparison'     => ['5 < 6'],
            'greater than'          => ['a > b'],
            // strip_tags() read each of these as a tag and reported them as HTML.
            'unspaced comparison'   => ['1<2'],
            'letter comparison'     => ['if a<b then'],
            'heart'                 => ['I <3 you'],
            'NUL byte'              => ["a\0b"],
            'unterminated tag'      => ['<p'],
        ];
    }

    #[DataProvider('providerNoMarkup')]
    public function testValidateHtmlReturnsFalseWithoutMarkup(?string $text): void {
        $this->assertFalse(Validator::validateHtml($text));
    }

    /**
     * Pins the documented limitation: it detects TAGS, not entities. Callers must not read a
     * FALSE here as "safe to render" — that is Security::xssClean()'s job.
     */
    public function testValidateHtmlDoesNotDetectHtmlEntities(): void {
        $this->assertFalse(Validator::validateHtml('&amp;'));
        $this->assertFalse(Validator::validateHtml('&lt;script&gt;'));
    }

    // ----------------------------------------------------- locale independence

    /**
     * Without /u, PCRE builds \s and \d from the process LC_CTYPE: under a cp1252 locale "\xB2"
     * (the superscript ²) counted as a digit and "\xA0" as whitespace, so "\xB21234567890" and
     * "52998224725\xA0" validated as CPFs and "\xA0\xA0" read as completely empty. The classes
     * are spelled out as ASCII now, so the locale cannot change what a document is.
     */
    public function testDocumentValidationDoesNotDependOnTheProcessLocale(): void {
        $previous = setlocale(LC_CTYPE, '0');
        $cp1252 = setlocale(LC_CTYPE, 'Portuguese_Brazil.1252', 'pt_BR.CP1252', 'pt_BR.cp1252', 'en_US.CP1252', 'English_United States.1252');
        try {
            if ($cp1252 === false) {
                $this->markTestSkipped('No cp1252 locale is installed on this host.');
            }

            $this->assertFalse(Validator::validateCpf("\xB21234567890"), 'a superscript digit is not a digit');
            $this->assertFalse(Validator::validateCpf("52998224725\xA0"), 'a non-breaking space is not a mask separator');
            $this->assertFalse(Validator::validateCnpj("11222333000181\xA0"));
            $this->assertFalse(Validator::isCompletelyEmpty("\xA0\xA0"));
            $this->assertFalse(Validator::isNegativeNumber("\xA0-5") === true && !str_starts_with("\xA0-5", "\xA0"), 'sanity');
            $this->assertTrue(Validator::validateCpf('529.982.247-25'));
            $this->assertTrue(Validator::validateCnpj('11.222.333/0001-81'));
        } finally {
            setlocale(LC_CTYPE, $previous);
        }
    }
}
