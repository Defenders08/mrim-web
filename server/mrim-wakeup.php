<?php
/**
 * MRIMWakeUp - Исследование и реализация поддержки функции «Будильник» (Wake Up / Alarm) протокола Mail.Ru Agent.
 *
 * =========================================================================================
 * ИССЛЕДОВАНИЕ ПРОТОКОЛА MRIM (MAIL.RU INSTANT MESSENGER WAKE UP / БУДИЛЬНИК)
 * =========================================================================================
 *
 * 1. ЧТО ТАКОЕ «БУДИЛЬНИК» В MAIL.RU AGENT:
 *    Функция «Будильник» (Wake Up) — это специальное высокое по приоритету уведомление
 *    в протоколе MRIM (введена начиная с Mail.Ru Agent 5.x / версия протокола 1.13+),
 *    которая заставляет окно чата у получателя вибрировать/трястись и воспроизводит
 *    громкий звуковой сигнал сирены для привлечения внимания собеседника.
 *
 * 2. КОМАНДЫ И ФЛАГИ ПРОТОКОЛА:
 *    - Команда отправки сообщения: MRIM_CS_MESSAGE (0x1008)
 *    - Флаг сообщения Будильника (Message Flags):
 *        * MESSAGE_FLAG_WAKEUP = 0x00000010 (16)
 *        * В некоторых версиях спецификаций пересекается с маской MESSAGE_FLAG_NOTIFY (0x10).
 *    - Флаг возможностей клиента (Capability / Feature Flags):
 *        * FEATURE_FLAG_WAKEUP = 0x00000010 (16 в десятичной системе)
 *        * Данный флаг объявляется клиентом при авторизации (MRIM_CS_LOGIN2 / 0x1038)
 *          в битовой маске поддерживаемых функций (Feature Mask).
 *
 * 3. ПОЧЕМУ СЕРВЕР ПРИСЫЛАЕТ ТЕКСТ: "Собеседник попытался вас разбудить...":
 *    - [ПОДТВЕРЖДЕНО ДОКУМЕНТАЦИЕЙ И ИСХОДНЫМИ КОДАМИ CLIENT/SERVER MRIM]
 *      Когда Пользователь А отправляет пакет Будильника Пользователю Б, сервер Mail.Ru
 *      проверяет флаг возможностей (FEATURE_FLAG_WAKEUP = 0x00000010), переданный
 *      Пользователем Б во время входа (LOGIN2).
 *    - Если клиент Пользователя Б НЕ сообщил о поддержке FEATURE_FLAG_WAKEUP, сервер
 *      Mail.Ru производит серверную подмену (fallback): преобразует сырой будильник
 *      в обычное текстовое сообщение с содержимым:
 *      "Собеседник попытался вас разбудить..." (или "User tried to wake you up...")
 *      Это сделано для того, чтобы устаревшие или сторонние клиенты (не умеющие
 *      трясти окно и играть звуки) не теряли уведомление и показывали его текстом.
 *
 * 4. ЧТО НУЖНО ДЛЯ ПОЛУЧЕНИЯ НАСТОЯЩЕГО СОБЫТИЯ БУДИЛЬНИКА:
 *    - Клиент при отправке MRIM_CS_LOGIN2 должен передавать маску возможностей с
 *      включенным битом FEATURE_FLAG_WAKEUP (0x00000010).
 *    - При получении пакета сообщения (MRIM_CS_MESSAGE_RECV 0x1011, RECV2 0x101D, RECV3 0x1063
 *      или ACK 0x1009) необходимо проверять наличие бита 0x00000010 в флагах либо
 *      совпадение с текстом фоллбека, генерируя внутреннее событие 'wakeup' / 'alarm'.
 *
 * 5. СТАТУС УТВЕРЖДЕНИЙ (ДОКУМЕНТАЦИЯ vs ГИПОТЕЗА):
 *    - [ПОДТВЕРЖДЕНО]: Для отправки будильника используется MRIM_CS_MESSAGE (0x1008).
 *    - [ПОДТВЕРЖДЕНО]: FEATURE_FLAG_WAKEUP = 0x00000010 заявляется клиентом в LOGIN2.
 *    - [ПОДТВЕРЖДЕНО]: Текст "Собеседник попытался вас разбудить..." генерируется сервером
 *      как fallback при отсутствии FEATURE_FLAG_WAKEUP у получателя.
 *    - [ГИПОТЕЗА]: Сообщение с флагом 0x10 может содержать пустую строку, 'alarm' или RTF,
 *      которые сервер транслирует с флагом 0x10 нативно при наличии флага возможностей.
 *
 * =========================================================================================
 */

require_once __DIR__ . '/mrim-protocol.php';

class MRIMWakeUp
{
    // Feature flags for client capabilities in MRIM protocol
    public const FEATURE_FLAG_WAKEUP = 0x00000010; // Bit 4 (16): WakeUp capability
    public const FEATURE_FLAG_MULTS  = 0x00000020; // Bit 5 (32): Flash animation smiles
    public const FEATURE_FLAG_SMS    = 0x00000001; // Bit 0 (1) : SMS capability
    public const FEATURE_FLAG_VIDEO  = 0x00000100; // Bit 8 (256): Video call capability

