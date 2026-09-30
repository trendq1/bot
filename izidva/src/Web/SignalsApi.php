<?php
declare(strict_types=1);

namespace App\Web;

use App\DB;
use App\Engine\TradeStats;
use App\Env;
use App\Settings;
use App\SignalParser;
use App\Signals;

/** Меню «Сигналы» в админке: каналы, админы, примеры постов, проверка разбора, журнал. Только для роли admin. */
final class SignalsApi
{
    private const WORD_KEYS = ['long_words', 'short_words', 'entry_words', 'target_words', 'stop_words', 'close_words', 'be_words'];

    public static function handle(string $m, string $path, array $b, string $actor): mixed
    {
        if ($m === 'GET' && $path === '/signals/overview') {
            return self::overview();
        }
        if ($m === 'POST' && $path === '/signals/channels') {
            return self::channelSave($b, $actor);
        }
        if ($m === 'DELETE' && preg_match('#^/signals/channels/(\d+)$#', $path, $mm)) {
            DB::q('DELETE FROM signal_channels WHERE id = ?', [(int)$mm[1]]);
            AdminApi::audit($actor, 'signals.channel_delete', $mm[1]);
            return ['ok' => true];
        }
        if ($m === 'POST' && $path === '/signals/admins') {
            return self::adminAdd($b, $actor);
        }
        if ($m === 'PUT' && preg_match('#^/signals/admins/(\d+)$#', $path, $mm)) {
            DB::update('signal_admins', ['enabled' => empty($b['enabled']) ? 0 : 1], 'id = :id', [':id' => (int)$mm[1]]);
            AdminApi::audit($actor, 'signals.admin_toggle', $mm[1]);
            return ['ok' => true];
        }
        if ($m === 'DELETE' && preg_match('#^/signals/admins/(\d+)$#', $path, $mm)) {
            DB::q('DELETE FROM signal_admins WHERE id = ?', [(int)$mm[1]]);
            AdminApi::audit($actor, 'signals.admin_delete', $mm[1]);
            return ['ok' => true];
        }
        if ($m === 'POST' && $path === '/signals/examples') {
            return self::exampleAdd($b, $actor);
        }
        if ($m === 'DELETE' && preg_match('#^/signals/examples/(\d+)$#', $path, $mm)) {
            DB::q('DELETE FROM signal_examples WHERE id = ?', [(int)$mm[1]]);
            return ['ok' => true];
        }
        if ($m === 'POST' && $path === '/signals/parse') {
            return self::playground($b);
        }
        if ($m === 'POST' && $path === '/signals/settings') {
            return self::settings($b, $actor);
        }
        if ($m === 'POST' && $path === '/signals/token/rotate') {
            Settings::save(['signal_ingest_rot' => (int)Settings::get('signal_ingest_rot') + 1]);
            AdminApi::audit($actor, 'signals.token_rotate');
            return ['ok' => true, 'token' => Signals::ingestToken()];
        }
        Api::fail(404, 'Не найдено');
    }

    /** Разобрать текст настройками канала (или черновиком настроек из формы) и показать, что увидит бот. */
    public static function analyze(string $text, array $cfg): array
    {
        $sig = SignalParser::parse($text, $cfg);
        if ($sig !== null) {
            $err = SignalParser::validate($sig);
            return ['type' => 'signal', 'ok' => $err === null, 'error' => $err, 'signal' => $sig];
        }
        $upd = SignalParser::parseUpdate($text, $cfg);
        if ($upd !== null) {
            return ['type' => 'update', 'ok' => true, 'error' => null, 'update' => $upd];
        }
        if (!empty($cfg['ai'])) {
            $ai = Signals::aiParse($text);
            if ($ai && isset($ai['signal'])) {
                $err = SignalParser::validate($ai['signal']);
                return ['type' => 'signal', 'ok' => $err === null, 'error' => $err, 'signal' => $ai['signal'], 'via' => 'ai'];
            }
            if ($ai && isset($ai['update'])) {
                return ['type' => 'update', 'ok' => true, 'error' => null, 'update' => $ai['update'], 'via' => 'ai'];
            }
            if ($ai && isset($ai['error'])) {
                return ['type' => 'none', 'ok' => false, 'error' => $ai['error'], 'symbol' => null, 'via' => 'ai'];
            }
        }
        return ['type' => 'none', 'ok' => false, 'error' => 'не похоже ни на сигнал, ни на обновление', 'symbol' => SignalParser::findSymbol($text, $cfg)];
    }

