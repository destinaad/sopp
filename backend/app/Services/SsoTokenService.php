<?php

namespace App\Services;

use App\Exceptions\SsoTokenException;
use Illuminate\Support\Facades\Cache;

/**
 * Membongkar dan memverifikasi token SSO yang dikirim dari
 * sopp_rincian_setoran.php di server lama.
 *
 * Urutan verifikasi SENGAJA seperti ini (jangan diacak urutannya):
 *   1. Decrypt AES        -- kalau ini gagal, sisanya tidak ada gunanya
 *   2. Verifikasi signature JWT -- pastikan payload tidak dipalsukan
 *   3. Cek expiry (exp)   -- baru cek ini SETELAH signature valid,
 *      supaya token yang di-tempering tidak bisa dipakai buat
 *      "menebak-nebak" exp yang valid
 *   4. Cek + tandai replay -- PALING TERAKHIR, karena ini side-effect
 *      (menulis ke cache). Token yang gagal di langkah 1-3 tidak
 *      boleh ikut menulis apapun ke cache.
 *
 * Kunci yang dipakai (SOPP_SSO_KEY, SOPP_AES_KEY) HARUS identik
 * persis dengan $secret_key_sso dan $secret_key_aes di
 * sopp_rincian_setoran.php -- kalau beda walau satu karakter,
 * SEMUA token akan selalu gagal verifikasi.
 */
class SsoTokenService
{
    public function __construct(
        private readonly string $ssoKey,
        private readonly string $aesKey,
    ) {
    }

    /**
     * @return array{user_id:string, loket_id:int, nama:string, tanggal:string, iat:int, exp:int}
     * @throws SsoTokenException
     */
    public function verifyAndDecode(string $tokenRaw): array
    {
        $jwt = $this->decryptToJwt($tokenRaw);

        [$header, $payloadEncoded, $signature] = $this->splitJwt($jwt);

        $this->verifySignature($header, $payloadEncoded, $signature);

        $payload = $this->decodePayload($payloadEncoded);

        $this->assertNotExpired($payload);

        // Replay check PALING TERAKHIR -- lihat catatan urutan di
        // atas. $signature dipakai sebagai identitas unik token
        // (setiap token generate signature berbeda karena payload
        // dan IV-nya beda), jadi aman dipakai sebagai cache key.
        $this->assertNotYetUsed($signature, $payload['exp']);

        return $payload;
    }

    /**
     * Base64url-decode token, pisahkan 16 byte pertama sebagai IV,
     * sisanya ciphertext, lalu AES-256-CBC decrypt.
     *
     * CATATAN INTEROPERABILITAS: server lama pakai
     * mcrypt_encrypt(MCRYPT_RIJNDAEL_128, ..., MCRYPT_MODE_CBC).
     * "RIJNDAEL_128" di sini merujuk ke BLOCK SIZE 128-bit (16 byte)
     * -- itu adalah AES standar (bukan varian Rijndael non-standar
     * yang block size-nya 192/256-bit). Key-nya 32 byte (256-bit,
     * diturunkan dari secret lewat SHA-256). Kombinasi ini PERSIS
     * setara dengan cipher "aes-256-cbc" di openssl_decrypt() --
     * itu sebabnya cipher string di bawah ini harus tetap
     * "aes-256-cbc", bukan yang lain.
     *
     * openssl_decrypt() TANPA flag OPENSSL_ZERO_PADDING otomatis
     * melepas PKCS7 padding sendiri -- server lama melakukan
     * pkcs7_pad() manual sebelum enkripsi, jadi ini sudah cocok,
     * tidak perlu unpad manual di sisi Laravel.
     */
    private function decryptToJwt(string $tokenRaw): string
    {
        $decoded = $this->base64UrlDecode($tokenRaw);

        $ivSize = openssl_cipher_iv_length('aes-256-cbc'); // 16 byte
        if ($decoded === false || strlen($decoded) <= $ivSize) {
            throw new SsoTokenException('Token tidak valid.');
        }

        $iv         = substr($decoded, 0, $ivSize);
        $ciphertext = substr($decoded, $ivSize);

        // Turunan key HARUS identik dengan sisi server lama:
        // substr(hash('sha256', $secret_key_aes, true), 0, 32)
        $aesKeyDerived = substr(hash('sha256', $this->aesKey, true), 0, 32);

        $jwt = openssl_decrypt(
            $ciphertext,
            'aes-256-cbc',
            $aesKeyDerived,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($jwt === false) {
            throw new SsoTokenException('Token tidak valid.');
        }

        return $jwt;
    }

    /**
     * @return array{0:string,1:string,2:string} [header, payload, signature] -- masih dalam bentuk base64url, belum di-decode
     */
    private function splitJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new SsoTokenException('Token tidak valid.');
        }

