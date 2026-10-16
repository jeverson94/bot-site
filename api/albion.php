<?php
// Proxy da API pública do Albion (gameinfo + serverstatus).
// A API oficial não libera CORS pro navegador, então o site chama este arquivo,
// que só repassa rotas fixas, valida os parâmetros e guarda cache em disco.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

const HOSTS = [
    'am' => 'https://gameinfo.albiononline.com/api/gameinfo',
    'eu' => 'https://gameinfo-ams.albiononline.com/api/gameinfo',
    'as' => 'https://gameinfo-sgp.albiononline.com/api/gameinfo',
];
const STATUS_HOSTS = [
    'am' => 'http://serverstatus.albiononline.com/',
    'eu' => 'http://serverstatus-ams.albiononline.com/',
    'as' => 'http://serverstatus-sgp.albiononline.com/',
];

function cache_dir() {
    $d = __DIR__ . '/cache';
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
        @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $d;
}

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['erro' => $msg]);
    exit;
}

function cache_get($key, $ttl) {
    $f = cache_dir() . '/' . md5($key) . '.json';
    if (is_file($f) && (time() - filemtime($f)) < $ttl) return file_get_contents($f);
    return null;
}

function cache_put($key, $body) {
    $f = cache_dir() . '/' . md5($key) . '.json';
    @file_put_contents($f, $body, LOCK_EX);
}

function curl_handle($url, $timeout) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'EmporioDosBots/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    return $ch;
}

// Busca várias URLs em paralelo. Devolve [url => corpo|null].
function fetch_many($urls, $ttl, $timeout = 25) {
    $out = [];
    $pending = [];
    foreach ($urls as $u) {
        $c = cache_get($u, $ttl);
        if ($c === null && empty($GLOBALS['no_stale'])) {
            // Resposta vencida mas recente: entrega já e atualiza depois que a página receber a resposta.
            $c = cache_get($u, 21600);
            if ($c !== null) $GLOBALS['refresh'][$u] = $ttl;
        }
        if ($c !== null) $out[$u] = $c; else $pending[] = $u;
    }
    foreach (array_chunk($pending, 16) as $chunk) {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($chunk as $u) {
            $hs[$u] = curl_handle($u, $timeout);
            curl_multi_add_handle($mh, $hs[$u]);
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $st == CURLM_OK);
        foreach ($hs as $u => $h) {
            $body = curl_multi_getcontent($h);
            $code = curl_getinfo($h, CURLINFO_HTTP_CODE);
            if ($code == 200 && $body !== '' && json_decode($body) !== null) {
                cache_put($u, $body);
                $out[$u] = $body;
            } else {
                $out[$u] = null;
            }
            curl_multi_remove_handle($mh, $h);
            curl_close($h);
        }
        curl_multi_close($mh);
    }
    return $out;
}

register_shutdown_function(function () {
    if (empty($GLOBALS['refresh'])) return;
    $GLOBALS['no_stale'] = true;
    @ignore_user_abort(true);
    @set_time_limit(90);
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    fetch_many(array_keys($GLOBALS['refresh']), 0);
});

function fetch_one($url, $ttl) {
    $r = fetch_many([$url], $ttl);
    return $r[$url];
}

function param_region() {
    $s = $_GET['s'] ?? 'am';
    if (!isset(HOSTS[$s])) fail(400, 'servidor inválido');
    return $s;
}

function param_id($name, $pattern) {
    $v = $_GET[$name] ?? '';
    if (!preg_match($pattern, $v)) fail(400, "parâmetro $name inválido");
    return $v;
}

function param_int($name, $def, $min, $max) {
    $v = isset($_GET[$name]) ? intval($_GET[$name]) : $def;
    return max($min, min($max, $v));
}

