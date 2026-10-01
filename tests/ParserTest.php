<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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
        libxml_set_external_entity_loader(null);
    }

    private function tempFile(string $contents, string $extension = '.xml'): string {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phphelper_parser_' . bin2hex(random_bytes(8)) . $extension;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * An XXE document whose external entity points at a canary file.
     *
     * The URI MUST be file:///C:/… on Windows. The previous version of these tests built
     * file://C:/…, which libxml rejects as an invalid URI — so the entity could never resolve and
     * the "does not expand" assertions passed even with every guard removed. xxePayloadIsEffective()
     * is the positive control that keeps that from happening again.
     */
    private function xxeDocument(string $canaryFile): string {
        // Every path segment is percent-encoded ("file:///C:/Users/Vitor%20Delgallo/..."): with a
        // raw space libxml refused the URI ("Invalid URI"), the positive control below failed and
        // BOTH XXE tests were SKIPPED on any Windows account whose profile path has a space —
        // the XXE hardening ran untested there, under a skip message that blamed libxml.
        $path = str_replace('\\', '/', (string) realpath($canaryFile));
        $encoded = implode('/', array_map(
            static fn (string $segment): string => preg_match('/^[A-Za-z]:$/', $segment) === 1 ? $segment : rawurlencode($segment),
            explode('/', $path)
        ));
        $uri = 'file:///' . ltrim($encoded, '/');

        return '<?xml version="1.0"?>'
            . '<!DOCTYPE r [<!ENTITY xxe SYSTEM "' . $uri . '">]>'
            . '<r><a>&xxe;</a></r>';
    }

    /** Proves the payload really does read the canary when parsed WITHOUT this class's hardening. */
    private function assertXxePayloadIsEffective(string $xxe, string $canary): void {
        libxml_use_internal_errors(true);
        $unguarded = simplexml_load_string($xxe, \SimpleXMLElement::class, LIBXML_NOENT);
        libxml_clear_errors();

        if ($unguarded === false || (string) $unguarded->a !== $canary) {
            // Only a build that expands NO entity at all makes the control meaningless. If an
            // INTERNAL entity expands while the external one does not, the canary URI (this
            // test's own doing) is what is broken — and that must fail, never skip.
            $internal = simplexml_load_string(
                '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY i "internal-ok">]><r><a>&i;</a></r>',
                \SimpleXMLElement::class,
                LIBXML_NOENT
            );
            libxml_clear_errors();
            if ($internal !== false && (string) $internal->a === 'internal-ok') {
                self::fail('The XXE control did not resolve although entities expand here: the canary URI is wrong, so the hardening would go untested.');
            }
            self::markTestSkipped('This libxml build does not resolve the XXE payload at all, so its absence proves nothing.');
        }
    }

    // ---------------------------------------------------------------- decodeText

    /**
     * The previous version of this test passed 'í' and 'café' — ALREADY-DECODED text — so it
     * asserted that decodeText() leaves plain text alone and would have passed with the method
     * body replaced by `return $str;`.
     */
    public function testDecodeTextConvertsUnicodeEscapeSequences(): void {
        $this->assertSame('í', Parser::decodeText('\u00ed'));
        $this->assertSame('café', Parser::decodeText('caf\u00e9'));
        $this->assertSame('São Paulo', Parser::decodeText('S\u00E3o Paulo'));
    }

    /**
     * Pins the fix: each half of a UTF-16 surrogate pair was converted on its own, producing bytes
     * that are not valid UTF-8 — the emoji every JSON encoder writes as \ud83d\ude00 came back as
     * garbage that then broke json_encode() and any database write.
     */
    public function testDecodeTextDecodesASurrogatePairAsOneCharacter(): void {
        $decoded = Parser::decodeText('smile \ud83d\ude00 ok');

        $this->assertSame("smile \u{1F600} ok", $decoded);
        $this->assertTrue(mb_check_encoding($decoded, 'UTF-8'));
    }

    public function testDecodeTextReplacesALoneSurrogateWithTheReplacementCharacter(): void {
        $this->assertSame("a\u{FFFD}b", Parser::decodeText('a\ud83db'));
        $this->assertSame("a\u{FFFD}b", Parser::decodeText('a\ude00b'));
    }

    public function testDecodeTextLeavesPlainTextAndNullUntouched(): void {
        $this->assertSame('plain text', Parser::decodeText('plain text'));
        $this->assertSame('not \u12 an escape', Parser::decodeText('not \u12 an escape'));
        $this->assertNull(Parser::decodeText(null));
        $this->assertSame('', Parser::decodeText(''));
    }

    // ---------------------------------------------------------------- decodeTextArray

    /** Same defect as the old decodeText test: its input was already decoded, so it proved nothing. */
    public function testDecodeTextArrayDecodesRecursivelyAndPreservesKeys(): void {
        $result = Parser::decodeTextArray([
            'name' => 'Jos\u00e9',
            'nested' => ['city' => 'S\u00e3o Paulo', 'n' => 7],
        ]);

        $this->assertSame(['name' => 'José', 'nested' => ['city' => 'São Paulo', 'n' => 7]], $result);
    }

    public function testDecodeTextArrayLeavesNonStringsAndObjectsUntouched(): void {
        $object = (object) ['a' => '\u00e9'];
        $result = Parser::decodeTextArray(['o' => $object, 'z' => 0, 'f' => false, 'e' => '']);

        $this->assertSame(['o' => $object, 'z' => 0, 'f' => false, 'e' => ''], $result);
        $this->assertSame('\u00e9', $object->a);
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
        Parser::arrayToXml(['note' => 'Acme & Co <b>tag</b> &amp; "q"'], $xml);

        $this->assertStringContainsString('Acme &amp; Co &lt;b&gt;tag&lt;/b&gt;', $xml->asXML());

        $reparsed = simplexml_load_string($xml->asXML());
        $this->assertInstanceOf(\SimpleXMLElement::class, $reparsed);
        $this->assertSame('Acme & Co <b>tag</b> &amp; "q"', (string) $reparsed->note);
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

    /**
     * BEHAVIOUR CHANGE: the root name is validated as an XML Name, instead of being pasted into
     * markup and parsed — which accepted 'r evil="1"' as a root WITH AN INJECTED ATTRIBUTE.
     *
     * @return array<string, array{0: string}>
     */
    public static function invalidRootNodeProvider(): array {
        return [
            'starts with a digit'   => ['9bad'],
            'attribute injection'   => ['r evil="1"'],
            'markup injection'      => ['r/><x'],
            'prefix'                => ['ns:root'],
            'space'                 => ['my root'],
        ];
    }

    #[DataProvider('invalidRootNodeProvider')]
    public function testArrayToXmlThrowsOnRootNodeThatIsNotAValidXmlName(string $rootNode): void {
        $xml = null;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$rootNode must be a legal XML element name');
        Parser::arrayToXml(['a' => 1], $xml, $rootNode);
    }

    public function testArrayToXmlRendersScalarEdgeValues(): void {
        $xml = null;
        Parser::arrayToXml(['t' => true, 'f' => false, 'n' => null, 'z' => 0, 'x' => 1.5], $xml);

        $this->assertSame('1', (string) $xml->t);
        $this->assertSame('', (string) $xml->f);
        $this->assertSame('', (string) $xml->n);
        $this->assertSame('0', (string) $xml->z);
        $this->assertSame('1.5', (string) $xml->x);
    }

    /**
     * Pins the fix: a key that is not an XML Name was emitted as an element name VERBATIM, giving
     * malformed XML — and markup injection for a key built from input ('a><evil'). '' threw a
     * ValueError. Such keys now become <item key="…">, losslessly.
     */
    public function testArrayToXmlCarriesKeysThatAreNotXmlNamesInAnAttribute(): void {
        $keys = ['bad key', 'a><evil x="1"', '1abc', 'ns:el', '', '1.5', 'q"&\'<'];
        $data = [];
        foreach ($keys as $i => $key) {
            $data[$key] = 'v' . $i;
        }

        $xml = null;
        Parser::arrayToXml($data, $xml);

        $reparsed = simplexml_load_string($xml->asXML());
        $this->assertInstanceOf(\SimpleXMLElement::class, $reparsed, 'The output must be well-formed XML.');
        $this->assertCount(count($keys), $reparsed->item);
        foreach ($keys as $i => $key) {
            $this->assertSame($key, (string) $reparsed->item[$i]['key']);
            $this->assertSame('v' . $i, (string) $reparsed->item[$i]);
        }
    }

    /** A legal key that happens to be named 'item' is an ordinary element, with no key attribute. */
    public function testArrayToXmlDoesNotAnnotateALegalKeyNamedItem(): void {
        $xml = null;
        Parser::arrayToXml(['item' => 'a', 'ação' => 'b', '_x.y-z' => 'c'], $xml);

        $this->assertSame(
            '<root><item>a</item><ação>b</ação><_x.y-z>c</_x.y-z></root>',
            html_entity_decode(explode("\n", $xml->asXML())[1], ENT_XML1, 'UTF-8')
        );
    }

    /**
     * Pins the fix: objects were cast with (array), which exposes PRIVATE and PROTECTED properties
     * under NUL-mangled keys — so an entity nested in the data leaked its password hash as
     * `<>HASH</>`, in malformed XML. Objects are now read the way json_encode() reads them.
     */
    public function testArrayToXmlNeverExposesNonPublicProperties(): void {
        $user = new class {
            public string $name = 'ana';
            protected string $token = 'TOKEN-SECRET';
            private string $passwordHash = 'HASH-SECRET';

            public function touch(): string {
                return $this->token . $this->passwordHash;
            }
        };

        $xml = null;
        Parser::arrayToXml(['user' => $user], $xml);
        $out = $xml->asXML();

        $this->assertStringNotContainsString('SECRET', $out);
        $this->assertStringContainsString('<user><name>ana</name></user>', $out);
        $this->assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($out));
    }

    public function testArrayToXmlUsesJsonSerializeDateTimeAndEnumRepresentations(): void {
        $serializable = new class implements \JsonSerializable {
            private string $internal = 'HIDDEN';

            public function jsonSerialize(): mixed {
                return ['shown' => 1, 'at' => new \DateTimeImmutable('2024-01-02 03:04:05', new \DateTimeZone('UTC'))];
            }
        };
        $scalarSerializable = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed {
                return 'as-text';
            }
        };

        $xml = null;
        Parser::arrayToXml([
            'j' => $serializable,
            's' => $scalarSerializable,
            'backed' => ParserTestBackedEnum::Active,
            'pure' => ParserTestPureEnum::Draft,
        ], $xml);
        $out = $xml->asXML();

        $this->assertStringContainsString('<j><shown>1</shown><at>2024-01-02T03:04:05+00:00</at></j>', $out);
        $this->assertStringContainsString('<s>as-text</s>', $out);
        $this->assertStringContainsString('<backed>active</backed>', $out);
        $this->assertStringContainsString('<pure>Draft</pure>', $out);
        $this->assertStringNotContainsString('HIDDEN', $out);
    }

    /** A top-level object that serialises to a scalar becomes the root's text. */
    public function testArrayToXmlPutsAScalarTopLevelObjectIntoTheRootText(): void {
        $xml = null;
        Parser::arrayToXml(new \DateTimeImmutable('2024-01-02 03:04:05', new \DateTimeZone('UTC')), $xml, 'when');

        $this->assertSame('<when>2024-01-02T03:04:05+00:00</when>', explode("\n", $xml->asXML())[1]);
    }

    /**
     * Pins the fix: libxml DROPS a value that is not valid UTF-8 (`<a/>`, silently) or that holds
     * a character XML 1.0 forbids (with a "char out of range" warning). Both now survive, with the
     * offending bytes replaced by U+FFFD, and the document still parses.
     */
    public function testArrayToXmlKeepsValuesWithInvalidUtf8OrForbiddenCharacters(): void {
        $xml = null;
        Parser::arrayToXml(['latin1' => "caf\xE9", 'ctrl' => "x\x01y\x0Bz", "k\x02" => 'v'], $xml);

        $reparsed = simplexml_load_string($xml->asXML());
        $this->assertInstanceOf(\SimpleXMLElement::class, $reparsed);
        $this->assertSame("caf\u{FFFD}", (string) $reparsed->latin1);
        $this->assertSame("x\u{FFFD}y\u{FFFD}z", (string) $reparsed->ctrl);
        $this->assertSame("k\u{FFFD}", (string) $reparsed->item['key']);
    }

    /**
     * Pins the fix: a reference cycle recursed until PHP ran out of memory — an uncatchable fatal
     * error. It now stops at the documented depth with an exception.
     */
    public function testArrayToXmlRejectsAReferenceCycleInsteadOfExhaustingMemory(): void {
        $node = new \stdClass();
        $node->self = $node;
        $xml = null;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('deeper than 512');
        Parser::arrayToXml(['node' => $node], $xml);
    }

    public function testArrayToXmlRejectsAnArrayReferenceCycle(): void {
        $data = ['a' => 1];
        $data['self'] = &$data;
        $xml = null;

        $this->expectException(\InvalidArgumentException::class);
        Parser::arrayToXml($data, $xml);
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

    /** Pins the fix: json_encode() writes 10.0 as "10", so a price came back as int 10. */
    public function testArrayToObjectKeepsWholeFloatsAsFloats(): void {
        $result = Parser::arrayToObject(['price' => 10.0, 'qty' => 3]);

        $this->assertSame(10.0, $result->price);
        $this->assertSame(3, $result->qty);
    }

    public function testArrayToObjectReturnsNullForEmptyArray(): void {
        $this->assertNull(Parser::arrayToObject([]));
    }

    /** Pins the fix: json_encode() returns false on bad UTF-8, which fataled against `?object`. */
    public function testArrayToObjectReturnsNullOnUnencodableDataInsteadOfThrowing(): void {
        $this->assertNull(Parser::arrayToObject(['s' => "\xB1\x31"]));
        $this->assertNull(Parser::arrayToObject(['n' => NAN]));
    }

    public function testArrayToObjectRoundTripsThroughObjectToArray(): void {
        $original = ['id' => 1, 'tags' => ['x', 'y'], 'sub' => ['b' => 2], 'price' => 9.0];
        $this->assertSame($original, Parser::objectToArray(Parser::arrayToObject($original)));
    }

    // ---------------------------------------------------------------- objectToArray

    public function testObjectToArrayConvertsDeeply(): void {
        $this->assertSame(
            ['a' => 1, 'sub' => ['b' => 2]],
            Parser::objectToArray((object) ['a' => 1, 'sub' => (object) ['b' => 2]])
        );
    }

    public function testObjectToArrayKeepsWholeFloatsAsFloats(): void {
        $this->assertSame(['price' => 10.0], Parser::objectToArray((object) ['price' => 10.0]));
    }

    public function testObjectToArrayReturnsEmptyArrayForNullAndPropertyLessObject(): void {
        $this->assertSame([], Parser::objectToArray(null));
        $this->assertSame([], Parser::objectToArray(new \stdClass()));
    }

    /** Pins the fix: bad UTF-8 (e.g. cp850/latin1 DBF data) threw `Return value must be of type array`. */
    public function testObjectToArrayReturnsEmptyArrayOnMalformedUtf8InsteadOfThrowing(): void {
        $this->assertSame([], Parser::objectToArray((object) ['s' => "\xB1\x31"]));
    }

    /** Documented: ONE unencodable value discards the whole object, and a cycle is not a crash. */
    public function testObjectToArrayReturnsEmptyArrayForNanAndCycles(): void {
        $this->assertSame([], Parser::objectToArray((object) ['ok' => 1, 'n' => NAN]));

        $cycle = new \stdClass();
        $cycle->self = $cycle;
        $this->assertSame([], Parser::objectToArray($cycle));
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
     * Pins the fix: without LIBXML_NOCDATA, SimpleXML's JSON form DROPS CDATA text — an element
     * holding only CDATA came back as an empty array, losing the (very common) CDATA payload.
     */
    public function testXmlToArrayKeepsCdataContent(): void {
        $this->assertSame(
            ['a' => 'hello <b>world</b> & more', 'b' => 'x'],
            Parser::xmlToArray('<r><a><![CDATA[hello <b>world</b> & more]]></a><b>x</b></r>')
        );
    }

    public function testXmlToArrayMapsAttributesAndRepeatedSiblings(): void {
        $this->assertSame(
            ['item' => [['@attributes' => ['id' => '1']], ['@attributes' => ['id' => '2']]]],
            Parser::xmlToArray('<r><item id="1"/><item id="2"/></r>')
        );
    }

    /**
     * BEHAVIOUR CHANGE, security: a string naming an existing file used to be LOADED AS THAT FILE,
     * so untrusted input (a webhook body, a form field) was an arbitrary local-file read. A path is
     * now just text that is not XML.
     */
    public function testXmlToArrayNeverTreatsItsInputAsAFilePath(): void {
        $path = $this->tempFile('<r><secret>FILE-CONTENTS</secret></r>');

        $this->assertSame([], Parser::xmlToArray($path));
        $this->assertSame(['secret' => 'FILE-CONTENTS'], Parser::xmlFileToArray($path));
    }

    public function testXmlToArrayReturnsEmptyArrayForEmptyInput(): void {
        $this->assertSame([], Parser::xmlToArray(''));
        $this->assertSame([], Parser::xmlToArray("  \n "));
        $this->assertSame([], Parser::xmlToArray(sys_get_temp_dir()));
    }

    /**
     * Malformed input returns [] WITHOUT a warning, even when the caller has not enabled
     * libxml_use_internal_errors(). phpunit.xml's failOnWarning is what makes this a real test: the
     * old code emitted "simplexml_load_string(): Entity: line 1: parser error ...".
     */
    public function testXmlToArrayReturnsEmptyArrayForMalformedXmlWithoutAWarning(): void {
        libxml_use_internal_errors(false);

        $this->assertSame([], Parser::xmlToArray('<a><unclosed></a>'));
        $this->assertFalse(libxml_use_internal_errors(), 'The caller\'s libxml error mode must be restored.');
    }

    /** A caller that collects libxml errors itself still finds them there afterwards. */
    public function testXmlToArrayLeavesErrorsForACallerThatCollectsThem(): void {
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        $this->assertSame([], Parser::xmlToArray('<a><unclosed></a>'));
        $this->assertTrue(libxml_use_internal_errors());
        $this->assertNotEmpty(libxml_get_errors());
    }

    /**
     * Pins the fix: the deny-all external-entity loader was installed and NEVER REMOVED, and libxml
     * routes the MAIN DOCUMENT of a file load through that same loader — so one call to
     * xmlToArray() broke every later simplexml_load_file() / DOMDocument::load() in the process.
     */
    public function testXmlToArrayRestoresTheExternalEntityLoader(): void {
        $path = $this->tempFile('<r><a>1</a></r>');

        Parser::xmlToArray('<r/>');

        $this->assertInstanceOf(\SimpleXMLElement::class, simplexml_load_file($path));
        $document = new \DOMDocument();
        $this->assertTrue($document->load($path));
    }

    /** ...and a loader the application installed itself is put back, not replaced by the default. */
    public function testXmlToArrayRestoresACallerInstalledLoader(): void {
        if (!\function_exists('libxml_get_external_entity_loader')) {
            $this->markTestSkipped('libxml_get_external_entity_loader() is not available on this PHP.');
        }

        $loader = static fn(?string $public, string $system, array $context) => null;
        libxml_set_external_entity_loader($loader);

        Parser::xmlToArray('<r/>');

        $this->assertSame($loader, libxml_get_external_entity_loader());
    }

    /** The XXE hardening must hold: no LIBXML_NOENT, so an external entity is never expanded. */
    public function testXmlToArrayDoesNotExpandExternalEntities(): void {
        $canaryFile = $this->tempFile('XXE-CANARY-VALUE', '.txt');
        $xxe = $this->xxeDocument($canaryFile);
        $this->assertXxePayloadIsEffective($xxe, 'XXE-CANARY-VALUE');

        $flattened = json_encode(Parser::xmlToArray($xxe));

        $this->assertIsString($flattened);
        $this->assertStringNotContainsString('XXE-CANARY-VALUE', $flattened);
    }

    // ---------------------------------------------------------------- xmlFileToArray

    /**
     * The file input xmlToArray() used to accept. Pins the old fix too: the XXE guard's entity
     * loader intercepted libxml's request for the MAIN DOCUMENT, so simplexml_load_file() always
     * failed and file input silently returned [].
     */
    public function testXmlFileToArrayParsesAnXmlFile(): void {
        $path = $this->tempFile('<r><a>1</a><b>two</b></r>');

        $this->assertSame(['a' => '1', 'b' => 'two'], Parser::xmlFileToArray($path));
        $this->assertSame(['a' => '1', 'b' => 'two'], Parser::xmlFileToArray('file:///' . ltrim(str_replace('\\', '/', $path), '/')));

        if (DIRECTORY_SEPARATOR === '\\') {
            // "C://dir/x.xml" — a drive letter followed by a doubled separator — is a PATH. The
            // wrapper filter took the single letter for a scheme and returned [] in silence.
            $doubled = substr($path, 0, 2) . '/' . str_replace('\\', '/', substr($path, 2));
            $this->assertStringStartsWith(substr($path, 0, 2) . '//', $doubled, 'premise');
            $this->assertSame(['a' => '1', 'b' => 'two'], Parser::xmlFileToArray($doubled));
        }
    }

    public function testXmlFileToArrayAndXmlToArrayAgree(): void {
        $xml = '<r><a>1</a><c><![CDATA[x]]></c></r>';
        $this->assertSame(Parser::xmlToArray($xml), Parser::xmlFileToArray($this->tempFile($xml)));
    }

    public function testXmlFileToArrayReturnsEmptyArrayForUnusablePaths(): void {
        $this->assertSame([], Parser::xmlFileToArray(''));
        $this->assertSame([], Parser::xmlFileToArray(sys_get_temp_dir()));
        $this->assertSame([], Parser::xmlFileToArray(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-' . bin2hex(random_bytes(6)) . '.xml'));
        $this->assertSame([], Parser::xmlFileToArray("a\0b"));
        $this->assertSame([], Parser::xmlFileToArray($this->tempFile('')));
    }

    /** A stream-wrapper URL is not a local path: it is refused without being opened. */
    public function testXmlFileToArrayRefusesStreamWrapperUrls(): void {
        $path = $this->tempFile('<r><a>1</a></r>');

        $this->assertSame([], Parser::xmlFileToArray('php://filter/resource=' . $path));
        $this->assertSame([], Parser::xmlFileToArray('http://127.0.0.1:1/x.xml'));
    }

    public function testXmlFileToArrayDoesNotExpandExternalEntities(): void {
        $canaryFile = $this->tempFile('XXE-CANARY-VALUE', '.txt');
        $xxe = $this->xxeDocument($canaryFile);
        $this->assertXxePayloadIsEffective($xxe, 'XXE-CANARY-VALUE');

        $flattened = json_encode(Parser::xmlFileToArray($this->tempFile($xxe)));

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
        $this->assertFalse(Parser::base64UrlDecode('!!!invalid!!!'));
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

        $this->assertCount(6, $groups, 'one group per BYTE: A, 2 for ÿ, NUL, 2 for é');
        foreach ($groups as $group) {
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

    /** Pins the fix: a double space was an empty group, decoded as a spurious NUL byte. */
    public function testBinaryToStringTreatsAnyWhitespaceRunAsOneSeparator(): void {
        $this->assertSame('AB', Parser::binaryToString("  01000001  \n\t01000010 "));
    }

    /**
     * Pins the fix: a non-binary character raised a base_convert() deprecation and was dropped,
     * and a 9-bit value expanded into two bytes — undetectable garbage either way.
     *
     * @return array<string, array{0: string}>
     */
    public static function invalidBinaryProvider(): array {
        return [
            'letter'            => ['0100000x'],
            'digit 2'           => ['01000002'],
            'value above 255'   => ['111111111'],
            'comma separated'   => ['01000001,01000010'],
        ];
    }

    #[DataProvider('invalidBinaryProvider')]
    public function testBinaryToStringRejectsInputThatIsNotBytesInBinary(string $input): void {
        $this->expectException(\InvalidArgumentException::class);
        Parser::binaryToString($input);
    }

    /** Pins the fix: explode(' ', '') is [''] and base_convert('', 2, 16) is '0' — it emitted a NUL. */
    public function testBinaryToStringReturnsEmptyStringForNullAndEmptyNotANulByte(): void {
        $this->assertSame('', Parser::binaryToString(null));
        $this->assertSame('', Parser::binaryToString(''));
        $this->assertSame('', Parser::binaryToString('   '));
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
        $this->assertSame('', Parser::hexToStr('6'));
    }

    /**
     * Pins the fix: hexdec() dropped a non-hex character with a deprecation, so 'zz41' decoded to
     * "\0A" and an '0x' prefix silently became a NUL byte.
     *
     * @return array<string, array{0: string}>
     */
    public static function invalidHexProvider(): array {
        return ['letters' => ['zz41'], '0x prefix' => ['0x41'], 'space' => ['41 42'], 'trailing newline' => ["41\n"]];
    }

    #[DataProvider('invalidHexProvider')]
    public function testHexToStrRejectsNonHexCharacters(string $hex): void {
        $this->expectException(\InvalidArgumentException::class);
        Parser::hexToStr($hex);
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

    /**
     * FINDING (low) — the docblock claimed the sentinels match "ignoring surrounding spaces". They
     * do not: Validator::isCompletelyEmpty() runs the string through
     * Str::removeExcessSpaces($v, false), which deletes whitespace runs OUTRIGHT, internal ones
     * included. So 'fa lse' and 'n o' are FALSE — genuinely surprising for a boolean parser, and
     * nothing in the doc warned you.
     *
     * DOCUMENTED rather than changed, deliberately. The normalisation lives in isCompletelyEmpty(),
     * which also backs Security's `asBoolean` sanitize option. Loosening it here — and only here —
     * would fork the two doors: 'n o' would read false when parsed and true when sanitised, a
     * silent disagreement between sibling paths that is far nastier than a parser being generous
     * about spaces. It is one shared helper: fix it for both callers or neither.
     *
     * DOC-ONLY FIX: this test passes before and after it. It pins the surprising truth so the doc
     * and the behavior cannot drift apart again without a red test.
     */
    public function testGetBoolStripsInternalWhitespaceBeforeMatchingSentinels(): void {
        foreach (['fa lse', 'n o', 'F A L S E', 'n u l l', '{ }', '[ ]', "fal\tse", "n\no", 'u n defined'] as $value) {
            $this->assertFalse(Parser::getBool($value), var_export($value, true) . ' must be falsy');
        }

        // A string that does NOT collapse onto a sentinel stays truthy, so the stripping is not a
        // blanket "any spaced string is false".
        $this->assertTrue(Parser::getBool('ye s'));
        $this->assertTrue(Parser::getBool('fa lsey'));
        $this->assertTrue(Parser::getBool('n ope'));
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

    /**
     * Pins the fix: brackets inside a JSON string counted toward the balance, so '{"a":"}"}' was
     * cut at the quoted '}' and returned as the unparseable '{"a":"}'.
     */
    public function testExtractJsonBlocksIgnoresBracketsInsideStrings(): void {
        $blocks = Parser::extractJsonBlocks('x {"a":"}"} y {"b":"[1"} z {"c":"\\"}"} w ["]"]');

        $this->assertSame(['{"a":"}"}', '{"b":"[1"}', '{"c":"\\"}"}', '["]"]'], $blocks);
        foreach ($blocks as $block) {
            $this->assertNotNull(json_decode($block), $block . ' must be valid JSON');
        }
    }

    /**
     * Pins the fix: a closing bracket of the wrong kind was PUSHED as if it opened something, so a
     * stray '{]' swallowed every valid block after it.
     */
    public function testExtractJsonBlocksRecoversAfterAMismatchedBracket(): void {
        $this->assertSame(['{"ok":1}'], Parser::extractJsonBlocks('a {] b {"ok":1}'));
        $this->assertSame(['[2]'], Parser::extractJsonBlocks('{[1}] [2]'));
    }

    /** The scan is a single forward pass: a hostile megabyte of openers costs milliseconds. */
    public function testExtractJsonBlocksIsLinearOnHostileInput(): void {
        $hostile = str_repeat('[', 500_000) . '}' . str_repeat('{"', 250_000);

        $started = hrtime(true);
        $this->assertSame([], Parser::extractJsonBlocks($hostile));
        $this->assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
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

    /** Both quote styles are encoded, so the result is safe inside either kind of quoted attribute. */
    public function testEncodeHtmlEncodesBothQuoteStyles(): void {
        $this->assertSame('&quot;x&quot; &#039;y&#039;', Parser::encodeHtml('"x" \'y\''));
    }

    /** Always UTF-8, and malformed input is substituted rather than returned as ''. */
    public function testEncodeHtmlIsUtf8AndSurvivesMalformedInput(): void {
        $this->assertSame('a&ccedil;&atilde;o', Parser::encodeHtml('ação'));
        $this->assertSame("caf\u{FFFD}", Parser::encodeHtml("caf\xE9"));
    }

    public function testEncodeHtmlReturnsNullAndEmptyUnchanged(): void {
        $this->assertNull(Parser::encodeHtml(null));
        $this->assertSame('', Parser::encodeHtml(''));
    }

    public function testDecodeHtmlReversesEncodeHtml(): void {
        $original = '<b>café & "quotes" \'single\'</b>';
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

    /**
     * BEHAVIOUR CHANGE: malformed input is an InvalidArgumentException. A non-digit used to escape
     * as a TypeError from chr(); a short trailing group ('6566' -> '656','6') and a code above 255
     * (wrapped modulo 256) decoded silently to garbage.
     *
     * @return array<string, array{0: string}>
     */
    public static function invalidNumericSequenceProvider(): array {
        return [
            'letters'              => ['abcdef'],
            'leading-numeric'      => ['6a5066'],
            'short trailing group' => ['6566'],
            'code above 255'       => ['065999'],
            'sign'                 => ['-65066'],
            'whitespace'           => ['065 066'],
        ];
    }

    #[DataProvider('invalidNumericSequenceProvider')]
    public function testNumericSequenceToStringRejectsMalformedInput(string $input): void {
        $this->expectException(\InvalidArgumentException::class);
        Parser::numericSequenceToString($input);
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

    public function testSetValueForKeyInArrayPromotesANullRow(): void {
        $this->assertSame([['k' => 1], ['a' => 2, 'k' => 1]], Parser::setValueForKeyInArray([null, ['a' => 2]], 'k', 1));
    }

    public function testSetValueForKeyInArrayDoesNotMutateTheCallersArray(): void {
        $rows = [['a' => 1]];
        Parser::setValueForKeyInArray($rows, 't', 9);

        $this->assertSame([['a' => 1]], $rows, 'the input array must not be modified by reference');
    }

    /**
     * BEHAVIOUR CHANGE: a non-array row is an InvalidArgumentException, raised before any row is
     * touched. It used to be an \Error thrown mid-walk — and a FALSE row was silently promoted to
     * an array with an "Automatic conversion of false to array" deprecation.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function invalidRowProvider(): array {
        return ['string' => ['not an array'], 'int' => [5], 'false' => [false], 'object' => [new \stdClass()]];
    }

    #[DataProvider('invalidRowProvider')]
    public function testSetValueForKeyInArrayRejectsANonArrayRow(mixed $row): void {
        $this->expectException(\InvalidArgumentException::class);
        Parser::setValueForKeyInArray([['a' => 1], $row], 'k', 'v');
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

    /** A private property is not "the key" of an object row: it is invisible from the outside. */
    public function testFindItemByKeyDoesNotMatchOnAnObjectsPrivateProperty(): void {
        $row = new class {
            private int $id = 7;

            public function id(): int {
                return $this->id;
            }
        };

        $this->assertSame([], Parser::findItemByKey([$row], 'id', 7));
    }
}

enum ParserTestBackedEnum: string {
    case Active = 'active';
}

enum ParserTestPureEnum {
    case Draft;
}
