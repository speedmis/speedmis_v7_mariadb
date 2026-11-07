<?php
/**
 * WebAuthn (FIDO2) 서버 구현 — 외부 라이브러리 없이 순수 PHP + openssl.
 *
 * 지원 알고리즘: ES256 (-7), RS256 (-257)
 * 지원 attestation: none (자체 검증만, 제조사 인증서 체인 미검증)
 */

namespace App;

class WebAuthn
{
    private string $rpId;
    private string $rpName;
    private string $origin;

    public function __construct(string $rpId, string $rpName, string $origin)
    {
        $this->rpId   = $rpId;
        $this->rpName = $rpName;
        $this->origin = $origin;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 등록 (Registration)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 등록 옵션 생성 (navigator.credentials.create 에 전달)
     */
    public function getRegisterOptions(string $userId, string $userName, string $displayName, array $excludeCredentialIds = []): array
    {
        $challenge = random_bytes(32);

        $options = [
            'challenge' => self::base64url($challenge),
            'rp' => [
                'name' => $this->rpName,
                'id'   => $this->rpId,
            ],
            'user' => [
                'id'          => self::base64url(hash('sha256', $userId, true)),
                'name'        => $userName,
                'displayName' => $displayName,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],   // ES256
                ['type' => 'public-key', 'alg' => -257], // RS256
            ],
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'userVerification'        => 'required',
            ],
            'timeout'     => 60000,
            'attestation' => 'none',
        ];

        if ($excludeCredentialIds) {
            $options['excludeCredentials'] = array_map(fn($id) => [
                'type' => 'public-key',
                'id'   => $id,
            ], $excludeCredentialIds);
        }

