<?php
declare(strict_types=1);

namespace App;

/**
 * Управление системой парсинга сигналов: каналы-источники, админы, приём постов (Telegram и внешний ридер),
 * разбор по настройкам канала, дедупликация. Исполнение клиентам — в демоне (Manager::processSignals).
 */
final class Signals
{
    /** Ключ «ручного» источника: пересылки админа, у которых Telegram не отдаёт исходный канал. */
    public const MANUAL_KEY = 'manual';

    /** Telegram ID, которым разрешено присылать сигналы боту: таблица админов + старая настройка signal_allowed_ids. */
    public static function adminIds(): array
    {
        $ids = array_map('strval', array_column(DB::all('SELECT tg_id FROM signal_admins WHERE enabled = 1'), 'tg_id'));
        foreach ((array)Settings::get('signal_allowed_ids') as $id) {
            if ((string)$id !== '' && !str_starts_with((string)$id, '-')) {
                $ids[] = (string)$id;
            }
        }
        return array_values(array_unique($ids));
    }

    public static function channelByKey(string $key): ?array
    {
        return DB::row('SELECT * FROM signal_channels WHERE source_key = ?', [$key]);
    }

    /** Настройки разбора канала: слова из parser_config поверх значений по умолчанию (пустые = по умолчанию). */
    public static function parserConfig(?array $channel): array
    {
        $c = $channel ? json_decode((string)($channel['parser_config'] ?? ''), true) : null;
        return is_array($c) ? $c : [];
    }

    /** Найти канал или создать: неизвестный канал появляется выключенным — включает админ вручную. */
    public static function ensureChannel(string $key, string $name, bool $enabled = false, string $by = 'auto'): array
    {
        $ch = self::channelByKey($key);
        if ($ch) {
            return $ch;
        }
        $id = DB::insert('signal_channels', ['name' => mb_substr($name !== '' ? $name : $key, 0, 120), 'source_key' => $key, 'enabled' => $enabled ? 1 : 0,
            'mode' => 'demo', 'risk_mult' => 1, 'created_at' => DB::now(), 'created_by' => $by]);
        return DB::row('SELECT * FROM signal_channels WHERE id = ?', [$id]);
    }

    /**
     * Сообщение Telegram → сигнал. Возвращает true, если сообщение принадлежит системе сигналов (обработано или
     * сознательно проигнорировано) и обычную обработку бота продолжать не нужно.
     */
    public static function fromTelegram(array $m): bool
    {
        if (!Settings::get('signal_enabled')) {
            return false;
        }
        $chat = $m['chat'] ?? [];
        $text = trim((string)($m['text'] ?? $m['caption'] ?? ''));
        if (($chat['type'] ?? '') === 'channel') {
            $key = (string)($chat['id'] ?? '');
            $ch = self::channelByKey($key);
            if (!$ch && in_array($key, array_map('strval', (array)Settings::get('signal_allowed_ids')), true)) {
                $ch = self::ensureChannel($key, (string)($chat['title'] ?? $key), true, 'legacy');
            }
            if (!$ch) {
                $new = self::ensureChannel($key, (string)($chat['title'] ?? $key), false, 'auto');   // бот — админ канала, но канал не подключён
                self::touch((int)$new['id']);
                return true;
            }
            self::receive($ch, $text, null);
            return true;
        }
        if (($chat['type'] ?? '') !== 'private' || str_starts_with($text, '/')
            || !in_array((string)($m['from']['id'] ?? ''), self::adminIds(), true)) {
            return false;
        }
        $reply = (int)$chat['id'];
        $origin = $m['forward_origin']['chat'] ?? $m['forward_from_chat'] ?? null;
        if (is_array($origin) && isset($origin['id'])) {
            $key = (string)$origin['id'];
            $ch = self::channelByKey($key);
            if (!$ch) {
                $ch = self::ensureChannel($key, (string)($origin['title'] ?? $key), false, 'forward');
                Telegram::send($reply, '📡 Канал «' . htmlspecialchars($ch['name']) . '» добавлен в список как <b>выключенный</b>. '
                    . 'Включите его в админке (меню «Сигналы») — тогда пересылки из него начнут исполняться.');
                self::touch((int)$ch['id']);
                return true;
            }
        } else {
            $ch = self::ensureChannel(self::MANUAL_KEY, 'Ручная пересылка (админ)', true, 'auto');
        }
        self::receive($ch, $text, $reply);
        return true;
    }

    /** Подмена ИИ-экстрактора (тесты). */
    public static ?\Closure $aiExtractor = null;
    private const AI_MAX_PER_HOUR = 60;