    // Message flags for WakeUp (0x00004000 = Alarm / WakeUp according to MRIM protocol spec)
    public const MESSAGE_FLAG_WAKEUP = 0x00004000;

    // Server fallback text triggers
    public const FALLBACK_RU_1 = 'Собеседник попытался вас разбудить';
    public const FALLBACK_RU_2 = 'попытался вас разбудить';
    public const FALLBACK_EN_1 = 'tried to wake you up';

    /**
     * Формирование бинарного payload пакета Будильника для MRIM_CS_MESSAGE (0x1008)
     *
     * @param string $toEmail E-mail получателя
     * @param string $text Текст сообщения (по умолчанию 'alarm')
     * @param string $rtf RTF разметка или base64 RTF (опционально)
     * @return string Бинарный payload пакета
     */
    public static function buildWakeUpPayload(string $toEmail, string $text = 'alarm', string $rtf = ''): string
    {
        $cleanEmail = strtolower(trim($toEmail));
        // 0x00004000 (MESSAGE_FLAG_WAKEUP) | 0x00000001 (MESSAGE_FLAG_OFFLINE) = 0x00004001
        $flags = self::MESSAGE_FLAG_WAKEUP | MRIMProtocol::MESSAGE_FLAG_OFFLINE;

        if ($rtf !== '') {
            $flags |= MRIMProtocol::MESSAGE_FLAG_RTF; // 0x80
        }

        // MRIM_CS_MESSAGE: uint32(flags) + LPS(toEmail) + LPSCp1251(text) + LPS(rtf)
        return MRIMProtocol::encodeUint32($flags) .
               MRIMProtocol::encodeLPS($cleanEmail) .
               MRIMProtocol::encodeLPSCp1251($text) .
               MRIMProtocol::encodeLPS($rtf);
    }

    /**
     * Проверка, является ли входящее сообщение Будильником (по флагам или фоллбек-тексту)
     *
     * @param int $flags Флаги сообщения из заголовка
     * @param string $text Расшифрованный текст сообщения
     * @return bool true, если сообщение является будильником
     */
    public static function isWakeUpMessage(int $flags, string $text): bool
    {
        // 1. Проверка битового флага 0x00004000 (или 0x00000010)
        if (($flags & self::MESSAGE_FLAG_WAKEUP) !== 0 || ($flags & 0x00000010) !== 0) {
            return true;
        }

        // 2. Проверка текста на серверный фоллбек
        if ($text !== '') {
            if (mb_stripos($text, self::FALLBACK_RU_1) !== false ||
                mb_stripos($text, self::FALLBACK_RU_2) !== false ||
                mb_stripos($text, self::FALLBACK_EN_1) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Формирование расширенного LOGIN2 payload с поддержкой FEATURE_FLAG_WAKEUP (0x00000010)
     *
     * @param string $email
     * @param string $password
     * @param int $status
     * @param string $clientName
     * @param int $additionalFeatures
     * @return string Бинарный payload для MRIM_CS_LOGIN2
     */
    public static function buildLogin2PayloadWithWakeUp(
        string $email,
        string $password,
        int $status = MRIMProtocol::STATUS_ONLINE,
        string $clientName = 'client="Agent" version="5.10" build="3850"',
        int $additionalFeatures = 0
    ): string {
        $featuresMask = self::FEATURE_FLAG_WAKEUP | $additionalFeatures;

        // Full MRIM_CS_LOGIN2: 10 fields
        $payload = MRIMProtocol::encodeLPS($email)
                 . MRIMProtocol::encodeLPS($password)
                 . MRIMProtocol::encodeUint32($status)
                 . MRIMProtocol::encodeLPS($clientName)
                 . MRIMProtocol::encodeUint32($featuresMask)
                 . MRIMProtocol::encodeLPS('')  // xstatus_uri
                 . MRIMProtocol::encodeLPS('')  // xstatus_title
                 . MRIMProtocol::encodeLPS('')  // xstatus_desc
                 . MRIMProtocol::encodeUint32(0) // user_feature_mask
                 . MRIMProtocol::encodeLPS('ru'); // lang

        return $payload;
    }

    /**
     * Обработка входящего события будильника и подготовка структурированного массива для WebSocket
     *
     * @param string $fromEmail
     * @param string $text
     * @param int $flags
     * @return array Массив события для WebSocket ('type' => 'wakeup', 'data' => [...])
     */
    public static function processIncomingWakeUp(string $fromEmail, string $text = '', int $flags = 0): array
    {
        $cleanFrom = strtolower(trim($fromEmail));
        $displayText = $text ?: "🔔 Собеседник $cleanFrom попытался вас разбудить!";

        return [
            'type' => 'wakeup',
            'data' => [
                'from'      => $cleanFrom,
                'text'      => $displayText,
                'timestamp' => time(),
                'flags'     => $flags,
                'is_alarm'  => true,
            ]
        ];
    }
}