    private static function cleanConfig(mixed $raw): array
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach (self::WORD_KEYS as $k) {
            $v = trim((string)($raw[$k] ?? ''));
            if ($v !== '') {
                $out[$k] = mb_substr($v, 0, 300);
            }
        }
        if (!empty($raw['ai'])) {
            $out['ai'] = 1;
        }
        if (in_array($raw['symbol_style'] ?? '', ['hash', 'any'], true) && $raw['symbol_style'] !== 'hash') {
            $out['symbol_style'] = 'any';
        }
        return $out;
    }

    private static function playground(array $b): array
    {
        $text = trim((string)($b['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 4000) {
            Api::fail(422, 'Вставьте текст поста (до 4000 символов)');
        }
        if (isset($b['parser']) && is_array($b['parser'])) {
            $cfg = self::cleanConfig($b['parser']);
        } else {
            $ch = !empty($b['channel_id']) ? DB::row('SELECT * FROM signal_channels WHERE id = ?', [(int)$b['channel_id']]) : null;
            $cfg = Signals::parserConfig($ch);
        }
        return self::analyze($text, $cfg);
    }

    private static function channelSave(array $b, string $actor): array
    {
        $name = Api::str($b, 'name', 1, 120);
        $key = trim((string)($b['source_key'] ?? ''));
        if (!preg_match('/^(-?\d{5,20}|manual|ext:[A-Za-z0-9_.\-]{1,40})$/', $key)) {
            Api::fail(422, 'Ключ канала: id вида -1001234567890, «manual» или «ext:имя»');
        }
        $mode = ($b['mode'] ?? 'demo') === 'real' ? 'real' : 'demo';
        $risk = (float)($b['risk_mult'] ?? 1);
        if ($risk < 0.1 || $risk > 3) {
            Api::fail(422, 'Множитель риска — от 0.1 до 3');
        }
        $chase = ($b['max_chase_pct'] ?? '') === '' || $b['max_chase_pct'] === null ? null : (float)$b['max_chase_pct'];
        if ($chase !== null && ($chase < 0 || $chase > 5)) {
            Api::fail(422, 'Допуск ухода цены — от 0 до 5%');
        }
        $data = ['name' => $name, 'source_key' => $key, 'enabled' => empty($b['enabled']) ? 0 : 1, 'mode' => $mode, 'risk_mult' => $risk,
            'max_chase_pct' => $chase, 'parser_config' => json_encode(self::cleanConfig($b['parser'] ?? []) ?: new \stdClass(), JSON_UNESCAPED_UNICODE),
            'notes' => mb_substr((string)($b['notes'] ?? ''), 0, 1000)];
        $id = (int)($b['id'] ?? 0);
        $dupe = DB::val('SELECT id FROM signal_channels WHERE source_key = ? AND id <> ?', [$key, $id]);
        if ($dupe) {
            Api::fail(422, 'Канал с таким ключом уже есть');
        }
        if ($id > 0) {
            DB::update('signal_channels', $data, 'id = :id', [':id' => $id]);
        } else {
            $id = DB::insert('signal_channels', $data + ['created_at' => DB::now(), 'created_by' => $actor]);
        }
        AdminApi::audit($actor, 'signals.channel_save', "$id $key enabled={$data['enabled']} mode=$mode");
        return ['ok' => true, 'id' => $id];
    }

    private static function adminAdd(array $b, string $actor): array
    {
        $tg = (string)($b['tg_id'] ?? '');
        if (!preg_match('/^\d{4,15}$/', $tg)) {
            Api::fail(422, 'Telegram ID — число (человек узнаёт его командой /id у бота)');
        }
        if (DB::val('SELECT 1 FROM signal_admins WHERE tg_id = ?', [(int)$tg])) {
            Api::fail(422, 'Такой админ уже добавлен');
        }
        $id = DB::insert('signal_admins', ['tg_id' => (int)$tg, 'name' => mb_substr(trim((string)($b['name'] ?? '')), 0, 120), 'enabled' => 1, 'created_at' => DB::now()]);
        AdminApi::audit($actor, 'signals.admin_add', $tg);
        return ['ok' => true, 'id' => $id];
    }

    private static function exampleAdd(array $b, string $actor): array
    {
        $ch = DB::row('SELECT id FROM signal_channels WHERE id = ?', [(int)($b['channel_id'] ?? 0)]);
        $kind = (string)($b['kind'] ?? 'signal');
        $text = trim((string)($b['text'] ?? ''));
        if (!$ch || !in_array($kind, ['signal', 'update', 'noise'], true) || $text === '' || mb_strlen($text) > 4000) {
            Api::fail(422, 'Нужны канал, тип (signal/update/noise) и текст поста');
        }
        $id = DB::insert('signal_examples', ['channel_id' => (int)$ch['id'], 'kind' => $kind, 'text' => $text, 'created_at' => DB::now()]);
        AdminApi::audit($actor, 'signals.example_add', "channel {$ch['id']} $kind");
        return ['ok' => true, 'id' => $id];
    }

    private static function settings(array $b, string $actor): array
    {
        $changes = [];
        foreach (['signal_enabled', 'signal_real_enabled', 'signal_max_chase_pct'] as $k) {
            if (array_key_exists($k, $b)) {
                $changes[$k] = Settings::coerce($k, $b[$k]);
            }
        }
        if (isset($changes['signal_max_chase_pct']) && ($changes['signal_max_chase_pct'] < 0 || $changes['signal_max_chase_pct'] > 5)) {
            Api::fail(422, 'Допуск ухода цены — от 0 до 5%');
        }
        Settings::save($changes);
        AdminApi::audit($actor, 'signals.settings', json_encode($changes));
        return ['ok' => true];
    }

    private static function stats(): array
    {
        $rows = DB::all("SELECT s.channel_id, t.pnl FROM trades t JOIN signals s ON t.session_id = CONCAT('sig', s.id) WHERE s.channel_id IS NOT NULL ORDER BY t.closed_at, t.id");
        $by = [];
        foreach ($rows as $r) {
            $by[(int)$r['channel_id']][] = (float)$r['pnl'];
        }
        $out = [];
        foreach ($by as $cid => $pnls) {
            $c = TradeStats::core($pnls);
            $out[$cid] = ['trades' => $c['trades'], 'net_pnl' => $c['net_pnl'], 'win_rate_pct' => $c['win_rate_pct'], 'profit_factor' => $c['profit_factor']];
        }
        return $out;
    }

    private static function overview(): array
    {
        $stats = self::stats();
        $counts = [];
        foreach (DB::all("SELECT channel_id, status, COUNT(*) c FROM signals WHERE channel_id IS NOT NULL AND kind = 'signal' GROUP BY channel_id, status") as $r) {
            $counts[(int)$r['channel_id']][$r['status']] = (int)$r['c'];
        }
        $examples = [];
        foreach (DB::all('SELECT * FROM signal_examples ORDER BY id') as $e) {
            $examples[(int)$e['channel_id']][] = $e;
        }
        $channels = [];
        foreach (DB::all('SELECT * FROM signal_channels ORDER BY enabled DESC, id') as $c) {
            $cid = (int)$c['id'];
            $cfg = Signals::parserConfig($c);
            $ex = [];
            foreach ($examples[$cid] ?? [] as $e) {
                $r = self::analyze($e['text'], $cfg);
                $ok = match ($e['kind']) { 'signal' => $r['type'] === 'signal' && $r['ok'], 'update' => $r['type'] === 'update', default => $r['type'] === 'none' };
                $ex[] = ['id' => (int)$e['id'], 'kind' => $e['kind'], 'text' => $e['text'], 'pass' => $ok, 'result' => $r];
            }
            $channels[] = ['id' => $cid, 'name' => $c['name'], 'source_key' => $c['source_key'], 'enabled' => (bool)$c['enabled'], 'mode' => $c['mode'],
                'risk_mult' => (float)$c['risk_mult'], 'max_chase_pct' => $c['max_chase_pct'] === null ? null : (float)$c['max_chase_pct'],
                'parser' => (object)$cfg, 'notes' => (string)$c['notes'], 'posts_seen' => (int)$c['posts_seen'], 'last_post_at' => Api::iso($c['last_post_at']),
                'created_by' => $c['created_by'], 'pending' => !(int)$c['enabled'] && in_array($c['created_by'], ['auto', 'forward', 'ingest'], true),
                'counts' => (object)($counts[$cid] ?? []), 'stats' => $stats[$cid] ?? ['trades' => 0, 'net_pnl' => 0.0, 'win_rate_pct' => 0.0, 'profit_factor' => null],
                'examples' => $ex];
        }
        $admins = array_map(fn($a) => ['id' => (int)$a['id'], 'tg_id' => (string)$a['tg_id'], 'name' => $a['name'], 'enabled' => (bool)$a['enabled']], DB::all('SELECT * FROM signal_admins ORDER BY id'));
        $names = array_column(DB::all('SELECT id, name FROM signal_channels'), 'name', 'id');
        $recent = array_map(fn($s) => ['id' => (int)$s['id'], 'kind' => $s['kind'], 'action' => $s['action'], 'channel' => $names[$s['channel_id'] ?? 0] ?? '—',
            'symbol' => $s['symbol'], 'side' => $s['side'], 'entry' => $s['entry_lo'] . '–' . $s['entry_hi'], 'stop' => (float)$s['stop_loss'],
            'targets' => json_decode((string)$s['targets'], true) ?: [], 'status' => $s['status'], 'summary' => $s['summary'], 'text' => $s['raw_text'],
            'at' => Api::iso($s['created_at'])], DB::all('SELECT * FROM signals ORDER BY id DESC LIMIT 40'));
        return [
            'settings' => ['signal_enabled' => (bool)Settings::get('signal_enabled'), 'signal_real_enabled' => (bool)Settings::get('signal_real_enabled'),
                'signal_max_chase_pct' => (float)Settings::get('signal_max_chase_pct')],
            'legacy_ids' => array_values(array_map('strval', (array)Settings::get('signal_allowed_ids'))),
            'ingest' => ['path' => '/tg/signal-ingest.php', 'token' => Signals::ingestToken()],
            'defaults' => SignalParser::DEFAULTS,
            'channels' => $channels, 'admins' => $admins, 'recent' => $recent,
        ];
    }
}
