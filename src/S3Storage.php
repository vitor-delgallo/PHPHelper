<?php

namespace VD\PHPHelper;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Class S3Storage
 *
 * Utility service for integrating with Amazon S3 using the AWS SDK for PHP.
 * Implements an internal singleton of the S3Client with "no-op" behaviour.
 *
 * Features:
 * - File upload (with extension and Content‑Type validation).
 * - Download (save to disk or return in-memory content).
 * - Object listing (with optional MIME via HeadObject).
 * - Reset of the internal state (reinitialises the client).
 * - Register and query the class default bucket; you may override the bucket
 *   via method parameters.
 * - Query the class default region.
 * - Point the client at a custom S3-compatible endpoint (MinIO, Ceph, LocalStack …)
 *   via setEndpoint(), with path-style addressing handled by setUsePathStyleEndpoint().
 * - Bucket creation.
 * - Register and query the last error message.
 *
 * Return conventions:
 * - When S3 is disabled (no‑op), write/read methods return logical success
 *   (true) or benign values (empty string / empty array), without contacting AWS.
 *   No‑op is entered ONLY by calling setNoOperation(true). It is never enabled
 *   automatically.
 * - On errors, methods return false and populate getLastError(). "Error" here covers
 *   BOTH real AWS failures AND missing configuration — they are not distinguishable
 *   from the return value alone, only from the getLastError() text.
 *
 * Configuration is mandatory, and missing configuration is NOT no‑op:
 * - Without setKey() + setSecret(), every public method returns false (or [] / null per
 *   its own signature) with a "not configured" message in getLastError(). An unconfigured
 *   deployment therefore HARD-FAILS; it does not degrade gracefully. Call
 *   setNoOperation(true) if graceful degradation is what you want.
 * - A bucket is required per OPERATION, not per client: each method resolves the explicit
 *   $bucket you pass, falling back to the default registered with setBucket(). Registering
 *   a default is therefore optional as long as every call names its own bucket. A call that
 *   resolves to no bucket at all fails locally with "missing AWS_S3_BUCKET" and never
 *   reaches AWS.
 */
class S3Storage
{
    /**
     * Characters allowed inside a single endpoint host label (see validateEndpointHost()).
     *
     * Underscore is included deliberately: it is not legal DNS, but a Docker-hosted MinIO
     * ("http://minio_1:9000") is the main reason setEndpoint() exists, and rejecting it would
     * break the option's primary use case to enforce a rule nothing here depends on.
     */
    private const HOST_LABEL_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_';

    /**
     * $options keys upload() owns — each is derived from an upload() argument and validated.
     *
     * - Bucket:      resolved and validated by requireBucket().
     * - Key:         checked for the mandatory extension by hasExtension().
     * - SourceFile:  checked by is_file()/is_readable(), and ContentType is detected FROM it.
     * - Body:        unusable, not merely redundant — the SDK's sourceFile middleware
     *                overwrites Body with SourceFile, which upload() always sends, so a
     *                caller's Body is silently discarded.
     * - IfNoneMatch: derived from $overwrite.
     *
     * @var string[]
     */
    private const UPLOAD_RESERVED_OPTIONS = ['Bucket', 'Key', 'SourceFile', 'Body', 'IfNoneMatch'];

    /**
     * $options keys copy() owns — each is derived from a copy() argument and validated.
     *
     * - Bucket/Key:        the validated destination.
     * - CopySource:        built from the requireBucket()-validated $fromBucket and $fromKey.
     * - MetadataDirective: derived from $preserveMetadata.
     * - IfNoneMatch:       derived from $overwrite.
     *
     * @var string[]
     */
    private const COPY_RESERVED_OPTIONS = ['Bucket', 'Key', 'CopySource', 'MetadataDirective', 'IfNoneMatch'];

    /**
     * Singleton instance of the S3 client.
     *
     * Initialised on demand in getClient(). Null when the service is
     * disabled or has not yet been initialised.
     *
     * @var S3Client|null
     */
    private static ?S3Client $client = null;

    /**
     * Default S3 bucket name used by the service.
     *
     * - Can be dynamically overridden in method calls that accept
     *   $bucket as a parameter.
     * - Must follow S3 naming rules:
     *   - only lowercase letters, numbers, '.' and '-'
     *   - between 3 and 63 characters
     *   - must not be in IP format (e.g. 192.168.0.1)
     *
     * @var string|null
     */
    private static ?string $bucket = null;

    /**
     * Default S3 region used by the service.
     *
     * - Can be manually overridden at runtime through setRegion().
     * - Must be compatible with AWS supported regions
     *   (e.g. us-east-1, sa-east-1, eu-west-1).
     * - If null, the class may use a fallback region when creating the client.
     *
     * @var string|null
     */
    private static ?string $region = null;

    /**
     * Custom S3-compatible endpoint the client should address.
     *
     * - Null (the default) means "use the AWS endpoint the SDK derives from the region".
     * - When set, it is an absolute http(s) URL with a host, validated by setEndpoint().
     *
     * @var string|null
     */
    private static ?string $endpoint = null;

    /**
     * Explicit path-style addressing override, or null for the automatic default.
     *
     * Tri-state on purpose (see setUsePathStyleEndpoint()):
     * - null  -> automatic: path-style ON when a custom endpoint is set, OFF otherwise.
     * - true  -> force path-style (https://host/bucket/key).
     * - false -> force virtual-host style (https://bucket.host/key).
     *
     * @var bool|null
     */
    private static ?bool $usePathStyleEndpoint = null;

    /**
     * AWS access key used to authenticate requests to S3.
     *
     * - Can be manually defined through setKey().
     * - This value is sensitive and should never be exposed in logs or responses.
     *
     * @var string|null
     */
    private static ?string $key = null;

    /**
     * AWS secret key used together with the access key to authenticate requests.
     *
     * - Can be manually defined through setSecret().
     * - This value is highly sensitive and should never be exposed in logs or responses.
     *
     * @var string|null
     */
    private static ?string $secret = null;

    /**
     * Flag that forces the service to operate in "no-op" mode.
     *
     * When enabled, the class should avoid performing real operations against S3
     * and instead return safe logical responses according to each method contract.
     *
     * @var bool
     */
    private static bool $noOperation = false;

    /**
     * Last error message recorded by the service.
     *
     * Populated when a failure occurs in AWS operations (upload, download,
     * listing, client initialisation) and cleared on each getClient().
     *
     * @var string|null
     */
    private static ?string $lastError = null;

    /**
     * Debug flag in case future tests are needed.
     *
     * - Should not be enabled in production, as it may expose
     *   sensitive information.
     *
     * @var bool
     */
    private static bool $debug = false;

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct()
    {
        // prevent instantiation
    }

