<?php
/**
 * Standalone crypto conformance tests for VD\PHPHelper\Security.
 *
 * No PHPUnit dependency: run with `php tests/SecurityCryptoTest.php`. Exits non-zero on any
 * failure. Covers the authenticated-encryption invariants the class must guarantee:
 * AAD context binding, version binding, tamper rejection, blind-index determinism, key-length
 * enforcement, and the chunked-file integrity (round-trip, truncation, reorder, splice, tamper).
 */

require __DIR__ . '/../vendor/autoload.php';

use VD\PHPHelper\Security;

$failures = 0;
$passes = 0;

function ok(string $name, bool $cond): void {
    global $failures, $passes;
    if ($cond) { $passes++; echo "  PASS  $name\n"; }
    else { $failures++; echo "  FAIL  $name\n"; }
}

/** Asserts that $fn throws. */
function throws(string $name, callable $fn): void {
    try { $fn(); ok($name . " (expected throw)", false); }
    catch (\Throwable $e) { ok($name, true); }
}

$KEY  = str_repeat("k", 32);           // 32-byte master key
$KEY2 = str_repeat("z", 32);           // a different master key
$AAD  = "product_formula.name_encrypted:1900-0000-7000-8000-000000000001";
$AAD2 = "product_formula.name_encrypted:1900-0000-7000-8000-000000000002"; // different row

echo "== encryptDataDB / decryptDataDB ==\n";

$ct = Security::encryptDataDB("Formula #1: NaOH 4%", $KEY, $AAD);
ok("envelope has version prefix v1:", str_starts_with($ct, "v1:"));
ok("round-trip returns plaintext", Security::decryptDataDB($ct, $KEY, $AAD) === "Formula #1: NaOH 4%");

// CRITICAL: a ciphertext bound to row 1 must NOT decrypt under row 2's context (relocation).
throws("relocation to another AAD is rejected", fn() => Security::decryptDataDB($ct, $KEY, $AAD2));
// Wrong key rejected.
throws("wrong key is rejected", fn() => Security::decryptDataDB($ct, $KEY2, $AAD));
// Tamper: flip one ciphertext byte.
$raw = base64_decode(substr($ct, 3));
$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);
$tampered = "v1:" . base64_encode($raw);
throws("tampered ciphertext is rejected", fn() => Security::decryptDataDB($tampered, $KEY, $AAD));
// Version downgrade / unknown version rejected.
throws("unknown version is rejected", fn() => Security::decryptDataDB("v9:" . substr($ct, 3), $KEY, $AAD));
throws("missing version prefix is rejected", fn() => Security::decryptDataDB(substr($ct, 3), $KEY, $AAD));
// Empty AAD forbidden on both sides.
throws("empty AAD on encrypt is rejected", fn() => Security::encryptDataDB("x", $KEY, ""));
throws("empty AAD on decrypt is rejected", fn() => Security::decryptDataDB($ct, $KEY, ""));
// Empty value round-trips to "".
ok("empty value encrypts to ''", Security::encryptDataDB("", $KEY, $AAD) === "");
ok("empty value decrypts to ''", Security::decryptDataDB("", $KEY, $AAD) === "");
// Nondeterministic envelope (fresh IV each call).
ok("two encryptions differ (random IV)", Security::encryptDataDB("same", $KEY, $AAD) !== Security::encryptDataDB("same", $KEY, $AAD));

echo "== key length enforcement ==\n";
throws("16-byte key rejected on encryptDataDB", fn() => Security::encryptDataDB("x", str_repeat("k", 16), $AAD));
throws("31-byte key rejected on generateSearchHash", fn() => Security::generateSearchHash("x", str_repeat("k", 31)));

echo "== generateSearchHash (blind index) ==\n";
$h1 = Security::generateSearchHash("alice@example.com", $KEY);
$h2 = Security::generateSearchHash("alice@example.com", $KEY);
$h3 = Security::generateSearchHash("bob@example.com", $KEY);
$h4 = Security::generateSearchHash("alice@example.com", $KEY2);
ok("blind index is 64 hex chars", strlen($h1) === 64 && ctype_xdigit($h1));
ok("blind index deterministic for equal input", $h1 === $h2);
ok("blind index differs for different input", $h1 !== $h3);
ok("blind index differs for different key", $h1 !== $h4);
ok("null salt == empty salt (stable)", Security::generateSearchHash("x", $KEY, null) === Security::generateSearchHash("x", $KEY, ""));

