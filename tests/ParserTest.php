<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\TestCase;
use VD\PHPHelper\Parser;

final class ParserTest extends TestCase {
    /** @var string[] Absolute paths created under sys_get_temp_dir(), removed in tearDown(). */
    private array $tempFiles = [];

    protected function tearDown(): void {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
        libxml_clear_errors();
        libxml_use_internal_errors(false);
    }

    private function tempFile(string $contents, string $extension = '.xml'): string {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phphelper_parser_' . bin2hex(random_bytes(8)) . $extension;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

    // ---------------------------------------------------------------- decodeText

    public function testDecodeTextConvertsUnicodeEscapeSequences(): void {
        $this->assertSame('í', Parser::decodeText('í'));
        $this->assertSame('café', Parser::decodeText('café'));
    }

    public function testDecodeTextLeavesPlainTextAndNullUntouched(): void {
        $this->assertSame('plain text', Parser::decodeText('plain text'));
        $this->assertNull(Parser::decodeText(null));
        $this->assertSame('', Parser::decodeText(''));
    }

    // ---------------------------------------------------------------- decodeTextArray

    public function testDecodeTextArrayDecodesRecursivelyAndPreservesKeys(): void {
        $result = Parser::decodeTextArray([
            'name' => 'José',
            'nested' => ['city' => 'São Paulo', 'n' => 7],
        ]);

        $this->assertSame(['name' => 'José', 'nested' => ['city' => 'São Paulo', 'n' => 7]], $result);
    }

    public function testDecodeTextArrayReturnsEmptyArrayForEmptyInput(): void {
        $this->assertSame([], Parser::decodeTextArray([]));
    }

    // ---------------------------------------------------------------- arrayToXml

    /** Pins the fix: a list of records used to vanish, leaving `<root><items/></root>`. */
    public function testArrayToXmlKeepsEveryRowOfAListInsteadOfSilentlyDroppingThem(): void {
        $xml = null;
        Parser::arrayToXml(['items' => [['id' => 1], ['id' => 2]]], $xml);

        $this->assertSame(
            '<?xml version="1.0"?>' . "\n" . '<root><items><item><id>1</id></item><item><id>2</id></item></items></root>' . "\n",
            $xml->asXML()
        );

        // The rows must survive a real parse, not just look right.
        $reparsed = Parser::xmlToArray($xml->asXML());
        $this->assertSame(['items' => ['item' => [['id' => '1'], ['id' => '2']]]], $reparsed);
    }

    /** Pins the fix: `empty($xml)` is TRUE for a childless SimpleXMLElement, so subnodes were detached. */
    public function testArrayToXmlAppendsIntoAnExistingChildlessNodeInsteadOfReplacingIt(): void {
        $xml = new \SimpleXMLElement('<envelope/>');
        Parser::arrayToXml(['a' => 1], $xml);

        $this->assertSame('envelope', $xml->getName());
        $this->assertSame('1', (string) $xml->a);
    }

    /** Pins the fix: `arrayToXml([], $xml)` used to fatal with a TypeError from addChild(). */
    public function testArrayToXmlOnEmptyArrayProducesBareRootInsteadOfThrowing(): void {
        $xml = null;
        Parser::arrayToXml([], $xml);

        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        $this->assertSame('<?xml version="1.0"?>' . "\n" . '<root/>' . "\n", $xml->asXML());
    }

    /** Pins the fix: addChild() does not escape '&' — it warned and DROPPED the whole value. */
    public function testArrayToXmlEscapesAmpersandsInsteadOfDiscardingTheValue(): void {
        $xml = null;
        Parser::arrayToXml(['note' => 'Acme & Co <b>tag</b>'], $xml);

        $this->assertStringContainsString('Acme &amp; Co &lt;b&gt;tag&lt;/b&gt;', $xml->asXML());

        $reparsed = simplexml_load_string($xml->asXML());
        $this->assertInstanceOf(\SimpleXMLElement::class, $reparsed);
        $this->assertSame('Acme & Co <b>tag</b>', (string) $reparsed->note);
    }

    public function testArrayToXmlUsesCustomRootNodeAndAcceptsObjects(): void {
        $xml = null;
        Parser::arrayToXml((object) ['a' => 'x'], $xml, 'payload');

        $this->assertSame('payload', $xml->getName());
        $this->assertSame('x', (string) $xml->a);
    }

    public function testArrayToXmlFallsBackToRootWhenRootNodeIsEmpty(): void {
        $xml = null;
        Parser::arrayToXml(['a' => 1], $xml, '');
        $this->assertSame('root', $xml->getName());
    }

    public function testArrayToXmlThrowsOnRootNodeThatIsNotAValidXmlName(): void {
        libxml_use_internal_errors(true);
        $xml = null;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('String could not be parsed as XML');
        Parser::arrayToXml(['a' => 1], $xml, '9bad');
    }

    public function testArrayToXmlRendersScalarEdgeValues(): void {
        $xml = null;
        Parser::arrayToXml(['t' => true, 'f' => false, 'n' => null, 'z' => 0], $xml);

        $this->assertSame('1', (string) $xml->t);
        $this->assertSame('', (string) $xml->f);
        $this->assertSame('', (string) $xml->n);
        $this->assertSame('0', (string) $xml->z);
    }

    // ---------------------------------------------------------------- arrayRemoveNulls

    /** Pins the fix: the body appended to `$cleaned[]`, destroying every string key. */
    public function testArrayRemoveNullsPreservesStringKeys(): void {
        $this->assertSame(
            ['name' => 'Ana', 'cpf' => '123'],
            Parser::arrayRemoveNulls(['name' => 'Ana', 'age' => null, 'cpf' => '123'])
        );
    }

    public function testArrayRemoveNullsIsRecursiveAndKeepsOtherFalsyValues(): void {
        $this->assertSame(
            ['a' => ['b' => 0, 'c' => ''], 'd' => false, 'e' => []],
            Parser::arrayRemoveNulls(['a' => ['b' => 0, 'x' => null, 'c' => ''], 'd' => false, 'e' => []])
        );
    }

    public function testArrayRemoveNullsReturnsEmptyArrayForNullAndEmpty(): void {
        $this->assertSame([], Parser::arrayRemoveNulls(null));
        $this->assertSame([], Parser::arrayRemoveNulls([]));
        $this->assertSame([], Parser::arrayRemoveNulls([null, null]));
    }

    // ---------------------------------------------------------------- arrayToObject

    /** Pins the fix: a top-level list threw `Return value must be of type ?object, array returned`. */
    public function testArrayToObjectAcceptsAListInsteadOfThrowingTypeError(): void {
        $result = Parser::arrayToObject([1, 2, 3]);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame(1, $result->{'0'});
        $this->assertSame(3, $result->{'2'});
    }

    public function testArrayToObjectAcceptsAResultSetOfRecords(): void {
        $result = Parser::arrayToObject([['id' => 1], ['id' => 2]]);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame(2, $result->{'1'}->id);
    }

    public function testArrayToObjectConvertsAssociativeArraysDeeplyAndKeepsNestedLists(): void {
        $result = Parser::arrayToObject(['a' => 1, 'sub' => ['b' => 2], 'tags' => ['x', 'y']]);

        $this->assertSame(1, $result->a);
        $this->assertSame(2, $result->sub->b);
        $this->assertSame(['x', 'y'], $result->tags);
    }

    public function testArrayToObjectReturnsNullForEmptyArray(): void {
        $this->assertNull(Parser::arrayToObject([]));
    }

    /** Pins the fix: json_encode() returns false on bad UTF-8, which fataled against `?object`. */
    public function testArrayToObjectReturnsNullOnUnencodableDataInsteadOfThrowing(): void {
        $this->assertNull(Parser::arrayToObject(['s' => "\xB1\x31"]));
    }

    public function testArrayToObjectRoundTripsThroughObjectToArray(): void {
        $original = ['id' => 1, 'tags' => ['x', 'y'], 'sub' => ['b' => 2]];
        $this->assertSame($original, Parser::objectToArray(Parser::arrayToObject($original)));
    }

    // ---------------------------------------------------------------- objectToArray

    public function testObjectToArrayConvertsDeeply(): void {
        $this->assertSame(
            ['a' => 1, 'sub' => ['b' => 2]],
            Parser::objectToArray((object) ['a' => 1, 'sub' => (object) ['b' => 2]])
        );
    }

    public function testObjectToArrayReturnsEmptyArrayForNullAndPropertyLessObject(): void {
        $this->assertSame([], Parser::objectToArray(null));
        $this->assertSame([], Parser::objectToArray(new \stdClass()));
    }

    /** Pins the fix: bad UTF-8 (e.g. cp850/latin1 DBF data) threw `Return value must be of type array`. */
    public function testObjectToArrayReturnsEmptyArrayOnMalformedUtf8InsteadOfThrowing(): void {
        $this->assertSame([], Parser::objectToArray((object) ['s' => "\xB1\x31"]));
    }

    /** Pins the fix: a JsonSerializable yielding a scalar threw `..., string returned`. */
    public function testObjectToArrayReturnsEmptyArrayWhenJsonFormIsAScalar(): void {
        $scalarSerializable = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed {
                return 'i am a scalar';
            }
        };

        $this->assertSame([], Parser::objectToArray($scalarSerializable));
    }

