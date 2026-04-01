<?php

namespace VD\PHPHelper;

class Security {
    /**
     * Number of encryption blocks to read per iteration.
     *
     * For the 'AES-128-CBC' cipher, each block consists of 16 bytes.
     * Therefore:
     * - 10,000 blocks = 160 KB
     * - 37,500 blocks = 600 KB
     *
     * This value can be adjusted based on the file size to optimize memory usage.
     *
     * @var int
     */
    private static int $fileEncryptionBlocks = 0;

    /**
     * Derives a key of the specified length from the given key & salt using HKDF with SHA-256.
     * This function is used to generate encryption keys of the required length from a base key.
     * 
     * @param string $key Base key to derive from
     * @param int $length Desired length of the derived key in bytes
     * @param string|null $salt Optional salt value to add randomness to the derived key
     * 
     * @return string
     */
    private static function deriveKey(string $key, int $length, ?string $salt = ""): string {
        return hash_hkdf(
            'sha256',
            $key,
            $length,
            'derived-key',
            ($salt ?? "")
        );
    }

    /**
     * Returns the number of encryption blocks to read per iteration.
     * If no value is set, the default (37,500) will be returned and set.
     *
     * @return int
     */
    public static function getFileEncryptionBlocks(): int
    {
        if (empty(self::$fileEncryptionBlocks)) {
            self::setFileEncryptionBlocks(37500);
        }

        return self::$fileEncryptionBlocks;
    }

    /**
     * Sets the number of encryption blocks to be used during file processing.
     * Accepts null, in which case the default will be used on next get.
     *
     * @param int|null $fileEncryptionBlocks
     * @return void
     */
    public static function setFileEncryptionBlocks(?int $fileEncryptionBlocks): void
    {
        if (empty($fileEncryptionBlocks) || $fileEncryptionBlocks < 0) {
            return;
        }

        self::$fileEncryptionBlocks = $fileEncryptionBlocks;
    }

    /**
     * Generates a search hash for a given string using HMAC with a derived key.
     * This function is used to create a consistent hash for search purposes, allowing for secure comparisons without exposing the original data.
     * 
     * @param mixed $str The input string to generate the search hash for
     * @param string $key Base key to derive the search hash key from
     * @param string $salt Salt value to add randomness to the derived search hash key
     * 
     * @throws \Exception
     * @return string
     */
    public static function generateSearchHash(mixed $str, string $key, string $salt): string {
        if ($str === null || $str === "") {
            return "";
        }

        if(is_bool($str)) {
            $str = (int) $str;
        }
        $str = (string) $str;

        if (empty($key) || mb_strlen($key, '8bit') !== 32) {
            throw new \Exception("Invalid encryption key. The key must be 32 bytes long.");
        }
        $keySearch = hash_hkdf(
            'sha256',
            $key,
            32,
            'search-hash',
            $salt
        );

        // hex: 64 caracteres
        return hash_hmac('sha256', $str, $keySearch);
    }

    /**
     * Encrypts the given file and saves the result to a new destination file.
     *
     * @param string $source Path to the file to be encrypted (use tmp_name if from $_FILES)
     * @param string $key Encryption key to be used
     * @param string|null $salt Optional salt for key derivation
     * @param string $destination Path where the encrypted file should be saved
     * @param string|null $permissionMode Optional file permission mode to apply to the destination file
     *
     * @return string Returns the path to the encrypted file
     * @throws \Exception If encryption fails or file handling encounters an error
     *
     * @ref https://riptutorial.com/php/example/25499/symmetric-encryption-and-decryption-of-large-files-with-openssl
     */
    public static function encryptFile(string $source, string $key, ?string $salt, string $destination, ?string $permissionMode = null): string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        // Sets the default permission mode if it's empty
        $mode = File::getPermissionMode($permissionMode);

        // Sets the IV (Initialization Vector) length using the 'AES-128-CBC' algorithm
        $iv_length = openssl_cipher_iv_length("AES-128-CBC");

