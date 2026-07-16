<?php

namespace VD\PHPHelper\Tests;

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\S3Storage;

/**
 * Offline contract tests for S3Storage.
 *
 * NO LIVE AWS. Nothing here performs a network request. Two facts make this possible:
 *  - `new S3Client([...])` is construction-only: it resolves config locally and does not
 *    contact AWS. So the credential guards in getClient(), and the endpoint/region/
 *    addressing-style config it builds, are fully exercisable by inspecting the client.
 *  - Every public method validates its arguments (mode, extension, local file, key) BEFORE
 *    issuing an S3 request, so each DENIED path returns without touching the network.
 *
 * The accepted paths that would actually call S3 (a real upload/download/list/copy/delete
 * against a bucket) are NOT covered here and are reported as `untestable`.
 *
 * The credentials below are inert placeholders; they are never authenticated.
 */
final class S3StorageTest extends TestCase
{
    private const TEST_ID = 'test-access-id';
    private const TEST_TOKEN = 'test-token-value';
    private const TEST_BUCKET = 'phphelper-unit-bucket';

    /** @var string[] */
    private array $tempFiles = [];

    /** Names of the AWS commands the subject issued, in order. @var string[] */
    private array $awsCommands = [];

    /** Params of each issued AWS command, keyed by command name. @var array<string, array<int, array>> */
    private array $awsParams = [];

    protected function setUp(): void
    {
        // S3Storage is entirely static/process-global: isolate every test.
        S3Storage::reset();
        $this->awsCommands = [];
        $this->awsParams = [];
    }

