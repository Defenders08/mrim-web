<?php
/**
 * MRIMProtocol
 *
 * Implements Mail.Ru Instant Messenger (MRIM) binary protocol packet structure,
 * serialization, deserialization, and string encoding (CP1251 <-> UTF-8).
 */

class MRIMProtocol
{

    // Magic header identifying MRIM packets
    public const CS_MAGIC = 0xDEADBEEF;

    // Supported protocol version (1.20)
    public const PROTO_VERSION = 0x00010014;

    // Command packets
    public const MRIM_CS_HELLO          = 0x1001;
    public const MRIM_CS_USER_INFO      = 0x1015;
    public const MRIM_CS_HELLO_ACK      = 0x1002;
    public const MRIM_CS_LOGIN_ACK      = 0x1004;
    public const MRIM_CS_LOGIN_REJ      = 0x1005;
    public const MRIM_CS_PING           = 0x1006;
    public const MRIM_CS_MESSAGE        = 0x1008;
    public const MRIM_CS_MESSAGE_ACK    = 0x1009;
    public const MRIM_CS_MESSAGE_RECV   = 0x1011;
    public const MRIM_CS_ADD_CONTACT    = 0x1019;
    public const MRIM_CS_ADD_CONTACT_ACK= 0x101A;
    public const MRIM_CS_MODIFY_CONTACT = 0x101B;
    public const MRIM_CS_AUTHORIZE      = 0x101C;
    public const MRIM_CS_AUTHORIZE_ACK  = 0x101D;
    public const MRIM_CS_MESSAGE_RECV2  = 0x101D;
    public const MRIM_CS_MESSAGE_RECV3  = 0x1063;
    public const MRIM_CS_MESSAGE_STATUS = 0x1012;
    public const MRIM_CS_USER_STATUS    = 0x100F;
    public const MRIM_CS_LOGOUT         = 0x1013;
    public const MRIM_CS_CONTACT_LIST2  = 0x1037;
    public const MRIM_CS_LOGIN2         = 0x1038;

    // Status constants
    public const STATUS_OFFLINE      = 0x00000000;
    public const STATUS_ONLINE       = 0x00000001;
    public const STATUS_AWAY         = 0x00000002;
    public const STATUS_UNDETERMINED = 0x00000004;

    // Message flags
    public const MESSAGE_FLAG_OFFLINE   = 0x00000001;
    public const MESSAGE_FLAG_NORECV    = 0x00000004;
    public const MESSAGE_FLAG_AUTHORIZE = 0x00000008;
    public const MESSAGE_FLAG_NOTIFY    = 0x00000010;
    public const MESSAGE_FLAG_SYSTEM    = 0x00000040;
    public const MESSAGE_FLAG_RTF       = 0x00000080;
    public const MESSAGE_FLAG_UTF16     = 0x00200000;

    /**
     * Build an MRIM 44-byte binary packet with header and payload
     *
     * Header structure (44 bytes total):
     *   uint32 magic      (0xDEADBEEF)
     *   uint32 proto      (0x00010014)
     *   uint32 seq        (Sequence number)
     *   uint32 msg        (Command ID)
     *   uint32 dlen       (Length of data body)
     *   uint32 from       (0)
     *   uint32 fromport   (0)
     *   byte[16] reserved (All zeros)
     */
    public static function buildPacket(int $msgId, int $seq, string $data = ''): string
    {
        $dlen = strlen($data);
        $header = pack(
            'VVVVVVV',
            self::CS_MAGIC,
            self::PROTO_VERSION,
            $seq,
            $msgId,
            $dlen,
            0,
            0
        ) . str_repeat("\0", 16);

        return $header . $data;
    }

    /**
     * Parse a 44-byte packet header
     *
     * @return array{magic: int, proto: int, seq: int, msg: int, dlen: int, valid: bool}
     */
    public static function parseHeader(string $headerBytes): array
    {
        if (strlen($headerBytes) < 44) {
            return [
                'magic' => 0,
                'proto' => 0,
                'seq'   => 0,
                'msg'   => 0,
                'dlen'  => 0,
                'valid' => false,
            ];
        }

        $unpacked = unpack('Vmagic/Vproto/Vseq/Vmsg/Vdlen', substr($headerBytes, 0, 20));

        // Note: PHP integers may be signed, handle 0xDEADBEEF conversion
        $magic = (int) $unpacked['magic'];
        $magicUnsigned = $magic < 0 ? ($magic + 0x100000000) : $magic;

        $valid = ($magicUnsigned === self::CS_MAGIC);

        return [
            'magic' => $magicUnsigned,
            'proto' => (int) $unpacked['proto'],
            'seq'   => (int) $unpacked['seq'],
            'msg'   => (int) $unpacked['msg'],
            'dlen'  => (int) $unpacked['dlen'],
            'valid' => $valid,
        ];
    }