        // Checks if the key is empty or not exactly 16 bytes long
        if (empty($key) || mb_strlen($key, '8bit') !== $iv_length) {
            throw new \Exception("Invalid encryption key. The key must be {$iv_length} bytes long.");
        }
        $key = self::deriveKey($key, 16, $salt);

        // Attempts to get the absolute path of the source file
        $sourceReal = realpath($source);
        if(!empty($sourceReal)) {
            $source = $sourceReal;
        } elseif (!is_uploaded_file($source)) {
            $source = false;
        }

        // Clears the sourceReal variable
        $sourceReal = null;

        // Sets the destination file path
        $destination = realpath($destination);

        // Checks the validity of the source and destination files
        if (empty($source)) {
            throw new \Exception("File not found to be encrypted.");
        }
        if (empty($destination)) {
            throw new \Exception("Invalid file destination to generate the encrypted file.");
        }

        // Attempts to generate a secure random initialization vector
        try {
            $iv = random_bytes($iv_length);
        } catch (\Exception $e) {
            $iv = openssl_random_pseudo_bytes($iv_length);
        }


        // Sets the permission of the destination file
        $oldMask = umask(0);
        @chmod($destination, $mode);
        umask($oldMask);

        // Attempts to open the destination file for writing
        if ($fpOut = fopen($destination, 'w')) {
            // Initializes the error flag
            $error = false;

            // Writes the initialization vector to the destination file
            if(fwrite($fpOut, (mb_strlen(base64_encode($iv), '8bit') . "-" . base64_encode($iv))) === false) {
                $error = true;
            }

            // Attempts to open the source file for reading
            if ($fpIn = fopen($source, 'rb')) {
                // Reads blocks of text, encrypts them, and writes to the destination file
                while (!feof($fpIn)) {
                    // Reads a block from the source file
                    $plaintext = fread($fpIn, $iv_length * self::getFileEncryptionBlocks());
                    if($plaintext === false) {
                        $error = true;
                    }
                    // Encrypts the plaintext block
                    $ciphertext = openssl_encrypt(
                        $plaintext,
                        "AES-128-CBC",
                        $key,
                        OPENSSL_RAW_DATA,
                        $iv
                    );
                    if($ciphertext === false) {
                        $error = true;
                    }
                    // Uses the last encrypted block as the next initialization vector
                    $iv = mb_substr($ciphertext, 0, $iv_length, '8bit');
                    // Encodes the encrypted block in base64
                    $ciphertext = base64_encode($ciphertext);
                    // Writes the base64 string length + "-" + encrypted text to the destination file
                    if(fwrite($fpOut, (mb_strlen($ciphertext, '8bit') . "-" . $ciphertext)) === false) {
                        $error = true;
                    }
                }

                // Closes the source file
                fclose($fpIn);
            } else {
                // Sets the error flag if the source file could not be opened
                $error = true;
            }

            // Closes the destination file
            fclose($fpOut);

            // Checks if any error occurred during encryption
            if ($error) {
                // Deletes the destination file if an error occurred
                if(is_file($destination)) {
                    @unlink($destination);
                }
                throw new \Exception("Error while reading the main file.");
            }
        } else {
            throw new \Exception("Error while writing to the destination file.");
        }