// Lista de batalhas recentes em formato compacto, usada pelo ranking,
// pelo quadro de guerras e pela tierlist.
function recent_battles($s, $pages) {
    // Com o coletor rodando, as batalhas já estão no banco e a resposta é imediata.
    try { $fromdb = recent_from_db($s, $pages * 50); } catch (Throwable $e) { $fromdb = null; }
    if ($fromdb) return $fromdb;
    $key = "recent:$s:$pages";
    $hit = cache_get($key, 900);
    if ($hit !== null) return json_decode($hit, true);
    $urls = [];
    for ($i = 0; $i < $pages; $i++) {
        $urls[] = HOSTS[$s] . '/battles?limit=50&offset=' . ($i * 50) . '&sort=recent';
    }
    $res = fetch_many($urls, 900);
    $list = [];
    $seen = [];
    foreach ($urls as $u) {
        $arr = $res[$u] ? json_decode($res[$u], true) : null;
        if (!is_array($arr)) continue;
        foreach ($arr as $b) {
            if (isset($seen[$b['id']])) continue;
            $seen[$b['id']] = 1;
            $guilds = [];
            foreach (($b['players'] ?? []) as $p) {
                $gid = $p['guildId'] ?? '';
                if ($gid === '') continue;
                if (!isset($guilds[$gid])) {
                    $guilds[$gid] = ['id' => $gid, 'n' => $p['guildName'] ?? '', 'a' => $p['allianceName'] ?? '', 'ai' => $p['allianceId'] ?? '', 'p' => 0, 'k' => 0, 'd' => 0, 'f' => 0];
                }
                $guilds[$gid]['p']++;
                $guilds[$gid]['k'] += $p['kills'] ?? 0;
                $guilds[$gid]['d'] += $p['deaths'] ?? 0;
                $guilds[$gid]['f'] += $p['killFame'] ?? 0;
            }
            $list[] = [
                'id' => $b['id'],
                't' => $b['startTime'],
                'p' => count($b['players'] ?? []),
                'k' => $b['totalKills'] ?? 0,
                'f' => $b['totalFame'] ?? 0,
                'g' => array_values($guilds),
            ];
        }
    }
    if (!$list) fail(502, 'a API do Albion não respondeu');
    cache_put($key, json_encode($list));
    return $list;
}

function battle_events($s, $id) {
    $all = battles_events($s, [$id]);
    return $all[$id];
}

// Eventos (mortes) de várias batalhas, buscando as páginas de todas em paralelo.
function battles_events($s, $ids) {
    $events = [];
    $open = [];
    foreach ($ids as $id) { $events[$id] = []; $open[$id] = true; }
    for ($start = 0; $start < 20 && $open; $start += 3) {
        $urls = [];
        foreach (array_keys($open) as $id) {
            for ($i = $start; $i < $start + 3; $i++) {
                $urls[$id][] = HOSTS[$s] . "/events/battle/$id?offset=" . ($i * 51) . '&limit=51';
            }
        }
        $flat = [];
        foreach ($urls as $list) foreach ($list as $u) $flat[] = $u;
        $res = fetch_many($flat, 1800);
        foreach ($urls as $id => $list) {
            foreach ($list as $u) {
                $arr = $res[$u] ? json_decode($res[$u], true) : null;
                if (!is_array($arr)) { unset($open[$id]); break; }
                foreach ($arr as $e) $events[$id][$e['EventId']] = $e;
                if (count($arr) < 51) { unset($open[$id]); break; }
            }
        }
    }
    foreach ($events as $id => $evs) { ksort($evs); $events[$id] = array_values($evs); }
    return $events;
}

function slim_equipment($eq) {
    $out = [];
    foreach (['MainHand', 'OffHand', 'Head', 'Armor', 'Shoes', 'Cape', 'Mount', 'Bag', 'Potion', 'Food'] as $k) {
        if (!empty($eq[$k]['Type'])) $out[$k] = $eq[$k]['Type'];
    }
    return $out;
}

function slim_player($p) {
    return [
        'id' => $p['Id'] ?? '',
        'n' => $p['Name'] ?? '',
        'g' => $p['GuildName'] ?? '',
        'a' => $p['AllianceName'] ?? '',
        'ip' => round($p['AverageItemPower'] ?? 0),
        'eq' => slim_equipment($p['Equipment'] ?? []),
    ];
}