    public function testObjectToArrayDropsNonPublicProperties(): void {
        $object = new class {
            public string $visible = 'yes';
            protected string $hiddenProtected = 'no';
            private string $hiddenPrivate = 'never';

            public function touch(): string {
                return $this->hiddenProtected . $this->hiddenPrivate;
            }
        };

        $this->assertSame(['visible' => 'yes'], Parser::objectToArray($object));
    }

    // ---------------------------------------------------------------- xmlToArray

    public function testXmlToArrayParsesAnXmlString(): void {
        $this->assertSame(['a' => '1', 'b' => 'x'], Parser::xmlToArray('<r><a>1</a><b>x</b></r>'));
    }

    /**
     * Pins the fix: the XXE guard's entity loader also intercepted libxml's request for the MAIN
     * DOCUMENT, so simplexml_load_file() always failed and the documented "path to XML file" input
     * silently returned [] while emitting a PHP warning.
     */
    public function testXmlToArrayParsesAnXmlFileInsteadOfSilentlyReturningEmpty(): void {
        $path = $this->tempFile('<r><a>1</a><b>two</b></r>');

        $this->assertSame(['a' => '1', 'b' => 'two'], Parser::xmlToArray($path));
    }

    public function testXmlToArrayFileAndStringInputAgree(): void {
        $xml = '<r><a>1</a></r>';
        $this->assertSame(Parser::xmlToArray($xml), Parser::xmlToArray($this->tempFile($xml)));
    }