        // Returns the path of the encrypted file
        return $destination;
    }

    /**
     * Decrypts the given file and saves the result to a new destination file.
     *
     * @param string $source Path to the file to be decrypted (use tmp_name when from $_FILES)
     * @param string $key Key used for decryption
     * @param string|null $salt Optional salt for key derivation
     * @param string $destination Path where the decrypted file should be saved
     * @param string|null $permissionMode Optional file permission mode to apply to the destination file
     * @param string $outReadMode Defines how the destination file will be opened ('w' or 'a')
     *
     * @return string|false Returns the path of the decrypted file or FALSE in case of error
     * @throws \Exception
     */
    public static function decryptFile(string $source, string $key, ?string $salt, string $destination, ?string $permissionMode, string $outReadMode = "w"): string|false {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        // Sets the default permission mode if it's empty
        $mode = File::getPermissionMode($permissionMode);

        // Sets the IV (Initialization Vector) length using the 'AES-128-CBC' algorithm
        $iv = null;
        $iv_length = openssl_cipher_iv_length("AES-128-CBC");

        // Checks if the key is empty or not exactly 16 bytes long
        if (empty($key) || mb_strlen($key, '8bit') !== $iv_length) {
            throw new \Exception("Invalid encryption key. The key must be {$iv_length} bytes long.");
        }
        $key = self::deriveKey($key, 16, $salt);

        // Defines defaults for $outReadMode options
        if (
            empty($outReadMode) ||
            !in_array($outReadMode, array('w', 'a'))
        ) {
            $outReadMode = "w";
        }

        // Attempts to get the absolute path of the source file
        $sourceReal = realpath($source);
        if (!empty($sourceReal)) {
            $source = $sourceReal;
        } elseif (!is_uploaded_file($source)) {
            $source = false;
        }

        // Clears the sourceReal variable
        $sourceReal = null;

        // Sets the destination file path
        $destination = realpath($destination);

        // Checks the validity of the source and destination files
        if (empty($source)) {
            throw new \Exception("File not found to be decrypted.");
        }
        if (empty($destination)) {
            throw new \Exception("Invalid destination path to generate the decrypted file.");
        }

        // Sets the permission of the destination file
        $oldMask = umask(0);
        @chmod($destination, $mode);
        umask($oldMask);

        // Attempts to open the destination file for writing
        if ($fpOut = fopen($destination, $outReadMode)) {
            // Initializes the error flag
            $error = false;

            // Attempts to open the source file for reading
            if ($fpIn = fopen($source, 'rb')) {
                // Reads encrypted text blocks, decrypts them, and writes to the destination file
                while (!feof($fpIn)) {
                    // Reads character by character until the next "-", to get the length of the next base64 string
                    $lenB64 = "";
                    while (!feof($fpIn)) {
                        $char = fgetc($fpIn);
                        if ($char === "-" || $char === false) {
                            break;
                        }

                        $lenB64 .= $char;
                    }

                    $lenB64 = Str::onlyNumbers($lenB64);
                    if (empty($lenB64)) {
                        break;
                    }
                    $lenB64 *= 1;

                    // Reads a block from the source file
                    $ciphertext = fread($fpIn, $lenB64);
                    if ($ciphertext === false) {
                        $error = true;
                    }

                    // Decrypts the base64 block
                    $ciphertext = base64_decode($ciphertext, true);

                    if (empty($iv)) {
                        // Reads the initialization vector from the source file
                        $iv = $ciphertext;
                    } else {
                        // Decrypts the encrypted text block
                        $plaintext = openssl_decrypt(
                            $ciphertext,
                            "AES-128-CBC",
                            $key,
                            OPENSSL_RAW_DATA,
                            $iv
                        );
                        if ($plaintext === false) {
                            $error = true;
                        }

                        // Uses the last encrypted block as the next initialization vector
                        $iv = mb_substr($ciphertext, 0, $iv_length, '8bit');

                        // Writes the decrypted text to the destination file
                        if (fwrite($fpOut, $plaintext) === false) {
                            $error = true;
                        }
                    }
                }
                // Closes the source file
                fclose($fpIn);
            } else {
                // Sets the error flag if the source file could not be opened
                $error = true;
            }

            // Closes the destination file
            fclose($fpOut);

            // Checks if any error occurred during decryption
            if ($error) {
                // Deletes the destination file if an error occurred
                if (is_file($destination)) {
                    @unlink($destination);
                }
                throw new \Exception("Error while reading the main file.");
            }
        } else {
            throw new \Exception("Error while writing to the destination file.");
        }

        // Returns the path of the decrypted file
        return $destination;
    }

    /**
     * Encrypts a string using a key with the "aes-256-gcm" algorithm.
     *
     * @param mixed $str The string to encrypt
     * @param string $key The encryption key
     * @param string|null $salt Optional salt for key derivation
     *
     * @return string
     * @throws \Exception
     */
    public static function encryptDataDB(mixed $str, string $key, ?string $salt = ""): string {
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

        if (empty($key) || mb_strlen($key, '8bit') !== 32) {
            throw new \Exception("Invalid encryption key. The key must be 32 bytes long.");
        }
        $key = self::deriveKey($key, 32, $salt);

        // Defines the IV (Initialization Vector) length using the 'aes-256-gcm' algorithm
        $cipher = "aes-256-gcm";
        $ivLength = openssl_cipher_iv_length($cipher);
        $tagLength = 16;

        // Generates a random IV. The IV size depends on the algorithm used.
        try {
            $iv = random_bytes($ivLength);
        } catch (\Exception $e) {
            $iv = openssl_random_pseudo_bytes($ivLength);
        }

        // Encrypts the text using "aes-256-gcm", the provided key, and the generated IV.
        // The $tag value is generated after encryption and is used to verify data integrity.
        $ciphertext = openssl_encrypt(
            $str,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        // Returns the encrypted text in a base64-encoded string containing the IV,
        // the ciphertext, and the tag.
        if ($ciphertext === false) {
            throw new \Exception("Encryption failed.");
        }

        if (mb_strlen($tag, '8bit') !== $tagLength) {
            throw new \Exception("Invalid authentication tag length.");
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts a message after verifying its integrity using "aes-256-gcm".
     *
     * @param string|null $str Encrypted message
     * @param string $key Encryption key
     * @param string|null $salt Optional salt for key derivation
     *
     * @return string|false Decrypted text or FALSE if an error occurs
     * @throws \Exception
     */
    public static function decryptDataDB(?string $str, string $key, ?string $salt = ""): string|false {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        if ($str === null || $str === "") {
            return "";
        }

        if (empty($key) || mb_strlen($key, '8bit') !== 32) {
            throw new \Exception("Invalid decryption key. The key must be 32 bytes long.");
        }
        $key = self::deriveKey($key, 32, $salt);

        // Decodes the base64-encoded encrypted text into binary
        $decoded = Parser::base64Decode($str);
        if ($decoded === false) {
            throw new \Exception("Failed to decode the secret message. Invalid base64.");
        }

        // Gets the IV length based on the encryption algorithm.
        // We use AES-256-GCM for performance and security.
        $cipher = "aes-256-gcm";
        $ivLength = openssl_cipher_iv_length($cipher);
        $tagLength = 16;

        if (mb_strlen($decoded, '8bit') < ($ivLength + $tagLength + 1)) {
            throw new \Exception("Encrypted payload is too short.");
        }

        $iv = mb_substr($decoded, 0, $ivLength, '8bit');
        $tag = mb_substr($decoded, $ivLength, $tagLength, '8bit');
        $ciphertext = mb_substr($decoded, $ivLength + $tagLength, null, '8bit');

        // Decrypts the text using "aes-256-gcm", the provided key, IV, and tag.
        // Extracts the IV, tag, and ciphertext from the decoded string.
        return openssl_decrypt(
            $ciphertext,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
    }

    /**
     * Encrypts a string using AES-256-CTR with authentication (MAC).
     *
     * It derives two keys from the provided 32-byte key:
     * - One for encryption (encKey)
     * - One for message authentication (authKey)
     *
     * The result is the MAC concatenated with the IV and ciphertext, encoded in Base64.
     *
     * @param mixed $str The plaintext to encrypt
     * @param string $key A 32-byte encryption key
     * @param string|null $salt Optional salt for key derivation
     *
     * @return string Encrypted string, Base64 encoded
     * @throws \Exception If the key is invalid or secure random bytes can't be generated
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

        if (empty($key) || mb_strlen($key, '8bit') !== 32) {
            throw new \Exception("Invalid encryption key. The key must be exactly 32 bytes long.");
        }
        $key = self::deriveKey($key, 32, $salt);

        // Derive encryption and authentication keys using HKDF
        [$encKey, $authKey] = [
            hash_hkdf("sha256", $key, 32, "LOCAL-ENC"),
            hash_hkdf("sha256", $key, 32, "LOCAL-AUTH"),
        ];
        
        $cipher = "aes-256-ctr";
        $ivLength = openssl_cipher_iv_length($cipher);

        // Generate a secure random IV (nonce)
        try {
            $nonce = random_bytes($ivLength);
        } catch (\Exception $e) {
            $nonce = openssl_random_pseudo_bytes($ivLength);
        }

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
     * @param string|null $str The encrypted string, Base64 encoded
     * @param string $key A 32-byte encryption key
     * @param string|null $salt Optional salt for key derivation
     *
     * @return string|false Decrypted string or false if decryption fails
     * @throws \Exception If the key is invalid, Base64 is malformed, or MAC verification fails
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

        if (empty($key) || mb_strlen($key, '8bit') !== 32) {
            throw new \Exception("Invalid encryption key. The key must be exactly 32 bytes long.");
        }
        $key = self::deriveKey($key, 32, $salt);

        // Derive encryption and authentication keys using HKDF
        [$encKey, $authKey] = [
            hash_hkdf("sha256", $key, 32, "LOCAL-ENC"),
            hash_hkdf("sha256", $key, 32, "LOCAL-AUTH"),
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
     * @param string $key Encryption key 
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
        if (empty($key) || mb_strlen($key, '8bit') !== 32) {
            throw new \Exception("Invalid encryption key. The key must be 32 bytes long.");
        }
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
     * @param string $key Decryption key
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
        if (empty($key) || mb_strlen($key, '8bit') !== 32) {
            throw new \Exception("Invalid encryption key. The key must be 32 bytes long.");
        }
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
     * Apply personalized encrypt/decrypt function in array elements
     *
     * @param mixed $item Item to be applied
     * @param string $key Decryption key
     * @param string|null $salt Optional salt for key derivation
     * @param string $fnName Name of function
     *
     * @return mixed
     * @throws \Exception
     */
    public static function applySecurityFunctionArray(mixed $item, string $key, ?string $salt, string $fnName): mixed {
        if(empty($fnName)) {
            return $item;
        }

        if(Str::containsString($fnName, "self::", true)) {
            $fnName = Str::replaceString("self::", "class::", $fnName, true);
        }
        if(Str::containsString($fnName, "Security::", true)) {
            $fnName = Str::replaceString("Security::", "class::", $fnName, true);
        }
        if(!Str::containsString($fnName, "class::", true)) {
            $fnName = "class::" . $fnName;
        }
        $fnName = Str::replaceString("class::", "Security::", $fnName, true);

        if (is_object($item)) {
            $item = (array) $item;
        }
        if (!is_array($item)) {
            return call_user_func($fnName, $item, $key, $salt);
        }

        $key = null;
        $value = null;
        foreach ($item as $key => $value) {
            if (!is_array($value) && !is_object($value)) {
                $item[$key] = call_user_func($fnName, $value, $key, $salt);
            } else {
                $item[$key] = self::applySecurityFunctionArray($value, $key, $salt, $fnName);
            }
        }
        $key = null;
        $value = null;

        return $item;
    }

    /**
     * Recursively sanitizes a variable or array to prevent XSS attacks.
     *
     * @param mixed $input The variable to sanitize
     * @return mixed The sanitized result
     */
    public static function xssCleanRecursive(mixed $input): mixed {
        if ($input === null || $input === "" || is_bool($input) || filter_var($input, FILTER_VALIDATE_INT) || filter_var($input, FILTER_VALIDATE_FLOAT)) {
            return $input;
        }

        /**
         * Internal XSS cleaning function for strings.
         *
         * @param string $data The string to clean
         * @return string
         *
         * @ref https://stackoverflow.com/questions/1336776/xss-filtering-function-in-php
         */
        $xssClean = function (string $data): string {
            // Fix &entity\n;
            $data = str_replace(['&amp;', '&lt;', '&gt;'], ['&amp;amp;', '&amp;lt;', '&amp;gt;'], $data);
            $data = preg_replace('/(&#*\w+)[\x00-\x20]+;/u', '$1;', $data);
            $data = preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $data);
            $data = html_entity_decode($data, ENT_COMPAT, 'UTF-8');

            // Remove attributes starting with "on" or xmlns
            $data = preg_replace('#(<[^>]+?[\x00-\x20"\'])(?:on|xmlns)[^>]*+>#iu', '$1>', $data);

            // Remove javascript: and vbscript: protocols
            $data = preg_replace('#([a-z]*)[\x00-\x20]*=[\x00-\x20]*([`\'"]*)[\x00-\x20]*j[\x00-\x20]*a[\x00-\x20]*v[\x00-\x20]*a[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2nojavascript...', $data);
            $data = preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*v[\x00-\x20]*b[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2novbscript...', $data);
            $data = preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*-moz-binding[\x00-\x20]*:#u', '$1=$2nomozbinding...', $data);

            // Remove style expressions (IE-specific XSS vectors)
            $data = preg_replace('#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?expression[\x00-\x20]*\([^>]*+>#i', '$1>', $data);
            $data = preg_replace('#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?behaviour[\x00-\x20]*\([^>]*+>#i', '$1>', $data);
            $data = preg_replace('#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:*[^>]*+>#iu', '$1>', $data);

            // Remove namespaced elements
            $data = preg_replace('#<\/*\w+:\w[^>]*+>#i', '', $data);

            // Remove undesirable tags
            do {
                $oldData = $data;
                $data = preg_replace('#<\/*(?:applet|b(?:ase|gsound|link)|embed|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i', '[REMOVED]', $data);
            } while ($oldData !== $data);

            return $data;
        };

        if (is_array($input)) {
            foreach ($input as $key => $value) {
                $input[$key] = self::xssCleanRecursive($value);
            }
        } elseif (is_object($input)) {
            foreach ($input as $key => $value) {
                $input->$key = self::xssCleanRecursive($value);
            }
        }

        return $xssClean($input);
    }

    /**
     * Retrieves a value from an array or variable, applying individual sanitization options.
     *
     * @param mixed $source The input array or variable
     * @param string|null $key If set, extracts a value from an array by key
     * @param mixed $ifNull Default value to return if the key is missing or null
     * @param bool $decodeStr Whether to decode the string using a custom decoder
     * @param bool $xssClean Whether to apply XSS sanitization
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
            foreach ($value as $k => $v) {
                $value[$k] = self::filterValue(
                    $v, null, $ifNull,
                    $decodeStr, $xssClean, $stripTags, $htmlEntities, $addSlashes, $escapeDB, $trim,
                    $formatDecimal, $asInteger, $asBoolean,
                    $base64Encode, $base64Decode, $base64UrlEncode, $base64UrlDecode,
                    $urlEncode, $urlDecode,
                    $jsonEncode, $jsonDecode
                );
            }
        } else {
            $value = $applyFilters($value);
        }

        return $value;
    }

    /**
     * Encrypts a password using the Whirlpool hash algorithm.
     *
     * @param string|null $password Password to be encrypted
     *
     * @return string Encrypted password
     */
    public static function encryptPassword(?string $password): string {
        if (empty($password)) {
            return '';
        }

        return strtoupper(hash('whirlpool', $password));
    }
}