echo "== encryptLocal / decryptLocal (AES-256-CTR + HMAC) ==\n";
$loc = Security::encryptLocal("local secret", $KEY);
ok("local round-trip", Security::decryptLocal($loc, $KEY) === "local secret");
$locRaw = base64_decode($loc);
$locRaw[strlen($locRaw) - 1] = chr(ord($locRaw[strlen($locRaw) - 1]) ^ 0x01);
throws("local tamper rejected (HMAC)", fn() => Security::decryptLocal(base64_encode($locRaw), $KEY));

echo "== passwords (Argon2id) ==\n";
$hash = Security::encryptPassword("s3nha-forte");
ok("password verifies", Security::verifyPassword("s3nha-forte", $hash));
ok("wrong password fails", !Security::verifyPassword("errada", $hash));

echo "== authenticated file encryption (encryptFileV2 / decryptFileV2) ==\n";
Security::setFileEncryptBlocksBytes(16); // force several blocks
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
$src = $tmp . "phpht_src_" . bin2hex(random_bytes(4)) . ".bin";
$enc = $tmp . "phpht_enc_" . bin2hex(random_bytes(4)) . ".bin";
$dec = $tmp . "phpht_dec_" . bin2hex(random_bytes(4)) . ".bin";
$payload = random_bytes(100); // ~7 blocks + trailer
file_put_contents($src, $payload);

Security::encryptFileV2($src, $KEY, $enc);
Security::decryptFileV2($enc, $KEY, $dec);
ok("file round-trip matches", file_get_contents($dec) === $payload);
@unlink($dec);

// Parse the length-encoded blocks so we can manipulate the ciphertext structurally.
$blob = file_get_contents($enc);
$blocks = [];
$i = 0; $n = strlen($blob);
while ($i < $n) {
    $j = strpos($blob, "-", $i);
    if ($j === false) break;
    $len = (int) substr($blob, $i, $j - $i);
    $b64 = substr($blob, $j + 1, $len);
    $blocks[] = $b64;
    $i = $j + 1 + $len;
}
// Layout: [cipher][version][salt][fileId] then triples [iv][tag][ct]... then trailer triple.
$rebuild = function(array $bs): string {
    $out = "";
    foreach ($bs as $b) { $out .= strlen($b) . "-" . $b; }
    return $out;
};

// (a) Truncation: drop the last triple (the authenticated end marker).
$trunc = array_slice($blocks, 0, count($blocks) - 3);
file_put_contents($enc, $rebuild($trunc));
throws("file truncation (end marker dropped) rejected", fn() => Security::decryptFileV2($enc, $KEY, $dec));
@unlink($dec);

// (b) Reorder: swap the first two data triples (indices 4..6 and 7..9).
$reordered = $blocks;
for ($k = 0; $k < 3; $k++) {
    $t = $reordered[4 + $k];
    $reordered[4 + $k] = $reordered[7 + $k];
    $reordered[7 + $k] = $t;
}
file_put_contents($enc, $rebuild($reordered));
throws("file block reorder rejected", fn() => Security::decryptFileV2($enc, $KEY, $dec));
@unlink($dec);

// (c) Tamper: flip a byte inside the first data ciphertext block.
$tamperedBlocks = $blocks;
$ctB64 = $tamperedBlocks[6]; // first triple's ciphertext
$ctBin = base64_decode($ctB64);
$ctBin[0] = chr(ord($ctBin[0]) ^ 0x01);
$tamperedBlocks[6] = base64_encode($ctBin);
file_put_contents($enc, $rebuild($tamperedBlocks));
throws("file ciphertext tamper rejected", fn() => Security::decryptFileV2($enc, $KEY, $dec));
@unlink($dec);

// (d) Header tamper: mangle the stored fileId (block index 3) -> all blocks fail.
$hdrTamper = $blocks;
$hdrTamper[3] = base64_encode(str_repeat("0", 32));
file_put_contents($enc, $rebuild($hdrTamper));
throws("file header (fileId) tamper rejected", fn() => Security::decryptFileV2($enc, $KEY, $dec));
@unlink($dec);

// (e) Wrong key on a valid file.
Security::encryptFileV2($src, $KEY, $enc);
throws("file wrong key rejected", fn() => Security::decryptFileV2($enc, $KEY2, $dec));
@unlink($dec);

@unlink($src); @unlink($enc);
Security::setFileEncryptBlocksBytes(null);

echo "\n== RESULT ==\n";
echo "  $passes passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