    protected function tearDown(): void
    {
        S3Storage::reset();

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * Creates a real temp file (inside sys_get_temp_dir() only) for the upload guards.
     */
    private function tempFile(string $extension = '.txt'): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 's3storage_test_' . bin2hex(random_bytes(8)) . $extension;
        file_put_contents($path, 'unit-test-payload');
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Supplies credentials + a default bucket so getClient() builds a (local, unauthenticated)
     * S3Client. Needed to reach the guards that sit AFTER the "client is null" check.
     */
    private static function configure(): void
    {
        S3Storage::setKey(self::TEST_ID);
        S3Storage::setSecret(self::TEST_TOKEN);
        S3Storage::setBucket(self::TEST_BUCKET);
    }

    /** Reads the private static client singleton. */
    private static function peekClient(): ?object
    {
        return (new \ReflectionProperty(S3Storage::class, 'client'))->getValue();
    }

    /**
     * Forces the client to be built without any network call: upload() constructs the client
     * first, then rejects a nonexistent local file.
     */
    private static function buildClientOffline(): void
    {
        self::configure();
        S3Storage::upload('warmup/file.txt', '/definitely/not/here.txt');
    }

    /**
     * Builds the singleton client under a given configuration and returns it for inspection.
     *
     * Still zero network: `new S3Client([...])` resolves its config locally, and upload()
     * builds the client BEFORE it rejects the nonexistent local file. Note no bucket is
     * registered here on purpose — the client does not need one, which is the point.
     */
    private function buildClientWith(callable $configure): S3Client
    {
        S3Storage::setKey(self::TEST_ID);
        S3Storage::setSecret(self::TEST_TOKEN);
        $configure();

        S3Storage::upload('warmup/file.txt', '/definitely/not/here.txt', true, [], self::TEST_BUCKET);

        $client = self::peekClient();
        $this->assertInstanceOf(S3Client::class, $client, 'precondition: the client must have been built offline');

        return $client;
    }

    /**
     * Installs an S3Client whose HTTP transport is the AWS SDK's own MockHandler, and injects
     * it as the class singleton. NOTHING here mocks S3Storage: the subject runs its real
     * logic, and only AWS's wire layer is replaced, so no request leaves the machine.
     *
     * $responses are consumed in order, one per AWS command. Each entry is either an
     * Aws\Result (success) or a \Closure($cmd, $req) returning an exception (failure).
     *
     * $withDefaultBucket = false skips setBucket(), for the tests that exercise per-call
     * bucket resolution with no default registered.
     */
    private function installMockS3(array $responses, bool $withDefaultBucket = true): void
    {
        $mock = new MockHandler();

        foreach ($responses as $response) {
            $mock->append(function ($cmd, $req) use ($response) {
                $this->awsCommands[] = $cmd->getName();
                $this->awsParams[$cmd->getName()][] = $cmd->toArray();

                return $response instanceof \Closure ? $response($cmd, $req) : $response;
            });
        }

        $client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => self::TEST_ID, 'secret' => self::TEST_TOKEN],
            'handler' => $mock,
            // The SDK retries 5xx by default, which would silently drain the mock queue and
            // turn a simulated AWS failure into a confusing "Mock queue is empty" error.
            'retries' => 0,
        ]);

        if ($withDefaultBucket) {
            S3Storage::setBucket(self::TEST_BUCKET);
        }
        (new \ReflectionProperty(S3Storage::class, 'client'))->setValue(null, $client);
    }

    /** Builds an AWS failure carrying a specific HTTP status (the SDK reads it back via getStatusCode()). */
    private static function awsFailure(int $status, string $message = 'Simulated failure'): \Closure
    {
        return static fn ($cmd, $req) => new S3Exception($message, $cmd, ['response' => new Response($status)]);
    }

    // ---------------------------------------------------------------------
    // reset() / defaults
    // ---------------------------------------------------------------------

    public function testResetRestoresDocumentedDefaults(): void
    {
        self::configure();
        S3Storage::setDebug(true);
        S3Storage::setNoOperation(true);
        S3Storage::setEndpoint('http://127.0.0.1:9000');
        S3Storage::setUsePathStyleEndpoint(false);

        S3Storage::reset();

        $this->assertNull(S3Storage::getBucket(), 'reset() must clear the default bucket');
        $this->assertSame('us-east-1', S3Storage::getRegion(), 'reset() must restore the fallback region');
        $this->assertFalse(S3Storage::getDebug(), 'reset() must disable debug');
        $this->assertNull(S3Storage::getLastError(), 'reset() must clear lastError');
        $this->assertNull(self::peekClient(), 'reset() must drop the cached client');
        $this->assertNull(S3Storage::getEndpoint(), 'reset() must clear the custom endpoint');
        $this->assertFalse(
            S3Storage::getUsePathStyleEndpoint(),
            'reset() must drop the path-style override back to the automatic default (AWS => virtual-host)'
        );
    }

    public function testGetLastErrorIsNullBeforeAnyOperation(): void
    {
        $this->assertNull(S3Storage::getLastError());
    }

    // ---------------------------------------------------------------------
    // region
    // ---------------------------------------------------------------------

    public function testGetRegionFallsBackToUsEast1WhenUnset(): void
    {
        $this->assertSame('us-east-1', S3Storage::getRegion());
    }

    public function testSetRegionOverridesTheFallback(): void
    {
        S3Storage::setRegion('sa-east-1');

        $this->assertSame('sa-east-1', S3Storage::getRegion());
    }

    // ---------------------------------------------------------------------
    // NEW (pass 2): custom endpoint support. getClient() used to build the S3Client from a
    // hardcoded config with no way to point it anywhere but AWS — which is precisely why an
    // S3-compatible container could not be used to exercise this class.
    //
    // Asserted through the BUILT CLIENT'S CONFIG, which the SDK resolves locally. Zero
    // network: no endpoint here is ever contacted.
    // ---------------------------------------------------------------------

    public function testDefaultsToTheAwsEndpointDerivedFromTheRegion(): void
    {
        $client = $this->buildClientWith(fn () => S3Storage::setRegion('sa-east-1'));

        $endpoint = (string)$client->getEndpoint();
        // Asserted by shape, not literal: the host is the SDK's to derive, not this contract's.
        $this->assertStringContainsString('amazonaws.com', $endpoint, 'no custom endpoint => real AWS');
        $this->assertStringContainsString('sa-east-1', $endpoint, 'the region must drive the AWS endpoint');
        $this->assertNull(S3Storage::getEndpoint());
    }

    public function testCustomEndpointIsPassedToTheBuiltClient(): void
    {
        $client = $this->buildClientWith(fn () => S3Storage::setEndpoint('http://127.0.0.1:9000'));

        $this->assertSame('http://127.0.0.1:9000', (string)$client->getEndpoint());
    }

    public function testCustomEndpointMayCarryAPathPrefix(): void
    {
        // Some gateways expose S3 under a prefix; the path must survive.
        $client = $this->buildClientWith(fn () => S3Storage::setEndpoint('https://gw.example.com/s3'));

        $this->assertSame('https://gw.example.com/s3', (string)$client->getEndpoint());
    }

    public function testCustomEndpointRoundTripsAndStripsATrailingSlash(): void
    {
        $this->assertTrue(S3Storage::setEndpoint('http://127.0.0.1:9000/'));
        $this->assertSame('http://127.0.0.1:9000', S3Storage::getEndpoint());
    }

    public function testEndpointCanBeClearedBackToAws(): void
    {
        $this->assertTrue(S3Storage::setEndpoint('http://127.0.0.1:9000'));

        $this->assertTrue(S3Storage::setEndpoint(null), 'null clears the endpoint');
        $this->assertNull(S3Storage::getEndpoint());

        $this->assertTrue(S3Storage::setEndpoint('http://127.0.0.1:9000'));
        $this->assertTrue(S3Storage::setEndpoint(''), 'an empty string clears it too');
        $this->assertNull(S3Storage::getEndpoint());
    }

    /**
     * The AWS SDK does NOT validate the endpoint: it accepts "not a url" and turns it into
     * the relative "not%20a%20url", yielding a client that signs this deployment's
     * credentials for a nonsense host. setEndpoint() rejects that locally instead.
     */
    public function testSetEndpointRejectsMalformedValuesTheSdkWouldSilentlyAccept(): void
    {
        $this->assertFalse(S3Storage::setEndpoint('not a url'));
        $this->assertStringContainsString('must be an absolute http(s) URL', (string)S3Storage::getLastError());
        $this->assertNull(S3Storage::getEndpoint(), 'a rejected endpoint must not be stored');

        $this->assertFalse(S3Storage::setEndpoint('minio.internal:9000'), 'a scheme is required');
        $this->assertFalse(S3Storage::setEndpoint('ftp://minio.internal'), 'only http/https address an S3 API');
        $this->assertFalse(S3Storage::setEndpoint('https://'), 'a host is required');
        $this->assertFalse(S3Storage::setEndpoint('/just/a/path'), 'a relative path is not an endpoint');
    }

    public function testSetEndpointRejectsAQueryStringOrFragment(): void
    {
        $this->assertFalse(S3Storage::setEndpoint('http://minio.internal:9000?x=1'));
        $this->assertStringContainsString('must not carry a query string or fragment', (string)S3Storage::getLastError());

        $this->assertFalse(S3Storage::setEndpoint('http://minio.internal:9000#frag'));
    }

    public function testARejectedEndpointLeavesThePreviousOneIntact(): void
    {
        $this->assertTrue(S3Storage::setEndpoint('https://minio.example.com:9000'));

        $this->assertFalse(S3Storage::setEndpoint('!!not a url!!'));
        $this->assertSame(
            'https://minio.example.com:9000',
            S3Storage::getEndpoint(),
            'a rejected endpoint must not clobber the working one'
        );
    }

    public function testSetEndpointInvalidatesTheCachedClient(): void
    {
        self::buildClientOffline();
        $this->assertNotNull(self::peekClient(), 'precondition: a client is cached');

        $this->assertTrue(S3Storage::setEndpoint('http://127.0.0.1:9000'));

        $this->assertNull(
            self::peekClient(),
            'setEndpoint() must drop the cached client, otherwise the switch keeps addressing the OLD host '
            . '(and keeps sending this deployment credentials there)'
        );
    }

    public function testClearingTheEndpointAlsoInvalidatesTheCachedClient(): void
    {
        S3Storage::setEndpoint('http://127.0.0.1:9000');
        self::buildClientOffline();
        $this->assertNotNull(self::peekClient());

        $this->assertTrue(S3Storage::setEndpoint(null));

        $this->assertNull(self::peekClient(), 'going back to AWS must rebuild the client too');
    }

    public function testARejectedEndpointDoesNotInvalidateTheCachedClient(): void
    {
        self::buildClientOffline();
        $this->assertNotNull(self::peekClient());

        $this->assertFalse(S3Storage::setEndpoint('not a url'));

        $this->assertNotNull(self::peekClient(), 'nothing changed, so there is nothing to rebuild');
    }

    // ---------------------------------------------------------------------
    // Path-style addressing: a separate toggle with a tri-state default.
    // ---------------------------------------------------------------------

    public function testPathStyleDefaultsOffForAwsAndOnForACustomEndpoint(): void
    {
        $this->assertFalse(S3Storage::getUsePathStyleEndpoint(), 'AWS uses virtual-host style');

        S3Storage::setEndpoint('http://minio.internal:9000');
        $this->assertTrue(
            S3Storage::getUsePathStyleEndpoint(),
            'MinIO and most S3-compatible servers require path-style; virtual-host does not work against them'
        );
    }

    public function testCustomEndpointBuildsAPathStyleClientByDefault(): void
    {
        $client = $this->buildClientWith(fn () => S3Storage::setEndpoint('http://127.0.0.1:9000'));

        $this->assertTrue($client->getConfig('use_path_style_endpoint'));
    }

    public function testAwsClientIsBuiltVirtualHostStyle(): void
    {
        $client = $this->buildClientWith(fn () => null);

        $this->assertFalse($client->getConfig('use_path_style_endpoint'));
    }

    public function testPathStyleCanBeForcedOffForAVirtualHostStyleProvider(): void
    {
        // DigitalOcean Spaces / Wasabi serve virtual-host style from a custom endpoint and
        // break under path-style: implying path-style from the endpoint alone, with no
        // override, would leave them unusable.
        $client = $this->buildClientWith(function () {
            S3Storage::setEndpoint('https://nyc3.digitaloceanspaces.com');
            S3Storage::setUsePathStyleEndpoint(false);
        });

        $this->assertFalse(
            $client->getConfig('use_path_style_endpoint'),
            'an explicit override must beat the endpoint-implied default'
        );
    }

    public function testPathStyleCanBeForcedOnWithoutACustomEndpoint(): void
    {
        $client = $this->buildClientWith(fn () => S3Storage::setUsePathStyleEndpoint(true));

        $this->assertTrue($client->getConfig('use_path_style_endpoint'));
        $this->assertNull(S3Storage::getEndpoint(), 'forcing path-style must not invent an endpoint');
    }

    public function testNullRestoresTheAutomaticPathStyleDefault(): void
    {
        S3Storage::setEndpoint('http://minio.internal:9000');
        S3Storage::setUsePathStyleEndpoint(false);
        $this->assertFalse(S3Storage::getUsePathStyleEndpoint());

        S3Storage::setUsePathStyleEndpoint(null);
        $this->assertTrue(S3Storage::getUsePathStyleEndpoint(), 'null restores the automatic rule');
    }

    public function testSetUsePathStyleEndpointInvalidatesTheCachedClient(): void
    {
        self::buildClientOffline();
        $this->assertNotNull(self::peekClient());

        S3Storage::setUsePathStyleEndpoint(true);

        $this->assertNull(
            self::peekClient(),
            'addressing style is baked into the client at construction, so it must be rebuilt'
        );
    }

    // ---------------------------------------------------------------------
    // setBucket() validation — the documented accepted values and rejections
    // ---------------------------------------------------------------------

    public function testSetBucketNormalisesCaseAndSurroundingWhitespace(): void
    {
        $this->assertTrue(S3Storage::setBucket('  MyBucket.Name-01  '));
        $this->assertSame('mybucket.name-01', S3Storage::getBucket());
    }

    public function testSetBucketAcceptsTheDocumentedLengthBoundaries(): void
    {
        $this->assertTrue(S3Storage::setBucket('abc'), '3 chars is the documented lower bound');
        $this->assertSame('abc', S3Storage::getBucket());

        $max = str_repeat('a', 63);
        $this->assertTrue(S3Storage::setBucket($max), '63 chars is the documented upper bound');
        $this->assertSame($max, S3Storage::getBucket());
    }

    public function testSetBucketRejectsEmptyAndNull(): void
    {
        $this->assertFalse(S3Storage::setBucket(''));
        $this->assertStringContainsString('cannot be empty', (string)S3Storage::getLastError());

        $this->assertFalse(S3Storage::setBucket(null));
        $this->assertStringContainsString('cannot be empty', (string)S3Storage::getLastError());

        $this->assertFalse(S3Storage::setBucket('   '), 'whitespace-only is empty after trim');
        $this->assertNull(S3Storage::getBucket());
    }

    public function testSetBucketRejectsLengthsOutsideTheBoundaries(): void
    {
        $this->assertFalse(S3Storage::setBucket('ab'));
        $this->assertStringContainsString('between 3 and 63 characters', (string)S3Storage::getLastError());

        $this->assertFalse(S3Storage::setBucket(str_repeat('a', 64)));
        $this->assertStringContainsString('between 3 and 63 characters', (string)S3Storage::getLastError());
    }

    public function testSetBucketRejectsCharactersOutsideTheDocumentedAlphabet(): void
    {
        // Underscore is the classic one: legal in many stores, illegal in S3.
        $this->assertFalse(S3Storage::setBucket('my_bucket'));
        $this->assertStringContainsString('may contain only lowercase letters', (string)S3Storage::getLastError());

        $this->assertFalse(S3Storage::setBucket('my bucket'));
        $this->assertFalse(S3Storage::setBucket('bucket/path'));
    }

    public function testSetBucketRejectsIpv4FormattedNames(): void
    {
        $this->assertFalse(S3Storage::setBucket('192.168.0.1'));
        $this->assertStringContainsString('must not be in the format of an IP address', (string)S3Storage::getLastError());
    }

    public function testSetBucketAcceptsNamesThatMerelyResembleAnIp(): void
    {
        // Only a full 4-octet name is rejected; these are legal bucket names.
        $this->assertTrue(S3Storage::setBucket('192.168.0.1x'));
        $this->assertTrue(S3Storage::setBucket('192.168.0'));
    }

    public function testRejectedSetBucketLeavesThePreviousBucketIntact(): void
    {
        $this->assertTrue(S3Storage::setBucket('valid-bucket'));

        $this->assertFalse(S3Storage::setBucket('!!invalid!!'));
        $this->assertSame('valid-bucket', S3Storage::getBucket(), 'a rejected bucket must not clobber the current one');
    }

    // ---------------------------------------------------------------------
    // debug
    // ---------------------------------------------------------------------

    public function testSetDebugRoundTripsAndDefaultsToFalse(): void
    {
        $this->assertFalse(S3Storage::getDebug());

        S3Storage::setDebug(true);
        $this->assertTrue(S3Storage::getDebug());

        S3Storage::setDebug(false);
        $this->assertFalse(S3Storage::getDebug());
    }

    // ---------------------------------------------------------------------
    // FINDING: no-op mode raised an uncaught TypeError out of getKey()/getSecret().
    // Every assertion below fatalled before the fix:
    //   TypeError: getKey(): Return value must be of type string, null returned
    // TypeError is an \Error, so it escaped every documented catch(\Exception).
    // ---------------------------------------------------------------------

    public function testNoOperationModeReturnsBenignValuesWithoutAnyCredentials(): void
    {
        S3Storage::setNoOperation(true);

        $this->assertTrue(S3Storage::createBucket(), 'no-op createBucket() must report logical success');
        $this->assertTrue(S3Storage::upload('a/b.txt', $this->tempFile()), 'no-op upload() must report logical success');
        $this->assertTrue(S3Storage::copy('a/b.txt', 'a/c.txt'), 'no-op copy() must report logical success');
        $this->assertTrue(S3Storage::move('a/b.txt', 'a/c.txt'), 'no-op move() must report logical success');
        $this->assertTrue(S3Storage::rename('a/b.txt', 'a/c.txt'), 'no-op rename() must report logical success');
        $this->assertTrue(S3Storage::delete('a/b.txt'), 'no-op delete() must report logical success');
    }

    public function testNoOperationModeReturnsBenignReadValuesWithoutAnyCredentials(): void
    {
        S3Storage::setNoOperation(true);

        $this->assertSame([], S3Storage::list('prefix/'), 'no-op list() must return an empty array');
        $this->assertNull(S3Storage::find('a/b.txt'), 'no-op find() must return null');
        $this->assertFalse(S3Storage::exists('a/b.txt'), 'no-op exists() reports absence');
        $this->assertSame('', S3Storage::download('a/b.txt', 'TEXT'), 'no-op in-memory download returns an empty string');
        $this->assertTrue(S3Storage::download('a/b.txt', 'SAVE:/tmp/b.txt'), 'no-op file download reports logical success');
    }

    public function testNoOperationSuccessLeavesLastErrorNull(): void
    {
        S3Storage::setNoOperation(true);

        $this->assertTrue(S3Storage::upload('a/b.txt', $this->tempFile()));
        $this->assertNull(
            S3Storage::getLastError(),
            'a successful no-op call must not leave a "not configured" message in getLastError()'
        );
    }

    public function testNoOperationModeWorksEvenWhenCredentialsArePresent(): void
    {
        self::configure();
        S3Storage::setNoOperation(true);

        $this->assertTrue(S3Storage::upload('a/b.txt', $this->tempFile()));
        $this->assertNull(self::peekClient(), 'no-op must not build a client at all');
    }

    // ---------------------------------------------------------------------
    // FINDING: the docs equated "not configured" with no-op. They are different:
    // missing config HARD-FAILS (false), it never returns no-op success.
    // ---------------------------------------------------------------------

    public function testUnconfiguredDeploymentHardFailsInsteadOfDegradingToNoOp(): void
    {
        // No setKey/setSecret/setBucket, and no setNoOperation(true).
        $this->assertFalse(S3Storage::createBucket(), 'missing config must NOT be treated as no-op success');
        $this->assertStringContainsString('missing AWS_S3_KEY', (string)S3Storage::getLastError());

        $this->assertFalse(S3Storage::upload('a/b.txt', $this->tempFile()));
        $this->assertFalse(S3Storage::copy('a/b.txt', 'a/c.txt'));
        $this->assertFalse(S3Storage::delete('a/b.txt'));
        $this->assertFalse(S3Storage::list('prefix/'));
        $this->assertFalse(S3Storage::find('a/b.txt'));
        $this->assertFalse(S3Storage::exists('a/b.txt'));
        $this->assertFalse(S3Storage::download('a/b.txt', 'TEXT'));
    }

    public function testMissingSecretAloneStillHardFails(): void
    {
        S3Storage::setKey(self::TEST_ID);
        S3Storage::setBucket(self::TEST_BUCKET);

        $this->assertFalse(S3Storage::createBucket());
        $this->assertStringContainsString('missing AWS_S3_KEY/AWS_S3_SECRET', (string)S3Storage::getLastError());
    }

    // ---------------------------------------------------------------------
    // BEHAVIOR CHANGE (pass 2): the bucket is now required per OPERATION, not per client.
    //
    // This replaces testDefaultBucketIsRequiredEvenWhenBucketIsPassedPerCall(), which pinned
    // the old rule that getClient() refused to build without a registered default — so the
    // per-call $bucket override the class advertised did not actually work. That old test
    // also proved the guard was load-bearing in an unintended way: with the guard removed it
    // FAILED by reaching real AWS ("The AWS Access Key Id you provided does not exist in our
    // records"), because nothing else stopped the call. Hence the mock transport below.
    // ---------------------------------------------------------------------

    /**
     * The pin for the guard that was REMOVED. getClient() is exercised directly because the
     * mock-transport tests below inject the client by reflection and therefore bypass client
     * construction entirely — they would pass with or without the old guard, so they cannot
     * be the evidence for this change. Offline: construction resolves config locally.
     */
    public function testTheClientIsBuiltWithoutAnyRegisteredDefaultBucket(): void
    {
        S3Storage::setKey(self::TEST_ID);
        S3Storage::setSecret(self::TEST_TOKEN);
        $this->assertNull(S3Storage::getBucket(), 'precondition: no default bucket registered');

        $client = (new \ReflectionMethod(S3Storage::class, 'getClient'))->invoke(null);

        $this->assertInstanceOf(
            S3Client::class,
            $client,
            'the client is not bound to a bucket, so it must build without a registered default'
        );
        $this->assertNull(S3Storage::getLastError(), 'building without a default bucket is not an error');
    }

    public function testAPerCallBucketNoLongerRequiresARegisteredDefault(): void
    {
        $this->installMockS3([new Result(['IsTruncated' => false])], false);
        $this->assertNull(S3Storage::getBucket(), 'precondition: no default bucket registered');

        $this->assertSame([], S3Storage::list('', false, 'an-explicitly-passed-bucket'));
        $this->assertSame(
            'an-explicitly-passed-bucket',
            $this->awsParams['ListObjectsV2'][0]['Bucket'] ?? null,
            'the explicit per-call bucket must be the one actually addressed'
        );
    }

    public function testAnOperationWithNoBucketAnywhereStillFailsWithoutReachingAws(): void
    {
        // The guard that matters: dropping the up-front requirement must NOT let an empty
        // bucket through to AWS.
        $this->installMockS3([new Result([])], false);

        $this->assertFalse(S3Storage::list(''));
        $this->assertStringContainsString('missing AWS_S3_BUCKET', (string)S3Storage::getLastError());
        $this->assertSame([], $this->awsCommands, 'an empty bucket must never reach AWS');
    }

    public function testEveryOperationRefusesToReachAwsWithoutAResolvableBucket(): void
    {
        // No responses queued: any AWS command would fail loudly on an empty mock queue.
        $this->installMockS3([], false);

        $this->assertFalse(S3Storage::createBucket());
        $this->assertFalse(S3Storage::upload('a/b.txt', $this->tempFile()));
        $this->assertFalse(S3Storage::copy('a/x.pdf', 'a/y.pdf'));
        $this->assertFalse(S3Storage::delete('a/b.txt'));
        $this->assertFalse(S3Storage::list('p/'));
        $this->assertFalse(S3Storage::find('a/b.txt'));
        $this->assertFalse(S3Storage::exists('a/b.txt'));
        $this->assertFalse(S3Storage::download('a/b.txt', 'TEXT'));

        $this->assertSame([], $this->awsCommands, 'no operation may reach AWS without a bucket');
    }

    public function testDefaultBucketIsStillUsedWhenNoPerCallBucketIsGiven(): void
    {
        $this->installMockS3([new Result(['IsTruncated' => false])]);

        $this->assertSame([], S3Storage::list('p/'));
        $this->assertSame(self::TEST_BUCKET, $this->awsParams['ListObjectsV2'][0]['Bucket'] ?? null);
    }

    /**
     * A per-call bucket used to skip every rule setBucket() enforces. copy() interpolates the
     * source bucket straight into CopySource ("{$fromBucket}/{$fromKey}"), so a name carrying
     * a '/' silently redirected the copy source to another bucket and key prefix.
     */
    public function testAPerCallBucketIsHeldToTheSameNamingRulesAsSetBucket(): void
    {
        $this->installMockS3([new Result([])], false);

        $this->assertFalse(
            S3Storage::copy('a/x.pdf', 'a/y.pdf', false, true, [], 'other-bucket/injected-prefix', self::TEST_BUCKET),
            'a bucket name containing a path separator must be rejected locally'
        );
        $this->assertStringContainsString('may contain only lowercase letters', (string)S3Storage::getLastError());
        $this->assertSame([], $this->awsCommands, 'an invalid bucket name must not reach AWS');
    }

    public function testAPerCallBucketIsNormalisedLikeTheDefaultOne(): void
    {
        $this->installMockS3([new Result(['IsTruncated' => false])], false);

        $this->assertSame([], S3Storage::list('', false, '  MixedCase-Bucket  '));
        $this->assertSame(
            'mixedcase-bucket',
            $this->awsParams['ListObjectsV2'][0]['Bucket'] ?? null,
            'S3 bucket names are lowercase; a per-call bucket is normalised like setBucket() does'
        );
    }

    // ---------------------------------------------------------------------
    // FINDING: setKey()/setSecret()/setRegion() are documented as runtime injection,
    // but the cached client kept the credentials it was constructed with. Before the
    // fix the client survived a rotation and every later request was signed with the
    // OLD key (silent cross-tenant / revoked-credential use).
    // ---------------------------------------------------------------------

    public function testSetKeyInvalidatesTheCachedClientSoRotationTakesEffect(): void
    {
        self::buildClientOffline();
        $this->assertNotNull(self::peekClient(), 'precondition: a client is cached');

        S3Storage::setKey('rotated-access-id');

        $this->assertNull(
            self::peekClient(),
            'setKey() must drop the cached client, otherwise the next request still uses the OLD key'
        );
    }

    public function testSetSecretInvalidatesTheCachedClient(): void
    {
        self::buildClientOffline();
        $this->assertNotNull(self::peekClient());

        S3Storage::setSecret('rotated-token-value');

        $this->assertNull(self::peekClient(), 'setSecret() must drop the cached client');
    }

    public function testSetRegionInvalidatesTheCachedClient(): void
    {
        self::buildClientOffline();
        $this->assertNotNull(self::peekClient());

        S3Storage::setRegion('eu-west-1');

        $this->assertNull(
            self::peekClient(),
            'setRegion() must drop the cached client, otherwise createBucket() sends a LocationConstraint '
            . 'for the new region through a client still pointed at the old endpoint'
        );
    }

    public function testSetBucketDoesNotNeedToInvalidateTheClient(): void
    {
        // The bucket is a per-request argument, not baked into the client.
        self::buildClientOffline();
        $this->assertNotNull(self::peekClient());

        S3Storage::setBucket('another-valid-bucket');

        $this->assertNotNull(self::peekClient(), 'the bucket is per-request; no rebuild is warranted');
    }

    // ---------------------------------------------------------------------
    // upload() guards
    // ---------------------------------------------------------------------

    public function testUploadRejectsAKeyWithoutAnExtension(): void
    {
        self::configure();

        $this->assertFalse(S3Storage::upload('folder/no-extension', $this->tempFile()));
        $this->assertStringContainsString('must end with an extension', (string)S3Storage::getLastError());
    }

    public function testUploadRejectsAMissingOrUnreadableLocalFile(): void
    {
        self::configure();

        $missing = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'absent_' . bin2hex(random_bytes(6)) . '.txt';

        $this->assertFalse(S3Storage::upload('a/b.txt', $missing));
        $this->assertStringContainsString('does not exist or is unreadable', (string)S3Storage::getLastError());
    }

    // ---------------------------------------------------------------------
    // download() argument validation (all reached before any S3 request)
    // ---------------------------------------------------------------------

    public function testDownloadRejectsAnUnknownMode(): void
    {
        $this->assertFalse(S3Storage::download('a/b.txt', 'BOGUS'));
        $this->assertStringContainsString("Mode 'BOGUS' is not permitted", (string)S3Storage::getLastError());
    }

    public function testDownloadModeNameIsCaseInsensitive(): void
    {
        S3Storage::setNoOperation(true);

        // Lowercase mode names resolve like their uppercase form rather than being rejected.
        $this->assertSame('', S3Storage::download('a/b.txt', 'text'));
        $this->assertTrue(S3Storage::download('a/b.txt', 'save:/tmp/b.txt'));
    }

    public function testDownloadSaveModeRequiresAPath(): void
    {
        $this->assertFalse(S3Storage::download('a/b.txt', 'SAVE'));
        $this->assertStringContainsString('was not specified', (string)S3Storage::getLastError());

        $this->assertFalse(S3Storage::download('a/b.txt', 'SAVE:'));
        $this->assertStringContainsString('was not specified', (string)S3Storage::getLastError());

        $this->assertFalse(S3Storage::download('a/b.txt', 'SAVE:[]'));
        $this->assertStringContainsString('was not specified', (string)S3Storage::getLastError());
    }

    public function testDownloadStreamRejectsANegativeChunkSize(): void
    {
        $this->assertFalse(S3Storage::download('a/b.txt', 'DOWNLOAD_STREAM:-5'));
        $this->assertStringContainsString('chunk size in MiB was specified incorrectly', (string)S3Storage::getLastError());
    }

    // ---------------------------------------------------------------------
    // FINDING: download() documented the SaveAs/key extension match as merely
    // "recommended ... for consistency". It is a hard precondition: on a mismatch
    // nothing is downloaded and the method returns false. The doc now says REQUIRED;
    // these tests pin the enforcement so the wording cannot drift back.
    // ---------------------------------------------------------------------

    public function testDownloadSaveEnforcesTheExtensionMatchItDocumentsAsRequired(): void
    {
        self::configure();

        $this->assertFalse(
            S3Storage::download('reports/x.pdf', 'SAVE:/tmp/x.txt'),
            'a mismatched destination extension is rejected, not merely discouraged'
        );
        $this->assertStringContainsString('must end with the same extension', (string)S3Storage::getLastError());
    }

    public function testDownloadSaveRejectsAnExtensionlessDestinationSuchAsTempnam(): void
    {
        self::configure();

        // This is the tempnam()/atomic-staging pattern the old "recommended" wording invited.
        $this->assertFalse(S3Storage::download('reports/x.pdf', 'SAVE:/tmp/dl_staging_file'));
        $this->assertStringContainsString('must end with the same extension', (string)S3Storage::getLastError());
    }

    /**
     * The extension comparison must ignore case: an S3 key and a local path routinely
     * disagree on casing, and before the fix 'a/REPORT.PDF' -> '/tmp/report.pdf' was a
     * hard failure. Exercised directly because the accepted path would otherwise issue a
     * real GetObject request.
     */
    public function testSaveAsExtensionComparisonIsCaseInsensitive(): void
    {
        $match = new \ReflectionMethod(S3Storage::class, 'saveAsExtensionMatchesKey');

        $this->assertTrue($match->invoke(null, 'a/REPORT.PDF', '/tmp/report.pdf'));
        $this->assertTrue($match->invoke(null, 'a/report.pdf', '/tmp/report.PDF'));
        $this->assertTrue($match->invoke(null, 'a/report.pdf', '/tmp/report.pdf'));

        $this->assertFalse($match->invoke(null, 'a/report.pdf', '/tmp/report.txt'));
        $this->assertFalse($match->invoke(null, 'a/report.pdf', '/tmp/report'), 'extensionless destination never matches');
    }

    public function testDownloadSaveUnwrapsABracketedPathSoDriveLettersSurvive(): void
    {
        self::configure();

        // The path is taken as everything after the FIRST colon, so a Windows path must be
        // bracketed. Proven via the extension error, which echoes the parsed SaveAs back:
        // without bracket handling the parsed value would be the truncated "C".
        $this->assertFalse(S3Storage::download('reports/x.pdf', 'SAVE:[C:\Temp\out.txt]'));
        $this->assertStringContainsString('C:\Temp\out.txt', (string)S3Storage::getLastError());
    }

    // ---------------------------------------------------------------------
    // FINDING: delete() silently required an extension on $key when $recursive=false.
    // The precondition is real; it is now documented, and pinned here.
    // ---------------------------------------------------------------------

    public function testDeleteNonRecursiveRejectsAnExtensionlessKey(): void
    {
        self::configure();

        // UUID / content-hash keys are ordinary in S3 and are refused by this guard.
        $this->assertFalse(S3Storage::delete('uploads/9f2c1a3e', false));
        $this->assertStringContainsString('must end with an extension', (string)S3Storage::getLastError());
    }

    public function testDeleteRejectsAnEmptyKey(): void
    {
        self::configure();

        $this->assertFalse(S3Storage::delete('', false));
        $this->assertStringContainsString('cannot be empty', (string)S3Storage::getLastError());

        $this->assertFalse(S3Storage::delete('', true), 'recursive mode does not exempt an empty key');
        $this->assertStringContainsString('cannot be empty', (string)S3Storage::getLastError());
    }

    public function testDeleteRecursiveDoesNotRequireAnExtension(): void
    {
        // Unconfigured on purpose: it must fail at the CONFIG guard, proving it got past
        // the extension guard that rejects the same key when $recursive is false.
        $this->assertFalse(S3Storage::delete('folders/reports/', true));
        $this->assertStringContainsString('missing AWS_S3_KEY', (string)S3Storage::getLastError());
        $this->assertStringNotContainsString('must end with an extension', (string)S3Storage::getLastError());
    }

    // =====================================================================
    // Paths that DO issue S3 commands, exercised through the AWS SDK's own
    // MockHandler transport. Still zero network. S3Storage itself is never mocked.
    // =====================================================================

    // ---------------------------------------------------------------------
    // REGRESSION PIN for the already-fixed critical: copy() returned TRUE on a
    // blocked overwrite (S3 answers 412 to IfNoneMatch='*'), so move()/rename()
    // treated it as success and DELETED THE SOURCE. Nothing had been copied, so
    // the object was destroyed and the caller was told it succeeded.
    // ---------------------------------------------------------------------

    public function testCopyReturnsFalseWhenOverwriteIsBlockedByAnExistingDestination(): void
    {
        $this->installMockS3([
            new Result([]),                                   // headBucket (destination exists)
            new Result(['ContentType' => 'application/pdf']), // headObject (source metadata)
            self::awsFailure(412, 'Precondition Failed'),     // copyObject refused
        ]);

        $this->assertFalse(
            S3Storage::copy('a/x.pdf', 'a/y.pdf', false),
            'a blocked overwrite copied NOTHING, so copy() must report failure'
        );
        $this->assertStringContainsString('already exists and overwrite is disabled', (string)S3Storage::getLastError());
    }

    public function testCopySendsIfNoneMatchOnlyWhenOverwriteIsDisabled(): void
    {
        $this->installMockS3([
            new Result([]),
            new Result([]),
            self::awsFailure(412),
        ]);
        S3Storage::copy('a/x.pdf', 'a/y.pdf', false);

        $this->assertSame('*', $this->awsParams['CopyObject'][0]['IfNoneMatch'] ?? null,
            'the no-overwrite guard is what makes S3 answer 412');
    }

    public function testMoveDoesNotDeleteTheSourceWhenTheCopyIsBlocked(): void
    {
        // The data-loss scenario, end to end: rename onto an existing destination.
        $this->installMockS3([
            new Result([]),                                   // headBucket
            new Result(['ContentType' => 'application/pdf']), // headObject
            self::awsFailure(412, 'Precondition Failed'),     // copyObject refused
            new Result([]),                                   // deleteObject — must never be reached
        ]);

        $this->assertFalse(S3Storage::move('a/x.pdf', 'a/y.pdf', false));
        $this->assertNotContains(
            'DeleteObject',
            $this->awsCommands,
            'move() must abort before deleting the source when the copy was blocked'
        );
    }

    public function testRenameOntoAnExistingKeyFailsWithoutDestroyingTheSource(): void
    {
        $this->installMockS3([
            new Result([]),
            new Result([]),
            self::awsFailure(412),
            new Result([]),
        ]);

        $this->assertFalse(S3Storage::rename('a/x.pdf', 'a/y.pdf'));
        $this->assertNotContains('DeleteObject', $this->awsCommands);
    }

    // ---------------------------------------------------------------------
    // upload() deliberately uses the OPPOSITE 412 convention to copy(): a blocked
    // overwrite is a benign "already there" success. Pinned so the two are not
    // "harmonised" by mistake — copy()'s false is what protects move().
    // ---------------------------------------------------------------------

    public function testUploadReportsSuccessWhenOverwriteIsBlocked(): void
    {
        $this->installMockS3([
            new Result([]),                // headBucket
            self::awsFailure(412),         // putObject refused: object already exists
        ]);

        $this->assertTrue(
            S3Storage::upload('a/b.txt', $this->tempFile(), false),
            'upload() documents a blocked overwrite as logical success (unlike copy())'
        );
        $this->assertStringContainsString('already exists and overwrite is disabled', (string)S3Storage::getLastError());
    }

    public function testUploadReturnsTheObjectUrlOnSuccess(): void
    {
        $this->installMockS3([
            new Result([]), // headBucket
            new Result([]), // putObject — the SDK's own middleware derives ObjectURL from the request URI
        ]);

        $url = S3Storage::upload('a/b.txt', $this->tempFile());

        // Asserted by shape, not by literal: the URL is built by the SDK from the endpoint,
        // so pinning a hardcoded host would test the SDK rather than this contract.
        $this->assertIsString($url, 'a successful upload returns the ObjectURL string');
        $this->assertStringContainsString(self::TEST_BUCKET, $url);
        $this->assertStringContainsString('a/b.txt', $url);
    }

    public function testUploadSendsAChecksumAndDoesNotSendIfNoneMatchWhenOverwriting(): void
    {
        $this->installMockS3([new Result([]), new Result([])]);

        S3Storage::upload('a/b.txt', $this->tempFile(), true);

        $params = $this->awsParams['PutObject'][0];
        $this->assertSame('SHA256', $params['ChecksumAlgorithm']);
        $this->assertNotEmpty($params['ChecksumSHA256'], 'a SHA256 checksum must accompany the upload');
        $this->assertArrayNotHasKey('IfNoneMatch', $params, 'overwriting must not send the no-overwrite guard');
    }

    public function testUploadReturnsFalseOnAGenuineAwsFailure(): void
    {
        $this->installMockS3([
            new Result([]),
            self::awsFailure(500, 'Internal Error'),
        ]);

        $this->assertFalse(S3Storage::upload('a/b.txt', $this->tempFile()));
        $this->assertStringContainsString('Error uploading', (string)S3Storage::getLastError());
    }

    // ---------------------------------------------------------------------
    // find() / exists()
    // ---------------------------------------------------------------------

    public function testFindReturnsNullWhenTheObjectDoesNotExist(): void
    {
        $this->installMockS3([self::awsFailure(404, 'Not Found')]);

        $this->assertNull(S3Storage::find('a/missing.txt'), 'a 404 is absence, not an error');
    }

    public function testFindReturnsFalseOnANonNotFoundError(): void
    {
        $this->installMockS3([self::awsFailure(403, 'Forbidden')]);

        $this->assertFalse(S3Storage::find('a/denied.txt'), 'a 403 is a real error, distinct from absence');
        $this->assertStringContainsString('Error fetching object', (string)S3Storage::getLastError());
    }

    public function testFindReturnsTheDocumentedItemShape(): void
    {
        $this->installMockS3([
            new Result([
                'ContentLength' => 1234,
                'LastModified' => new \DateTimeImmutable('2024-01-02 03:04:05'),
                'ContentType' => 'text/plain',
                'ChecksumSHA256' => 'abc123=',
            ]),
        ]);

        $item = S3Storage::find('folder/report.txt');

        $this->assertSame('folder/report.txt', $item['key']);
        $this->assertSame('report.txt', $item['name'], 'name is the basename of the key');
        $this->assertSame(1234, $item['size']);
        $this->assertSame('2024-01-02 03:04:05', $item['last_modified']);
        $this->assertSame('text/plain', $item['mime']);
        $this->assertSame('abc123=', $item['check_sum']);
    }

    public function testExistsIsTrueForAPresentObjectAndFalseForAMissingOne(): void
    {
        $this->installMockS3([new Result(['ContentLength' => 1])]);
        $this->assertTrue(S3Storage::exists('a/present.txt'));

        S3Storage::reset();
        $this->installMockS3([self::awsFailure(404)]);
        $this->assertFalse(S3Storage::exists('a/missing.txt'));
    }

    // ---------------------------------------------------------------------
    // list()
    // ---------------------------------------------------------------------

    public function testListFollowsContinuationTokensAcrossPages(): void
    {
        $this->installMockS3([
            new Result([
                'Contents' => [['Key' => 'p/a.txt', 'Size' => 1, 'LastModified' => new \DateTimeImmutable('2024-01-01 00:00:00')]],
                'IsTruncated' => true,
                'NextContinuationToken' => 'page-2',
            ]),
            new Result([
                'Contents' => [['Key' => 'p/b.txt', 'Size' => 2, 'LastModified' => new \DateTimeImmutable('2024-01-02 00:00:00')]],
                'IsTruncated' => false,
            ]),
        ]);

        $files = S3Storage::list('p/');

        $this->assertSame(['p/a.txt', 'p/b.txt'], array_keys($files), 'a truncated listing must be followed to the end');
        $this->assertSame('page-2', $this->awsParams['ListObjectsV2'][1]['ContinuationToken'] ?? null);
    }

    public function testListReturnsAnEmptyArrayWhenThePrefixMatchesNothing(): void
    {
        $this->installMockS3([new Result(['IsTruncated' => false])]);

        $this->assertSame([], S3Storage::list('p/none/'), 'no matches is [] — distinct from false, which means error');
    }

    public function testListReturnsFalseOnAwsFailure(): void
    {
        $this->installMockS3([self::awsFailure(500)]);

        $this->assertFalse(S3Storage::list('p/'));
        $this->assertStringContainsString('Error listing bucket', (string)S3Storage::getLastError());
    }

    // ---------------------------------------------------------------------
    // delete()
    // ---------------------------------------------------------------------

    public function testDeleteNonRecursiveRemovesExactlyTheGivenKey(): void
    {
        $this->installMockS3([new Result([])]);

        $this->assertTrue(S3Storage::delete('a/b.txt'));
        $this->assertSame(['DeleteObject'], $this->awsCommands);
        $this->assertSame('a/b.txt', $this->awsParams['DeleteObject'][0]['Key']);
    }

    public function testDeleteRecursiveRemovesEveryKeyUnderThePrefix(): void
    {
        $this->installMockS3([
            new Result([
                'Contents' => [
                    ['Key' => 'p/a.txt', 'Size' => 1, 'LastModified' => new \DateTimeImmutable('2024-01-01 00:00:00')],
                    ['Key' => 'p/sub/b.txt', 'Size' => 2, 'LastModified' => new \DateTimeImmutable('2024-01-01 00:00:00')],
                ],
                'IsTruncated' => false,
            ]),
            new Result([]), // deleteObjects
        ]);

        $this->assertTrue(S3Storage::delete('p/', true));

        // Asserted on the key set, which is the contract that matters (what gets deleted).
        // The array is associative — list() keys its result by object key and array_map()
        // preserves those keys — but the SDK serialises the values, so the delete is correct.
        $this->assertSame(
            ['p/a.txt', 'p/sub/b.txt'],
            array_column($this->awsParams['DeleteObjects'][0]['Delete']['Objects'], 'Key')
        );
    }

    public function testDeleteRecursiveReturnsFalseWhenTheListingFails(): void
    {
        $this->installMockS3([self::awsFailure(500)]);

        $this->assertFalse(S3Storage::delete('p/', true), 'a failed listing must not be reported as a successful delete');
        $this->assertNotContains('DeleteObjects', $this->awsCommands);
    }

    // ---------------------------------------------------------------------
    // $options must not override what the method already validated.
    //
    // $options is merged OVER each method's own arguments, so a caller-supplied
    // key silently won: the per-call bucket guard validated one bucket while the
    // request went to another. The guard checked nothing that mattered.
    // ---------------------------------------------------------------------

    public function testUploadRejectsAnOptionsBucketInsteadOfLettingItOverrideTheValidatedOne(): void
    {
        $this->installMockS3([
            new Result([]), // headBucket — must never be reached
            new Result([]), // putObject — must never be reached
        ]);

        // Uppercase: a name validateBucketName() rejects outright, which used to reach AWS
        // anyway — while createBucket() had just ensured the *validated* bucket existed.
        $this->assertFalse(
            S3Storage::upload('a/b.txt', $this->tempFile(), true, ['Bucket' => 'ATTACKER-BUCKET']),
            "an \$options['Bucket'] must not override the bucket requireBucket() validated"
        );
        $this->assertStringContainsString("may not contain 'Bucket'", (string)S3Storage::getLastError());
        $this->assertSame(
            [],
            $this->awsCommands,
            'a rejected call must not reach AWS at all — not even createBucket(), which would '
            . 'otherwise leave a bucket behind for a call that never ran'
        );
    }

    public function testUploadRejectsEveryOptionItDerivesFromItsOwnArguments(): void
    {
        $owned = [
            'Key'         => 'somewhere/else.txt', // would dodge the mandatory-extension guard
            'SourceFile'  => __FILE__,             // would dodge the is_file()/is_readable() check
            'Body'        => 'replacement bytes',  // silently discarded: SourceFile always wins
            'IfNoneMatch' => '*',                  // would contradict $overwrite
        ];

        foreach ($owned as $name => $value) {
            S3Storage::reset();
            $this->awsCommands = [];
            $this->awsParams = [];
            $this->installMockS3([new Result([]), new Result([])]);

            $this->assertFalse(
                S3Storage::upload('a/b.txt', $this->tempFile(), true, [$name => $value]),
                "\$options['{$name}'] would override an upload() argument that was already validated"
            );
            $this->assertStringContainsString("may not contain '{$name}'", (string)S3Storage::getLastError());
            $this->assertSame([], $this->awsCommands, "a rejected '{$name}' must not reach AWS");
        }
    }

    public function testTheReservedOptionGuardIsCaseInsensitive(): void
    {
        $this->installMockS3([new Result([]), new Result([])]);

        // The SDK silently drops a lowercase 'bucket' rather than applying it, so this is the
        // same caller mistake with a quieter failure mode.
        $this->assertFalse(S3Storage::upload('a/b.txt', $this->tempFile(), true, ['bucket' => 'other-bucket']));
        $this->assertStringContainsString("may not contain 'Bucket'", (string)S3Storage::getLastError());
        $this->assertSame([], $this->awsCommands);
    }

    public function testUploadSendsTheValidatedBucketAndStillPassesGenuineExtraOptionsThrough(): void
    {
        $this->installMockS3([
            new Result([]), // headBucket
            new Result([]), // putObject
        ]);

        $this->assertNotFalse(S3Storage::upload('a/b.txt', $this->tempFile(), true, [
            'StorageClass' => 'STANDARD_IA',
            'Metadata'     => ['owner' => 'unit'],
            'ContentType'  => 'application/x-custom',
        ]));

        $put = $this->awsParams['PutObject'][0];
        $this->assertSame(self::TEST_BUCKET, $put['Bucket'] ?? null, 'the bucket on the wire must be the validated one');
        $this->assertSame('STANDARD_IA', $put['StorageClass'] ?? null, 'a genuine extra option must still reach S3');
        $this->assertSame(['owner' => 'unit'], $put['Metadata'] ?? null);
        $this->assertSame(
            'application/x-custom',
            $put['ContentType'] ?? null,
            'ContentType is documented as an honoured override of the detected type'
        );
    }

    public function testCopyRejectsAnOptionsCopySourceThatWouldRedirectTheSource(): void
    {
        $this->installMockS3([new Result([]), new Result([]), new Result([])]);

        $this->assertFalse(
            S3Storage::copy('a/x.pdf', 'a/y.pdf', true, true, [
                'CopySource' => 'someone-elses-bucket/secrets/private.pdf',
            ]),
            "an \$options['CopySource'] re-opened the exact cross-bucket redirect requireBucket() exists to close"
        );
        $this->assertStringContainsString("may not contain 'CopySource'", (string)S3Storage::getLastError());
        $this->assertSame([], $this->awsCommands);
    }

    public function testCopyRejectsEveryOptionItDerivesFromItsOwnArguments(): void
    {
        $owned = [
            'Bucket'            => 'ATTACKER-BUCKET',
            'Key'               => 'somewhere/else.pdf',
            'MetadataDirective' => 'REPLACE', // would contradict $preserveMetadata
            'IfNoneMatch'       => '*',       // would contradict $overwrite
        ];

        foreach ($owned as $name => $value) {
            S3Storage::reset();
            $this->awsCommands = [];
            $this->awsParams = [];
            $this->installMockS3([new Result([]), new Result([]), new Result([])]);

            $this->assertFalse(
                S3Storage::copy('a/x.pdf', 'a/y.pdf', true, true, [$name => $value]),
                "\$options['{$name}'] would override a copy() argument that was already validated"
            );
            $this->assertStringContainsString("may not contain '{$name}'", (string)S3Storage::getLastError());
            $this->assertSame([], $this->awsCommands, "a rejected '{$name}' must not reach AWS");
        }
    }

    public function testCopySendsTheValidatedBucketsAndStillPassesGenuineExtraOptionsThrough(): void
    {
        $this->installMockS3([
            new Result([]),                                   // headBucket
            new Result(['ContentType' => 'application/pdf']), // headObject
            new Result([]),                                   // copyObject
        ]);

        $this->assertTrue(S3Storage::copy('a/x.pdf', 'b/y.pdf', true, true, ['StorageClass' => 'GLACIER']));

        $copy = $this->awsParams['CopyObject'][0];
        $this->assertSame(self::TEST_BUCKET, $copy['Bucket'] ?? null, 'the destination on the wire is the validated one');
        $this->assertSame(
            self::TEST_BUCKET . '/a/x.pdf',
            $copy['CopySource'] ?? null,
            'CopySource must be built from the validated source bucket, not from $options'
        );
        $this->assertSame('GLACIER', $copy['StorageClass'] ?? null, 'a genuine extra option must still reach S3');
    }

    public function testMoveRejectsReservedOptionsBeforeCopyingOrDeletingAnything(): void
    {
        $this->installMockS3([new Result([]), new Result([]), new Result([]), new Result([])]);

        $this->assertFalse(S3Storage::move('a/x.pdf', 'a/y.pdf', true, true, ['Bucket' => 'elsewhere']));
        $this->assertSame(
            [],
            $this->awsCommands,
            'move() forwards $options to copy(), which must reject before anything is copied or deleted'
        );
        $this->assertStringContainsString('copy()', (string)S3Storage::getLastError());
    }

    // ---------------------------------------------------------------------
    // setEndpoint(): the host shape is a config error the caller can still act on.
    // ---------------------------------------------------------------------

    public function testSetEndpointRejectsHostsWithSpacesOrControlCharacters(): void
    {
        // parse_url() parses every one of these WITHOUT complaint, and the raw string —
        // control bytes and all — is what gets stored and baked into the client. Accepting
        // them here deferred a detectable config error to every later call.
        $junk = [
            'http://minio internal:9000',
            "http://minio\ninternal:9000",
            "http://host\r\nX-Injected: 1",
            "http://mi\tnio.internal",
            "http://minio\x00.internal",
            "http://\x07host.internal",
        ];

        foreach ($junk as $endpoint) {
            S3Storage::reset();

            $this->assertFalse(
                S3Storage::setEndpoint($endpoint),
                'an endpoint carrying spaces/control characters must be rejected at the setter'
            );
            $this->assertStringContainsString(
                'must not contain spaces or control characters',
                (string)S3Storage::getLastError()
            );
            $this->assertNull(S3Storage::getEndpoint(), 'a rejected endpoint must not be stored');
        }
    }

    public function testSetEndpointDoesNotEchoRawControlBytesIntoTheErrorMessage(): void
    {
        $this->assertFalse(S3Storage::setEndpoint("http://host\r\nX-Injected: 1"));

        $error = (string)S3Storage::getLastError();
        $this->assertStringNotContainsString(
            "\r",
            $error,
            'a raw CR in getLastError() is a log injection in whatever writes the message out'
        );
        $this->assertStringNotContainsString("\n", $error);
        $this->assertStringContainsString('\r\n', $error, 'the offending value is still reported, escaped');
    }

    public function testSetEndpointRejectsAStructurallyInvalidHost(): void
    {
        foreach ([
            'http://-bad.example.com',
            'http://bad-.example.com',
            'http://a..b',
            'http://[:::1]:9000',
            'http://[::1',
        ] as $endpoint) {
            S3Storage::reset();

            $this->assertFalse(S3Storage::setEndpoint($endpoint), "'{$endpoint}' is not a usable host");
            $this->assertNull(S3Storage::getEndpoint());
        }
    }

    public function testSetEndpointAcceptsTheHostsRealDeploymentsActuallyUse(): void
    {
        // Guards the shape check against over-strictness: each of these is legitimate, and
        // rejecting any would break a supported deployment to enforce a rule nothing needs.
        foreach ([
            'http://127.0.0.1:9000',
            'http://localhost:9000',
            'http://minio_1:9000',                // Docker service name: underscores are not
                                                  // legal DNS but this host really answers
            'http://[::1]:9000',                  // IPv6 literal
            'https://gw.example.com/s3',          // gateway exposing S3 under a prefix
            'https://nyc3.digitaloceanspaces.com',
            'https://xn--mnchen-3ya.de',          // IDN, punycoded as the SDK requires
            'http://minio.internal.',             // FQDN root
        ] as $endpoint) {
            S3Storage::reset();

            $this->assertTrue(
                S3Storage::setEndpoint($endpoint),
                "'{$endpoint}' is a legitimate endpoint and must be accepted"
            );
        }
    }

    public function testARejectedControlCharacterEndpointLeavesTheWorkingOneAndTheClientIntact(): void
    {
        $this->assertTrue(S3Storage::setEndpoint('https://minio.example.com:9000'));
        self::buildClientOffline();
        $this->assertNotNull(self::peekClient(), 'precondition: a client is cached');

        $this->assertFalse(S3Storage::setEndpoint('http://minio internal:9000'));

        $this->assertSame(
            'https://minio.example.com:9000',
            S3Storage::getEndpoint(),
            'a rejected endpoint must not clobber the working one'
        );
        $this->assertNotNull(self::peekClient(), 'nothing changed, so there is nothing to rebuild');
    }
}