    public function testXmlToArrayReturnsEmptyArrayForEmptyInputAndDirectory(): void {
        $this->assertSame([], Parser::xmlToArray(''));
        $this->assertSame([], Parser::xmlToArray(sys_get_temp_dir()));
    }

    public function testXmlToArrayReturnsEmptyArrayForMalformedXml(): void {
        libxml_use_internal_errors(true);
        $this->assertSame([], Parser::xmlToArray('<a><unclosed></a>'));
    }

    /** The XXE hardening must hold: no LIBXML_NOENT, so an external entity is never expanded. */
    public function testXmlToArrayDoesNotExpandExternalEntities(): void {
        libxml_use_internal_errors(true);
        $canaryFile = $this->tempFile('XXE-CANARY-VALUE', '.txt');
        $xxe = '<?xml version="1.0"?>'
            . '<!DOCTYPE r [<!ENTITY xxe SYSTEM "file://' . str_replace('\\', '/', $canaryFile) . '">]>'
            . '<r><a>&xxe;</a></r>';

        $result = Parser::xmlToArray($xxe);

        $flattened = json_encode($result);
        $this->assertIsString($flattened);
        $this->assertStringNotContainsString('XXE-CANARY-VALUE', $flattened);
    }

    /** The file branch now parses real files, so the XXE guard must still hold on that path too. */
    public function testXmlToArrayDoesNotExpandExternalEntitiesFromAFile(): void {
        libxml_use_internal_errors(true);
        $canaryFile = $this->tempFile('XXE-CANARY-VALUE', '.txt');
        $xxeFile = $this->tempFile(
            '<?xml version="1.0"?>'
            . '<!DOCTYPE r [<!ENTITY xxe SYSTEM "file://' . str_replace('\\', '/', $canaryFile) . '">]>'
            . '<r><a>&xxe;</a></r>'
        );

        $flattened = json_encode(Parser::xmlToArray($xxeFile));

        $this->assertIsString($flattened);
        $this->assertStringNotContainsString('XXE-CANARY-VALUE', $flattened);
    }

    // ---------------------------------------------------------------- base64Decode

    public function testBase64DecodeDecodesPlainAndDataUriInput(): void {
        $this->assertSame('Hello', Parser::base64Decode('SGVsbG8='));
        $this->assertSame('Hello', Parser::base64Decode('data:image/png;base64,SGVsbG8='));
    }

    public function testBase64DecodeReturnsFalseForEmptyAndInvalidInput(): void {
        $this->assertFalse(Parser::base64Decode(null));
        $this->assertFalse(Parser::base64Decode(''));
        $this->assertFalse(Parser::base64Decode('!!!invalid!!!'));
    }

    // ---------------------------------------------------------------- base64UrlEncode / base64UrlDecode

