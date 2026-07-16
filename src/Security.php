<?php

namespace VD\PHPHelper;

class Security {
    /**
     * Number of byte blocks to read per iteration during file encryption (AES-256-GCM).
     *
     * This value can be adjusted based on the file size to optimize memory usage.
     *
     * @var int
     */
    private static int $fileEncryptBlocksBytes = 0;

    /**
     * Minimum master-key length (in bytes) for the AES-256 code paths. HKDF cannot add
     * entropy, so a 32-byte master is required to reach real 256-bit strength.
     *
     * @var int
     */
    private const MIN_KEY_BYTES = 32;

    /**
     * Version tag for the encryptDataDB/decryptDataDB envelope. Emitted as a prefix AND
     * bound into the GCM AAD, so a value cannot be reinterpreted under another version.
     *
     * @var string
     */
    private const DB_ENVELOPE_VERSION = 'v1';

    /**
     * Version tag for the encryptFileV2/decryptFileV2 authenticated file format.
     *
     * @var string
     */
    private const FILE_V2_VERSION = 'v2';

    /**
     * Upper bound (bytes) accepted for a single length-encoded block on read. Prevents a
     * crafted/corrupted file from forcing a huge allocation before authentication runs.
     *
     * @var int
     */
    private const MAX_ENCODED_BLOCK_BYTES = 268435456; // 256 MiB

    /**
     * Generates cryptographically secure random bytes, failing CLOSED.
     *
     * Uses random_bytes(), which throws when the platform CSPRNG is unavailable. There is
     * deliberately NO openssl_random_pseudo_bytes() fallback: that path can return
     * non-strong bytes, and a weak/repeated GCM nonce is catastrophic (nonce reuse leaks the
     * GHASH authentication key and enables forgery). Aborting beats encrypting with
     * unverified randomness.
     *
     * @param int $length Number of bytes to generate
     *
     * @throws \Exception When no cryptographically strong RNG is available
     * @return string
     */
    private static function secureRandomBytes(int $length): string {
        try {
            return random_bytes($length);
        } catch (\Throwable $e) {
            throw new \Exception("No cryptographically strong RNG available.", 0, $e);
        }
    }

    /**
     * Derives a key of the specified length from the given key & salt using HKDF with SHA-256.
     * This function is used to generate encryption keys of the required length from a base key.
     * 
     * @param string $key Base key to derive from
     * @param int $length Desired length of the derived key in bytes
     * @param string|null $salt Optional salt value to add randomness to the derived key
     * 
     * @throws \Exception
     * @return string
     */
    private static function deriveKey(string $key, int $length, ?string $salt = "", string $info = 'derived-key'): string {
        // Checks if the key is empty or it does not have the min length for AES-256.
        if (empty($key) || mb_strlen($key, '8bit') < self::MIN_KEY_BYTES) {
            throw new \Exception("Invalid encryption key. The key must be at least " . self::MIN_KEY_BYTES . " bytes long.");
        }

        return hash_hkdf(
            'sha256',
            $key,
            $length,
            $info,
            ($salt ?? "")
        );
    }
    
    /**
     * Reads a length-encoded block from the given file pointer.
     * The block format is expected to be: "{length}-{base64_encoded_data}".
     * The numeric length is read until the "-" separator, then the corresponding
     * number of bytes is read and base64-decoded before being returned.
     *
     * @param resource $fp File pointer opened for reading
     *
     * @return string|bool  Returns the decoded block content, 
     *                      false if the block length is invalid or the data cannot be fully read
     *                      true if it has reached the end of file
     */
    private static function readLengthEncodedBlock($fp): string|bool {
        $lenData = "";
        while (!feof($fp)) {
            $char = fgetc($fp);
            if ($char === "-") {
                break;
            } else if ($char === false) {
                if($lenData === "" && feof($fp)) {
                    return true;
                } else {
                    return false;
                }
            } 
            
            // Abort early if the length header grows implausibly long (hostile/corrupt file).
            if (mb_strlen($lenData, '8bit') > 20) {
                return false;
            }
            $lenData .= $char;
        }

        $lenData = Str::onlyNumbers($lenData);
        if ($lenData === "") {
            return false;
        }

        $len = (int) $lenData;
        // Bound the declared length BEFORE allocating: a crafted/corrupt file must not be able
        // to force a huge fread() before any authentication runs (memory-exhaustion DoS).
        if ($len < 0 || $len > self::MAX_ENCODED_BLOCK_BYTES) {
            return false;
        }
        $data = fread($fp, $len);
        if ($data === false || mb_strlen($data, '8bit') !== $len) {
            return false;
        }

        return ($data !== "" ? base64_decode($data, true) : true);
    }
    
    /**
     * Writes a length-encoded block to the given file pointer.
     * The given content is base64-encoded and written in the format:
     * "{length}-{base64_encoded_data}".
     *
     * @param resource $fp File pointer opened for writing
     * @param string $text Raw content to be encoded and written
     *
     * @return bool Returns true on success or false on failure
     */
    private static function writeLengthEncodedBlock($fp, $text): bool {
        $textEncoded = base64_encode($text);
        if (fwrite($fp, (mb_strlen($textEncoded, '8bit') . "-" . $textEncoded)) === false) {
            return false;
        }

        return true;
    }
    
    /**
     * Resolves and validates the real source file path.
     * It first attempts to resolve the absolute path using realpath(). If that fails,
     * it checks whether the given source is an uploaded file. An exception is thrown
     * if the source is not valid or cannot be resolved.
     *
     * @param string|null $source Source file path
     *
     * @throws \Exception
     * @return string Returns the validated real source file path
     */
    private static function getRealSource(?string $source) : string {
        $ret = false;

        // Attempts to get the absolute path of the source file
        $sourceReal = realpath($source);
        if(!empty($sourceReal)) {
            $ret = $sourceReal;
        } elseif (is_uploaded_file($source)) {
            // A genuine PHP upload whose realpath() failed: keep the tmp path itself
            // (previously this branch left $ret=false and wrongly rejected real uploads).
            $ret = $source;
        }

        // Checks the validity of the source files
        $sourceReal = null;
        if (empty($ret)) {
            throw new \Exception("File not found to be encrypted.");
        }

        return $ret;
    }
    
    /**
     * Resolves and validates the destination file path for writing.
     * It extracts the file name and directory, ensures the target directory exists
     * (creating it when allowed), normalizes the final path, and validates that the
     * destination is usable for generating the output file.
     *
     * @param string|null $destination Destination file path
     * @param string|null $permissionMode Optional permission mode used when creating the destination directory
     *
     * @throws \Exception
     * @return string Returns the validated full destination file path
     */
    private static function getRealDestination(?string $destination, ?string $permissionMode = null) : string {
        $ret = false;

        $destPath = File::getPathInfo($destination, keepFileNotExists: true, createPath: $permissionMode ?? true);
        if(empty($destPath['path']) || empty($destPath['file'])) {
            throw new \Exception("Invalid destination path provided for writing!");
        }

        if(empty($destPath['dir']) || !is_dir($destPath['dir'])) {
            throw new \Exception("Error on creating destination path!");
        }

        $ret = $destPath['path'];
        $destPath = null;

        return $ret;
    }

    /**
     * Returns the block size (bytes) read per iteration by encryptFileV2.
     *
     * When no value is set (initial state, or after setFileEncryptBlocksBytes(null)), the default
     * of 3.200.000 is installed and returned, so this never returns 0.
     *
     * @return int Always >= 1
     */
    public static function getFileEncryptBlocksBytes(): int
    {
        if (empty(self::$fileEncryptBlocksBytes)) {
            self::setFileEncryptBlocksBytes(3200000);
        }

        return self::$fileEncryptBlocksBytes;
    }