    /**
     * Resets the internal state of the S3 service.
     *
     * Effects:
     * - Sets the S3 client to null, forcing a new initialisation
     *   on the next call to getClient().
     * - Clears class variables.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$client = null;
        self::$bucket = null;
        self::$region = null;
        self::$endpoint = null;
        self::$usePathStyleEndpoint = null;
        self::$key = null;
        self::$secret = null;
        self::$noOperation = false;
        self::$lastError = null;
        self::$debug = false;
    }

    /**
     * Defines a custom S3-compatible endpoint (MinIO, Ceph, LocalStack, a private gateway …).
     *
     * - Pass null or an empty string to clear it and go back to the AWS endpoint the SDK
     *   derives from getRegion().
     * - The value must be an absolute http(s) URL including a host, with no query string or
     *   fragment. This is validated here BECAUSE THE SDK DOES NOT: handing the AWS SDK a
     *   malformed endpoint such as "not a url" is accepted silently (it becomes the relative
     *   "not%20a%20url"), producing a client that signs requests for a nonsense host instead
     *   of failing. A rejected value leaves the previous endpoint untouched.
     * - The HOST SHAPE is validated too, and for the same reason: parse_url() is not a
     *   validator. It parses "http://minio internal:9000" and "http://h\r\nX-Injected: 1"
     *   without complaint, and the RAW string — control characters and all — is what gets
     *   stored and baked into the client. Rejecting that here turns a config error the caller
     *   can still fix into a failure at the setter, instead of an unexplained failure on
     *   every later call. Accepted: a DNS hostname, an IPv4 address, or a bracketed IPv6
     *   literal ("http://[::1]:9000"). Underscores are allowed in a label — they are not
     *   legal DNS, but "http://minio_1:9000" is exactly what a Docker-hosted MinIO answers
     *   to, which is this option's main use case. Non-ASCII is rejected: the SDK does not
     *   punycode an IDN host, so pass it already encoded ("xn--mnchen-3ya.de").
     * - A trailing slash is stripped for stable comparison; the path, if any, is preserved
     *   (some gateways expose S3 under a prefix).
     * - Discards any cached S3 client, so the new endpoint takes effect on the next
     *   operation. The endpoint is baked into the client at construction; without this
     *   invalidation, a switch would keep addressing the PREVIOUS host — including sending
     *   this deployment's credentials there.
     * - Pairs with setUsePathStyleEndpoint(): by default, setting an endpoint switches the
     *   client to path-style addressing, which MinIO and most S3-compatible servers require.
     *
     * SECURITY: an "http://" endpoint transmits the request — including the Authorization
     * signature — in the clear. That is accepted here on purpose, because a local MinIO on
     * "http://127.0.0.1:9000" is the ordinary development case, but it is NOT safe across an
     * untrusted network: use https for any endpoint you do not control end to end.
     *
     * @param string|null $endpoint Absolute http(s) endpoint URL, or null/'' to use AWS.
     *
     * @return bool True if defined (or cleared) successfully; false on an invalid value
     *              (use getLastError() for details).
     */
    public static function setEndpoint(?string $endpoint): bool
    {
        $endpoint = trim($endpoint ?? '');
        if ($endpoint === '') {
            self::$endpoint = null;
            self::$client = null;
            return true;
        }

        $parts = parse_url($endpoint);
        if ($parts === false || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            self::$lastError = "[AWS S3] setEndpoint(): The endpoint '{$endpoint}' must be an absolute http(s) URL including a host (e.g. https://minio.example.com:9000).";
            return false;
        }

        if (!empty($parts['query']) || !empty($parts['fragment'])) {
            self::$lastError = "[AWS S3] setEndpoint(): The endpoint '{$endpoint}' must not carry a query string or fragment.";
            return false;
        }

        // Spaces, control characters and DEL anywhere in the RAW value — which is what gets
        // stored, not parse_url()'s cleaned-up view of it. Deliberately checked AFTER the
        // scheme/host and query/fragment rules, so a plain "not a url" still reports the
        // specific complaint it always has rather than this generic one.
        // The offending value is escaped into the message: a raw CRLF here would be a log
        // injection in whatever writes getLastError() out.
        if (preg_match('/[\x00-\x20\x7F]/', $endpoint) === 1) {
            $shown = addcslashes($endpoint, "\0..\37\177");
            self::$lastError = "[AWS S3] setEndpoint(): The endpoint '{$shown}' must not contain spaces or control characters.";
            return false;
        }

        $hostError = self::validateEndpointHost($parts['host']);
        if ($hostError !== null) {
            self::$lastError = "[AWS S3] setEndpoint(): {$hostError}";
            return false;
        }

        self::$endpoint = rtrim($endpoint, '/');
        self::$client = null;
        return true;
    }

    /**
     * Applies the host-shape rules described in setEndpoint() to a parse_url() host.
     *
     * Accepts a DNS hostname, an IPv4 address, or a bracketed IPv6 literal. Implemented with
     * explode()/strspn() rather than one hostname regex ON PURPOSE, and the reason is not
     * style:
     * - The obvious pattern for this shape is /^([\w-]+\.)*[\w-]+$/ — nested quantifiers over
     *   overlapping classes, which backtracks catastrophically on a long non-matching host and
     *   would trade a config bug for a PCRE denial of service.
     * - '$' additionally matches BEFORE a trailing newline, so that same regex would accept
     *   "minio.internal\n" — reintroducing exactly the control-character hole this method
     *   exists to close.
     * strspn() scans linearly, has no anchors and no backtracking, so neither trap applies.
     *
     * @param string $host Host component as returned by parse_url() (never '').
     *
     * @return string|null Null when the host is usable; the error detail otherwise.
     */
    private static function validateEndpointHost(string $host): ?string
    {
        // IPv6 literal: parse_url() keeps the brackets ("http://[::1]:9000" -> "[::1]").
        if ($host[0] === '[') {
            $inner = substr($host, -1) === ']' ? substr($host, 1, -1) : '';
            if ($inner === '' || filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return "The endpoint host '{$host}' is not a valid IPv6 literal (expected e.g. http://[::1]:9000).";
            }

            return null;
        }

        if (strlen($host) > 253) {
            return "The endpoint host '{$host}' exceeds the 253-character DNS limit.";
        }

        // A single trailing dot is the legal FQDN root ("minio.internal.").
        $probe = substr($host, -1) === '.' ? substr($host, 0, -1) : $host;
        if ($probe === '') {
            return "The endpoint host '{$host}' is not a valid hostname or IP address.";
        }

        foreach (explode('.', $probe) as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || $label[0] === '-'
                || $label[strlen($label) - 1] === '-'
                || strspn($label, self::HOST_LABEL_CHARS) !== strlen($label)
            ) {
                return "The endpoint host '{$host}' is not a valid hostname or IP address.";
            }
        }