    public function testBase64UrlEncodeUsesTheDocumentedNonStandardAlphabet(): void {
        // Documented as NOT RFC 4648: '+' => '.', '/' => '_', '=' => '-'.
        $encoded = Parser::base64UrlEncode('~~~?a=b&c');

        $this->assertSame('fn5.P2E9YiZj', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
    }

    public function testBase64UrlEncodePadsWithHyphenNotRfc4648(): void {
        // 'Hi' -> base64 'SGk=' -> '=' becomes '-', where RFC 4648 base64url would strip it.
        $this->assertSame('SGk-', Parser::base64UrlEncode('Hi'));
    }

    public function testBase64UrlEncodeReturnsNullForEmptyInput(): void {
        $this->assertNull(Parser::base64UrlEncode(null));
        $this->assertNull(Parser::base64UrlEncode(''));
    }

    public function testBase64UrlPairRoundTripsBinarySafeData(): void {
        $payload = random_bytes(64);
        $this->assertSame($payload, Parser::base64UrlDecode(Parser::base64UrlEncode($payload)));
    }

    /**
     * Pins the fix: the `?string` return type coerced base64Decode()'s FALSE into '', so the
     * documented `=== false` guard never fired and a malformed token passed as a decoded value.
     */
    public function testBase64UrlDecodeReturnsStrictFalseOnInvalidInputNotEmptyString(): void {
        $result = Parser::base64UrlDecode('!!!invalid!!!');

        $this->assertFalse($result);
        $this->assertNotSame('', $result);
    }

    public function testBase64UrlDecodeReturnsFalseForEmptyInput(): void {
        $this->assertFalse(Parser::base64UrlDecode(null));
        $this->assertFalse(Parser::base64UrlDecode(''));
    }

    public function testBase64UrlDecodeIsAlwaysStrictAndIgnoresAPhantomStrictArgument(): void {
        // The docblock used to advertise a $strict parameter the signature never had. PHP silently
        // discards the extra argument; decoding stays strict either way.
        $this->assertSame('Hello', Parser::base64UrlDecode('SGVsbG8-', false)); // @phpstan-ignore-line
        $this->assertFalse(Parser::base64UrlDecode('!!!invalid!!!', true)); // @phpstan-ignore-line
        $this->assertSame(1, (new \ReflectionMethod(Parser::class, 'base64UrlDecode'))->getNumberOfParameters());
    }

    // ---------------------------------------------------------------- stringToBinary / binaryToString

    /** Pins the fix: the pad target was `strlen($bin) * 8`, giving 56 bits for 'A' and 64 for 0xFF. */
    public function testStringToBinaryEmitsExactlyEightBitsPerByte(): void {
        $this->assertSame('01000001', Parser::stringToBinary('A'));
        $this->assertSame('11111111', Parser::stringToBinary("\xFF"));
        $this->assertSame('01100001 00001010', Parser::stringToBinary("a\n"));
    }

    public function testStringToBinaryGroupsAreAllEightBitsWideForMixedInput(): void {
        $groups = explode(' ', Parser::stringToBinary("Aÿ\x00é"));

        foreach ($groups as $group) {
            $this->assertSame(8, strlen($group), 'every group must be 8 bits wide');
            $this->assertMatchesRegularExpression('/^[01]{8}$/', $group);
        }
    }

    public function testStringToBinaryReturnsEmptyStringForNullAndEmpty(): void {
        $this->assertSame('', Parser::stringToBinary(null));
        $this->assertSame('', Parser::stringToBinary(''));
    }

    /**
     * Pins the fix: base_convert() drops leading zeros and pack('H*') pads on the RIGHT, so every
     * byte below 0x10 was corrupted — 0x0A (LF) came back as 0xA0.
     */
    public function testBinaryToStringRestoresBytesBelowSixteenInsteadOfCorruptingThem(): void {
        $this->assertSame("a\nb", Parser::binaryToString(Parser::stringToBinary("a\nb")));
        $this->assertSame("a\tb", Parser::binaryToString(Parser::stringToBinary("a\tb")));
        $this->assertSame("\x00\x01\x0F", Parser::binaryToString(Parser::stringToBinary("\x00\x01\x0F")));
    }

    public function testBinaryToStringRoundTripsEveryPossibleByteValue(): void {
        $allBytes = '';
        for ($i = 0; $i <= 255; $i++) {
            $allBytes .= chr($i);
        }

        $this->assertSame($allBytes, Parser::binaryToString(Parser::stringToBinary($allBytes)));
    }

    public function testBinaryToStringAcceptsLegacyWiderGroups(): void {
        // Groups wider than 8 bits (written by the old ragged-width encoder) must still decode.
        $this->assertSame('A', Parser::binaryToString('0000000000000000001000001'));
    }

    /** Pins the fix: explode(' ', '') is [''] and base_convert('', 2, 16) is '0' — it emitted a NUL. */
    public function testBinaryToStringReturnsEmptyStringForNullAndEmptyNotANulByte(): void {
        $this->assertSame('', Parser::binaryToString(null));
        $this->assertSame('', Parser::binaryToString(''));
    }

    // ---------------------------------------------------------------- strToHex / hexToStr

    public function testStrToHexProducesUppercaseTwoDigitPairs(): void {
        $this->assertSame('610A62', Parser::strToHex("a\nb"));
        $this->assertSame('00FF', Parser::strToHex("\x00\xFF"));
    }

    public function testStrToHexReturnsEmptyStringForNullAndEmpty(): void {
        $this->assertSame('', Parser::strToHex(null));
        $this->assertSame('', Parser::strToHex(''));
    }

    public function testHexToStrDecodesUpperAndLowerCaseHex(): void {
        $this->assertSame("a\nb", Parser::hexToStr('610A62'));
        $this->assertSame("a\nb", Parser::hexToStr('610a62'));
    }

    public function testHexToStrIgnoresATrailingOddNibble(): void {
        $this->assertSame('a', Parser::hexToStr('616'));
    }

    public function testHexToStrReturnsEmptyStringForNullAndEmpty(): void {
        $this->assertSame('', Parser::hexToStr(null));
        $this->assertSame('', Parser::hexToStr(''));
    }

    public function testStrToHexRoundTripsThroughHexToStr(): void {
        $payload = random_bytes(32);
        $this->assertSame($payload, Parser::hexToStr(Parser::strToHex($payload)));
    }

    // ---------------------------------------------------------------- resetArrayIndexes

    public function testResetArrayIndexesRewritesKeysSequentiallyInPlace(): void {
        $array = [3 => 'a', 7 => 'b', 'x' => 'c'];
        Parser::resetArrayIndexes($array);

        $this->assertSame(['a', 'b', 'c'], $array);
    }

    public function testResetArrayIndexesLeavesAnEmptyArrayAlone(): void {
        $array = [];
        Parser::resetArrayIndexes($array);

        $this->assertSame([], $array);
    }

    // ---------------------------------------------------------------- getBool

    /**
     * Pins the fix: getBool(false) returned TRUE while getBool('false') returned FALSE — an
     * inversion that fails OPEN on a permission or visibility flag.
     */
    public function testGetBoolReturnsFalseForBooleanFalseAndNumericZero(): void {
        $this->assertFalse(Parser::getBool(false));
        $this->assertFalse(Parser::getBool(0));
        $this->assertFalse(Parser::getBool('0'));
        $this->assertFalse(Parser::getBool(0.0));
        $this->assertFalse(Parser::getBool('0.0'));
        $this->assertFalse(Parser::getBool('00'));
        $this->assertFalse(Parser::getBool('-0'));
        $this->assertFalse(Parser::getBool(' 0 '));
    }

    public function testGetBoolReturnsFalseForTheDocumentedEmptyVocabulary(): void {
        foreach ([null, '', "\0", [], 'false', 'FALSE', ' no ', 'N', 'tno', 'null', 'undefined', '{}', '[]'] as $value) {
            $this->assertFalse(Parser::getBool($value), var_export($value, true) . ' must be falsy');
        }

        $this->assertFalse(Parser::getBool(new \stdClass()));
    }

    public function testGetBoolReturnsTrueForTruthyValues(): void {
        foreach ([true, 1, -1, 0.5, '1', 'yes', 'true', 'anything', ['a'], '0.1'] as $value) {
            $this->assertTrue(Parser::getBool($value), var_export($value, true) . ' must be truthy');
        }

        $this->assertTrue(Parser::getBool((object) ['a' => 1]));
    }

    /** Pins the fix: a non-empty array made the internal string cast emit "Array to string conversion". */
    public function testGetBoolHandlesArraysWithoutEmittingAWarning(): void {
        $this->assertTrue(Parser::getBool(['a', 'b']));
        $this->assertFalse(Parser::getBool([]));
    }

    /** Pins the fix: a non-empty object threw `Error: Object of class stdClass could not be converted to string`. */
    public function testGetBoolHandlesObjectsWithoutThrowing(): void {
        $this->assertTrue(Parser::getBool((object) ['a' => 1]));
        $this->assertFalse(Parser::getBool((object) []));
    }

    public function testGetBoolJudgesStringableObjectsByTheirStringValue(): void {
        $no = new class implements \Stringable {
            public function __toString(): string {
                return 'no';
            }
        };
        $yes = new class implements \Stringable {
            public function __toString(): string {
                return 'yes';
            }
        };

        $this->assertFalse(Parser::getBool($no));
        $this->assertTrue(Parser::getBool($yes));
    }

    // ---------------------------------------------------------------- extractJsonBlocks

    public function testExtractJsonBlocksPullsBalancedObjectsAndArraysOutOfNoise(): void {
        $this->assertSame(
            ['{"a":{"b":1}}', '[1,2]'],
            Parser::extractJsonBlocks('noise {"a":{"b":1}} tail [1,2] end')
        );
    }

    public function testExtractJsonBlocksReturnsEmptyArrayWhenNothingIsBalanced(): void {
        $this->assertSame([], Parser::extractJsonBlocks('{"unclosed": 1'));
        $this->assertSame([], Parser::extractJsonBlocks('no json here'));
        $this->assertSame([], Parser::extractJsonBlocks(null));
        $this->assertSame([], Parser::extractJsonBlocks(''));
    }

    public function testExtractJsonBlocksOutputIsParsableJson(): void {
        $blocks = Parser::extractJsonBlocks('log: {"id":1,"tags":["a","b"]} done');

        $this->assertCount(1, $blocks);
        $this->assertSame(['id' => 1, 'tags' => ['a', 'b']], json_decode($blocks[0], true));
    }

    // ---------------------------------------------------------------- splitLines

    /**
     * Pins the fix: the pattern's `\s` matched a SPACE, so this returned
     * ['John','Doe','Jane','Roe'] and joinLines() turned every space into a '<br />'.
     */
    public function testSplitLinesSplitsOnLineBreaksOnlyAndNeverOnSpaces(): void {
        $this->assertSame(['John Doe', 'Jane Roe'], Parser::splitLines("John Doe\nJane Roe"));
        $this->assertSame(
            ['Rua das Flores, 100', 'Sao Paulo'],
            Parser::splitLines('Rua das Flores, 100<br />Sao Paulo')
        );
    }

    /** The documented round-trip must be lossless — this is what the space-splitting bug destroyed. */
    public function testSplitLinesJoinLinesRoundTripPreservesAnAddress(): void {
        $address = 'Rua das Flores, 100<br />Sao Paulo';
        $this->assertSame($address, Parser::joinLines(Parser::splitLines($address)));

        $withTab = "col a\tcol b";
        $this->assertSame($withTab, Parser::joinLines(Parser::splitLines($withTab)));
    }

    public function testSplitLinesHandlesEveryDocumentedDelimiter(): void {
        $this->assertSame(['a', 'b'], Parser::splitLines("a\r\nb"));
        $this->assertSame(['a', 'b'], Parser::splitLines("a\rb"));
        $this->assertSame(['a', 'b'], Parser::splitLines("a\nb"));
        $this->assertSame(['a', 'b'], Parser::splitLines('a<br>b'));
        $this->assertSame(['a', 'b'], Parser::splitLines('a<br/>b'));
        $this->assertSame(['a', 'b'], Parser::splitLines('a<BR />b'));
    }

    public function testSplitLinesTreatsABrFollowedByANewlineAsASingleBreak(): void {
        $this->assertSame(['a', 'b'], Parser::splitLines("a<br />\nb"));
        $this->assertSame(['a', 'b'], Parser::splitLines("a<br />\r\nb"));
    }

    public function testSplitLinesPreservesBlankLinesAsEmptyEntries(): void {
        $this->assertSame(['a', '', 'b'], Parser::splitLines("a\n\nb"));
        $this->assertSame(['a', '', 'b'], Parser::splitLines('a<br /><br />b'));
    }

    public function testSplitLinesReturnsEmptyArrayForEmptyInput(): void {
        $this->assertSame([], Parser::splitLines(null));
        $this->assertSame([], Parser::splitLines(''));
        // Documented quirk: empty() semantics mean the string '0' is treated as empty.
        $this->assertSame([], Parser::splitLines('0'));
    }

    // ---------------------------------------------------------------- joinLines

    public function testJoinLinesUsesBrByDefaultAndAcceptsACustomGlue(): void {
        $this->assertSame('a<br />b', Parser::joinLines(['a', 'b']));
        $this->assertSame("a\nb", Parser::joinLines(['a', 'b'], "\n"));
        $this->assertSame('ab', Parser::joinLines(['a', 'b'], ''));
    }

    public function testJoinLinesReturnsEmptyStringForNullAndEmpty(): void {
        $this->assertSame('', Parser::joinLines(null));
        $this->assertSame('', Parser::joinLines([]));
    }

    // ---------------------------------------------------------------- timeToSeconds / secondsToTime

    public function testTimeToSecondsConvertsAWellFormedTime(): void {
        $this->assertSame(3723, Parser::timeToSeconds('01:02:03'));
        $this->assertSame(0, Parser::timeToSeconds('00:00:00'));
        $this->assertSame(90000, Parser::timeToSeconds('25:00:00'));
    }

    public function testTimeToSecondsReturnsZeroForInvalidFormat(): void {
        $this->assertSame(0, Parser::timeToSeconds('bogus'));
        $this->assertSame(0, Parser::timeToSeconds('01:02'));
        $this->assertSame(0, Parser::timeToSeconds(null));
        $this->assertSame(0, Parser::timeToSeconds(''));
    }

    public function testSecondsToTimeFormatsAsHhMmSs(): void {
        $this->assertSame('01:02:03', Parser::secondsToTime(3723));
        $this->assertSame('01:02:03', Parser::secondsToTime('3723'));
        $this->assertSame('00:00:00', Parser::secondsToTime(0));
        $this->assertSame('00:00:00', Parser::secondsToTime(null));
    }

    public function testSecondsToTimeRoundTripsThroughTimeToSeconds(): void {
        $this->assertSame(3723, Parser::timeToSeconds(Parser::secondsToTime(3723)));
    }

    // ---------------------------------------------------------------- encodeHtml / decodeHtml

    public function testEncodeHtmlNeutralizesStructuralCharacters(): void {
        $encoded = Parser::encodeHtml('<script>alert("x")</script>');

        $this->assertStringNotContainsString('<script>', $encoded);
        $this->assertStringContainsString('&lt;script&gt;', $encoded);
    }

    public function testEncodeHtmlReturnsNullAndEmptyUnchanged(): void {
        $this->assertNull(Parser::encodeHtml(null));
        $this->assertSame('', Parser::encodeHtml(''));
    }

    public function testDecodeHtmlReversesEncodeHtml(): void {
        $original = '<b>café & "quotes"</b>';
        $this->assertSame($original, Parser::decodeHtml(Parser::encodeHtml($original)));
    }

    public function testDecodeHtmlReturnsNullAndEmptyUnchanged(): void {
        $this->assertNull(Parser::decodeHtml(null));
        $this->assertSame('', Parser::decodeHtml(''));
    }

    // ---------------------------------------------------------------- stringToNumericSequence / numericSequenceToString

    public function testStringToNumericSequenceProducesThreeDigitCodes(): void {
        $this->assertSame('065066067', Parser::stringToNumericSequence('ABC'));
        $this->assertSame('000255', Parser::stringToNumericSequence("\x00\xFF"));
    }

    public function testStringToNumericSequenceReturnsNullAndEmptyUnchanged(): void {
        $this->assertNull(Parser::stringToNumericSequence(null));
        $this->assertSame('', Parser::stringToNumericSequence(''));
    }

    public function testNumericSequenceToStringDecodesThreeDigitCodes(): void {
        $this->assertSame('ABC', Parser::numericSequenceToString('065066067'));
    }

    public function testNumericSequencePairRoundTripsEveryByteValue(): void {
        $allBytes = '';
        for ($i = 0; $i <= 255; $i++) {
            $allBytes .= chr($i);
        }

        $this->assertSame($allBytes, Parser::numericSequenceToString(Parser::stringToNumericSequence($allBytes)));
    }

    public function testNumericSequenceToStringReturnsNullAndEmptyUnchanged(): void {
        $this->assertNull(Parser::numericSequenceToString(null));
        $this->assertSame('', Parser::numericSequenceToString(''));
    }

    public function testNumericSequenceToStringThrowsOnNonNumericInputAsDocumented(): void {
        $this->expectException(\TypeError::class);
        Parser::numericSequenceToString('abcdef');
    }

    // ---------------------------------------------------------------- setValueForKeyInArray

    /** Pins the fix: the null branch returned null from a `: array` method, throwing a TypeError. */
    public function testSetValueForKeyInArrayReturnsEmptyArrayForNullInsteadOfThrowing(): void {
        $this->assertSame([], Parser::setValueForKeyInArray(null, 'tenant_id', 7));
        $this->assertSame([], Parser::setValueForKeyInArray([], 'tenant_id', 7));
    }

    public function testSetValueForKeyInArraySetsTheKeyOnEveryRow(): void {
        $result = Parser::setValueForKeyInArray([['a' => 1], ['a' => 2]], 'tenant_id', 7);

        $this->assertSame([['a' => 1, 'tenant_id' => 7], ['a' => 2, 'tenant_id' => 7]], $result);
    }

    public function testSetValueForKeyInArrayOverwritesAnExistingKeyAndPreservesOuterKeys(): void {
        $result = Parser::setValueForKeyInArray(['x' => ['a' => 1, 't' => 'old']], 't', 'new');

        $this->assertSame(['x' => ['a' => 1, 't' => 'new']], $result);
    }

    public function testSetValueForKeyInArrayIsANoOpForEmptyKeyButStillAcceptsZeroAsAKey(): void {
        $rows = [['a' => 1]];

        $this->assertSame($rows, Parser::setValueForKeyInArray($rows, null, 'v'));
        $this->assertSame($rows, Parser::setValueForKeyInArray($rows, '', 'v'));
        // '0' is NOT empty here: emptyExceptZero(), not empty().
        $this->assertSame([['a' => 1, '0' => 'v']], Parser::setValueForKeyInArray($rows, '0', 'v'));
    }

    public function testSetValueForKeyInArrayDoesNotMutateTheCallersArray(): void {
        $rows = [['a' => 1]];
        Parser::setValueForKeyInArray($rows, 't', 9);

        $this->assertSame([['a' => 1]], $rows, 'the input array must not be modified by reference');
    }

    public function testSetValueForKeyInArrayThrowsOnAScalarRowAsDocumented(): void {
        $this->expectException(\Error::class);
        Parser::setValueForKeyInArray(['not an array'], 'k', 'v');
    }

    // ---------------------------------------------------------------- findItemByKey

    /**
     * Pins the fix: array_column() re-indexes, so a key-less row shifted the mapping and this
     * silently returned a DIFFERENT record — a cross-record leak with no error raised.
     */
    public function testFindItemByKeyReturnsTheRightRowWhenAnotherRowLacksTheKey(): void {
        $rows = [['id' => 1, 'n' => 'one'], ['n' => 'no-id'], ['id' => 3, 'n' => 'three']];

        $this->assertSame(['id' => 3, 'n' => 'three'], Parser::findItemByKey($rows, 'id', 3));
        $this->assertSame(['id' => 1, 'n' => 'one'], Parser::findItemByKey($rows, 'id', 1));
    }

    /** Pins the fix: a string-keyed map (any array_filter result) returned [] for items present. */
    public function testFindItemByKeyFindsItemsInANonSequentiallyKeyedList(): void {
        $rows = ['a' => ['id' => 1], 'b' => ['id' => 2]];

        $this->assertSame(['id' => 2], Parser::findItemByKey($rows, 'id', 2));
    }

    public function testFindItemByKeyFindsItemsInAGappedListFromArrayFilter(): void {
        $rows = array_filter(
            [['id' => 1], ['id' => 2], ['id' => 3]],
            fn(array $row): bool => $row['id'] !== 2
        );

        $this->assertSame(['id' => 3], Parser::findItemByKey($rows, 'id', 3));
    }

    /** Pins the fix: the docblock invites objects, but returning one fataled against `: array`. */
    public function testFindItemByKeyAcceptsAListOfObjectsAndReturnsAnArray(): void {
        $rows = [(object) ['id' => 1, 'n' => 'one'], (object) ['id' => 2, 'n' => 'two']];

        $this->assertSame(['id' => 2, 'n' => 'two'], Parser::findItemByKey($rows, 'id', 2));
    }

    public function testFindItemByKeyHandlesAMixedListOfArraysAndObjects(): void {
        $rows = [['id' => 1], (object) ['id' => 2], 'a scalar row', ['id' => 3]];

        $this->assertSame(['id' => 2], Parser::findItemByKey($rows, 'id', 2));
        $this->assertSame(['id' => 3], Parser::findItemByKey($rows, 'id', 3));
    }

    public function testFindItemByKeyMatchesLooselyOnStringValue(): void {
        $rows = [['id' => 1], ['id' => 2]];

        $this->assertSame(['id' => 2], Parser::findItemByKey($rows, 'id', '2'));
        $this->assertSame(['id' => 2], Parser::findItemByKey($rows, 'id', 2));
    }

    public function testFindItemByKeyReturnsFirstMatchOnDuplicateKeys(): void {
        $rows = [['id' => 1, 'n' => 'first'], ['id' => 1, 'n' => 'second']];

        $this->assertSame(['id' => 1, 'n' => 'first'], Parser::findItemByKey($rows, 'id', 1));
    }

    public function testFindItemByKeyReturnsEmptyArrayWhenNotFound(): void {
        $rows = [['id' => 1], ['id' => 2]];

        $this->assertSame([], Parser::findItemByKey($rows, 'id', 99));
        $this->assertSame([], Parser::findItemByKey($rows, 'nonexistent_key', 1));
    }

    public function testFindItemByKeyReturnsEmptyArrayForEmptyArguments(): void {
        $rows = [['id' => 1]];

        $this->assertSame([], Parser::findItemByKey(null, 'id', 1));
        $this->assertSame([], Parser::findItemByKey([], 'id', 1));
        $this->assertSame([], Parser::findItemByKey($rows, null, 1));
        $this->assertSame([], Parser::findItemByKey($rows, '', 1));
        $this->assertSame([], Parser::findItemByKey($rows, 'id', null));
        $this->assertSame([], Parser::findItemByKey($rows, 'id', ''));
    }

    public function testFindItemByKeyNeverMatchesAnArrayOrObjectValuedKey(): void {
        $rows = [['id' => ['nested']], ['id' => (object) ['a' => 1]], ['id' => 'plain']];

        $this->assertSame(['id' => 'plain'], Parser::findItemByKey($rows, 'id', 'plain'));
        $this->assertSame([], Parser::findItemByKey($rows, 'id', 'Array'));
    }
}