    /**
     * Sets the block size (bytes) read per iteration during file encryption.
     *
     * This is PROCESS-GLOBAL static state: on a long-lived worker (FPM child, queue worker, Swoole)
     * a value set here survives until it is changed or reset, across requests/jobs. Pass null to
     * reset, which makes the next getFileEncryptBlocksBytes() reinstall the 3.200.000 default.
     *
     * @param int|null $fileEncryptBlocksBytes Block size in bytes; must be >= 1. Pass NULL to reset
     *                                         to the default (the reset is real: it clears any
     *                                         previously-set value, it is not a no-op).
     *
     * @throws \Exception When a non-null value <= 0 is given. Such a value is a caller bug and is
     *                    rejected LOUDLY rather than silently ignored, because a silently-kept
     *                    previous value causes memory blowups far from this call site.
     * @return void
     */
    public static function setFileEncryptBlocksBytes(?int $fileEncryptBlocksBytes): void
    {
        // NULL means "reset": 0 is the sentinel getFileEncryptBlocksBytes() treats as "unset", so
        // it reinstalls the default. This must clear a previously-set value, not preserve it.
        if ($fileEncryptBlocksBytes === null) {
            self::$fileEncryptBlocksBytes = 0;
            return;
        }

        if ($fileEncryptBlocksBytes <= 0) {
            throw new \Exception("Invalid file encryption block size: must be >= 1 byte, or null to reset to the default.");
        }

        self::$fileEncryptBlocksBytes = $fileEncryptBlocksBytes;
    }

    /**
     * Generates a search hash for a given string using HMAC with a derived key.
     * This function is used to create a consistent hash for search purposes, allowing for secure comparisons without exposing the original data.
     * 
     * @param mixed $str The input string to generate the search hash for
     * @param string $key Base key to derive the search hash key from
     * @param string|null $salt (Optional) Salt value to add randomness to the derived search hash key
     * 
     * @throws \Exception
     * @return string
     */
    public static function generateSearchHash(mixed $str, string $key, ?string $salt = ""): string {
        if ($str === null || $str === "") {
            return "";
        }

        if(is_bool($str)) {
            $str = (int) $str;
        }
        $str = (string) $str;

        // Checks if the key is empty or it does not have the min length for AES-256-grade keying
        if (empty($key) || mb_strlen($key, '8bit') < self::MIN_KEY_BYTES) {
            throw new \Exception("Invalid encryption key. The key must be at least " . self::MIN_KEY_BYTES . " bytes long.");
        }

        // Normalize the salt (null -> "") so a null vs "" caller cannot yield different, unstable
        // blind indexes. The blind index must be perfectly deterministic to match on lookup.
        $keySearch = hash_hkdf(
            'sha256',
            $key,
            32,
            'search-hash',
            ($salt ?? "")
        );

        // hex: 64 caracteres
        return hash_hmac('sha256', $str, $keySearch);
    }