function status_log_update($now) {
    $f = cache_dir() . '/status_log.json';
    $log = is_file($f) ? json_decode(file_get_contents($f), true) : null;
    if (!is_array($log)) $log = ['last' => [], 'events' => []];
    foreach ($now as $reg => $st) {
        // "Sem resposta" não muda o estado registrado: só online, offline e starting contam.
        if ($st['status'] === 'unreachable') continue;
        $prev = $log['last'][$reg]['status'] ?? null;
        if ($prev !== $st['status']) {
            $log['events'][] = ['r' => $reg, 's' => $st['status'], 't' => $st['checked']];
        }
        $log['last'][$reg] = $st;
    }
    $log['events'] = array_slice($log['events'], -600);
    @file_put_contents($f, json_encode($log), LOCK_EX);
    return $log;
}

require __DIR__ . '/coleta.php';

$r = $_GET['r'] ?? '';

switch ($r) {
    case 'dir': {
        $s = param_region();
        $days = in_array((int)($_GET['days'] ?? 7), [1, 7, 28]) ? (int)$_GET['days'] : 7;
        $o = [
            'days' => $days,
            'min' => param_int('min', 5, 5, 1000),
            'max' => param_int('max', 100000, 5, 100000),
            'h0' => param_int('h0', 0, 0, 23),
            'h1' => param_int('h1', 23, 0, 23),
            'q' => mb_substr((string)($_GET['q'] ?? ''), 0, 40),
            'sort' => in_array($_GET['sort'] ?? 'b', ['b', 'f', 'kd', 'm', 'k', 'avg']) ? $_GET['sort'] : 'b',
            'page' => param_int('page', 1, 1, 999),
            'per' => 24,
        ];
        cached_or_build('dir:' . $s . ':' . json_encode($o), 120, function () use ($s, $o) { return guild_directory($s, $o); }, $s);
        break;
    }
    case 'lista': {
        $s = param_region();
        $off = param_int('offset', 0, 0, 100000);
        $min = param_int('min', 5, 5, 1000);
        $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 40);
        cached_or_build("lista:$s:$off:$min:" . mb_strtolower($q), 60, function () use ($s, $off, $min, $q) { return battle_list($s, $off, $min, $q); }, $s);
        break;
    }
    case 'guilda': {
        $s = param_region();
        $gid = param_id('id', '/^[A-Za-z0-9_-]{6,40}$/');
        $days = in_array((int)($_GET['days'] ?? 28), [7, 28]) ? (int)$_GET['days'] : 28;
        cached_or_build("guilda:$s:$gid:$days", 180, function () use ($s, $gid, $days) { return guild_profile($s, $gid, $days); }, $s);
        break;
    }
    case 'coletar': {
        // Chamado pelo agendador a cada poucos minutos; também roda sozinho quando alguém abre as páginas.
        $s = param_region();
        $force = !empty($_GET['forcar']);
        if (!empty($_GET['esperar'])) { echo json_encode(collect($s, $force)); break; }
        // Responde na hora e coleta depois, para o tempo limite do servidor web não cortar a coleta.
        respond_then(json_encode(['iniciado' => true]), function () use ($s, $force) { collect($s, $force); });
        break;
    }
    case 'saude': {
        $out = ['sqlite' => class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers()),
            'finish' => function_exists('litespeed_finish_request') ? 'litespeed' : (function_exists('fastcgi_finish_request') ? 'fastcgi' : 'nenhum'),
            'php' => PHP_VERSION, 'limite' => ini_get('max_execution_time')];
        if ($out['sqlite']) foreach (array_keys(HOSTS) as $reg) {
            $pdo = db($reg);
            $out[$reg] = ['batalhas' => (int)$pdo->query('SELECT COUNT(*) FROM battles')->fetchColumn(),
                'desde' => (int)meta_get($pdo, 'since', 0), 'ultima' => (int)meta_get($pdo, 'last_ok', 0),
                'historico' => (int)meta_get($pdo, 'backfill_off', 0), 'etapa' => meta_get($pdo, 'etapa', '')];
        }
        echo json_encode($out);
        break;
    }
    case 'battles': {
        $s = param_region();
        $q = [
            'limit' => param_int('limit', 50, 1, 51),
            'offset' => param_int('offset', 0, 0, 9900),
            'sort' => in_array($_GET['sort'] ?? '', ['recent', 'totalfame', 'totalkills']) ? $_GET['sort'] : 'recent',
        ];
        if (!empty($_GET['guildId'])) $q['guildId'] = param_id('guildId', '/^[A-Za-z0-9_-]{6,40}$/');
        if (!empty($_GET['allianceId'])) $q['allianceId'] = param_id('allianceId', '/^[A-Za-z0-9_-]{6,40}$/');
        $body = fetch_one(HOSTS[$s] . '/battles?' . http_build_query($q), 120);
        if ($body === null) fail(502, 'a API do Albion não respondeu');
        echo $body;
        break;
    }
    case 'battle': {
        $s = param_region();
        $id = param_id('id', '/^\d{1,15}$/');
        $key = "battlefull:$s:$id";
        $hit = cache_get($key, 600);
        if ($hit !== null) { echo $hit; break; }
        $b = fetch_one(HOSTS[$s] . "/battles/$id", 600);
        if ($b === null) fail(404, 'batalha não encontrada');
        $events = [];
        foreach (battle_events($s, $id) as $e) {
            $events[] = [
                'id' => $e['EventId'],
                't' => $e['TimeStamp'],
                'f' => $e['TotalVictimKillFame'] ?? 0,
                'k' => slim_player($e['Killer']),
                'v' => slim_player($e['Victim']),
                'n' => $e['numberOfParticipants'] ?? count($e['Participants'] ?? []),
            ];
        }
        $out = json_encode(['battle' => json_decode($b, true), 'events' => $events]);
        cache_put($key, $out);
        echo $out;
        break;
    }
    case 'search': {
        $s = param_region();
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2 || mb_strlen($q) > 40) fail(400, 'busca muito curta');
        $body = fetch_one(HOSTS[$s] . '/search?q=' . rawurlencode($q), 600);
        if ($body === null) fail(502, 'a API do Albion não respondeu');
        echo $body;
        break;
    }
    case 'guild': {
        $s = param_region();
        $id = param_id('id', '/^[A-Za-z0-9_-]{6,40}$/');
        $body = fetch_one(HOSTS[$s] . "/guilds/$id", 1800);
        if ($body === null) fail(404, 'guilda não encontrada');
        echo $body;
        break;
    }
    case 'alliance': {
        $s = param_region();
        $id = param_id('id', '/^[A-Za-z0-9_-]{6,40}$/');
        $body = fetch_one(HOSTS[$s] . "/alliances/$id", 1800);
        if ($body === null) fail(404, 'aliança não encontrada');
        echo $body;
        break;
    }
    case 'members': {
        $s = param_region();
        $id = param_id('id', '/^[A-Za-z0-9_-]{6,40}$/');
        $body = fetch_one(HOSTS[$s] . "/guilds/$id/members", 1800);
        if ($body === null) fail(404, 'guilda não encontrada');
        $out = [];
        foreach (json_decode($body, true) ?: [] as $m) {
            $lt = $m['LifetimeStatistics'] ?? [];
            $out[] = ['id' => $m['Id'], 'n' => $m['Name'], 'kf' => $m['KillFame'] ?? 0, 'df' => $m['DeathFame'] ?? 0, 'pve' => $lt['PvE']['Total'] ?? 0];
        }
        echo json_encode($out);
        break;
    }
    case 'player': {
        $s = param_region();
        $id = param_id('id', '/^[A-Za-z0-9_-]{6,40}$/');
        $urls = [HOSTS[$s] . "/players/$id", HOSTS[$s] . "/players/$id/kills", HOSTS[$s] . "/players/$id/deaths"];
        $res = fetch_many($urls, 600);
        if ($res[$urls[0]] === null) fail(404, 'jogador não encontrado');
        $slim = function ($list, $who) {
            $o = [];
            foreach (json_decode($list ?? '[]', true) ?: [] as $e) {
                $o[] = ['t' => $e['TimeStamp'], 'f' => $e['TotalVictimKillFame'] ?? 0, 'b' => $e['BattleId'] ?? null,
                        'k' => slim_player($e['Killer']), 'v' => slim_player($e['Victim'])];
            }
            return $o;
        };
        echo json_encode(['player' => json_decode($res[$urls[0]], true), 'kills' => $slim($res[$urls[1]], 'k'), 'deaths' => $slim($res[$urls[2]], 'v')]);
        break;
    }
    case 'recent': {
        $s = param_region();
        echo json_encode(recent_battles($s, 20));
        break;
    }
    case 'meta': {
        $s = param_region();
        $min = param_int('min', 30, 0, 500);
        $max = param_int('max', 10000, 1, 10000);
        $key = "meta:$s:$min:$max";
        $hit = cache_get($key, 3600);
        if ($hit !== null) { echo $hit; break; }
        @set_time_limit(120);
        $list = array_values(array_filter(recent_battles($s, 20), function ($b) use ($min, $max) {
            return $b['p'] >= $min && $b['p'] <= $max && $b['k'] > 0;
        }));
        usort($list, function ($a, $b) { return $b['k'] - $a['k']; });
        $list = array_slice($list, 0, 10);
        $all = battles_events($s, array_map(function ($b) { return $b['id']; }, $list));
        $items = [];
        $players = 0;
        foreach ($list as $b) {
            $seen = [];
            foreach ($all[$b['id']] as $e) {
                foreach (['Killer' => 'k', 'Victim' => 'd'] as $who => $field) {
                    $p = $e[$who];
                    $eq = slim_equipment($p['Equipment'] ?? []);
                    $pid = $p['Id'] ?? '';
                    foreach ($eq as $slot => $type) {
                        if (in_array($slot, ['Bag', 'Potion', 'Food'])) continue;
                        $base = preg_replace('/^T\d_|@\d+$/', '', $type);
                        if (!isset($items[$base])) $items[$base] = ['id' => $type, 'slot' => $slot, 'u' => 0, 'k' => 0, 'd' => 0];
                        $items[$base][$field]++;
                        if (!isset($seen[$pid . $slot])) {
                            $seen[$pid . $slot] = 1;
                            $items[$base]['u']++;
                        }
                    }
                    if (!isset($seen[$pid])) { $seen[$pid] = 1; $players++; }
                }
            }
        }
        $out = json_encode([
            'battles' => count($list),
            'players' => $players,
            'items' => array_values($items),
            'from' => $list ? end($list)['t'] : null,
            'generated' => gmdate('c'),
        ]);
        cache_put($key, $out);
        echo $out;
        break;
    }
    case 'status': {
        $key = 'status:all';
        $hit = cache_get($key, 60);
        if ($hit !== null) { echo $hit; break; }
        $now = [];
        $mh = curl_multi_init();
        $hs = [];
        foreach (STATUS_HOSTS as $reg => $u) {
            $hs[$reg] = curl_handle($u . '?t=' . time(), 10);
            curl_multi_add_handle($mh, $hs[$reg]);
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $st == CURLM_OK);
        foreach ($hs as $reg => $h) {
            $body = curl_multi_getcontent($h);
            $code = curl_getinfo($h, CURLINFO_HTTP_CODE);
            $ms = round(curl_getinfo($h, CURLINFO_TOTAL_TIME) * 1000);
            // O JSON do serverstatus às vezes vem com BOM ou espaços extras.
            $j = json_decode(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$body)), true);
            $st = is_array($j) && isset($j['status']) ? strtolower((string)$j['status']) : '';
            // Erros do Cloudflare (520 a 527) e respostas estranhas não são queda do jogo.
            if ($code != 200 || !in_array($st, ['online', 'offline', 'starting'])) {
                $now[$reg] = ['status' => 'unreachable', 'message' => $code ? "HTTP $code" : 'sem conexão', 'ms' => $ms, 'checked' => gmdate('c')];
            } else {
                $now[$reg] = ['status' => $st, 'message' => $j['message'] ?? '', 'ms' => $ms, 'checked' => gmdate('c')];
            }
            curl_multi_remove_handle($mh, $h);
            curl_close($h);
        }
        curl_multi_close($mh);
        $log = status_log_update($now);
        $out = json_encode(['now' => $now, 'events' => $log['events']]);
        cache_put($key, $out);
        echo $out;
        break;
    }
    default:
        fail(404, 'rota desconhecida');
}
