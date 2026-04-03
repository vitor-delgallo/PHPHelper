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
 * - Bucket creation.
 * - Register and query the last error message.
 *
 * Return conventions:
 * - When S3 is disabled (no‑op), write/read methods return logical success
 *   (true) or benign values (empty string / empty array).
 * - On real errors (AWS exceptions), methods return false and populate
 *   getLastError().
 *
 * @package App\Helper
 */
class S3Storage
{
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
        self::$key = null;
        self::$secret = null;
        self::$noOperation = false;
        self::$lastError = null;
        self::$debug = false;
    }

    /**
     * Defines the default AWS region to be used by the service.
     *
     * - Overrides the current region stored in the class.
     * - Does not perform validation by itself.
     * - Recommended to pass a valid AWS region string
     *   (e.g. us-east-1, sa-east-1, eu-west-1).
     *
     * @param string $region AWS region to be used by the service.
     *
     * @return void
     */
    public static function setRegion(string $region): void
    {
        self::$region = $region;
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
     * - This value is sensitive and should be handled carefully.
     *
     * @param string $key AWS access key.
     *
     * @return void
     */
    public static function setKey(string $key): void
    {
        self::$key = $key;
    }
    
    /**
     * Returns the AWS access key currently configured in the class.
     *
     * - Intended for internal use only.
     * - Returns the value previously defined through setKey().
     * - This value is sensitive and should not be exposed outside secure internal flows.
     *
     * @return string Currently configured AWS access key.
     */
    private static function getKey(): string
    {
        return self::$key;
    }

    /**
     * Defines the AWS secret key to be used by the service.
     *
     * - Overrides the current secret key stored in the class.
     * - Useful when credentials need to be injected dynamically at runtime.
     * - This value is highly sensitive and should be handled carefully.
     *
     * @param string $secret AWS secret key.
     *
     * @return void
     */
    public static function setSecret(string $secret): void
    {
        self::$secret = $secret;
    }

    /**
     * Returns the AWS secret key currently configured in the class.
     *
     * - Intended for internal use only.
     * - Returns the value previously defined through setSecret().
     * - This value is highly sensitive and should never be exposed outside secure internal flows.
     *
     * @return string Currently configured AWS secret key.
     */
    private static function getSecret(): string
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
        $bucket = strtolower(trim($bucket ?? ''));
        if (empty($bucket)) {
            self::$lastError = "[AWS S3] setBucket(): The bucket name '{$bucket}' cannot be empty.";
            return false;
        }

        $len = strlen($bucket);
        if ($len < 3 || $len > 63) {
            self::$lastError = "[AWS S3] setBucket(): The bucket name '{$bucket}' must be between 3 and 63 characters.";
            return false;
        }

        // Only a–z, 0–9, dot and hyphen
        if (!preg_match('/^[a-z0-9.\-]+$/', $bucket)) {
            self::$lastError = "[AWS S3] setBucket(): The bucket name '{$bucket}' may contain only lowercase letters, numbers, dot (.) and hyphen (-).";
            return false;
        }

        // Must not be an IPv4 address (e.g. 192.168.0.1)
        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $bucket)) {
            self::$lastError = "[AWS S3] setBucket(): The bucket name '{$bucket}' must not be in the format of an IP address.";
            return false;
        }

        self::$bucket = $bucket;
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
     * - If one already exists, returns the singleton instance.
     * - If AWS_S3_KEY/SECRET are missing, it enables no‑op, sets lastError and returns null.
     * - On initialisation error, sets lastError and returns null.
     * - Clears lastError on each attempt to obtain the client.
     *
     * @return S3Client|null S3 client or null if unavailable/disabled.
     */
    private static function getClient(): ?S3Client
    {
        // reset last error on each attempt to obtain the client
        self::$lastError = null;
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

        $bucket = self::getBucket();
        if (empty($bucket)) {
            self::$lastError = '[AWS S3] getClient(): S3 not configured: missing AWS_S3_BUCKET.';
            return null;
        }

        try {
            self::$client = new S3Client([
                'version' => 'latest',
                'region'  => self::getRegion(),
                'credentials' => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
            ]);
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
        $bucket = !empty($bucket) ? $bucket : self::getBucket();
        if (self::isNoOperation()) {
            return [];
        } elseif ($client === null) {
            throw new \Exception('Unable to obtain client to perform connection!');
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
     * - If S3 is in no‑op mode (not configured), returns true.
     * - If the bucket already exists, returns true.
     * - If it is created successfully, returns true.
     * - On a real failure (AWS/IO), returns false and populates getLastError().
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
        $bucket = !empty($bucket) ? $bucket : self::getBucket();
        if (self::isNoOperation()) {
            return true;
        } elseif ($client === null) {
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
     * @param array  $options   Additional putObject options (ACL, StorageClass, Metadata, Tagging etc.).
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
        $bucket = !empty($bucket) ? $bucket : self::getBucket();
        if (self::isNoOperation()) {
            return true;
        } elseif ($client === null) {
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
     *
     * @param string $fromKey          Source key (exact).
     * @param string $toKey            Destination key (exact).
     * @param bool   $overwrite        If false, does not overwrite the destination if it exists.
     * @param bool   $preserveMetadata If false, REPLACE metadata (you may use $options['Metadata']).
     * @param array  $options          Additional copyObject options (ACL, StorageClass, Metadata, Tagging etc.).
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
        $fromBucket = !empty($fromBucket) ? $fromBucket : self::getBucket();
        $toBucket   = !empty($toBucket) ? $toBucket   : self::getBucket();
        if (self::isNoOperation()) {
            return true;
        } elseif ($client === null) {
            return false;
        }

        if (empty($fromBucket) || empty($toBucket)) {
            self::$lastError = '[AWS S3] copy(): fromKey/toKey cannot be empty.';
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
                self::$lastError = "[AWS S3] copy(): Error copying: The object '{$toKey}' in bucket '{$toBucket}' already exists and overwrite is disabled.";
                return true;
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
     * - If $recursive = true:
     *   - Treats $key as a prefix (e.g. "folders/reports/") and deletes
     *     all objects starting with that prefix.
     *
     * Rules:
     * - In no‑op mode, returns true without calling S3.
     * - On success, returns true.
     * - On error, returns false and populates getLastError().
     *
     * @param string $key       Exact object key or folder prefix in S3.
     * @param bool   $recursive If true, deletes all objects beginning with $key.
     * @param string $bucket    Bucket name (optional; uses the class default bucket if empty).
     *
     * @return bool True on success or no‑op; false on error.
     */
    public static function delete(string $key, bool $recursive = false, string $bucket = ''): bool
    {
        $client = self::getClient();
        $bucket = !empty($bucket) ? $bucket : self::getBucket();
        if (self::isNoOperation()) {
            return true;
        } elseif ($client === null) {
            return false;
        } elseif (empty($key)) {
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
     * Implements copy() + delete() of the source object.
     *
     * @param string $fromKey          Source key (exact).
     * @param string $toKey            Destination key (exact).
     * @param bool   $overwrite        If false, does not overwrite the destination.
     * @param bool   $preserveMetadata If false, REPLACE metadata (you may use $options['Metadata']).
     * @param array  $options          Additional copyObject options (ACL, StorageClass, Metadata, Tagging etc.).
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
     * Shortcut to move() keeping source and destination in the same bucket.
     *
     * @param string $fromKey   Current key.
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
     * - If $mode begins with "SAVE:/path/to/file": saves the requested file locally.
     *
     * No‑op behaviour:
     * - When S3 is disabled, returns true (if saving to file or sending to output) or an empty string (if returning in memory).
     *
     * Notes:
     * - For consistency, it is recommended that the extension of the saved file ($mode "SAVE:") matches that of the key.
     *
     * @param string $key   Object key in S3.
     * @param string $mode  Defines the download mode (see above).
     * @param string $bucket Bucket name (optional; uses the class default bucket if empty).
     *
     * @return mixed File content as string, stream resource, true/false when operating on a file or direct output.
     */
    public static function download(string $key, string $mode = 'TEXT', string $bucket = ''): mixed
    {
        $client = self::getClient();
        $bucket = !empty($bucket) ? $bucket : self::getBucket();

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

        // If saving to a file, validate the extension (only when $saveAs is not null)
        if ($saveAs !== null) {
            if (pathinfo($key, PATHINFO_EXTENSION) !== pathinfo($saveAs, PATHINFO_EXTENSION)) {
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
        $bucket = !empty($bucket) ? $bucket : self::getBucket();
        if (self::isNoOperation()) {
            return [];
        } elseif ($client === null) {
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
        $bucket = !empty($bucket) ? $bucket : self::getBucket();
        if (self::isNoOperation()) {
            return null;
        } elseif ($client === null) {
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