    /**
     * ИИ-разбор поста, который не подошёл строгому шаблону. ИИ только ПЕРЕПИСЫВАЕТ поля — каждое число и монета обязаны
     * буквально присутствовать в тексте, а итог проходит те же проверки (SignalParser::validate), что и обычный сигнал.
     * @return array{type:string,signal?:array,update?:array,error?:string}|null null — ИИ не применялся или ничего не нашёл
     */
    public static function aiParse(string $text): ?array
    {
        if (mb_strlen($text) < 15 || mb_strlen($text) > 3000 || !preg_match('/\d/', $text)) {
            return null;                                       // болтовня без цифр — не тратим токены
        }
        if ((int)DB::val("SELECT COUNT(*) FROM ai_usage WHERE kind = 'signal_parse' AND ts > ?", [gmdate('Y-m-d H:i:s', time() - 3600)]) >= self::AI_MAX_PER_HOUR) {
            return null;                                       // защита от потока постов: лимит расходов на ИИ
        }
        $raw = self::$aiExtractor ? (self::$aiExtractor)($text) : (new Engine\AIAnalyst())->extractSignal($text);
        return is_array($raw) ? self::verifyAi($raw, $text) : null;
    }

    /** Сверка ответа ИИ с исходным текстом (защита от выдуманных цифр) и приведение к формату SignalParser. */
    public static function verifyAi(array $r, string $text): ?array
    {
        $type = (string)($r['type'] ?? 'none');
        $coin = strtoupper(preg_replace('/USDT$/i', '', trim((string)($r['symbol'] ?? ''))));
        if (!in_array($type, ['signal', 'update'], true) || !preg_match('/^[A-Z0-9]{2,15}$/', $coin)
            || !preg_match('/(?<![A-Za-z0-9])' . preg_quote($coin, '/') . '(?![A-Za-z0-9])/i', $text)) {
            return null;
        }
        $symbol = $coin . 'USDT';
        if ($type === 'update') {
            $a = (string)($r['action'] ?? 'none');
            return in_array($a, ['close', 'breakeven'], true) ? ['type' => 'update', 'update' => ['symbol' => $symbol, 'action' => $a]] : null;
        }
        preg_match_all('/\d+(?:[.,]\d+)?/', $text, $m);
        $nums = array_map(fn($x) => (float)str_replace(',', '.', $x), $m[0]);
        $present = function (float $v) use ($nums): bool {
            foreach ($nums as $n) {
                if (abs($n - $v) <= 1e-9 + abs($v) * 1e-9) {
                    return true;
                }
            }
            return false;
        };
        $side = $r['side'] ?? '';
        $targets = array_values(array_map('floatval', (array)($r['targets'] ?? [])));
        $vals = [(float)($r['entry_lo'] ?? 0), (float)($r['entry_hi'] ?? 0), (float)($r['stop'] ?? 0), ...$targets];
        if (!in_array($side, ['Buy', 'Sell'], true)) {
            return null;
        }
        foreach ($vals as $v) {
            if ($v <= 0 || !$present($v)) {
                return ['type' => 'signal', 'error' => 'ИИ вернул число, которого нет в тексте (' . $v . ') — отклонено'];
            }
        }
        $s = ['symbol' => $symbol, 'side' => $side, 'entry_lo' => min($vals[0], $vals[1]), 'entry_hi' => max($vals[0], $vals[1]),
            'stop' => $vals[2], 'targets' => $targets, 'leverage' => null];
        return ['type' => 'signal', 'signal' => $s];
    }

    private static function touch(int $id): void
    {
        DB::q('UPDATE signal_channels SET posts_seen = posts_seen + 1, last_post_at = ? WHERE id = ?', [DB::now(), $id]);
    }