    /**
     * Encode an unsigned 32-bit integer (little endian)
     */
    public static function encodeUint32(int $val): string
    {
        return pack('V', $val);
    }

    /**
     * Decode an unsigned 32-bit integer from binary string at given offset
     */
    public static function decodeUint32(string $data, int $offset): int
    {
        if ($offset + 4 > strlen($data)) {
            return 0;
        }
        $res = unpack('Vval', substr($data, $offset, 4));
        return (int) ($res['val'] ?? 0);
    }

    /**
     * Safely check if a string is valid UTF-8 without throwing if mbstring extension is absent
     */
    public static function isValidUtf8(string $str): bool
    {
        if ($str === '') {
            return true;
        }
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($str, 'UTF-8');
        }
        return (bool) @preg_match('//u', $str);
    }

    /**
     * Ensure a string is clean, valid UTF-8
     */
    public static function ensureUtf8(string $str): string
    {
        if ($str === '') {
            return '';
        }

        $str = trim($str, "\x00");

        // 1. If string is UTF-16LE bytes, decode it first
        if (self::isMostlyUtf16Le($str)) {
            $str = self::decodeUtf16String($str);
        }

        // 2. If valid UTF-8, check for double-encoding (e.g. "РџСЂРёРІРµС‚")
        if (self::isValidUtf8($str)) {
            if (self::isDoubleEncodedUtf8($str)) {
                return self::fixDoubleEncodedUtf8($str);
            }
            return $str;
        }

        // 3. Try CP1251 to UTF-8 conversion
        $utf8 = self::cp1251ToUtf8($str);
        if (self::isValidUtf8($utf8)) {
            if (self::isDoubleEncodedUtf8($utf8)) {
                return self::fixDoubleEncodedUtf8($utf8);
            }
            return $utf8;
        }

        return $str;
    }

    /**
     * Detect if string is double-encoded UTF-8
     */
    public static function isDoubleEncodedUtf8(string $str): bool
    {
        if ($str === '') {
            return false;
        }
        $cp1251Bytes = self::utf8ToCp1251($str);
        return ($cp1251Bytes !== '' && $cp1251Bytes !== $str && self::isValidUtf8($cp1251Bytes) && (bool) preg_match('/[\xD0-\xD1][\x80-\xBF]/', $cp1251Bytes));
    }

    /**
     * Fix double-encoded UTF-8 string by converting CP1251 representation back to UTF-8
     */
    public static function fixDoubleEncodedUtf8(string $str): string
    {
        if ($str === '') {
            return '';
        }
        for ($i = 0; $i < 2; $i++) {
            $cp1251Bytes = self::utf8ToCp1251($str);
            if ($cp1251Bytes !== '' && $cp1251Bytes !== $str && self::isValidUtf8($cp1251Bytes)) {
                if (preg_match('/[\xD0-\xD1][\x80-\xBF]/', $cp1251Bytes)) {
                    $str = $cp1251Bytes;
                    continue;
                }
            }
            break;
        }
        return $str;
    }

    /**
     * Recursively ensure all string values in an array/payload are clean UTF-8
     */
    public static function cleanArrayForJson(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = self::ensureUtf8($v);
            } elseif (is_array($v)) {
                $data[$k] = self::cleanArrayForJson($v);
            }
        }
        return $data;
    }

    /**
     * Pure PHP UTF-16LE to UTF-8 conversion without external extension dependencies
     */
    public static function decodeUtf16LePure(string $rawBytes): string
    {
        if ($rawBytes === '') {
            return '';
        }
        if (substr($rawBytes, 0, 2) === "\xFF\xFE") {
            $rawBytes = substr($rawBytes, 2);
        }
        $len = strlen($rawBytes);
        if ($len < 2) {
            return '';
        }
        $utf8 = '';
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $low = ord($rawBytes[$i]);
            $high = ord($rawBytes[$i + 1]);
            $code = ($high << 8) | $low;

            // Handle surrogate pairs if needed
            if ($code >= 0xD800 && $code <= 0xDBFF && $i + 3 < $len) {
                $low2 = ord($rawBytes[$i + 2]);
                $high2 = ord($rawBytes[$i + 3]);
                $code2 = ($high2 << 8) | $low2;
                if ($code2 >= 0xDC00 && $code2 <= 0xDFFF) {
                    $code = (($code - 0xD800) << 10) + ($code2 - 0xDC00) + 0x010000;
                    $i += 2;
                }
            }

            if ($code === 0) {
                continue;
            }

            if ($code <= 0x7F) {
                $utf8 .= chr($code);
            } elseif ($code <= 0x7FF) {
                $utf8 .= chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
            } elseif ($code <= 0xFFFF) {
                $utf8 .= chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
            } else {
                $utf8 .= chr(0xF0 | ($code >> 18)) . chr(0x80 | (($code >> 12) & 0x3F)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
            }
        }
        return $utf8;
    }

    /**
     * Check if string contains multi-byte UTF-8 sequences (bytes >= 0x80)
     */
    public static function containsMultibyteUtf8(string $str): bool
    {
        return (bool) preg_match('/[\xC0-\xF4][\x80-\xBF]/', $str);
    }

    /**
     * Safely decode UTF-16LE string bytes to UTF-8
     */
    public static function decodeUtf16String(string $rawBytes): string
    {
        if ($rawBytes === '') {
            return '';
        }

        // If rawBytes is already valid multi-byte UTF-8 (contains \xD0\xB4...) and is NOT UTF-16LE
        if (self::isValidUtf8($rawBytes) && self::containsMultibyteUtf8($rawBytes) && !self::isMostlyUtf16Le($rawBytes)) {
            return self::ensureUtf8($rawBytes);
        }

        if (substr($rawBytes, 0, 2) === "\xFF\xFE") {
            $rawBytes = substr($rawBytes, 2);
        }

        if (strlen($rawBytes) % 2 !== 0) {
            $rawBytes = substr($rawBytes, 0, -1);
        }

        if ($rawBytes === '') {
            return '';
        }

        $value = '';
        if (function_exists('mb_convert_encoding')) {
            $value = @mb_convert_encoding($rawBytes, 'UTF-8', 'UTF-16LE');
        } elseif (function_exists('iconv')) {
            $value = @iconv('UTF-16LE', 'UTF-8//IGNORE', $rawBytes);
        }

        if ($value === false || $value === '' || !self::isValidUtf8($value)) {
            $value = self::decodeUtf16LePure($rawBytes);
        }

        return self::ensureUtf8($value);
    }

    /**
     * Check if raw bytes look like UTF-16LE
     */
    public static function isMostlyUtf16Le(string $rawBytes): bool
    {
        $len = strlen($rawBytes);
        if ($len < 2) {
            return false;
        }
        if ($len % 2 !== 0) {
            $len--;
        }
        $highBytesMatch = 0;
        $sampleCount = 0;
        for ($i = 1; $i < $len; $i += 2) {
            $b = ord($rawBytes[$i]);
            $sampleCount++;
            if ($b === 0x00 || $b === 0x04 || $b === 0x05 || ($b >= 0x01 && $b <= 0x0A)) {
                $highBytesMatch++;
            }
        }
        return ($sampleCount > 0 && ($highBytesMatch / $sampleCount) >= 0.4);
    }

    /**
     * Encode an MRIM Length-Prefixed String (LPS) in raw UTF-8
     */
    public static function encodeLPS(string $utf8Str): string
    {
        $clean = self::ensureUtf8($utf8Str);
        $len = strlen($clean);
        return pack('V', $len) . $clean;
    }

    /**
     * Encode an MRIM Length-Prefixed String in CP1251 (legacy fallback)
     * Note: MRIM clients auto-detect UTF-16LE vs CP1251. An even byte length in CP1251
     * triggers false positive UTF-16LE decoding in recipient clients (resulting in Asian/Arabic characters).
     * Enforcing an odd byte length by appending a trailing space guarantees CP1251 decoding on the recipient.
     */
    public static function encodeLPSCp1251(string $utf8Str): string
    {
        $clean = self::ensureUtf8($utf8Str);
        $cp1251 = self::utf8ToCp1251($clean);
        if (strlen($cp1251) > 0 && strlen($cp1251) % 2 === 0) {
            $cp1251 .= ' ';
        }
        $len = strlen($cp1251);
        return pack('V', $len) . $cp1251;
    }

    /**
     * Pure PHP UTF-8 to UTF-16LE conversion without external extension dependencies
     */
    public static function utf8ToUtf16LePure(string $utf8Str): string
    {
        $clean = self::ensureUtf8($utf8Str);
        $utf16 = '';
        $len = strlen($clean);
        for ($i = 0; $i < $len; ) {
            $c = ord($clean[$i]);
            if ($c <= 0x7F) {
                $code = $c;
                $i += 1;
            } elseif (($c & 0xE0) === 0xC0 && $i + 1 < $len) {
                $code = (($c & 0x1F) << 6) | (ord($clean[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($c & 0xF0) === 0xE0 && $i + 2 < $len) {
                $code = (($c & 0x0F) << 12) | ((ord($clean[$i + 1]) & 0x3F) << 6) | (ord($clean[$i + 2]) & 0x3F);
                $i += 3;
            } elseif (($c & 0xF8) === 0xF0 && $i + 3 < $len) {
                $code = (($c & 0x07) << 18) | ((ord($clean[$i + 1]) & 0x3F) << 12) | ((ord($clean[$i + 2]) & 0x3F) << 6) | (ord($clean[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $code = 0x3F;
                $i += 1;
            }

            if ($code <= 0xFFFF) {
                $utf16 .= pack('v', $code);
            } else {
                $code -= 0x10000;
                $high = 0xD800 + ($code >> 10);
                $low = 0xDC00 + ($code & 0x3FF);
                $utf16 .= pack('v', $high) . pack('v', $low);
            }
        }
        return $utf16;
    }

    /**
     * Encode MRIM LPS string in UTF-16LE
     */
    public static function encodeLPSUtf16(string $utf8Str): string
    {
        $clean = self::ensureUtf8($utf8Str);
        $utf16 = false;
        if (function_exists('mb_convert_encoding')) {
            $utf16 = @mb_convert_encoding($clean, 'UTF-16LE', 'UTF-8');
        } elseif (function_exists('iconv')) {
            $utf16 = @iconv('UTF-8', 'UTF-16LE//IGNORE', $clean);
        }

        if ($utf16 === false || $utf16 === '') {
            $utf16 = self::utf8ToUtf16LePure($clean);
        }

        return pack('V', strlen($utf16)) . $utf16;
    }

    /**
     * Decode an MRIM Length-Prefixed UTF-16LE String
     *
     * @return array{value: string, next_offset: int, debug: array}
     */
    public static function decodeLPSUtf16(string $data, int $offset): array
    {
        return self::decodeLPS($data, $offset, true);
    }

    /**
     * Decode an MRIM Length-Prefixed String (LPS) at offset
     *
     * @param string $data
     * @param int $offset
     * @param bool|null $utf16 Explicit UTF-16 flag (true=UTF-16LE, false=CP1251/UTF-8, null=autodetect)
     * @return array{value: string, next_offset: int, debug: array}
     */
    public static function decodeLPS(string $data, int $offset, ?bool $utf16 = null): array
    {
        $dataLen = strlen($data);

        if ($offset + 4 > $dataLen) {
            return [
                'value'       => '',
                'next_offset' => $offset,
                'debug'       => ['len' => 0, 'hex' => '', 'enc' => 'out_of_bounds', 'raw_utf8' => '']
            ];
        }

        $len = self::decodeUint32($data, $offset);
        $offset += 4;

        if ($len <= 0 || $offset + $len > $dataLen) {
            return [
                'value'       => '',
                'next_offset' => $offset,
                'debug'       => ['len' => $len, 'hex' => '', 'enc' => 'empty_or_truncated', 'raw_utf8' => '']
            ];
        }

        $rawStr = substr($data, $offset, $len);
        $nextOffset = $offset + $len;
        $hex = bin2hex($rawStr);

        $enc = '';
        $value = '';

        if ($utf16 === true || self::isMostlyUtf16Le($rawStr)) {
            $enc = 'UTF-16LE';
            $value = self::decodeUtf16String($rawStr);
        } else {
            if (self::containsMultibyteUtf8($rawStr) || self::isValidUtf8($rawStr)) {
                $enc = 'UTF-8';
                $value = self::ensureUtf8($rawStr);
            } else {
                $enc = 'CP1251';
                $value = self::cp1251ToUtf8($rawStr);
            }
        }

        $value = self::ensureUtf8(trim($value, "\x00 "));

        return [
            'value'       => $value,
            'next_offset' => $nextOffset,
            'debug'       => [
                'len'      => $len,
                'hex'      => $hex,
                'enc'      => $enc,
                'raw_utf8' => $value,
            ],
        ];
    }



    /**
     * Convert UTF-8 string to Windows-1251 (CP1251)
     */
    public static function utf8ToCp1251(string $utf8Str): string
    {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($utf8Str, 'Windows-1251', 'UTF-8');
        }
        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'Windows-1251//IGNORE', $utf8Str);
            if ($conv !== false) {
                return $conv;
            }
        }
        return self::fallbackUtf8ToCp1251($utf8Str);
    }

    /**
     * Convert Windows-1251 (CP1251) string to UTF-8
     */
    public static function cp1251ToUtf8(string $cp1251Str): string
    {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($cp1251Str, 'UTF-8', 'Windows-1251');
        }
        if (function_exists('iconv')) {
            $conv = @iconv('Windows-1251', 'UTF-8//IGNORE', $cp1251Str);
            if ($conv !== false) {
                return $conv;
            }
        }
        return self::fallbackCp1251ToUtf8($cp1251Str);
    }

    /**
     * Fallback CP1251 to UTF-8 conversion if mbstring/iconv are unavailable
     */
    private static function fallbackCp1251ToUtf8(string $str): string
    {
        $map = self::getCp1251Table();
        $res = '';
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $code = ord($str[$i]);
            if ($code < 128) {
                $res .= $str[$i];
            } elseif (isset($map[$code])) {
                $res .= $map[$code];
            } else {
                $res .= '?';
            }
        }
        return $res;
    }

    /**
     * Fallback UTF-8 to CP1251 conversion if mbstring/iconv are unavailable
     */
    private static function fallbackUtf8ToCp1251(string $str): string
    {
        $map = array_flip(self::getCp1251Table());
        $res = '';
        $len = strlen($str);
        $i = 0;
        while ($i < $len) {
            $c = ord($str[$i]);
            if ($c < 128) {
                $res .= $str[$i];
                $i++;
            } elseif (($c & 0xE0) === 0xC0 && $i + 1 < $len) {
                $char = substr($str, $i, 2);
                $res .= isset($map[$char]) ? chr($map[$char]) : '?';
                $i += 2;
            } elseif (($c & 0xF0) === 0xE0 && $i + 2 < $len) {
                $char = substr($str, $i, 3);
                $res .= isset($map[$char]) ? chr($map[$char]) : '?';
                $i += 3;
            } elseif (($c & 0xF8) === 0xF0 && $i + 3 < $len) {
                $char = substr($str, $i, 4);
                $res .= isset($map[$char]) ? chr($map[$char]) : '?';
                $i += 4;
            } else {
                $res .= '?';
                $i++;
            }
        }
        return $res;
    }

    /**
     * Windows-1251 code to UTF-8 string lookup table (128-255)
     */
    private static function getCp1251Table(): array
    {
        static $table = null;
        if ($table !== null) {
            return $table;
        }

        $table = [
            128 => 'Ђ', 129 => 'Ѓ', 130 => '‚', 131 => 'ѓ', 132 => '„', 133 => '…', 134 => '†', 135 => '‡',
            136 => '€', 137 => '‰', 138 => 'Љ', 139 => '‹', 140 => 'Њ', 141 => 'Ќ', 142 => 'Ћ', 143 => 'Џ',
            144 => 'ђ', 145 => '‘', 146 => '’', 147 => '“', 148 => '”', 149 => '•', 150 => '–', 151 => '—',
            152 => ' ', 153 => '™', 154 => 'љ', 155 => '›', 156 => 'њ', 157 => 'ќ', 158 => 'ћ', 159 => 'џ',
            160 => ' ', 161 => 'Ў', 162 => 'ў', 163 => 'Ј', 164 => '¤', 165 => 'Ґ', 166 => '¦', 167 => '§',
            168 => 'Ё', 169 => '©', 170 => 'Є', 171 => '«', 172 => '¬', 173 => "\u{00AD}", 174 => '®', 175 => 'Ї',
            176 => '°', 177 => '±', 178 => 'І', 179 => 'і', 180 => 'ґ', 181 => 'µ', 182 => '¶', 183 => '·',
            184 => 'ё', 185 => '№', 186 => 'є', 187 => '»', 188 => 'ј', 189 => 'Ѕ', 190 => 'ѕ', 191 => 'ї',
            192 => 'А', 193 => 'Б', 194 => 'В', 195 => 'Г', 196 => 'Д', 197 => 'Е', 198 => 'Ж', 199 => 'З',
            200 => 'И', 201 => 'Й', 202 => 'К', 203 => 'Л', 204 => 'М', 205 => 'Н', 206 => 'О', 207 => 'П',
            208 => 'Р', 209 => 'С', 210 => 'Т', 211 => 'У', 212 => 'Ф', 213 => 'Х', 214 => 'Ц', 215 => 'Ч',
            216 => 'Ш', 217 => 'Щ', 218 => 'Ъ', 219 => 'Ы', 220 => 'Ь', 221 => 'Э', 222 => 'Ю', 223 => 'Я',
            224 => 'а', 225 => 'б', 226 => 'в', 227 => 'г', 228 => 'д', 229 => 'е', 230 => 'ж', 231 => 'з',
            232 => 'и', 233 => 'й', 234 => 'к', 235 => 'л', 236 => 'м', 237 => 'н', 238 => 'о', 239 => 'п',
            240 => 'р', 241 => 'с', 242 => 'т', 243 => 'у', 244 => 'ф', 245 => 'х', 246 => 'ц', 247 => 'ч',
            248 => 'ш', 249 => 'щ', 250 => 'ъ', 251 => 'ы', 252 => 'ь', 253 => 'э', 254 => 'ю', 255 => 'я',
        ];

        return $table;
    }

    /**
     * Get a human-readable name for command IDs
     */
    public static function getCommandName(int $cmd): string
    {
        $names = [
            self::MRIM_CS_USER_INFO      => 'MRIM_CS_USER_INFO',
            self::MRIM_CS_MESSAGE_RECV2  => 'MRIM_CS_MESSAGE_RECV2',
            self::MRIM_CS_HELLO         => 'MRIM_CS_HELLO',
            self::MRIM_CS_HELLO_ACK     => 'MRIM_CS_HELLO_ACK',
            self::MRIM_CS_LOGIN_ACK     => 'MRIM_CS_LOGIN_ACK',
            self::MRIM_CS_LOGIN_REJ     => 'MRIM_CS_LOGIN_REJ',
            self::MRIM_CS_PING          => 'MRIM_CS_PING',
            self::MRIM_CS_MESSAGE       => 'MRIM_CS_MESSAGE',
            self::MRIM_CS_MESSAGE_ACK   => 'MRIM_CS_MESSAGE_ACK',
            self::MRIM_CS_MESSAGE_RECV  => 'MRIM_CS_MESSAGE_RECV',
            self::MRIM_CS_MESSAGE_STATUS => 'MRIM_CS_MESSAGE_STATUS',
            self::MRIM_CS_ADD_CONTACT    => 'MRIM_CS_ADD_CONTACT',
            self::MRIM_CS_ADD_CONTACT_ACK=> 'MRIM_CS_ADD_CONTACT_ACK',
            self::MRIM_CS_MODIFY_CONTACT => 'MRIM_CS_MODIFY_CONTACT',
            self::MRIM_CS_AUTHORIZE      => 'MRIM_CS_AUTHORIZE',
            self::MRIM_CS_USER_STATUS    => 'MRIM_CS_USER_STATUS',
            self::MRIM_CS_LOGOUT        => 'MRIM_CS_LOGOUT',
            self::MRIM_CS_CONTACT_LIST2 => 'MRIM_CS_CONTACT_LIST2',
            self::MRIM_CS_LOGIN2        => 'MRIM_CS_LOGIN2',
        ];

        return $names[$cmd] ?? sprintf('UNKNOWN(0x%04X)', $cmd);
    }
}