        return ['options' => $options, 'challenge' => self::base64url($challenge)];
    }

    /**
     * 등록 응답 검증
     * @return array{credential_id: string, public_key: string, sign_count: int, transports: string}
     */
    public function verifyRegister(array $credential, string $expectedChallenge): array
    {
        // 1. clientDataJSON 파싱
        $clientDataJSON = self::base64urlDecode($credential['response']['clientDataJSON'] ?? '');
        $clientData     = json_decode($clientDataJSON, true);

        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            throw new \Exception('clientData type 불일치');
        }
        if (($clientData['challenge'] ?? '') !== $expectedChallenge) {
            throw new \Exception('challenge 불일치');
        }
        if (!$this->verifyOrigin($clientData['origin'] ?? '')) {
            throw new \Exception('origin 불일치: ' . ($clientData['origin'] ?? ''));
        }

        // 2. attestationObject 파싱 (CBOR)
        $attestationObject = self::base64urlDecode($credential['response']['attestationObject'] ?? '');
        $attestation       = self::cborDecode($attestationObject);

        $authData = $attestation['authData'] ?? '';
        if (strlen($authData) < 37) {
            throw new \Exception('authData 길이 부족');
        }

        // 3. rpIdHash 검증 (punycode / unicode 양쪽 허용)
        $rpIdHash = substr($authData, 0, 32);
        $validRpIds = [$this->rpId];
        // punycode → unicode 변환 시도
        if (function_exists('idn_to_utf8')) {
            $unicode = idn_to_utf8($this->rpId);
            if ($unicode && $unicode !== $this->rpId) $validRpIds[] = $unicode;
        }
        // unicode → punycode 변환 시도
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($this->rpId);
            if ($ascii && $ascii !== $this->rpId) $validRpIds[] = $ascii;
        }
        $rpIdValid = false;
        foreach ($validRpIds as $rid) {
            if ($rpIdHash === hash('sha256', $rid, true)) { $rpIdValid = true; break; }
        }
        if (!$rpIdValid) {
            throw new \Exception('rpIdHash 불일치');
        }

        // 4. flags 검증 (UP=0x01, AT=0x40 필수)
        $flags = ord($authData[32]);
        if (!($flags & 0x01)) throw new \Exception('User Present 플래그 없음');
        if (!($flags & 0x40)) throw new \Exception('Attested Credential Data 없음');

        // 5. signCount
        $signCount = unpack('N', substr($authData, 33, 4))[1];

        // 6. Attested Credential Data 파싱
        $offset = 37;
        // aaguid (16 bytes)
        $offset += 16;
        // credentialIdLength (2 bytes, big-endian)
        $credIdLen = unpack('n', substr($authData, $offset, 2))[1];
        $offset += 2;
        // credentialId
        $credentialId = substr($authData, $offset, $credIdLen);
        $offset += $credIdLen;
        // credentialPublicKey (CBOR)
        $pubKeyCbor = substr($authData, $offset);
        $coseKey    = self::cborDecode($pubKeyCbor);

        // 7. COSE key → PEM
        $publicKeyPem = self::coseKeyToPem($coseKey);

        $transports = implode(',', $credential['response']['transports'] ?? $credential['transports'] ?? []);

        return [
            'credential_id' => self::base64url($credentialId),
            'public_key'    => $publicKeyPem,
            'sign_count'    => $signCount,
            'transports'    => $transports,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // 인증 (Authentication)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 인증 옵션 생성 (navigator.credentials.get 에 전달)
     */
    /**
     * @param array $allowCredentials [{credential_id, transports}, ...]
     */
    public function getLoginOptions(array $allowCredentials = []): array
    {
        $challenge = random_bytes(32);

        $options = [
            'challenge'        => self::base64url($challenge),
            'rpId'             => $this->rpId,
            'userVerification' => 'preferred',
            'timeout'          => 60000,
        ];

        if ($allowCredentials) {
            $options['allowCredentials'] = array_map(function($c) {
                $entry = ['type' => 'public-key', 'id' => $c['credential_id']];
                $tr = array_filter(explode(',', $c['transports'] ?? ''));
                if ($tr) $entry['transports'] = array_values($tr);
                else $entry['transports'] = ['internal']; // 기본: 플랫폼 인증기
                return $entry;
            }, $allowCredentials);
        }

        return ['options' => $options, 'challenge' => self::base64url($challenge)];
    }

    /**
     * 인증 응답 검증
     * @return array{credential_id: string, sign_count: int, user_handle: string}
     */
    public function verifyLogin(array $credential, string $expectedChallenge, string $publicKeyPem, int $storedSignCount): array
    {
        // 1. clientDataJSON 검증
        $clientDataJSON = self::base64urlDecode($credential['response']['clientDataJSON'] ?? '');
        $clientData     = json_decode($clientDataJSON, true);

        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            throw new \Exception('clientData type 불일치');
        }
        if (($clientData['challenge'] ?? '') !== $expectedChallenge) {
            throw new \Exception('challenge 불일치');
        }
        if (!$this->verifyOrigin($clientData['origin'] ?? '')) {
            throw new \Exception('origin 불일치');
        }

        // 2. authenticatorData
        $authData = self::base64urlDecode($credential['response']['authenticatorData'] ?? '');
        if (strlen($authData) < 37) {
            throw new \Exception('authenticatorData 길이 부족');
        }

        // rpIdHash (punycode / unicode 양쪽 허용)
        $rpIdHash = substr($authData, 0, 32);
        $validRpIds = [$this->rpId];
        if (function_exists('idn_to_utf8')) { $u = idn_to_utf8($this->rpId); if ($u && $u !== $this->rpId) $validRpIds[] = $u; }
        if (function_exists('idn_to_ascii')) { $a = idn_to_ascii($this->rpId); if ($a && $a !== $this->rpId) $validRpIds[] = $a; }
        $rpOk = false;
        foreach ($validRpIds as $rid) { if ($rpIdHash === hash('sha256', $rid, true)) { $rpOk = true; break; } }
        if (!$rpOk) throw new \Exception('rpIdHash 불일치');

        // flags (UP 필수)
        $flags = ord($authData[32]);
        if (!($flags & 0x01)) throw new \Exception('User Present 플래그 없음');

        // signCount
        $signCount = unpack('N', substr($authData, 33, 4))[1];

        // 3. 서명 검증
        $signature      = self::base64urlDecode($credential['response']['signature'] ?? '');
        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $signedData     = $authData . $clientDataHash;

        // PEM 에서 공개키 로드
        $pubKey = openssl_pkey_get_public($publicKeyPem);
        if (!$pubKey) {
            throw new \Exception('공개키 로드 실패');
        }

        $keyDetails = openssl_pkey_get_details($pubKey);
        $algo = ($keyDetails['type'] ?? -1) === OPENSSL_KEYTYPE_EC ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA256;

        $valid = openssl_verify($signedData, $signature, $pubKey, $algo);
        if ($valid !== 1) {
            throw new \Exception('서명 검증 실패');
        }

        // 4. signCount 검증 (0이 아닌 경우 증가해야 함)
        if ($storedSignCount > 0 && $signCount > 0 && $signCount <= $storedSignCount) {
            throw new \Exception('signCount 비정상 (복제 가능성)');
        }

        $userHandle = self::base64urlDecode($credential['response']['userHandle'] ?? '');

        return [
            'credential_id' => $credential['id'] ?? '',
            'sign_count'    => $signCount,
            'user_handle'   => $userHandle,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // 유틸리티
    // ═══════════════════════════════════════════════════════════════════

    private function verifyOrigin(string $origin): bool
    {
        // origin 비교 (https://domain 형태) — 같은 사이트의 IDN/ASCII 양쪽 허용
        if ($origin === $this->origin) return true;
        // 호스트 부분만 추출하여 punycode/unicode 변환 후 비교
        $oh = parse_url($origin, PHP_URL_HOST) ?: '';
        $th = parse_url($this->origin, PHP_URL_HOST) ?: '';
        if (function_exists('idn_to_ascii')) {
            if (idn_to_ascii($oh) === idn_to_ascii($th)) return true;
        }
        return false;
    }

    /**
     * COSE key → PEM 변환
     */
    private static function coseKeyToPem(array $cose): string
    {
        $kty = $cose['1'] ?? $cose[1] ?? null;  // 1=OKP, 2=EC, 3=RSA
        $alg = $cose['3'] ?? $cose[3] ?? null;

        if ($kty == 2) {
            // EC2 (ES256: P-256)
            $crv = $cose['-1'] ?? $cose[-1] ?? null; // 1=P-256
            $x   = $cose['-2'] ?? $cose[-2] ?? '';
            $y   = $cose['-3'] ?? $cose[-3] ?? '';

            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new \Exception('EC 키 좌표 길이 이상');
            }

            // Uncompressed point: 0x04 || x || y
            $point = "\x04" . $x . $y;

            // DER 인코딩 (P-256 SubjectPublicKeyInfo)
            $der = self::ecDer($point);
            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64) . "-----END PUBLIC KEY-----\n";
        }

        if ($kty == 3) {
            // RSA
            $n = $cose['-1'] ?? $cose[-1] ?? '';
            $e = $cose['-2'] ?? $cose[-2] ?? '';

            $der = self::rsaDer($n, $e);
            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64) . "-----END PUBLIC KEY-----\n";
        }

        throw new \Exception("지원하지 않는 키 타입: kty={$kty}");
    }

    private static function ecDer(string $point): string
    {
        // OID for P-256: 1.2.840.10045.3.1.7
        $oid_ec    = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"; // id-ecPublicKey
        $oid_p256  = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // P-256

        $algo = "\x30" . chr(strlen($oid_ec . $oid_p256)) . $oid_ec . $oid_p256;
        $bitString = "\x03" . self::derLength(strlen($point) + 1) . "\x00" . $point;
        $seq = "\x30" . self::derLength(strlen($algo . $bitString)) . $algo . $bitString;
        return $seq;
    }

    private static function rsaDer(string $n, string $e): string
    {
        // RSA SubjectPublicKeyInfo DER
        $nInt = self::derInteger($n);
        $eInt = self::derInteger($e);
        $seq  = "\x30" . self::derLength(strlen($nInt . $eInt)) . $nInt . $eInt;
        $bitString = "\x03" . self::derLength(strlen($seq) + 1) . "\x00" . $seq;

        // AlgorithmIdentifier: rsaEncryption OID + NULL
        $oid_rsa = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"; // 1.2.840.113549.1.1.1
        $algo = "\x30" . chr(strlen($oid_rsa) + 2) . $oid_rsa . "\x05\x00";

        $outer = "\x30" . self::derLength(strlen($algo . $bitString)) . $algo . $bitString;
        return $outer;
    }

    private static function derInteger(string $bytes): string
    {
        // 선행 0 제거 후 부호 비트 처리
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') $bytes = "\x00";
        if (ord($bytes[0]) & 0x80) $bytes = "\x00" . $bytes;
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $len): string
    {
        if ($len < 128) return chr($len);
        if ($len < 256) return "\x81" . chr($len);
        return "\x82" . pack('n', $len);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Base64url
    // ═══════════════════════════════════════════════════════════════════

    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }

    // ═══════════════════════════════════════════════════════════════════
    // 최소 CBOR 디코더 (WebAuthn에 필요한 subset만)
    // ═══════════════════════════════════════════════════════════════════

    public static function cborDecode(string $data): mixed
    {
        $offset = 0;
        return self::cborDecodeItem($data, $offset);
    }

    private static function cborDecodeItem(string $data, int &$offset): mixed
    {
        if ($offset >= strlen($data)) {
            throw new \Exception('CBOR: 데이터 부족');
        }

        $byte     = ord($data[$offset]);
        $major    = $byte >> 5;
        $addInfo  = $byte & 0x1f;
        $offset++;

        $value = self::cborDecodeLength($data, $offset, $addInfo);

        switch ($major) {
            case 0: // unsigned int
                return $value;
            case 1: // negative int
                return -1 - $value;
            case 2: // byte string
                $result = substr($data, $offset, $value);
                $offset += $value;
                return $result;
            case 3: // text string
                $result = substr($data, $offset, $value);
                $offset += $value;
                return $result;
            case 4: // array
                $arr = [];
                for ($i = 0; $i < $value; $i++) {
                    $arr[] = self::cborDecodeItem($data, $offset);
                }
                return $arr;
            case 5: // map
                $map = [];
                for ($i = 0; $i < $value; $i++) {
                    $k = self::cborDecodeItem($data, $offset);
                    $v = self::cborDecodeItem($data, $offset);
                    $map[$k] = $v;
                }
                return $map;
            case 6: // tag (무시하고 내용만 파싱)
                return self::cborDecodeItem($data, $offset);
            case 7: // simple/float
                if ($addInfo === 20) return false;
                if ($addInfo === 21) return true;
                if ($addInfo === 22) return null;
                return $value;
            default:
                throw new \Exception("CBOR: 지원하지 않는 major type: {$major}");
        }
    }

    private static function cborDecodeLength(string $data, int &$offset, int $addInfo): int
    {
        if ($addInfo < 24) return $addInfo;
        if ($addInfo === 24) {
            $val = ord($data[$offset]);
            $offset += 1;
            return $val;
        }
        if ($addInfo === 25) {
            $val = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            return $val;
        }
        if ($addInfo === 26) {
            $val = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            return $val;
        }
        if ($addInfo === 27) {
            $hi = unpack('N', substr($data, $offset, 4))[1];
            $lo = unpack('N', substr($data, $offset + 4, 4))[1];
            $offset += 8;
            return ($hi << 32) | $lo;
        }
        return 0;
    }
}