        return null;
    }

    /**
     * Returns the custom endpoint currently configured.
     *
     * @return string|null The endpoint, or null when the SDK's region-derived AWS endpoint is used.
     */
    public static function getEndpoint(): ?string
    {
        return self::$endpoint;
    }

    /**
     * Forces path-style addressing on or off, or restores the automatic default.
     *
     * This is a SEPARATE toggle rather than something implied by setEndpoint(), because the
     * two properties are genuinely independent and neither implication holds universally:
     * MinIO/Ceph/LocalStack need path-style, but other S3-compatible providers (DigitalOcean
     * Spaces, Wasabi) serve virtual-host style from a custom endpoint and break under
     * path-style. Implying it from the endpoint alone would leave those unusable with no way
     * out. The tri-state keeps the convenient default AND the escape hatch:
     * - null (default): path-style ON when a custom endpoint is set, OFF for real AWS — the
     *   right answer for the common MinIO case with no extra call, and the right answer for
     *   AWS, which has deprecated path-style for newer buckets.
     * - true/false: explicit, wins over the automatic rule in both directions.
     *
     * Discards any cached S3 client: addressing style is baked in at construction.
     *
     * @param bool|null $usePathStyleEndpoint True/false to force; null to restore the automatic default.
     *
     * @return void
     */
    public static function setUsePathStyleEndpoint(?bool $usePathStyleEndpoint): void
    {
        self::$usePathStyleEndpoint = $usePathStyleEndpoint;
        self::$client = null;
    }

    /**
     * Returns the RESOLVED addressing style that the next client will be built with.
     *
     * Resolves the tri-state described in setUsePathStyleEndpoint(): the explicit override
     * when one was set, otherwise "true when a custom endpoint is configured". It reports the
     * effective boolean, not whether an override is in place.
     *
     * @return bool True if the client uses path-style addressing; false for virtual-host style.
     */
    public static function getUsePathStyleEndpoint(): bool
    {
        return self::$usePathStyleEndpoint ?? (self::$endpoint !== null);
    }

    /**
     * Defines the default AWS region to be used by the service.
     *
     * - Overrides the current region stored in the class.
     * - Does not perform validation by itself.
     * - Recommended to pass a valid AWS region string
     *   (e.g. us-east-1, sa-east-1, eu-west-1).
     * - Discards any cached S3 client, so the new region takes effect on the next
     *   operation. The region is baked into the client's endpoint at construction, so
     *   without this the client would keep addressing the previous region while
     *   createBucket() read the new one for LocationConstraint — a guaranteed mismatch.
     *
     * @param string $region AWS region to be used by the service.
     *
     * @return void
     */
    public static function setRegion(string $region): void
    {
        self::$region = $region;
        self::$client = null;
    }

    /**
     * Returns the default configured region.
     *
     * - If none has been defined, returns "us-east-1" as a fallback.
     *
     * @return string Current configured region or "us-east-1" by default.
     */
    public static function getRegion(): string
    {
        return self::$region ?? 'us-east-1';
    }

    /**
     * Returns the currently configured default bucket for the service.
     * - When upload/download/list methods explicitly pass $bucket, that value
     *   takes precedence over the default bucket.
     *
     * @return string|null Bucket name or null if not defined.
     */
    public static function getBucket(): ?string
    {
        return self::$bucket;
    }

    /**
     * Defines the AWS access key to be used by the service.
     *
     * - Overrides the current access key stored in the class.
     * - Useful when credentials need to be injected dynamically at runtime.
     * - Discards any cached S3 client, so the new key takes effect on the next operation.
     *   Credentials are baked into the client at construction; without this invalidation a
     *   key rotation or a tenant switch would silently keep signing requests with the
     *   PREVIOUS credentials for the life of the process.
     * - Pair with setSecret(): a key without its matching secret will fail to authenticate.
     * - This value is sensitive and should be handled carefully.
     *
     * @param string $key AWS access key.
     *
     * @return void
     */
    public static function setKey(string $key): void
    {
        self::$key = $key;
        self::$client = null;
    }
    
    /**
     * Returns the AWS access key currently configured in the class.
     *
     * - Intended for internal use only.
     * - Returns the value previously defined through setKey(), or NULL when no key
     *   has been configured (the initial state, and the state after reset()).
     * - This value is sensitive and should not be exposed outside secure internal flows.
     *
     * The return type is nullable on purpose: the backing property is ?string, and the
     * only consumer (getClient()) tests it with empty(). Declaring `: string` here made
     * every unconfigured call raise a TypeError instead of reaching that guard.
     *
     * @return string|null Currently configured AWS access key, or null if not configured.
     */
    private static function getKey(): ?string
    {
        return self::$key;
    }

    /**
     * Defines the AWS secret key to be used by the service.
     *
     * - Overrides the current secret key stored in the class.
     * - Useful when credentials need to be injected dynamically at runtime.
     * - Discards any cached S3 client, so the new secret takes effect on the next
     *   operation. See setKey() for why this invalidation is required.
     * - This value is highly sensitive and should be handled carefully.
     *
     * @param string $secret AWS secret key.
     *
     * @return void
     */
    public static function setSecret(string $secret): void
    {
        self::$secret = $secret;
        self::$client = null;
    }

    /**
     * Returns the AWS secret key currently configured in the class.
     *
     * - Intended for internal use only.
     * - Returns the value previously defined through setSecret(), or NULL when no secret
     *   has been configured (the initial state, and the state after reset()).
     * - This value is highly sensitive and should never be exposed outside secure internal flows.
     *
     * The return type is nullable on purpose: see the note on getKey().
     *
     * @return string|null Currently configured AWS secret key, or null if not configured.
     */
    private static function getSecret(): ?string
    {
        return self::$secret;
    }

    /**
     * Enables or disables the forced "no operation" mode.
     *
     * When enabled, the class should skip real S3 requests and behave as if
     * the external storage integration were disabled.
     *
     * @param bool $noOperation True to enable no-op mode; false to disable it.
     *
     * @return void
     */
    public static function setNoOperation(bool $noOperation): void
    {
        self::$noOperation = $noOperation;
    }

    /**
     * Indicates the "no operation" mode (S3 disabled).
     *
     * @return bool
     */
    private static function isNoOperation(): bool
    {
        return self::$noOperation;
    }

    /**
     * Defines the default bucket to be used by the service.
     *
     * Validation rules applied:
     * - Must not be empty.
     * - Automatically converted to lowercase (strtolower).
     * - Length must be between 3 and 63 characters.
     * - Only lowercase letters, numbers, dot (.) and hyphen (-).
     * - Must not be in IPv4 format (e.g. 192.168.0.1).
     *
     * @param string|null $bucket Bucket name to be configured as default.
     *
     * @return bool True if defined successfully; false on invalid value (use getLastError() for details).
     */
    public static function setBucket(?string $bucket): bool
    {
        $bucket = self::normaliseBucketName($bucket);

        $error = self::validateBucketName($bucket);
        if ($error !== null) {
            self::$lastError = "[AWS S3] setBucket(): {$error}";
            return false;
        }

        self::$bucket = $bucket;
        return true;
    }

    /**
     * Normalises a bucket name to the only form S3 accepts: trimmed and lowercase.
     *
     * @param string|null $bucket Raw bucket name.
     *
     * @return string Normalised name ('' when nothing usable was given).
     */
    private static function normaliseBucketName(?string $bucket): string
    {
        return strtolower(trim($bucket ?? ''));
    }

    /**
     * Applies the S3 bucket naming rules to an ALREADY normalised name.
     *
     * Shared by setBucket() and by the per-call bucket guard (see requireBucket()), so a
     * bucket passed straight to a method is held to exactly the same rules as a registered
     * default instead of going unchecked to AWS.
     *
     * @param string $bucket Normalised bucket name.
     *
     * @return string|null Null when the name is valid; the error detail otherwise.
     */
    private static function validateBucketName(string $bucket): ?string
    {
        if ($bucket === '') {
            return "The bucket name '{$bucket}' cannot be empty.";
        }

        $len = strlen($bucket);
        if ($len < 3 || $len > 63) {
            return "The bucket name '{$bucket}' must be between 3 and 63 characters.";
        }

        // Only a–z, 0–9, dot and hyphen
        if (!preg_match('/^[a-z0-9.\-]+$/', $bucket)) {
            return "The bucket name '{$bucket}' may contain only lowercase letters, numbers, dot (.) and hyphen (-).";
        }

        // Must not be an IPv4 address (e.g. 192.168.0.1)
        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $bucket)) {
            return "The bucket name '{$bucket}' must not be in the format of an IP address.";
        }

        return null;
    }

    /**
     * Resolves and validates the bucket for a SINGLE operation.
     *
     * Resolution order: the explicit $bucket passed to the method, falling back to the
     * default registered with setBucket(). This is where the per-call override the class
     * advertises actually takes effect — the client itself needs no bucket, so requiring a
     * registered default before one could be built (the previous behaviour) contradicted the
     * override for no benefit.
     *
     * The resolved name goes through the same rules as setBucket(). That closes a real hole:
     * a per-call bucket used to be interpolated straight into copy()'s CopySource
     * ("{$fromBucket}/{$fromKey}"), so a name containing '/' could silently redirect the copy
     * source to a different bucket and key prefix. Names are now rejected locally instead.
     *
     * @param string $method Calling method name, for the getLastError() prefix convention.
     * @param string $bucket Explicit bucket for this call ('' to use the default).
     *
     * @return string|false The resolved, validated bucket name; false when unusable
     *                      (getLastError() is populated and the caller must not reach AWS).
     */
    private static function requireBucket(string $method, string $bucket = ''): string|false
    {
        $resolved = self::normaliseBucketName($bucket);
        if ($resolved === '') {
            $resolved = self::normaliseBucketName(self::getBucket());
        }

        if ($resolved === '') {
            self::$lastError = "[AWS S3] {$method}(): S3 not configured: missing AWS_S3_BUCKET. Pass \$bucket to {$method}() or register a default with setBucket().";
            return false;
        }

        $error = self::validateBucketName($resolved);
        if ($error !== null) {
            self::$lastError = "[AWS S3] {$method}(): {$error}";
            return false;
        }

        return $resolved;
    }

    /**
     * Rejects $options keys that the calling method derives from its own arguments.
     *
     * $options carries EXTRA S3 parameters (ACL, StorageClass, Metadata, Tagging …). It is
     * merged OVER the method's own arguments, so any key the method already owns silently
     * wins over the validated value. That was not hypothetical:
     * - $options['Bucket'] overrode the name requireBucket() had just validated, so the guard
     *   checked one bucket while the request went to another — including names like
     *   'ATTACKER-BUCKET' that validateBucketName() rejects outright, and after createBucket()
     *   had already ensured the *validated* bucket existed.
     * - $options['CopySource'] re-opened the exact cross-bucket redirect requireBucket() was
     *   written to close, pointing a copy at an arbitrary bucket and key.
     * - $options['IfNoneMatch'] contradicted $overwrite, making upload() answer true with
     *   "already exists and overwrite is disabled" to a caller that asked to overwrite.
     *
     * Rejecting, rather than "validate the merged result", is the deliberate choice: for
     * CopySource, SourceFile and Key there is no equivalent validation to re-run, and the
     * method's error messages would still name the argument instead of the value actually
     * used. A colliding call is a caller bug and fails here — locally, before any AWS request
     * and before createBucket() can have a side effect.
     *
     * Matching is case-insensitive: S3 parameter names are unique PascalCase, so a lowercase
     * 'bucket' is never some other legitimate parameter — it is the same mistake, silently
     * dropped by the SDK rather than applied. Non-string keys are ignored: array_merge()
     * appends those instead of overriding anything.
     *
     * @param string   $method   Calling method name, for the getLastError() prefix convention.
     * @param array    $options  Caller-supplied options.
     * @param string[] $reserved Parameter names the method owns.
     *
     * @return bool True when $options is clean; false on a collision (getLastError() populated).
     */
    private static function requireNoReservedOptions(string $method, array $options, array $reserved): bool
    {
        foreach (array_keys($options) as $name) {
            if (!is_string($name)) {
                continue;
            }

            foreach ($reserved as $owned) {
                if (strcasecmp($name, $owned) === 0) {
                    self::$lastError = "[AWS S3] {$method}(): \$options may not contain '{$owned}': {$method}() derives it from its own arguments and validates it, so an \$options value would silently override a checked one. Use the {$method}() parameter instead.";
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Enables or disables debug mode for testing purposes.
     *
     * - Should not be enabled in production, as it may expose sensitive information.
     *
     * @param bool $debug Boolean value to enable/disable debug.
     *
     * @return void
     */
    public static function setDebug(bool $debug): void
    {
        self::$debug = $debug;
    }

    /**
     * Returns the current state of debug mode.
     *
     * @return bool True if debug is enabled; false otherwise.
     */
    public static function getDebug(): bool
    {
        return self::$debug;
    }

    /**
     * Obtains (or initialises) the S3 client.
     *
     * Behaviour:
     * - Clears lastError on each attempt to obtain the client.
     * - In no‑op mode (setNoOperation(true)), returns null WITHOUT reading credentials and
     *   without setting lastError. No‑op never depends on credential state; every caller
     *   checks isNoOperation() before using the returned client.
     * - If one already exists, returns the cached singleton instance.
     * - If the key/secret are missing, sets lastError and returns null. It does NOT enable
     *   no‑op mode: missing configuration makes callers return false, not logical success.
     *   No‑op is entered ONLY through setNoOperation(true).
     * - A bucket is NOT required here: the client is not bound to one. Each method resolves
     *   and validates its own bucket through requireBucket(), which is what makes the
     *   documented per-call $bucket override real.
     * - Addresses the endpoint from setEndpoint() when one is set (AWS's region-derived
     *   endpoint otherwise), with the addressing style resolved by getUsePathStyleEndpoint().
     * - On initialisation error, sets lastError and returns null.
     *
     * @return S3Client|null S3 client, or null in no‑op mode / when unconfigured / on error.
     */
    private static function getClient(): ?S3Client
    {
        // reset last error on each attempt to obtain the client
        self::$lastError = null;

        // No‑op short-circuits before any credential access, so the documented no‑op
        // contract holds for an unconfigured deployment and a successful no‑op call
        // leaves getLastError() null.
        if (self::isNoOperation()) {
            return null;
        }

        if (self::$client !== null) {
            return self::$client;
        }

        $key = self::getKey();
        $secret = self::getSecret();
        if (empty($key) || empty($secret)) {
            // S3 disabled/not configured
            self::$lastError = '[AWS S3] getClient(): S3 not configured: missing AWS_S3_KEY/AWS_S3_SECRET.';
            return null;
        }

        try {
            $config = [
                'version' => 'latest',
                'region'  => self::getRegion(),
                'credentials' => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
                // Always explicit: the SDK's own default is virtual-host style, and stating
                // the resolved value keeps the built client's addressing inspectable.
                'use_path_style_endpoint' => self::getUsePathStyleEndpoint(),
            ];

            $endpoint = self::getEndpoint();
            if ($endpoint !== null) {
                $config['endpoint'] = $endpoint;
            }

            self::$client = new S3Client($config);
        } catch (\Throwable $e) {
            self::$lastError = '[AWS S3] getClient(): Failed to initialise S3Client: ' . $e->getMessage();
            return null;
        }

        return self::$client;
    }

    /**
     * Extracts the filename from a key (after the last "/").
     *
     * @param string $key Full object key in S3.
     *
     * @return string The filename (basename).
     */
    private static function extractName(string $key): string
    {
        return basename(rtrim($key, '/'));
    }

    /**
     * Checks whether the key has an extension (e.g. ".pdf", ".jpg").
     *
     * @param string $key Object key.
     *
     * @return bool True if it has an extension; false otherwise.
     */
    private static function hasExtension(string $key): bool
    {
        return pathinfo($key, PATHINFO_EXTENSION) !== '';
    }

    /**
     * Checks whether a download() "SAVE:" destination carries the same extension as the key.
     *
     * The comparison is case-insensitive: an S3 key and a local path routinely disagree on
     * extension casing ("a/REPORT.PDF" vs "/tmp/report.pdf"), and rejecting that pairing
     * serves no purpose. Two extensionless paths compare equal ('' === ''), so the caller
     * must reject an extensionless destination separately if the key has an extension.
     *
     * @param string $key    Object key in S3.
     * @param string $saveAs Local destination path.
     *
     * @return bool True when both extensions match ignoring case; false otherwise.
     */
    private static function saveAsExtensionMatchesKey(string $key, string $saveAs): bool
    {
        return strtolower(pathinfo($key, PATHINFO_EXTENSION))
            === strtolower(pathinfo($saveAs, PATHINFO_EXTENSION));
    }

    /**
     * Detects the MIME type of a local file.
     *
     * Attempts to use mime_content_type() and, as a fallback, \finfo(FILEINFO_MIME_TYPE).
     *
     * @param string $filePath Local file path.
     *
     * @return string|null Detected MIME or null if it cannot be inferred.
     */
    private static function detectMimeFromPath(string $filePath): ?string
    {
        if (function_exists('mime_content_type')) {
            $m = @mime_content_type($filePath);
            if ($m) {
                return $m;
            }
        }

        if (class_exists(\finfo::class)) {
            $f = new \finfo(FILEINFO_MIME_TYPE);
            $m = @$f->file($filePath);
            if ($m) {
                return $m;
            }
        }

        return null;
    }

    /**
     * Obtains the object metadata via HeadObject.
     *
     * Note: Each call incurs an additional request to AWS.
     *
     * @param string $key    Object key.
     * @param string $bucket Bucket name (optional; uses the class default bucket if empty).
     *
     * @throws AwsException|\Throwable
     *
     * @return array Metadata returned by AWS or empty array in no‑op mode.
     */
    private static function getHead(string $key, string $bucket = ''): array
    {
        $client = self::getClient();
        if (self::isNoOperation()) {
            return [];
        } elseif ($client === null) {
            throw new \Exception('Unable to obtain client to perform connection!');
        }

        $bucket = self::requireBucket('getHead', $bucket);
        if ($bucket === false) {
            throw new \Exception('Unable to resolve a bucket to perform connection!');
        }

        $h = $client->headObject([
            'Bucket'      => $bucket,
            'Key'         => $key,
            'ChecksumMode' => 'ENABLED',
        ]);
        $h['ChecksumSHA256'] = $h['ChecksumSHA256'] ?? null;
        $h['ContentType']    = $h['ContentType']    ?? null;

        return $h->toArray();
    }

    /**
     * Creates a bucket if it does not exist.
     *
     * Behaviour:
     * - If S3 is in no‑op mode (setNoOperation(true)), returns true without contacting AWS.
     *   Note "no‑op" means exactly that flag — an unconfigured deployment is NOT no‑op and
     *   returns false here (see the class-level Return conventions).
     * - If the bucket already exists, returns true.
     * - If it is created successfully, returns true.
     * - On a failure (AWS/IO, or missing key/secret, or no bucket resolvable from $bucket
     *   and the default), returns false and populates getLastError().
     *
     * Notes:
     * - The bucket name must be globally unique across AWS.
     * - Follows S3 naming rules (lowercase, 3–63 characters, etc.).
     *
     * @param string $bucket Bucket name (optional; uses the class default bucket if empty).
     *
     * @return bool True on success or no‑op; false on error.
     */
    public static function createBucket(string $bucket = ''): bool
    {
        $client = self::getClient();
        if (self::isNoOperation()) {
            return true;
        } elseif ($client === null) {
            return false;
        }

        $bucket = self::requireBucket('createBucket', $bucket);
        if ($bucket === false) {
            return false;
        }

        try {
            // Check if bucket already exists
            $client->headBucket(['Bucket' => $bucket]);
            return true;
        } catch (AwsException $e) {
            if ($e->getStatusCode() !== 404) {
                self::$lastError = "[AWS S3] createBucket(): Error checking bucket '{$bucket}': " . $e->getAwsErrorMessage();
                return false;
            }
        }

        // If we reach here, bucket does not exist -> create
        try {
            $args = ['Bucket' => $bucket];

            // us-east-1 does not accept LocationConstraint, other regions require it
            if (self::getRegion() !== 'us-east-1') {
                $args['CreateBucketConfiguration'] = [
                    'LocationConstraint' => self::getRegion(),
                ];
            }

            $client->createBucket($args);
            $client->waitUntil('BucketExists', ['Bucket' => $bucket]);

            return true;
        } catch (AwsException $e) {
            self::$lastError = "[AWS S3] createBucket(): Error creating bucket '{$bucket}': " . $e->getAwsErrorMessage();
            return false;
        } catch (\Throwable $e) {
            self::$lastError = "[AWS S3] createBucket(): Unexpected error creating bucket '{$bucket}': " . $e->getMessage();
            return false;
        }
    }

    /**
     * Uploads a local file to S3.
     *
     * Rules and validations:
     * - The key must end with an extension (e.g. ".pdf", ".png"). If not, the upload is rejected.
     * - The file provided in $filePath must exist on the server; otherwise,
     *   the method returns false and populates getLastError() with an appropriate message.
     * - The Content‑Type is detected from the local file (fallback to "application/octet-stream").
     *   Pass $options['ContentType'] to state it yourself; that override is honoured.
     * - $options may NOT carry a parameter upload() owns — Bucket, Key, SourceFile, Body or
     *   IfNoneMatch. Such a call is REJECTED (returns false, nothing is uploaded, no AWS
     *   request is made). $options is merged over upload()'s own arguments, so these used to
     *   win silently: an $options['Bucket'] sent the object to a bucket requireBucket() never
     *   validated, while createBucket() had ensured the validated one. See
     *   requireNoReservedOptions().
     *
     * Behaviour of the $overwrite parameter:
     * - If true (default), the object will be overwritten if it already exists in the bucket.
     * - If false, the method sends the condition `IfNoneMatch = '*'`; if the object already exists,
     *   the upload is aborted and returns true (getLastError() will indicate that the object already exists).
     *
     * Return conventions:
     * - S3 disabled (no‑op): returns true (without sending anything).
     * - Real success: returns the ObjectURL (string) of the object, or true if the SDK does not return the URL.
     * - Real failure (AWS/IO or local validation): returns false and populates getLastError().
     *
     * @param string $key       Object key in S3 (must have an extension).
     * @param string $filePath  Local file path to send (must exist).
     * @param bool   $overwrite If true, overwrites the existing object; if false, does not allow overwriting.
     * @param array  $options   ADDITIONAL putObject options (ACL, StorageClass, Metadata, Tagging,
     *                          ContentType, ChecksumAlgorithm …). Must not contain Bucket, Key,
     *                          SourceFile, Body or IfNoneMatch (case-insensitive) — see above.
     * @param string $bucket    Destination bucket name (optional; uses the class default bucket if empty).
     *
     * @return string|bool string(ObjectURL) on success; true on no‑op or success without ObjectURL; false on error.
     */
    public static function upload(
        string $key,
        string $filePath,
        bool $overwrite = true,
        array $options = [],
        string $bucket = ''
    ): string|bool {
        $client = self::getClient();
        if (self::isNoOperation()) {
            return true;
        } elseif ($client === null) {
            return false;
        }

        $bucket = self::requireBucket('upload', $bucket);
        if ($bucket === false) {
            return false;
        }

        // Before createBucket(), so a colliding call costs no AWS request and creates nothing.
        if (!self::requireNoReservedOptions('upload', $options, self::UPLOAD_RESERVED_OPTIONS)) {
            return false;
        }

        // validate and/or ensure extension
        if (!self::hasExtension($key)) {
            self::$lastError = "[AWS S3] upload(): The key '{$key}' in bucket '{$bucket}' must end with an extension.";
            return false;
        }

        // validate local file
        if (!is_file($filePath) || !is_readable($filePath)) {
            self::$lastError = "[AWS S3] upload(): Local file '{$filePath}' does not exist or is unreadable.";
            return false;
        }

        if (!self::createBucket($bucket)) {
            return false;
        }

        $contentType = self::detectMimeFromPath($filePath) ?? 'application/octet-stream';
        try {
            $args = array_merge([
                'Bucket'           => $bucket,
                'Key'              => $key,
                'ACL'              => 'private',
                'SourceFile'       => $filePath,
                'ChecksumAlgorithm' => 'SHA256',
                'ContentType'      => $contentType,
            ], $options);
            if (
                $args['ChecksumAlgorithm'] === 'SHA256' &&
                empty($args['ChecksumSHA256'])
            ) {
                $args['ChecksumSHA256'] = base64_encode(hash_file('sha256', $filePath, true));
            }
            if (!$overwrite) {
                $args['IfNoneMatch'] = '*';
            }

            $result = $client->putObject($args);
            return $result['ObjectURL'] ?? true;
        } catch (AwsException $e) {
            if ($e->getStatusCode() === 412) {
                self::$lastError = "[AWS S3] upload(): Upload error: The object '{$key}' in bucket '{$bucket}' already exists and overwrite is disabled.";
                return true;
            }
            self::$lastError = "[AWS S3] upload(): Error uploading '{$key}' to bucket '{$bucket}': " . $e->getAwsErrorMessage();
            return false;
        } catch (\Throwable $e) {
            self::$lastError = "[AWS S3] upload(): Unexpected error uploading '{$key}' to bucket '{$bucket}': " . $e->getMessage();
            return false;
        }
    }

    /**
     * Copies an object within S3 (same bucket or between buckets).
     *
     * Behaviour:
     * - If $overwrite = false and the destination already exists, aborts and returns false.
     * - Preserves metadata by default (MetadataDirective = COPY). If $preserveMetadata = false,
     *   uses REPLACE and you may pass new metadata via $options['Metadata'].
     * - In no‑op, returns true.
     * - $options may NOT carry a parameter copy() owns — Bucket, Key, CopySource,
     *   MetadataDirective or IfNoneMatch. Such a call is REJECTED (returns false, nothing is
     *   copied, no AWS request is made). $options is merged over copy()'s own arguments, so
     *   these used to win silently, and $options['CopySource'] in particular re-opened the very
     *   cross-bucket redirect requireBucket() exists to close. See requireNoReservedOptions().
     *
     * @param string $fromKey          Source key (exact).
     * @param string $toKey            Destination key (exact).
     * @param bool   $overwrite        If false, does not overwrite the destination if it exists.
     * @param bool   $preserveMetadata If false, REPLACE metadata (you may use $options['Metadata']).
     * @param array  $options          ADDITIONAL copyObject options (ACL, StorageClass, Metadata,
     *                                 Tagging, ContentType, ChecksumAlgorithm …). Must not contain
     *                                 Bucket, Key, CopySource, MetadataDirective or IfNoneMatch
     *                                 (case-insensitive) — see above.
     * @param string $fromBucket       Source bucket (optional; uses the default bucket if empty).
     * @param string $toBucket         Destination bucket (optional; uses the default bucket if empty).
     *
     * @return bool True on success/no‑op; false on error (use getLastError()).
     */
    public static function copy(
        string $fromKey,
        string $toKey,
        bool $overwrite = false,
        bool $preserveMetadata = true,
        array $options = [],
        string $fromBucket = '',
        string $toBucket = ''
    ): bool {
        $client = self::getClient();
        if (self::isNoOperation()) {
            return true;
        } elseif ($client === null) {
            return false;
        }

        $fromBucket = self::requireBucket('copy', $fromBucket);
        if ($fromBucket === false) {
            return false;
        }

        $toBucket = self::requireBucket('copy', $toBucket);
        if ($toBucket === false) {
            return false;
        }

        // Before createBucket(), so a colliding call costs no AWS request and creates nothing.
        if (!self::requireNoReservedOptions('copy', $options, self::COPY_RESERVED_OPTIONS)) {
            return false;
        }

        // Ensure destination bucket
        if (!self::createBucket($toBucket)) {
            return false;
        }

        try {
            $head = self::getHead($fromKey, $fromBucket);

            $args = array_merge([
                'Bucket'           => $toBucket,
                'Key'              => $toKey,
                'ACL'              => 'private',
                'CopySource'       => $fromBucket . '/' . str_replace('%2F', '/', rawurlencode(ltrim($fromKey, '/'))),
                'MetadataDirective' => $preserveMetadata ? 'COPY' : 'REPLACE',
                'ChecksumAlgorithm' => 'SHA256',
            ], $options);
            if (
                $args['ChecksumAlgorithm'] === 'SHA256' &&
                !empty($head['ChecksumSHA256']) &&
                empty($args['ChecksumSHA256'])
            ) {
                $args['ChecksumSHA256'] = $head['ChecksumSHA256'];
            }
            if (!empty($head['ContentType']) && empty($args['ContentType'])) {
                $args['ContentType'] = $head['ContentType'];
            }

            if (!$overwrite) {
                $args['IfNoneMatch'] = '*';
            }

            $client->copyObject($args);
            return true;
        } catch (AwsException $e) {
            if ($e->getStatusCode() === 412) {
                // IfNoneMatch='*' made S3 refuse: the destination exists and $overwrite is false.
                // NOTHING was copied, so this must report failure — move()/rename() call copy() and
                // delete the source only when it returns true. Returning true here destroyed the
                // source object on every blocked overwrite.
                self::$lastError = "[AWS S3] copy(): Error copying: The object '{$toKey}' in bucket '{$toBucket}' already exists and overwrite is disabled.";
                return false;
            }
            self::$lastError = "[AWS S3] copy(): Error copying '{$fromKey}' from bucket '{$fromBucket}' to '{$toKey}' in bucket '{$toBucket}': " . $e->getAwsErrorMessage();
            return false;
        } catch (\Throwable $e) {
            self::$lastError = "[AWS S3] copy(): Unexpected error copying '{$fromKey}' from bucket '{$fromBucket}' to '{$toKey}' in bucket '{$toBucket}': " . $e->getMessage();
            return false;
        }
    }

    /**
     * Deletes object(s) from a bucket in S3.
     *
     * Behaviour:
     * - If $recursive = false (default):
     *   - Removes only the exact object identified by $key.
     *   - $key MUST end with a file extension, or the call is REJECTED (returns false,
     *     nothing is deleted). This mirrors upload(), which refuses to write a key without
     *     an extension, so every object this library creates satisfies it. The guard exists
     *     to stop a folder prefix being handed to a single-object delete.
     *   - Deleting a key that does not exist returns true: S3's DeleteObject is idempotent,
     *     so true means "the object is not there", NOT "an object was removed".
     * - If $recursive = true:
     *   - Treats $key as a PREFIX (e.g. "folders/reports/") and deletes every object whose
     *     key starts with it. The extension rule does not apply.
     *   - The prefix is matched literally, with no folder-boundary logic: the prefix
     *     "uploads/report" also deletes "uploads/report-2024-draft.pdf". Always end a
     *     prefix with "/" unless you mean that. Do NOT reach for $recursive = true just to
     *     delete one extensionless key — you may take its siblings with it.
     *
     * Rules:
     * - In no‑op mode, returns true without calling S3.
     * - On success, returns true.
     * - On error, returns false and populates getLastError().
     *
     * Inherited by move() and rename(): both delete the SOURCE with $recursive = false, so
     * an extensionless $fromKey makes them fail AFTER the copy has already succeeded. They
     * then roll the destination back and return false, and getLastError() names delete().
     *
     * @param string $key       Exact object key (must have an extension) when $recursive is
     *                          false; a folder prefix when $recursive is true.
     * @param bool   $recursive If true, deletes all objects beginning with $key.
     * @param string $bucket    Bucket name (optional; uses the class default bucket if empty).
     *
     * @return bool True on success or no‑op; false on error or on a rejected key.
     */
    public static function delete(string $key, bool $recursive = false, string $bucket = ''): bool
    {
        $client = self::getClient();
        if (self::isNoOperation()) {
            return true;
        } elseif ($client === null) {
            return false;
        }

        $bucket = self::requireBucket('delete', $bucket);
        if ($bucket === false) {
            return false;
        }

        if (empty($key)) {
            self::$lastError = "[AWS S3] delete(): Error deleting object(s): The key in bucket '{$bucket}' cannot be empty!";
            return false;
        }

        // validate and/or ensure extension
        if (!$recursive && !self::hasExtension($key)) {
            self::$lastError = "[AWS S3] delete(): Recursive mode disabled; the key '{$key}' in bucket '{$bucket}' must end with an extension.";
            return false;
        }

        try {
            if ($recursive) {
                // list all objects with this prefix
                $objects = self::list($key, false, $bucket);
                if ($objects === false) {
                    return false;
                }

                if (!empty($objects)) {
                    $toDelete = array_map(fn($obj) => ['Key' => $obj['key']], $objects);

                    $client->deleteObjects([
                        'Bucket' => $bucket,
                        'Delete' => ['Objects' => $toDelete],
                    ]);
                }
            } else {
                // delete only a single exact object
                $client->deleteObject([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                ]);
            }

            return true;
        } catch (AwsException $e) {
            self::$lastError = "[AWS S3] delete(): Error deleting object(s) '{$key}' from bucket '{$bucket}': " . $e->getAwsErrorMessage();
            return false;
        } catch (\Throwable $e) {
            self::$lastError = "[AWS S3] delete(): Unexpected error deleting object(s) '{$key}' from bucket '{$bucket}': " . $e->getMessage();
            return false;
        }
    }

    /**
     * Moves an object within S3.
     *
     * Implements copy() + delete() of the source object. The source is deleted only after
     * the copy reports success; if the delete then fails, the destination is rolled back and
     * false is returned.
     *
     * Preconditions inherited from delete(): $fromKey MUST end with a file extension (the
     * source is removed with $recursive = false). An extensionless $fromKey fails after the
     * copy, triggering the rollback, with a getLastError() that names delete().
     *
     * With $overwrite = false, an existing destination makes copy() return false, so the
     * source is NOT deleted and nothing is lost.
     *
     * $options is forwarded to copy() untouched, so copy()'s reserved-key rule applies here
     * too: Bucket, Key, CopySource, MetadataDirective and IfNoneMatch are rejected. The
     * rejection happens inside copy(), i.e. before anything is copied or deleted, and
     * getLastError() names copy().
     *
     * @param string $fromKey          Source key (exact; must have an extension).
     * @param string $toKey            Destination key (exact).
     * @param bool   $overwrite        If false, does not overwrite the destination.
     * @param bool   $preserveMetadata If false, REPLACE metadata (you may use $options['Metadata']).
     * @param array  $options          ADDITIONAL copyObject options; see copy() for the reserved keys.
     * @param string $fromBucket       Source bucket (optional; uses the default bucket if empty).
     * @param string $toBucket         Destination bucket (optional; uses the default bucket if empty).
     *
     * @return bool True on success/no‑op; false on error (use getLastError()).
     */
    public static function move(
        string $fromKey,
        string $toKey,
        bool $overwrite = false,
        bool $preserveMetadata = true,
        array $options = [],
        string $fromBucket = '',
        string $toBucket   = ''
    ): bool {
        // copy first
        if (!self::copy($fromKey, $toKey, $overwrite, $preserveMetadata, $options, $fromBucket, $toBucket)) {
            return false;
        }

        // delete source only after successful copy
        if (!self::delete($fromKey, false, $fromBucket)) {
            $err = self::getLastError();

            // attempt to rollback the destination
            self::delete($toKey, false, $toBucket);
            if (!empty($err)) {
                self::$lastError = $err;
            }

            return false;
        }

        return true;
    }

    /**
     * Renames an object within the SAME bucket.
     *
     * Shortcut to move() keeping source and destination in the same bucket. Metadata is
     * always preserved; pass through move() directly if you need REPLACE.
     *
     * Preconditions inherited from move()/delete(): $fromKey MUST end with a file extension.
     *
     * @param string $fromKey   Current key (must have an extension).
     * @param string $toKey     New key.
     * @param bool   $overwrite If false, does not overwrite the destination.
     * @param string $bucket    Bucket (optional; uses the default bucket if empty).
     *
     * @return bool True on success/no‑op; false on error.
     */
    public static function rename(
        string $fromKey,
        string $toKey,
        bool $overwrite = false,
        string $bucket = ''
    ): bool {
        return self::move($fromKey, $toKey, $overwrite, true, [], $bucket, $bucket);
    }

    /**
     * Downloads an object from S3.
     *
     * Operation modes:
     * - If $mode begins with "TEXT": returns the file contents as a string.
     * - If $mode begins with "STREAM": returns the file contents as a stream.
     * - If $mode begins with "DOWNLOAD_TEXT": sends HTTP headers and echoes the contents (forcing a browser download as text).
     * - If $mode begins with "DOWNLOAD_STREAM:[MiB]": sends HTTP headers and streams the contents in chunks of the specified MiB (default 8 MiB).
     * - If $mode begins with "SAVE:/path/to/file": saves the requested file locally. The
     *   destination extension is REQUIRED to match the key's (see Notes). The path may be
     *   wrapped in square brackets — "SAVE:[/path/to/file]" — and MUST be when it contains
     *   a colon: the path is taken as everything after the FIRST colon, so an unwrapped
     *   Windows path ("SAVE:C:\dir\f.txt") is truncated to "C". Use "SAVE:[C:\dir\f.txt]".
     *
     * No‑op behaviour:
     * - When S3 is disabled, returns true (if saving to file or sending to output) or an empty string (if returning in memory).
     *
     * Notes:
     * - "SAVE:" REQUIRES the destination path's extension to match the key's extension.
     *   This is enforced, not advisory: on a mismatch nothing is downloaded, the method
     *   returns false and getLastError() explains. The comparison is case-insensitive, so
     *   key "a/REPORT.PDF" may be saved to "/tmp/report.pdf", but an extensionless
     *   destination (e.g. the path returned by tempnam()) is REJECTED — give the temp file
     *   the key's extension, or use "TEXT"/"STREAM" and write the bytes yourself.
     * - Any unrecognised $mode returns false without contacting S3.
     *
     * @param string $key   Object key in S3.
     * @param string $mode  Defines the download mode (see above). One of "TEXT", "STREAM",
     *                      "DOWNLOAD_TEXT", "DOWNLOAD_STREAM[:MiB]", "SAVE:/path/to/file".
     *                      Case-insensitive for the mode name itself; the SAVE path is not.
     * @param string $bucket Bucket name (optional; uses the class default bucket if empty).
     *
     * @return mixed File content as string, stream resource, true/false when operating on a file or direct output.
     */
    public static function download(string $key, string $mode = 'TEXT', string $bucket = ''): mixed
    {
        $client = self::getClient();

        $realMode = explode(':', strtoupper(trim($mode ?? '')), 2)[0];
        switch ($realMode) {
            case 'TEXT':
            case 'STREAM':
            case 'DOWNLOAD_TEXT':
            case 'DOWNLOAD_STREAM':
            case 'SAVE':
                break;
            default:
                self::$lastError = "[AWS S3] download(): Mode '{$realMode}' is not permitted.";
                return false;
        }

        $saveAs = null;
        $mbStream = null;
        if ($realMode === 'SAVE') {
            $saveAs = explode(':', trim($mode ?? ''), 2)[1] ?? '';
            if (strlen($saveAs) >= 2 && $saveAs[0] === '[' && $saveAs[strlen($saveAs) - 1] === ']') {
                $saveAs = substr($saveAs, 1, -1);
            }

            if (empty($saveAs)) {
                self::$lastError = "[AWS S3] download(): The SaveAs path '{$saveAs}' was not specified in mode 'SAVE:/path/to/file.xyz'.";
                return false;
            }
        } elseif ($realMode === 'DOWNLOAD_STREAM') {
            $mbStream = filter_var(explode(':', trim($mode ?? ''), 2)[1] ?? '', FILTER_SANITIZE_NUMBER_INT);
            $mbStream = !empty($mbStream) ? ((int)$mbStream) : 8;

            if (empty($mbStream) || $mbStream <= 0) {
                self::$lastError = "[AWS S3] download(): The chunk size in MiB was specified incorrectly in mode 'DOWNLOAD_STREAM:[MiB]'.";
                return false;
            }
        }

        if (self::isNoOperation()) {
            // no‑op: simulate success on file/output operations; empty string when returning in memory
            return ($realMode === 'SAVE' || $realMode === 'DOWNLOAD_TEXT' || $realMode === 'DOWNLOAD_STREAM') ? true : '';
        } elseif ($client === null) {
            return false;
        }

        $bucket = self::requireBucket('download', $bucket);
        if ($bucket === false) {
            return false;
        }

        // If saving to a file, validate the extension (only when $saveAs is not null)
        if ($saveAs !== null) {
            if (!self::saveAsExtensionMatchesKey($key, $saveAs)) {
                self::$lastError = "[AWS S3] download(): The SaveAs '{$saveAs}' and the key '{$key}' in bucket '{$bucket}' must end with the same extension.";
                return false;
            }
        }

        try {
            $args = [
                'Bucket' => $bucket,
                'Key'    => $key,
            ];

            // Save to disk mode
            if ($realMode === 'SAVE') {
                $args['SaveAs'] = $saveAs;
                $client->getObject($args);
                return true;
            }

            // Retrieve object in memory
            $res = $client->getObject($args);

            $body = null;
            if ($realMode === 'TEXT' || $realMode === 'DOWNLOAD_TEXT') {
                $body = (string)$res['Body'];
            } else {
                $body = $res['Body'];
            }

            // Send as attachment directly (headers + echo)
            if ($realMode === 'DOWNLOAD_TEXT' || $realMode === 'DOWNLOAD_STREAM') {
                // Avoid sending if headers have already been emitted
                if (!headers_sent()) {
                    $filename = self::extractName($key);
                    $contentType   = $res['ContentType'] ?? 'application/octet-stream';
                    $contentLength = isset($res['ContentLength']) ? (string)$res['ContentLength'] : null;
                    if ($contentLength === null && $realMode === 'DOWNLOAD_TEXT') {
                        $contentLength = (string)strlen($body);
                    }

                    header('Content-Type: ' . $contentType);
                    if ($contentLength !== null) {
                        header('Content-Length: ' . $contentLength);
                    }
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
                    header('Pragma: no-cache');
                }

                if ($realMode === 'DOWNLOAD_TEXT') {
                    echo $body;
                } else {
                    // Clear existing buffers to avoid contaminating the payload
                    while (ob_get_level() > 0) {
                        @ob_end_flush();
                    }
                    flush();

                    // Stream in blocks
                    $chunkSize = $mbStream * 1024 * 1024;
                    while (!$body->eof()) {
                        echo $body->read($chunkSize);
                        flush();
                    }
                }

                return true;
            }

            // Return content in memory
            return $body;
        } catch (AwsException $e) {
            self::$lastError = "[AWS S3] download(): Error downloading '{$key}' from bucket '{$bucket}': " . $e->getAwsErrorMessage();
            return false;
        } catch (\Throwable $e) {
            self::$lastError = "[AWS S3] download(): Unexpected error downloading '{$key}' from bucket '{$bucket}': " . $e->getMessage();
            return false;
        }
    }

    /**
     * Formats metadata of an S3 object according to the class standard.
     *
     * @param string                         $key          Full object key.
     * @param int|float|null                 $size         Size in bytes.
     * @param \DateTimeInterface|string|null $lastModified Modification date (DateTime, string or null).
     * @param string|null                    $mime         MIME type (optional).
     * @param string|null                    $checkSum     File checksum (optional).
     *
     * @return array{
     *   key:string,
     *   name:string,
     *   size:int|float,
     *   last_modified:string,
     *   mime:?string,
     *   check_sum:?string
     * }
     */
    private static function formatS3Item(string $key, int|float|null $size = 0, \DateTimeInterface|string|null $lastModified = null, ?string $mime = null, ?string $checkSum = null): array
    {
        $last = $lastModified instanceof \DateTimeInterface ? $lastModified->format('Y-m-d H:i:s') : (string)($lastModified ?? '');

        return [
            'key'          => $key,
            'name'         => self::extractName($key),
            'size'         => ($size ?? 0),
            'last_modified' => $last,
            'mime'         => $mime,
            'check_sum'    => $checkSum,
        ];
    }

    /**
     * Lists objects in a bucket/prefix.
     *
     * Options:
     * - $withMeta = true: performs a HeadObject call per item to obtain Content‑Type (additional cost).
     *
     * Return behaviour:
     * - S3 disabled (no‑op): returns [].
     * - Success: returns an array of items with (key, name, size, last_modified, mime?).
     * - Real failure (AWS/IO): returns false and populates getLastError().
     *
     * @param string $prefix   Prefix to filter objects (e.g. '/stock_adjustments/').
     * @param bool   $withMeta If true, includes the 'mime' field via HeadObject (N+1 requests).
     * @param string $bucket   Bucket name (optional; uses the class default bucket if empty).
     *
     * @return array|false Array of objects, [] in no‑op, or false on error.
     */
    public static function list(string $prefix = '', bool $withMeta = false, string $bucket = ''): array|false
    {
        $client = self::getClient();
        if (self::isNoOperation()) {
            return [];
        } elseif ($client === null) {
            return false;
        }

        $bucket = self::requireBucket('list', $bucket);
        if ($bucket === false) {
            return false;
        }

        try {
            $files = [];
            $continuation = null;
            do {
                $args = ['Bucket' => $bucket, 'Prefix' => $prefix];
                if (!empty($continuation)) {
                    $args['ContinuationToken'] = $continuation;
                }

                $result = $client->listObjectsV2($args);
                if (!empty($result['Contents'])) {
                    foreach ($result['Contents'] as $object) {
                        $head = $withMeta ? self::getHead($object['Key'], $bucket) : [];

                        $files[$object['Key']] = self::formatS3Item(
                            $object['Key'],
                            $object['Size'],
                            $object['LastModified'],
                            $head['ContentType'] ?? null,
                            $head['ChecksumSHA256'] ?? null
                        );
                    }
                }

                $continuation = $result['IsTruncated'] ? ($result['NextContinuationToken'] ?? null) : null;
            } while (!empty($continuation));
            return $files;
        } catch (AwsException $e) {
            self::$lastError = "[AWS S3] list(): Error listing bucket '{$bucket}', prefix '{$prefix}': " . $e->getAwsErrorMessage();
            return false;
        } catch (\Throwable $e) {
            self::$lastError = "[AWS S3] list(): Unexpected error listing bucket '{$bucket}', prefix '{$prefix}': " . $e->getMessage();
            return false;
        }
    }

    /**
     * Fetches a specific object by key from the bucket.
     *
     * - If the object does not exist, returns null.
     * - If an error occurs (other than 404), returns false and populates getLastError().
     *
     * @param string $key    Object key in S3.
     * @param string $bucket Bucket name (optional; uses the class default bucket).
     *
     * @return array|null|false Array with metadata; null if not found; false on error.
     */
    public static function find(string $key, string $bucket = ''): array|null|false
    {
        $client = self::getClient();
        if (self::isNoOperation()) {
            return null;
        } elseif ($client === null) {
            return false;
        }

        $bucket = self::requireBucket('find', $bucket);
        if ($bucket === false) {
            return false;
        }

        try {
            $result = self::getHead($key, $bucket);

            return self::formatS3Item(
                $key,
                $result['ContentLength'] ?? 0,
                $result['LastModified'] ?? null,
                $result['ContentType'] ?? null,
                $result['ChecksumSHA256'] ?? null
            );
        } catch (AwsException $e) {
            if ($e->getStatusCode() === 404) {
                return null; // object not found
            }
            self::$lastError = "[AWS S3] find(): Error fetching object '{$key}' from bucket '{$bucket}': " . $e->getAwsErrorMessage();
            return false;
        } catch (\Throwable $e) {
            self::$lastError = "[AWS S3] find(): Unexpected error fetching object '{$key}' from bucket '{$bucket}': " . $e->getMessage();
            return false;
        }
    }

    /**
     * Checks whether an object exists in the bucket by reusing find().
     *
     * - Returns true if the object exists.
     * - Returns false if it does not exist or on error (getLastError() may contain details).
     *
     * @param string $key    Object key in S3.
     * @param string $bucket Bucket name (optional; uses the class default bucket if empty).
     *
     * @return bool
     */
    public static function exists(string $key, string $bucket = ''): bool
    {
        $result = self::find($key, $bucket);

        // On error find() already populates lastError; here we normalise to bool
        return $result !== null && $result !== false;
    }

    /**
     * Returns the last error message recorded.
     * It is set when an error occurs in any method of the class.
     *
     * @return string|null Error message or null if there has been no error since the last call to getClient().
     */
    public static function getLastError(): ?string
    {
        return self::$lastError;
    }
}