    /**
     * Общий вход для любого источника: посчитать пост, разобрать, проверить, положить в очередь.
     * @return array{status:string,id:?int,message:string} status = new|rejected|duplicate|update|ignored
     */
    public static function receive(array $ch, string $text, ?int $reply, string $source = ''): array
    {
        $say = fn(string $t) => $reply ? Telegram::send($reply, $t) : null;
        self::touch((int)$ch['id']);
        if (!(int)$ch['enabled']) {
            $say('⏸ Канал «' . htmlspecialchars($ch['name']) . '» выключен в админке — ничего не открыто.');
            return ['status' => 'ignored', 'id' => null, 'message' => 'канал выключен'];
        }
        $cfg = self::parserConfig($ch);
        $base = ['channel_id' => (int)$ch['id'], 'raw_text' => mb_substr($text, 0, 2000), 'source' => $source !== '' ? $source : 'channel:' . $ch['source_key'],
            'reply_chat' => $reply, 'created_at' => DB::now()];
        $s = SignalParser::parse($text, $cfg);
        $u = null;
        $viaAi = false;
        if ($s === null) {
            $u = SignalParser::parseUpdate($text, $cfg);
            if ($u === null && !empty($cfg['ai'])) {
                $ai = self::aiParse($text);
                if ($ai && isset($ai['signal'])) {
                    $s = $ai['signal'];
                    $viaAi = true;
                } elseif ($ai && isset($ai['update'])) {
                    $u = $ai['update'];
                    $viaAi = true;
                } elseif ($ai && isset($ai['error'])) {
                    DB::insert('signals', $base + ['symbol' => '?', 'side' => '', 'entry_lo' => 0, 'entry_hi' => 0, 'stop_loss' => 0, 'targets' => [],
                        'status' => 'rejected', 'summary' => $ai['error']]);
                    $say('⚠️ ' . $ai['error']);
                    return ['status' => 'rejected', 'id' => null, 'message' => $ai['error']];
                }
            }
        }
        if ($viaAi) {
            $base['source'] = mb_substr($base['source'], 0, 60) . '+ai';
        }
        if ($s === null) {
            if ($u !== null) {
                $id = DB::insert('signals', $base + ['symbol' => $u['symbol'], 'side' => '', 'entry_lo' => 0, 'entry_hi' => 0, 'stop_loss' => 0, 'targets' => [],
                    'kind' => 'update', 'action' => $u['action'], 'status' => 'new']);
                $say("📝 Обновление #$id по {$u['symbol']}: " . ($u['action'] === 'close' ? 'закрыть' : 'стоп в безубыток') . '. Применяю к сделкам этого канала.');
                return ['status' => 'update', 'id' => $id, 'message' => $u['action']];
            }
            $say('❔ Не распознал сигнал: нужны #МОНЕТА/USDT, ЛОНГ/ШОРТ, диапазон входа, цели и СТОП ЛОСС. Ничего не открыто. '
                . 'Слова канала настраиваются в админке (меню «Сигналы»).');
            if ($reply) {
                DB::insert('signals', $base + ['symbol' => '?', 'side' => '', 'entry_lo' => 0, 'entry_hi' => 0, 'stop_loss' => 0, 'targets' => [],
                    'status' => 'ignored', 'summary' => 'не распознан']);
            }
            return ['status' => 'ignored', 'id' => null, 'message' => 'не распознан'];
        }
        $row = $base + ['symbol' => $s['symbol'], 'side' => $s['side'], 'entry_lo' => $s['entry_lo'], 'entry_hi' => $s['entry_hi'],
            'stop_loss' => $s['stop'], 'targets' => $s['targets'], 'channel_leverage' => $s['leverage']];
        $error = SignalParser::validate($s);
        if ($error !== null) {
            $id = DB::insert('signals', $row + ['status' => 'rejected', 'summary' => $error]);
            $say("⚠️ Сигнал {$s['symbol']} отклонён: $error. Ничего не открыто.");
            return ['status' => 'rejected', 'id' => $id, 'message' => $error];
        }
        // Дублей по времени нет: повторный сигнал по монете исполняется, если у клиента нет позиции/ордера по ней (проверяет Worker::signalOrder)
        $id = DB::insert('signals', $row + ['status' => 'new']);
        $say("📡 Сигнал #$id принят: {$s['symbol']} " . ($s['side'] === 'Buy' ? 'LONG' : 'SHORT') . ", вход {$s['entry_lo']}–{$s['entry_hi']}, стоп {$s['stop']}, целей "
            . count($s['targets']) . ". Исполняю клиентам, отчёт пришлю сюда.");
        return ['status' => 'new', 'id' => $id, 'message' => 'принят'];
    }

    /** Токен внешнего ридера (userbot/скрипт): производный от SECRET_KEY, сбрасывается счётчиком signal_ingest_rot. */
    public static function ingestToken(): string
    {
        return substr(hash_hmac('sha256', 'signal-ingest:' . (int)Settings::get('signal_ingest_rot'), (string)Env::get('SECRET_KEY', 'dev')), 0, 40);
    }

    /** Внешний приём: канал определяется по channel_id (ext:<id>), неизвестный создаётся выключенным. */
    public static function ingest(string $channelId, string $channelName, string $text): array
    {
        $key = str_starts_with($channelId, '-100') || $channelId === self::MANUAL_KEY || str_starts_with($channelId, 'ext:') ? $channelId : 'ext:' . $channelId;
        $ch = self::channelByKey($key) ?? self::ensureChannel($key, $channelName !== '' ? $channelName : $key, false, 'ingest');
        return self::receive($ch, $text, null, 'ingest:' . $key);
    }
}