        return $parts;
    }

    /**
     * Hitung ulang HMAC-SHA256 dari "header.payload" pakai SOPP_SSO_KEY,
     * bandingkan ke signature yang dikirim pakai hash_equals()
     * (BUKAN ==) supaya tidak rentan timing attack.
     */
    private function verifySignature(string $header, string $payloadEncoded, string $signatureDiterima): void
    {
        $signatureHitung = $this->base64UrlEncode(
            hash_hmac('sha256', $header . '.' . $payloadEncoded, $this->ssoKey, true)
        );

        if (! hash_equals($signatureHitung, $signatureDiterima)) {
            throw new SsoTokenException('Token tidak valid.');
        }
    }

    /**
     * @return array{user_id:string, loket_id:int, nama:string, tanggal:string, iat:int, exp:int}
     */
    private function decodePayload(string $payloadEncoded): array
    {
        $json = $this->base64UrlDecode($payloadEncoded);
        if ($json === false) {
            throw new SsoTokenException('Token tidak valid.');
        }

        $payload = json_decode($json, true);
        if (! is_array($payload)) {
            throw new SsoTokenException('Token tidak valid.');
        }

        foreach (['user_id', 'loket_id', 'nama', 'tanggal', 'iat', 'exp'] as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new SsoTokenException('Token tidak valid.');
            }
        }

        $payload['loket_id'] = (int) $payload['loket_id'];
        $payload['iat']      = (int) $payload['iat'];
        $payload['exp']      = (int) $payload['exp'];

        return $payload;
    }

    private function assertNotExpired(array $payload): void
    {
        if (time() > $payload['exp']) {
            throw new SsoTokenException('Token sudah kadaluarsa, silakan ulangi dari SOPP.');
        }
    }

    /**
     * Cache::add() dipakai (BUKAN has() lalu put() terpisah) karena
     * add() itu atomik -- "set kalau belum ada key ini, dan kasih
     * tau berhasil atau tidak" dalam SATU operasi. Kalau dipisah
     * jadi has() dulu baru put(), ada celah waktu di antara dua
     * pemanggilan itu yang secara teori bisa kena race condition
     * kalau ada 2 request dengan token sama persis datang nyaris
     * bersamaan. add() menutup celah itu sepenuhnya.
     *
     * TTL dihitung dari SISA waktu sampai exp token (bukan angka
     * tetap) -- begitu token itu sendiri sudah pasti expired
     * (dicegah oleh assertNotExpired), tidak ada gunanya lagi
     * menyimpan catatan "sudah dipakai" untuknya.
     */
    private function assertNotYetUsed(string $signature, int $exp): void
    {
        $cacheKey = 'sso_token_used:' . hash('sha256', $signature);
        $ttlDetik = max(1, $exp - time());

        $berhasilDitandai = Cache::add($cacheKey, true, $ttlDetik);

        if (! $berhasilDitandai) {
            throw new SsoTokenException('Token sudah pernah digunakan.');
        }
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string|false
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=', STR_PAD_RIGHT);

        return base64_decode($padded, true);
    }
}