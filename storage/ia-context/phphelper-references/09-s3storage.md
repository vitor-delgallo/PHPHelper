# S3Storage Helper

Class: `VD\PHPHelper\S3Storage`
Source file: `src/S3Storage.php`

Use for Amazon S3 integration through the AWS SDK for PHP. The class keeps static internal state for the client, bucket, region, credentials, no-op mode, debug flag, and last error.

## Methods

| Method | Use |
| --- | --- |
| `reset()` | Clears internal state, client, bucket, region, credentials, no-op, debug, and last error. |
| `setRegion(string $region)` | Sets the default AWS region. |
| `getRegion()` | Returns the configured region or fallback `us-east-1`. |
| `getBucket()` | Returns the configured default bucket. |
| `setKey(string $key)` | Sets the AWS access key. |
| `setSecret(string $secret)` | Sets the AWS secret key. |
| `setNoOperation(bool $noOperation)` | Enables/disables no-op mode. |
| `setBucket(?string $bucket)` | Sets the default bucket after validating S3 naming rules. |
| `setDebug(bool $debug)` | Enables/disables internal debug. |
| `getDebug()` | Returns the debug flag. |
| `createBucket(string $bucket = '')` | Creates an S3 bucket. |
| `upload(string $key, string $filePath, bool $overwrite = true, array $options = [], string $bucket = '')` | Uploads a local file to S3. |
| `copy(string $fromKey, string $toKey, bool $overwrite = false, bool $preserveMetadata = true, array $options = [], string $fromBucket = '', string $toBucket = '')` | Copies an object between keys/buckets. |
| `delete(string $key, bool $recursive = false, string $bucket = '')` | Deletes an object or recursive prefix. |
| `move(string $fromKey, string $toKey, bool $overwrite = false, bool $preserveMetadata = true, array $options = [], string $fromBucket = '', string $toBucket = '')` | Moves an object by copying and deleting the source. |
| `rename(string $fromKey, string $toKey, bool $overwrite = false, string $bucket = '')` | Renames an object in the same bucket. |
| `download(string $key, string $mode = 'TEXT', string $bucket = '')` | Downloads content as text, file, or configured mode. |
| `list(string $prefix = '', bool $withMeta = false, string $bucket = '')` | Lists objects by prefix, optionally with metadata. |
| `find(string $key, string $bucket = '')` | Finds a specific object and returns metadata/result. |
| `exists(string $key, string $bucket = '')` | Checks whether an object exists. |
| `getLastError()` | Returns the last recorded error. |

## Cautions

- Depends on `aws/aws-sdk-php`; do not install it without user's permission.
- Access key and secret are sensitive; never expose them in logs, responses, or public context.
- No-op mode returns logical success or benign values without performing real operations.
- Recursive `delete` can remove many objects; validate prefix and bucket first.
- Always check `getLastError()` when a method returns `false`.