    /**
     * Encrypts the given file and saves the result to a new destination file V2
     *
     * @param string $source Path to the file to be encrypted (use tmp_name if from $_FILES)
     * @param string $key Encryption key to be used
     * @param string $destination Path where the encrypted file should be saved
     * @param string|null $salt Optional salt for key derivation
     * @param string|null $permissionMode Optional file permission mode to apply to the destination file
     *
     * @return string Returns the path to the encrypted file
     * @throws \Exception If encryption fails or file handling encounters an error
     *
     * @ref https://riptutorial.com/php/example/25499/symmetric-encryption-and-decryption-of-large-files-with-openssl
     */
    public static function encryptFileV2(string $source, string $key, string $destination, ?string $salt = null, ?string $permissionMode = null): string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }
        $salt = (($salt ?? "") === "" ? "?" : $salt);
        // Domain-separated key (distinct from the DB-cell / local subsystems).
        $key = self::deriveKey($key, 32, $salt, 'file-v2');

        // Attempts to get the absolute path of the source file
        $source = self::getRealSource($source);

        // Sets the destination file path
        $destination = self::getRealDestination($destination, $permissionMode);

        // Sets the default permission mode if it's empty
        $mode = File::getPermissionMode($permissionMode);

        // Sets the IV (Initialization Vector) length using the 'AES-256-gcm' algorithm
        $cipher = "aes-256-gcm";
        $version = self::FILE_V2_VERSION;
        $iv_length = openssl_cipher_iv_length($cipher);
        $tag_length = 16;

        // Random per-file identity, folded into EVERY block's AAD so blocks cannot be spliced in
        // from another file (even one under the same key) and the header cannot be swapped.
        $fileId = bin2hex(self::secureRandomBytes(16));

        // Sets the permission of the destination file
        $oldMask = umask(0);
        @chmod($destination, $mode);
        umask($oldMask);

        // Attempts to open the destination file for writing
        if ($fpOut = fopen($destination, 'wb')) {
            // Initializes the error flag
            $error = false;

            // Writes the header (cipher, version, salt, fileId). Its integrity is enforced because
            // $fileId is bound into every block's AAD and $salt drives key derivation.
            if(!self::writeLengthEncodedBlock($fpOut, $cipher)) {
                $error = "Error on writing cipher type";
            }
            if(!$error && !self::writeLengthEncodedBlock($fpOut, $version)) {
                $error = "Error on writing cipher version";
            }
            if(!$error && !self::writeLengthEncodedBlock($fpOut, $salt)) {
                $error = "Error on writing cipher salt";
            }
            if(!$error && !self::writeLengthEncodedBlock($fpOut, $fileId)) {
                $error = "Error on writing file id";
            }

            // Monotonic block counter, bound into each block's AAD to fix its position.
            $index = 0;

            // Attempts to open the source file for reading
            if (!$error && $fpIn = fopen($source, 'rb')) {
                // Reads blocks of text, encrypts them, and writes to the destination file
                while (!feof($fpIn)) {
                    // Reads a block from the source file
                    $plaintext = fread($fpIn, self::getFileEncryptBlocksBytes());
                    if($plaintext === false) {
                        $error = "Error on reading plaintext";
                        break;
                    }

                    if($plaintext === "") {
                        break;
                    }

                    // Fresh CSPRNG nonce; fails closed if no strong RNG is available.
                    $iv = self::secureRandomBytes($iv_length);

                    // AAD binds this ciphertext to (file, version, "data", position): reorder,
                    // duplicate, cross-file splice, and header tamper all fail the GCM tag.
                    $aad = $fileId . "|" . $version . "|D|" . $index;
                    $ciphertext = openssl_encrypt(
                        $plaintext,
                        $cipher,
                        $key,
                        OPENSSL_RAW_DATA,
                        $iv,
                        $tag,
                        $aad,
                        $tag_length
                    );
                    if($ciphertext === false) {
                        $error = "Error on creating cipher of plaintext";
                        break;
                    }
                    if (mb_strlen($tag, '8bit') !== $tag_length) {
                        $error = "Error on validating tag length";
                        break;
                    }

                    if(!self::writeLengthEncodedBlock($fpOut, $iv)) {
                        $error = "Error on writing IV ciphertext";
                        break;
                    }
                    if(!self::writeLengthEncodedBlock($fpOut, $tag)) {
                        $error = "Error on writing tag ciphertext";
                        break;
                    }
                    if(!self::writeLengthEncodedBlock($fpOut, $ciphertext)) {
                        $error = "Error on writing ciphertext";
                        break;
                    }

                    $index++;
                }
                @fclose($fpIn);
            } else if(!$error) {
                // Sets the error flag if the source file could not be opened
                $error = "Error on opening source file stream";
            }

            // Authenticated trailer: encrypts the total block count under AAD "...|F|<count>", so
            // dropping trailing blocks (truncation) or removing the trailer is detected on decrypt.
            if (!$error) {
                $iv = self::secureRandomBytes($iv_length);
                $aad = $fileId . "|" . $version . "|F|" . $index;
                $ciphertext = openssl_encrypt(
                    (string) $index,
                    $cipher,
                    $key,
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag,
                    $aad,
                    $tag_length
                );
                if($ciphertext === false) {
                    $error = "Error on creating end marker";
                } else if (mb_strlen($tag, '8bit') !== $tag_length) {
                    $error = "Error on validating end marker tag length";
                } else if(!self::writeLengthEncodedBlock($fpOut, $iv)) {
                    $error = "Error on writing end marker IV";
                } else if(!self::writeLengthEncodedBlock($fpOut, $tag)) {
                    $error = "Error on writing end marker tag";
                } else if(!self::writeLengthEncodedBlock($fpOut, $ciphertext)) {
                    $error = "Error on writing end marker ciphertext";
                }
            }

            // Closes the destination file
            @fclose($fpOut);

            // Checks if any error occurred during encryption
            if ($error) {
                // Deletes the destination file if an error occurred
                if(is_file($destination)) {
                    @unlink($destination);
                }
                throw new \Exception($error);
            }
        } else {
            throw new \Exception("Error while writing to the destination file.");
        }

        // Returns the path of the encrypted file
        return $destination;
    }

    /**
     * Decrypts a file produced by encryptFileV2, verifying the GCM tag of every block.
     *
     * FAILS LOUD — this function NEVER returns false. Every failure mode (unreadable source,
     * unresolvable destination, wrong key, tampered/reordered/spliced block, truncated file with no
     * authenticated end marker, missing OpenSSL) throws \Exception. There is no falsy return a
     * caller could mistake for success, matching decryptDataDB's guarantee. Callers MUST try/catch;
     * an `if ($out === false)` guard is dead code and will not run.
     *
     * The \Exception messages are raw internal diagnostics ("Encrypted file is truncated ...").
     * They are for logs — do NOT render them to end users.
     *
     * On failure the destination is restored to its pre-call state: in "w" mode the (freshly
     * created/truncated) destination is deleted; in "a" mode it is truncated back to the length it
     * had on entry, so appending a bad part NEVER destroys parts already appended. Unauthenticated
     * plaintext is never left behind either way.
     *
     * @param string $source Path to the file to be decrypted (use tmp_name when from $_FILES)
     * @param string $key Master key (>= 32 bytes), the same one passed to encryptFileV2
     * @param string $destination Path where the decrypted file should be saved
     * @param string|null $permissionMode Optional file permission mode to apply to the destination file
     * @param string $outReadMode How the destination is opened. Accepts exactly "w"/"wb" (truncate,
     *                            the default) or "a"/"ab" (append — for reassembling a multi-part
     *                            payload into one destination). Any other value THROWS; it is never
     *                            silently rewritten, because substituting a truncating mode for an
     *                            appending one destroys the caller's data.
     *
     * @return string Returns the path of the decrypted file
     * @throws \Exception On an unknown $outReadMode, or on any source/destination resolution,
     *                    authentication, truncation or tamper failure.
     */
    public static function decryptFileV2(string $source, string $key, string $destination, ?string $permissionMode = null, string $outReadMode = "w"): string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        // Attempts to get the absolute path of the source file
        $source = self::getRealSource($source);

        // Sets the destination file path
        $destination = self::getRealDestination($destination, $permissionMode);

        // Sets the default permission mode if it's empty
        $mode = File::getPermissionMode($permissionMode);

        // Sets the IV (Initialization Vector) length using the algorithm
        $cipher = "aes-256-gcm";
        // Must be the SAME constant encryptFileV2 writes and binds into every block's AAD: a
        // hardcoded literal here silently stops decrypting new files the moment the constant moves.
        $version = self::FILE_V2_VERSION;
        $iv_length = openssl_cipher_iv_length($cipher);
        $tag_length = 16;

        // Normalize the documented modes to their binary form. An unrecognized mode is REJECTED,
        // never silently rewritten: the old code mapped the documented "a" onto "wb", which
        // truncated the destination a caller had asked to append to (silent data destruction).
        $appendMode = in_array($outReadMode, array('a', 'ab'), true);
        if (!$appendMode && !in_array($outReadMode, array('w', 'wb'), true)) {
            throw new \Exception("Invalid \$outReadMode '{$outReadMode}': expected 'w'/'wb' (truncate) or 'a'/'ab' (append).");
        }
        $outReadMode = ($appendMode ? "ab" : "wb");

        // Length of the destination BEFORE we touch it. In append mode a failure must roll back to
        // exactly this length, so previously-appended parts survive a bad part.
        clearstatcache(true, $destination);
        $destExistedBefore = is_file($destination);
        $appendStartOffset = ($appendMode && $destExistedBefore ? (int) filesize($destination) : 0);

        // Sets the permission of the destination file
        $oldMask = umask(0);
        @chmod($destination, $mode);
        umask($oldMask);

        // Attempts to open the source file for reading
        if ($fpIn = fopen($source, 'rb')) {
            // Initializes the error flag
            $error = false;

            // Reads the cipher in source file
            $fCipher = self::readLengthEncodedBlock($fpIn);
            if (is_bool($fCipher) || $fCipher !== $cipher) {
                $error = "Cipher type does not match with the one used in function";
            }

            // Reads the version in source file
            $fVersion = self::readLengthEncodedBlock($fpIn);
            if (!$error && (is_bool($fVersion) || $fVersion !== $version)) {
                $error = "Cipher version does not match with the one used in function";
            }

            // Reads the salt in source file
            $salt = self::readLengthEncodedBlock($fpIn);
            if (!$error && is_bool($salt)) {
                $error = "Error on reading cipher salt";
            }

            // Reads the per-file identity (bound into every block AAD).
            $fileId = self::readLengthEncodedBlock($fpIn);
            if (!$error && (is_bool($fileId) || $fileId === "")) {
                $error = "Error on reading file id";
            }

            if (!$error) {
                $key = self::deriveKey($key, 32, $salt, 'file-v2');
            }

            // Expected position of the next data block, and whether the authenticated end marker
            // was seen. Truncation is detected by the marker being absent.
            $index = 0;
            $sawTrailer = false;

            // Attempts to open the destination file for writing
            $fpOut = false;
            if (!$error && !($fpOut = fopen($destination, $outReadMode))) {
                $error = "Error while writing to the destination file.";
            }

            while (!$error) {
                // Reads one [iv][tag][ciphertext] triple.
                $iv = self::readLengthEncodedBlock($fpIn);
                if ($iv === true) {
                    // Clean EOF: valid only if we already consumed the end marker.
                    break;
                }
                if ($iv === false) {
                    $error = "Error on reading IV ciphertext";
                    break;
                }
                if (mb_strlen($iv, '8bit') !== $iv_length) {
                    $error = "Error on validating iv length";
                    break;
                }

                $tag = self::readLengthEncodedBlock($fpIn);
                if (is_bool($tag)) {
                    $error = "Error on reading tag ciphertext";
                    break;
                }
                if (mb_strlen($tag, '8bit') !== $tag_length) {
                    $error = "Error on validating tag length";
                    break;
                }

                $ciphertext = self::readLengthEncodedBlock($fpIn);
                if (is_bool($ciphertext)) {
                    $error = "Error on reading ciphertext";
                    break;
                }

                // Try to authenticate it as the DATA block at the expected position.
                $dataAad = $fileId . "|" . $version . "|D|" . $index;
                $plaintext = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag, $dataAad);
                if ($plaintext !== false) {
                    if (fwrite($fpOut, $plaintext) === false) {
                        $error = "Error on writing plaintext";
                        break;
                    }
                    $index++;
                    continue;
                }

                // Otherwise it must be the authenticated end marker for exactly $index blocks.
                $trailerAad = $fileId . "|" . $version . "|F|" . $index;
                $count = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag, $trailerAad);
                if ($count !== false && $count === (string) $index) {
                    $sawTrailer = true;
                    // The end marker must be the last block: reject any trailing/spliced data.
                    if (self::readLengthEncodedBlock($fpIn) !== true) {
                        $error = "Trailing data after end-of-file marker";
                    }
                    break;
                }

                // Neither a valid data block nor a valid end marker => tamper / reorder / splice.
                $error = "Error on creating plaintext of a ciphertext";
                break;
            }

            // A file with no authenticated end marker has been truncated.
            if (!$error && !$sawTrailer) {
                $error = "Encrypted file is truncated (missing authenticated end marker)";
            }

            // Closes the source and destination file
            @fclose($fpIn);
            if ($fpOut) {
                @fclose($fpOut);
            }

            // Checks if any error occurred during decryption
            if ($error) {
                // Roll the destination back to its pre-call state. Unauthenticated plaintext must
                // never survive a failure, but neither must data this call did not write: in append
                // mode the file (and every part appended by an earlier successful call) belongs to
                // the caller, so truncate back to the entry length instead of deleting it.
                if (is_file($destination)) {
                    if ($appendMode && $destExistedBefore) {
                        if ($fpTrunc = fopen($destination, 'r+b')) {
                            @ftruncate($fpTrunc, $appendStartOffset);
                            @fclose($fpTrunc);
                        }
                    } else {
                        @unlink($destination);
                    }
                }
                throw new \Exception($error);
            }
        } else {
            throw new \Exception("Error while reading the source file.");
        }

        // Returns the path of the decrypted file
        return $destination;
    }

    /**
     * Encrypts a value for storage with AES-256-GCM, binding it to a caller-supplied context
     * (AAD) so a ciphertext cannot be relocated to another cell/row and still decrypt.
     *
     * Output is a self-describing envelope: "<version>:" . base64(iv || tag || ciphertext).
     * The version AND the caller's $aad are both fed as GCM Additional Authenticated Data, so a
     * value cannot be reinterpreted under another version, table, column, or row.
     *
     * @param mixed $str The value to encrypt (null/empty encrypt to "")
     * @param string $key Master key (>= 32 bytes)
     * @param string $aad Context to bind, e.g. "{table}.{column}:{row_id}". REQUIRED and should be
     *                     unique per logical cell. An empty AAD is rejected to forbid an unbound value.
     * @param string|null $salt Optional per-subject salt for key derivation
     *
     * @return string
     * @throws \Exception
     */
    public static function encryptDataDB(mixed $str, string $key, string $aad, ?string $salt = ""): string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        if ($str === null || $str === "") {
            return "";
        }

        if(is_bool($str)) {
            $str = (int) $str;
        }
        $str = (string) $str;

        // A missing context defeats the whole point of the AAD binding.
        if ($aad === "") {
            throw new \Exception("A non-empty AAD (value context) is required for encryptDataDB.");
        }

        // Domain-separated 256-bit key (distinct from the file/local subsystems).
        $key = self::deriveKey($key, 32, $salt, 'db-cell');

        $cipher = "aes-256-gcm";
        $ivLength = openssl_cipher_iv_length($cipher);
        $tagLength = 16;

        // Fresh CSPRNG nonce; fails closed if no strong RNG is available.
        $iv = self::secureRandomBytes($ivLength);

        // Bind the envelope version into the AAD so a value cannot be replayed across versions.
        $fullAad = self::DB_ENVELOPE_VERSION . "|" . $aad;

        $ciphertext = openssl_encrypt(
            $str,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $fullAad,
            $tagLength
        );

        if ($ciphertext === false) {
            throw new \Exception("Encryption failed.");
        }
        if (mb_strlen($tag, '8bit') !== $tagLength) {
            throw new \Exception("Invalid authentication tag length.");
        }

        return self::DB_ENVELOPE_VERSION . ":" . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts a value produced by encryptDataDB, verifying the GCM tag over the SAME context
     * (AAD). A tampered value, a wrong context (relocated ciphertext), an unknown version, or a
     * wrong key THROWS — it never returns a falsy value a caller could mistake for success.
     *
     * @param string|null $str Envelope produced by encryptDataDB ("" for an empty value)
     * @param string $key Master key (>= 32 bytes)
     * @param string $aad The identical context passed to encryptDataDB
     * @param string|null $salt The identical salt passed to encryptDataDB
     *
     * @return string Decrypted text ("" for an empty input)
     * @throws \Exception On decode / version / authentication failure
     */
    public static function decryptDataDB(?string $str, string $key, string $aad, ?string $salt = ""): string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        if ($str === null || $str === "") {
            return "";
        }

        if ($aad === "") {
            throw new \Exception("A non-empty AAD (value context) is required for decryptDataDB.");
        }

        // Parse and validate the self-describing version prefix ("<version>:").
        $sep = strpos($str, ":");
        if ($sep === false) {
            throw new \Exception("Malformed envelope: missing version prefix.");
        }
        $version = substr($str, 0, $sep);
        if ($version !== self::DB_ENVELOPE_VERSION) {
            throw new \Exception("Unsupported envelope version.");
        }
        $payload = substr($str, $sep + 1);

        $key = self::deriveKey($key, 32, $salt, 'db-cell');

        $decoded = Parser::base64Decode($payload);
        if ($decoded === false) {
            throw new \Exception("Failed to decode the secret message. Invalid base64.");
        }

        $cipher = "aes-256-gcm";
        $ivLength = openssl_cipher_iv_length($cipher);
        $tagLength = 16;

        if (mb_strlen($decoded, '8bit') < ($ivLength + $tagLength + 1)) {
            throw new \Exception("Encrypted payload is too short.");
        }

        $iv = mb_substr($decoded, 0, $ivLength, '8bit');
        $tag = mb_substr($decoded, $ivLength, $tagLength, '8bit');
        $ciphertext = mb_substr($decoded, $ivLength + $tagLength, null, '8bit');

        // Bind the same version + context; a mismatch fails the GCM tag below.
        $fullAad = self::DB_ENVELOPE_VERSION . "|" . $aad;

        $plaintext = openssl_decrypt(
            $ciphertext,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $fullAad
        );

        // GCM authentication failure (tamper / wrong context / wrong key) => fail LOUD.
        if ($plaintext === false) {
            throw new \Exception("Decryption failed: authentication tag mismatch.");
        }

        return $plaintext;
    }

    /**
     * Encrypts a string using AES-256-CTR with authentication (MAC).
     *
     * It derives two keys from the provided master key:
     * - One for encryption (encKey)
     * - One for message authentication (authKey)
     *
     * The result is the MAC concatenated with the IV and ciphertext, encoded in Base64.
     *
     * @param mixed $str The plaintext to encrypt (null/empty encrypt to "")
     * @param string $key Master key of AT LEAST 32 bytes. Shorter keys are REJECTED, including
     *                    16..31-byte ones: HKDF cannot add entropy, so a sub-32-byte master would
     *                    never reach real 256-bit strength.
     * @param string|null $salt Optional salt for key derivation
     *
     * @return string Encrypted string, Base64 encoded
     * @throws \Exception If the key is shorter than 32 bytes, or secure random bytes can't be generated
     *
     * @link https://stackoverflow.com/questions/9262109/simplest-two-way-encryption-using-php
     */
    public static function encryptLocal(mixed $str, string $key, ?string $salt = ""): string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        if ($str === null || $str === "") {
            return "";
        }

        if(is_bool($str)) {
            $str = (int) $str;
        }
        $str = (string) $str;

        // The 32-byte floor is enforced once, by deriveKey (see MIN_KEY_BYTES).
        $key = self::deriveKey($key, 32, $salt, 'local');

        // Derive encryption and authentication keys using HKDF
        [$encKey, $authKey] = [
            hash_hkdf("sha256", $key, 32, "local-encryption"),
            hash_hkdf("sha256", $key, 32, "local-authentication"),
        ];

        $cipher = "aes-256-ctr";
        $ivLength = openssl_cipher_iv_length($cipher);

        // Fresh CSPRNG nonce; fails closed if no strong RNG is available.
        $nonce = self::secureRandomBytes($ivLength);

        // Encrypt the plaintext using AES-256-CTR
        $encryptedData = openssl_encrypt(
            $str,
            $cipher,
            $encKey,
            OPENSSL_RAW_DATA,
            $nonce
        );

        if ($encryptedData === false) {
            throw new \Exception("Encryption failed.");
        }
        
        $payload = $nonce . $encryptedData;
        $mac = hash_hmac("sha256", $payload, $authKey, true);

        return base64_encode($mac . $payload);
    }

    /**
     * Decrypts a Base64-encoded string encrypted with AES-256-CTR and authenticated with HMAC-SHA256.
     *
     * It verifies the MAC before attempting decryption. If the MAC is invalid, an exception is thrown.
     *
     * @param string|null $str The encrypted string, Base64 encoded ("" for an empty value)
     * @param string $key Master key of AT LEAST 32 bytes — the same one passed to encryptLocal.
     *                    Shorter keys are REJECTED (see encryptLocal).
     * @param string|null $salt Optional salt for key derivation
     *
     * @return string|false Decrypted string or false if decryption fails
     * @throws \Exception If the key is shorter than 32 bytes, Base64 is malformed, or MAC verification fails
     *
     * @link https://stackoverflow.com/questions/9262109/simplest-two-way-encryption-using-php
     */
    public static function decryptLocal(?string $str, string $key, ?string $salt = ""): string|false {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        if ($str === null || $str === "") {
            return "";
        }

        // The 32-byte floor is enforced once, by deriveKey (see MIN_KEY_BYTES).
        $key = self::deriveKey($key, 32, $salt, 'local');

        // Derive encryption and authentication keys using HKDF
        [$encKey, $authKey] = [
            hash_hkdf("sha256", $key, 32, "local-encryption"),
            hash_hkdf("sha256", $key, 32, "local-authentication"),
        ];

        // Decode Base64 (custom function)
        $decoded = Parser::base64Decode($str);
        if ($decoded === false) {
            throw new \Exception("Failed to decode encrypted message. Invalid Base64.");
        }

        $cipher = "aes-256-ctr";
        $ivLength = openssl_cipher_iv_length($cipher);
        $macSize = 32;

        if (mb_strlen($decoded, '8bit') < ($macSize + $ivLength + 1)) {
            throw new \Exception("Encrypted payload is too short.");
        }

        $mac = mb_substr($decoded, 0, $macSize, '8bit');
        $payload = mb_substr($decoded, $macSize, null, '8bit');

        $calculatedMac = hash_hmac("sha256", $payload, $authKey, true);
        if (!hash_equals($mac, $calculatedMac)) {
            throw new \Exception("Provided MAC does not match the calculated MAC.");
        }

        $nonce = mb_substr($payload, 0, $ivLength, '8bit');
        $encryptedPayload = mb_substr($payload, $ivLength, null, '8bit');

        return openssl_decrypt(
            $encryptedPayload,
            $cipher,
            $encKey,
            OPENSSL_RAW_DATA,
            $nonce
        );
    }

    /**
     * Encrypts a string, can be used cross-platform.
     *
     * @param mixed $var Value to be encrypted
     * @param string $key Master key of AT LEAST 32 bytes. Shorter keys are REJECTED (HKDF cannot
     *                    add entropy).
     * @param string|null $salt Salt for key derivation
     *
     * @return string|null
     * @throws \Exception
     *
     * @ref https://github.com/mervick/aes-bridge-php
     */
    public static function encryptCrossPlatform(mixed $var, string $key, ?string $salt = ""): ?string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }
        if (!class_exists(\AesBridge\Gcm::class)) {
            throw new \Exception("Class '\AesBridge\Gcm' not found");
        }

        if ($var === null || $var === "") {
            return $var;
        }

        // The 32-byte floor is enforced once, by deriveKey (see MIN_KEY_BYTES).
        $key = self::deriveKey($key, 32, $salt);

        if ($var === true) {
            $var = "{{!BOOL_TRUE!}}";
        }
        if ($var === false) {
            $var = "{{!BOOL_FALSE!}}";
        }
        return \AesBridge\Gcm::encrypt($var, $key);
    }

    /**
     * Decrypts a string, can be used cross-platform.
     *
     * @param mixed $encrypted Text to be decrypted
     * @param string $key Master key of AT LEAST 32 bytes — the same one passed to
     *                    encryptCrossPlatform. Shorter keys are REJECTED.
     * @param string|null $salt Salt for key derivation
     *
     * @return mixed
     * @throws \Exception
     *
     * @ref https://github.com/mervick/aes-bridge-php
     */
    public static function decryptCrossPlatform(mixed $encrypted, string $key, ?string $salt = ""): mixed {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }
        if (!class_exists(\AesBridge\Gcm::class)) {
            throw new \Exception("Class '\AesBridge\Gcm' not found");
        }

        if ($encrypted === null || $encrypted === "") {
            return $encrypted;
        }

        // The 32-byte floor is enforced once, by deriveKey (see MIN_KEY_BYTES).
        $key = self::deriveKey($key, 32, $salt);

        $ret = \AesBridge\Gcm::decrypt($encrypted, $key);
        if ($ret === "{{!BOOL_TRUE!}}") {
            $ret = true;
        }
        if ($ret === "{{!BOOL_FALSE!}}") {
            $ret = false;
        }
        return $ret;
    }

    /**
     * Applies a Security encrypt/decrypt/hash method to every scalar leaf of an array or object.
     *
     * NOT a general callable dispatcher. $fnName names a method OF THIS CLASS and nothing else; it
     * is resolved against an ALLOWLIST, and anything outside it throws \Exception. Your own
     * functions and other classes' methods are not reachable by design.
     *
     * The leaf call is `Security::$fnName($value, $key, $salt)`, so only methods accepting
     * (mixed $value, string $key, ?string $salt) can be dispatched. Exactly these five qualify:
     *   encryptLocal · decryptLocal · encryptCrossPlatform · decryptCrossPlatform · generateSearchHash
     *
     * encryptDataDB / decryptDataDB are REJECTED, not merely discouraged. Their third parameter is
     * $aad, not $salt, so dispatching them here would silently bind $salt as the AAD while their own
     * $salt kept its "" default — every value encrypted with an unsalted key and an AAD nobody
     * intended. It would round-trip (both directions wrong identically), so the defect would stay
     * invisible while the per-cell relocation binding those methods exist to provide was gone. They
     * need a per-cell AAD, which is meaningless for a bulk array walk — call them directly.
     *
     * @param mixed $item Value, array or object to walk. Arrays/objects recurse to every scalar
     *                    leaf; an object is converted to an array and RETURNED AS AN ARRAY.
     * @param string $key Master key, forwarded as the 2nd argument to $fnName
     * @param string|null $salt Optional salt for key derivation, forwarded as the THIRD argument
     * @param string $fnName Name of one of the five allowlisted methods above. A "Security::" or
     *                       "self::" prefix is optional and stripped. An empty name returns $item
     *                       unchanged.
     *
     * @throws \Exception When $fnName is not one of the five allowlisted methods, or by $fnName
     *                    itself (invalid key, authentication failure, ...).
     * @return mixed The walked structure; arrays/objects come back as arrays, a scalar comes back
     *               as $fnName's return value.
     */
    public static function applySecurityFunctionArray(mixed $item, string $key, ?string $salt, string $fnName): mixed {
        if(empty($fnName)) {
            return $item;
        }

        // Strip an optional "self::" / "Security::" / "class::" prefix down to the bare method name.
        foreach (["self::", "Security::", "class::"] AS $prefix) {
            if (Str::containsString($fnName, $prefix, true)) {
                $fnName = Str::replaceString($prefix, "", $fnName, true);
            }
        }

        // Allowlist: ONLY methods whose 3rd parameter really is $salt. This is what makes the
        // documented contract enforceable instead of advisory — notably it turns a bulk
        // encryptDataDB/decryptDataDB call (whose 3rd parameter is $aad) into a loud failure
        // rather than a silent, symmetric AAD misbinding.
        $allowed = [
            'encryptLocal',
            'decryptLocal',
            'encryptCrossPlatform',
            'decryptCrossPlatform',
            'generateSearchHash',
        ];
        $method = null;
        foreach ($allowed AS $candidate) {
            if (strcasecmp($fnName, $candidate) === 0) {
                $method = $candidate;
                break;
            }
        }
        if ($method === null) {
            throw new \Exception(
                "Unsupported function '{$fnName}' for applySecurityFunctionArray. Allowed: " .
                implode(", ", $allowed) . ". (encryptDataDB/decryptDataDB take an \$aad as their " .
                "third argument, not a \$salt, and must be called directly with a per-cell AAD.)"
            );
        }

        // Bind to THIS class explicitly. A bare "Security::method" string resolves against the
        // GLOBAL namespace and never finds VD\PHPHelper\Security, which made every call fail.
        $callable = [self::class, $method];

        if (is_object($item)) {
            $item = (array) $item;
        }
        if (!is_array($item)) {
            return call_user_func($callable, $item, $key, $salt);
        }

        $keyItem = null;
        $valueItem = null;
        foreach ($item AS $keyItem => $valueItem) {
            if (!is_array($valueItem) && !is_object($valueItem)) {
                $item[$keyItem] = call_user_func($callable, $valueItem, $key, $salt);
            } else {
                $item[$keyItem] = self::applySecurityFunctionArray($valueItem, $key, $salt, $fnName);
            }
        }
        $keyItem = null;
        $valueItem = null;

        return $item;
    }

    /**
     * Elements this sanitizer is willing to EMIT, mapped to the attributes allowed on each
     * (on top of XSS_GLOBAL_ATTRIBUTES). Anything absent here is never emitted.
     *
     * Deliberately excluded, and NOT to be added without re-reading xssCleanRecursive's soundness
     * argument — adding any of these breaks it:
     *  - Raw-text / escapable-raw-text elements (script, style, textarea, title, xmp, noembed,
     *    noframes, plaintext): their content is NOT parsed as markup, so escaped text inside them
     *    does not stay inert when the browser re-parses our output.
     *  - Foreign content (svg, math): switches the parser into XML-ish rules mid-document, which is
     *    the engine behind most mutation-XSS (mXSS) vectors.
     *  - id / name-bearing form controls: DOM clobbering.
     *
     * @var array<string, string[]>
     */
    private const XSS_ALLOWED_ELEMENTS = [
        'a' => ['href'], 'abbr' => [], 'b' => [], 'blockquote' => ['cite'], 'br' => [],
        'caption' => [], 'cite' => [], 'code' => [], 'col' => ['span'], 'colgroup' => ['span'],
        'dd' => [], 'del' => ['cite', 'datetime'], 'div' => [], 'dl' => [], 'dt' => [], 'em' => [],
        'figcaption' => [], 'figure' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'h5' => [], 'h6' => [], 'hr' => [], 'i' => [], 'img' => ['src', 'alt', 'width', 'height'],
        'ins' => ['cite', 'datetime'], 'kbd' => [], 'li' => ['value'], 'mark' => [],
        'ol' => ['start', 'reversed'], 'p' => [], 'pre' => [], 'q' => ['cite'], 's' => [],
        'samp' => [], 'small' => [], 'span' => [], 'strong' => [], 'sub' => [], 'sup' => [],
        'table' => [], 'tbody' => [], 'td' => ['colspan', 'rowspan'], 'tfoot' => [],
        'th' => ['colspan', 'rowspan', 'scope'], 'thead' => [], 'tr' => [], 'u' => [], 'ul' => [],
        'var' => [], 'wbr' => [],
    ];

    /**
     * Attributes allowed on every allow-listed element.
     *
     * "style" is deliberately absent: CSS is its own injection context (expression(),
     * url(javascript:), -moz-binding). "id"/"name" are absent to avoid DOM clobbering.
     *
     * @var string[]
     */
    private const XSS_GLOBAL_ATTRIBUTES = ['title', 'lang', 'dir', 'class'];

    /**
     * Allow-listed elements that are void (self-closing, never given a closing tag).
     *
     * @var string[]
     */
    private const XSS_VOID_ELEMENTS = ['br', 'hr', 'img', 'col', 'wbr'];

    /**
     * Allow-listed attributes whose value is a URL and therefore needs scheme validation.
     *
     * @var string[]
     */
    private const XSS_URL_ATTRIBUTES = ['href', 'src', 'cite'];

    /**
     * URL schemes permitted in an XSS_URL_ATTRIBUTES value. A scheme-less (relative) URL is also
     * allowed; anything else — javascript:, vbscript:, data:, blob:, file: — is dropped.
     *
     * @var string[]
     */
    private const XSS_ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto', 'tel', 'ftp', 'ftps'];

    /**
     * Elements whose ENTIRE SUBTREE is discarded rather than unwrapped.
     *
     * Soundness does not depend on this list (an unwrapped subtree emits only escaped text and
     * allow-listed elements, which is already inert). It exists so that dropping <script> does not
     * leave its source code behind as visible page text.
     *
     * @var string[]
     */
    private const XSS_DROP_SUBTREE_ELEMENTS = [
        'script', 'style', 'svg', 'math', 'template', 'noscript', 'noembed', 'noframes', 'iframe',
        'object', 'embed', 'applet', 'frame', 'frameset', 'link', 'meta', 'base', 'title', 'head',
        'xml', 'form', 'input', 'button', 'select', 'textarea', 'option', 'optgroup', 'canvas',
        'audio', 'video', 'source', 'track', 'param', 'marquee', 'xmp', 'plaintext', 'listing',
        'portal', 'keygen', 'menuitem', 'bgsound', 'layer', 'ilayer',
    ];

    /**
     * Escapes a string so it is inert in an HTML text or quoted-attribute context.
     *
     * @param string $text Raw text
     * @return string
     */
    private static function xssEscape(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Validates a URL attribute value against XSS_ALLOWED_URL_SCHEMES.
     *
     * The scheme is matched against a PROBE copy with every control character and every kind of
     * whitespace removed, because browsers ignore those when resolving a scheme: "jav&#x09;ascript:"
     * decodes to "jav\tascript:" and still executes. Testing the raw value would miss it.
     *
     * @param string $value Decoded attribute value
     * @return string|null The ORIGINAL value when the scheme is allowed (or the URL is relative);
     *                     null when the attribute must be dropped.
     */
    private static function xssSafeUrl(string $value): ?string {
        $probe = preg_replace('/[\p{C}\p{Z}\s]+/u', '', $value);

        // preg_replace returns null on a malformed-UTF-8 subject: fail CLOSED.
        if ($probe === null || $probe === "") {
            return null;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $matches)) {
            if (!in_array(strtolower($matches[1]), self::XSS_ALLOWED_URL_SCHEMES, true)) {
                return null;
            }
        } elseif (str_starts_with($probe, ":")) {
            return null;
        }

        return $value;
    }

    /**
     * Recursively rebuilds one parsed DOM node as sanitized HTML.
     *
     * This is the whole allow-list: it can only ever emit (a) text escaped by xssEscape(), or
     * (b) an element named in XSS_ALLOWED_ELEMENTS carrying only allow-listed, escaped attributes.
     * Comments, CDATA, processing instructions and doctypes are dropped — a comment is an mXSS
     * vector and carries no display value here.
     *
     * @param \DOMNode $node Node to rebuild
     * @return string Sanitized HTML
     */
    private static function xssSanitizeNode(\DOMNode $node): string {
        if ($node instanceof \DOMText || $node instanceof \DOMCdataSection) {
            return self::xssEscape($node->nodeValue ?? "");
        }
        if (!($node instanceof \DOMElement)) {
            // Comment, processing instruction, doctype, ...
            return "";
        }

        $tag = strtolower($node->nodeName);
        if (in_array($tag, self::XSS_DROP_SUBTREE_ELEMENTS, true)) {
            return "";
        }

        $children = "";
        foreach ($node->childNodes as $child) {
            $children .= self::xssSanitizeNode($child);
        }

        // Not allow-listed but not dangerous either: drop the tag, keep the sanitized content.
        if (!array_key_exists($tag, self::XSS_ALLOWED_ELEMENTS)) {
            return $children;
        }

        $allowedAttributes = array_merge(self::XSS_GLOBAL_ATTRIBUTES, self::XSS_ALLOWED_ELEMENTS[$tag]);

        $html = "<" . $tag;
        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (!in_array($name, $allowedAttributes, true)) {
                // Every event handler (on*) lands here, whatever separator introduced it.
                continue;
            }

            $value = $attribute->nodeValue ?? "";
            if (in_array($name, self::XSS_URL_ATTRIBUTES, true)) {
                $value = self::xssSafeUrl($value);
                if ($value === null) {
                    continue;
                }
            }

            // $name is a literal from the allow-list, so only the value can carry hostile bytes.
            $html .= " " . $name . '="' . self::xssEscape($value) . '"';
        }

        if (in_array($tag, self::XSS_VOID_ELEMENTS, true)) {
            return $html . " />";
        }

        return $html . ">" . $children . "</" . $tag . ">";
    }

    /**
     * Sanitizes one HTML string by parsing it and rebuilding it against the allow-list.
     *
     * @param string $data Untrusted HTML
     * @return string Sanitized HTML, safe for an HTML body context
     */
    private static function xssSanitizeHtml(string $data): string {
        // Fail CLOSED when we cannot parse: escape everything instead. This destroys markup but can
        // never execute. ext-dom is only a "suggest" of this package, so its absence must be safe.
        if (!class_exists(\DOMDocument::class) || !mb_check_encoding($data, 'UTF-8')) {
            return self::xssEscape($data);
        }

        $document = new \DOMDocument();

        // Hostile input is expected to be malformed; libxml must not emit warnings for it. Restore
        // the caller's error mode rather than clobbering it.
        $previousErrorMode = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
            . '<body>' . $data . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        if (!$loaded) {
            return self::xssEscape($data);
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return self::xssEscape($data);
        }

        $html = "";
        foreach ($body->childNodes as $child) {
            $html .= self::xssSanitizeNode($child);
        }

        return $html;
    }

    /**
     * Sanitizes untrusted HTML down to a safe subset, recursively over arrays and objects.
     *
     * This is an ALLOW-LIST sanitizer. The string is PARSED (DOMDocument) and then REBUILT from the
     * parse tree: the output can only contain elements named in XSS_ALLOWED_ELEMENTS, carrying only
     * allow-listed attributes, with every text and attribute value escaped. Everything else — every
     * event handler, every unknown attribute, every javascript:/vbscript:/data: URI, <script>,
     * <svg>, <math>, comments — is dropped. Nothing is pattern-matched, so there is no separator,
     * entity or casing trick to "evade": a novel bypass shape would have to make the PARSER hand us
     * an allow-listed element with an allow-listed attribute, which is exactly the safe subset.
     *
     * This REPLACES a blacklist that had live bypasses (`<svg/onload=alert(1)>` and
     * `<img/onerror=alert(1) src=x>` passed through it unchanged, because its on*-attribute rule
     * required whitespace before "on" and "/" is also a valid attribute separator). Both are now
     * neutralised, along with the wider bypass battery pinned in SecurityTest.
     *
     * Why the output stays inert once the browser re-parses it (the mXSS argument): the allow-list
     * contains no raw-text element (script/style/textarea/title/xmp/...) and no foreign-content
     * element (svg/math). Those are the only constructs whose descendants get parsed under
     * different rules, so our serialized output re-parses to the tree we sanitized. This matters
     * because DOMDocument uses libxml's HTML4 parser, which does NOT implement the HTML5 parsing
     * algorithm — refusing to emit those elements is what makes the divergence unexploitable.
     *
     * HONEST LIMITS — still true, read them:
     *  - Output is safe for an HTML BODY (element content) context ONLY. It is NOT pre-escaped for
     *    an attribute, <script>, <style> or URL context. Interpolating it into any of those is
     *    still an injection.
     *  - It does NOT replace context-correct output encoding + a Content-Security-Policy. Use it to
     *    accept rich text you intend to RENDER as HTML; for input you will render as plain text,
     *    escape at output instead — that is simpler and strictly safer.
     *  - It REWRITES rather than preserves: markup is normalized (tags lowercased, attributes
     *    reordered/quoted, unknown tags unwrapped, `&` escaped). Do not use it on a value you need
     *    back byte-for-byte, and sanitize on OUTPUT (or store both forms) rather than destroying
     *    the original on input.
     *  - If ext-dom is unavailable, or the input is not valid UTF-8, it falls back to escaping the
     *    whole string (htmlspecialchars, ENT_QUOTES|ENT_SUBSTITUTE): still safe, but it destroys
     *    legitimate markup.
     *
     * @param mixed $input The value to sanitize. Arrays and objects are walked recursively to every
     *                     scalar leaf (an object's public properties are rewritten IN PLACE, and
     *                     the same instance is returned). Non-string scalars are returned unchanged.
     * @return mixed The sanitized value
     */
    public static function xssCleanRecursive(mixed $input): mixed {
        if ($input === null || $input === "" || is_bool($input) || filter_var($input, FILTER_VALIDATE_INT) || filter_var($input, FILTER_VALIDATE_FLOAT)) {
            return $input;
        }

        if (is_array($input)) {
            foreach ($input as $key => $value) {
                $input[$key] = self::xssCleanRecursive($value);
            }
        } elseif (is_object($input)) {
            foreach ($input as $key => $value) {
                $input->$key = self::xssCleanRecursive($value);
            }
        }

        if (is_string($input)) {
            return self::xssSanitizeHtml($input);
        }

        return $input;
    }

    /**
     * Retrieves a value from an array/object/scalar, applying the selected transformations.
     *
     * Arrays and objects are walked recursively and the filters are applied to every scalar leaf;
     * the container type is preserved (an object stays an object, its public properties rewritten
     * in place). A scalar is filtered directly.
     *
     * MUTATION ASYMMETRY: an array $source is copied (PHP copy-on-write), so the caller's array is
     * untouched and only the return value is filtered. An OBJECT $source is a shared handle, so its
     * public properties are rewritten IN PLACE — the caller's object is modified and the same
     * instance is returned. Clone before calling if you need the original intact. Only public
     * properties are visited; private/protected ones are invisible to the walk and survive
     * unfiltered.
     *
     * @param mixed $source The input array, object, or scalar
     * @param string|null $key If set, extracts a value from $source by key. ARRAYS ONLY: if $source
     *                         is an object (or not an array at all), or the key is absent or null,
     *                         $ifNull is returned. Pass null to filter $source itself.
     * @param mixed $ifNull Default value to return if $source is null, or the key is missing/null
     * @param bool $decodeStr Whether to decode the string using a custom decoder
     * @param bool $xssClean Runs xssCleanRecursive(), an ALLOW-LIST sanitizer that reduces the value
     *                       to a safe HTML subset. Read that method's docblock before enabling this:
     *                       its output is safe for an HTML BODY context only (not an attribute,
     *                       <script>, <style> or URL context), and it REWRITES markup rather than
     *                       preserving it byte-for-byte. Enable it only for rich text you intend to
     *                       RENDER as HTML; for values you render as plain text, escape at output.
     * @param bool $stripTags Whether to strip HTML tags
     * @param bool $htmlEntities Whether to apply htmlentities()
     * @param bool $addSlashes Whether to apply addslashes()
     * @param bool $escapeDB Whether to escape for DB queries using custom method
     * @param bool $trim Whether to apply trim()
     * @param bool $formatDecimal Whether to format value as a decimal
     * @param bool $asInteger Whether to extract only numeric digits
     * @param bool $asBoolean Whether to convert result to boolean
     * @param bool $base64Encode Whether to base64-encode the result
     * @param bool $base64Decode Whether to base64-decode the result
     * @param bool $base64UrlEncode Whether to base64-URL-encode the result
     * @param bool $base64UrlDecode Whether to base64-URL-decode the result
     * @param bool $urlEncode Whether to URL-encode the result
     * @param bool $urlDecode Whether to URL-decode the result
     * @param bool $jsonEncode Whether to JSON-encode the result
     * @param bool $jsonDecode Whether to JSON-decode the result
     *
     * @return mixed The filtered value
     */
    public static function filterValue(
        mixed $source,
        ?string $key = null,
        mixed $ifNull = null,
        bool $decodeStr = false,
        bool $xssClean = false,
        bool $stripTags = false,
        bool $htmlEntities = false,
        bool $addSlashes = false,
        bool $escapeDB = false,
        bool $trim = false,
        bool $formatDecimal = false,
        bool $asInteger = false,
        bool $asBoolean = false,
        bool $base64Encode = false,
        bool $base64Decode = false,
        bool $base64UrlEncode = false,
        bool $base64UrlDecode = false,
        bool $urlEncode = false,
        bool $urlDecode = false,
        bool $jsonEncode = false,
        bool $jsonDecode = false
    ): mixed {
        if (
            $source === null ||
            ($key !== null && (!is_array($source) || !Validator::hasProperty($key, $source) || $source[$key] === null))
        ) {
            return $ifNull;
        }

        $value = ($key !== null) ? $source[$key] : $source;

        $applyFilters = function ($val) use (
            $decodeStr, $xssClean, $stripTags, $htmlEntities, $addSlashes, $escapeDB, $trim,
            $formatDecimal, $asInteger, $asBoolean,
            $base64Encode, $base64Decode, $base64UrlEncode, $base64UrlDecode,
            $urlEncode, $urlDecode,
            $jsonEncode, $jsonDecode
        ) {
            if ($decodeStr) {
                $val = Str::decodeText($val);
            }
            if ($xssClean) {
                $val = self::xssCleanRecursive($val);
            }
            if ($stripTags) {
                $val = strip_tags($val);
            }
            if ($htmlEntities) {
                $val = htmlentities($val);
            }
            if ($addSlashes) {
                $val = addslashes($val);
            }
            if ($escapeDB) {
                $val = SQL::escapeString($val);
            }
            if ($trim) {
                $val = trim($val);
            }

            if ($formatDecimal) {
                $val = Formatter::formatNumber($val);
                if ($val === "") $val = 0;
            } elseif ($asInteger) {
                $val = Str::onlyNumbers($val);
                if ($val === "") $val = 0;
            } elseif ($asBoolean) {
                $val = !Validator::isCompletelyEmpty($val);
            }

            if ($base64Encode) {
                $val = base64_encode($val);
            } elseif ($base64Decode) {
                $val = Parser::base64Decode($val);
            } elseif ($base64UrlEncode) {
                $val = Parser::base64UrlEncode($val);
            } elseif ($base64UrlDecode) {
                $val = Parser::base64UrlDecode($val);
            }

            if ($urlEncode) {
                $val = urlencode($val);
            } elseif ($urlDecode) {
                $val = urldecode($val);
            }

            if ($jsonEncode) {
                $val = json_encode($val, true);
            } elseif ($jsonDecode) {
                $val = json_decode(Str::decodeText($val), true);
            }

            return $val;
        };

        if (is_array($value) || is_object($value)) {
            $isObject = is_object($value);
            foreach ($value as $k => $v) {
                $filtered = self::filterValue(
                    $v, null, $ifNull,
                    $decodeStr, $xssClean, $stripTags, $htmlEntities, $addSlashes, $escapeDB, $trim,
                    $formatDecimal, $asInteger, $asBoolean,
                    $base64Encode, $base64Decode, $base64UrlEncode, $base64UrlDecode,
                    $urlEncode, $urlDecode,
                    $jsonEncode, $jsonDecode
                );

                // Write back through the right accessor. Subscripting an object with [] (the old
                // behavior) is a fatal Error for anything not implementing ArrayAccess, e.g. the
                // stdClass every json_decode() produces. Mirrors xssCleanRecursive.
                if ($isObject) {
                    $value->$k = $filtered;
                } else {
                    $value[$k] = $filtered;
                }
            }
        } else {
            $value = $applyFilters($value);
        }

        return $value;
    }

    /**
     * Hashes a password with Argon2id, for storage.
     *
     * Rejects an empty password LOUDLY. It previously returned "" — which is not a hash — for any
     * empty()-y password, so a caller following the "@return string Encrypted password" contract
     * persisted "" into the password column and permanently locked the account out (verifyPassword
     * against "" is always false). That also swallowed the literal password "0", which empty()
     * reports as empty. Both are now impossible: this either returns a real hash or throws.
     *
     * The returned hash is self-describing (algorithm, cost and salt are embedded), so no salt or
     * algorithm needs to be stored alongside it. Store it as-is; it is never "" and never null.
     *
     * @param string|null $password Password to hash. Must be a non-empty string. NULL and "" are
     *                              rejected; "0" is a perfectly valid password and IS hashed.
     *
     * @throws \Exception When $password is null or "". An empty password is a validation failure
     *                    the caller must handle — it is never silently turned into a stored value.
     * @return string Argon2id hash, always non-empty
     */
    public static function encryptPassword(?string $password): string {
        // Strict test, matching this file's null/"" idiom elsewhere: empty() would also swallow the
        // legitimate password "0".
        if ($password === null || $password === "") {
            throw new \Exception("Cannot hash an empty password.");
        }

        return password_hash($password, PASSWORD_ARGON2ID);
    }

    /**
     * Verifies a password against a hash produced by encryptPassword, in constant time.
     *
     * @param string $password Password to be verified. NOT nullable: passing null raises a
     *                         \TypeError. Null-coalesce at the call site (`$input ?? ''`) rather
     *                         than forwarding a missing form field straight in — a null password is
     *                         a caller bug, not a failed login.
     * @param string $hash Hash produced by encryptPassword. REQUIRED and NOT nullable: a NULL
     *                     column (SSO-only, invited, or not-yet-activated user) raises a
     *                     \TypeError, so check for it before calling. A non-hash value such as ""
     *                     simply returns false.
     *
     * @throws \TypeError When $password or $hash is null. NOTE: \TypeError is an \Error, NOT an
     *                    \Exception — `catch (\Exception $e)` will NOT stop it.
     * @return bool True only if $password matches $hash. False for a wrong password AND for any
     *              malformed/empty $hash — a false is never proof the hash was valid.
     */
    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
}
