<?php
// Coletor de batalhas: guarda as batalhas do Albion num banco SQLite local,
// para as páginas lerem do banco em vez de esperar a API oficial (que é lenta).
// Incluído pelo albion.php, que já define HOSTS, cache_dir() e curl_handle().

const MIN_PLAYERS = 5;          // lutas menores ficam de fora
const KEEP_DAYS = 29;           // quanto histórico guardar
const MAX_OFFSET = 9900;        // a API não passa disso
const SCALES = [                // faixas de tamanho usadas nos filtros e selos
    'roaming' => [5, 14],
    'pequena' => [15, 29],
    'media' => [30, 49],
    'grande' => [50, 100000],
];

function db($s) {
    static $dbs = [];
    if (isset($dbs[$s])) return $dbs[$s];
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers())) fail(500, 'SQLite indisponível no servidor');
    $pdo = new PDO('sqlite:' . cache_dir() . "/batalhas_$s.sqlite");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=8000;');
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS battles (id INTEGER PRIMARY KEY, t INTEGER, p INTEGER, k INTEGER, f INTEGER)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS battles_t ON battles(t)');
    // Uma linha por guilda em cada batalha, já com o horário e o tamanho da luta para filtrar sem join.
    $pdo->exec('CREATE TABLE IF NOT EXISTS bg (b INTEGER, g TEXT, ai TEXT, p INTEGER, k INTEGER, d INTEGER, f INTEGER, t INTEGER, h INTEGER, bp INTEGER, PRIMARY KEY (b, g))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS bg_t ON bg(t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS bg_g ON bg(g, t)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS guilds (g TEXT PRIMARY KEY, n TEXT, a TEXT, ai TEXT, last INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS gp (g TEXT, pl TEXT, n TEXT, t INTEGER, b INTEGER, k INTEGER, d INTEGER, f INTEGER, PRIMARY KEY (g, pl))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS gp_t ON gp(t)');
    return $dbs[$s] = $pdo;
}

function meta_get($pdo, $k, $def = null) {
    $st = $pdo->prepare('SELECT v FROM meta WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $def : $v;
}

function meta_set($pdo, $k, $v) {
    $pdo->prepare('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, (string)$v]);
}

// Grava uma página de batalhas. Devolve [novas, menor horário, maior horário].
function ingest($pdo, $arr) {
    $new = 0;
    $tmin = PHP_INT_MAX;
    $tmax = 0;
    $exists = $pdo->prepare('SELECT 1 FROM battles WHERE id = ?');
    $ib = $pdo->prepare('INSERT INTO battles (id, t, p, k, f) VALUES (?, ?, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET p = excluded.p, k = excluded.k, f = excluded.f');
    $ig = $pdo->prepare('INSERT INTO bg (b, g, ai, p, k, d, f, t, h, bp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(b, g) DO UPDATE SET p = excluded.p, k = excluded.k, d = excluded.d, f = excluded.f, bp = excluded.bp');
    $gn = $pdo->prepare('INSERT INTO guilds (g, n, a, ai, last) VALUES (?, ?, ?, ?, ?) ON CONFLICT(g) DO UPDATE SET n = excluded.n, a = excluded.a, ai = excluded.ai, last = excluded.last WHERE excluded.last >= guilds.last');
    $gp = $pdo->prepare('INSERT INTO gp (g, pl, n, t, b, k, d, f) VALUES (?, ?, ?, ?, 1, ?, ?, ?) ON CONFLICT(g, pl) DO UPDATE SET n = excluded.n, t = MAX(gp.t, excluded.t), b = gp.b + 1, k = gp.k + excluded.k, d = gp.d + excluded.d, f = gp.f + excluded.f');
    $pdo->beginTransaction();
    foreach ($arr as $b) {
        $t = strtotime($b['startTime'] ?? '') ?: 0;
        if (!$t) continue;
        $tmin = min($tmin, $t);
        $tmax = max($tmax, $t);
        $players = $b['players'] ?? [];
        $np = count($players);
        if ($np < MIN_PLAYERS) continue;
        $exists->execute([$b['id']]);
        $isNew = !$exists->fetchColumn();
        $ib->execute([$b['id'], $t, $np, $b['totalKills'] ?? 0, $b['totalFame'] ?? 0]);
        $guilds = [];
        foreach ($players as $pid => $p) {
            $gid = $p['guildId'] ?? '';
            if ($gid === '') continue;
            if (!isset($guilds[$gid])) $guilds[$gid] = ['n' => $p['guildName'] ?? '', 'a' => $p['allianceName'] ?? '', 'ai' => $p['allianceId'] ?? '', 'p' => 0, 'k' => 0, 'd' => 0, 'f' => 0];
            $g = &$guilds[$gid];
            $g['p']++;
            $g['k'] += $p['kills'] ?? 0;
            $g['d'] += $p['deaths'] ?? 0;
            $g['f'] += $p['killFame'] ?? 0;
            unset($g);
            // Membros: só conta a batalha uma vez por jogador (a lista volta a aparecer enquanto a luta está aberta).
            if ($isNew) $gp->execute([$gid, $p['id'] ?? $pid, $p['name'] ?? '', $t, $p['kills'] ?? 0, $p['deaths'] ?? 0, $p['killFame'] ?? 0]);
        }
        $h = (int)gmdate('G', $t);
        foreach ($guilds as $gid => $g) {
            $ig->execute([$b['id'], $gid, $g['ai'], $g['p'], $g['k'], $g['d'], $g['f'], $t, $h, $np]);
            $gn->execute([$gid, $g['n'], $g['a'], $g['ai'], $t]);
        }
        if ($isNew) $new++;
    }
    $pdo->commit();
    return [$new, $tmin === PHP_INT_MAX ? 0 : $tmin, $tmax];
}

// Busca páginas da lista de batalhas em paralelo, sem cache em disco (o banco é o cache).
function fetch_pages($s, $offsets, $timeout = 30) {
    $mh = curl_multi_init();
    $hs = [];
    foreach ($offsets as $o) {
        $hs[$o] = curl_handle(HOSTS[$s] . "/battles?limit=50&offset=$o&sort=recent", $timeout);
        curl_multi_add_handle($mh, $hs[$o]);
    }
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $st == CURLM_OK);
    $out = [];
    foreach ($hs as $o => $h) {
        $body = curl_multi_getcontent($h);
        $code = curl_getinfo($h, CURLINFO_HTTP_CODE);
        $arr = $code == 200 ? json_decode((string)$body, true) : null;
        $out[$o] = is_array($arr) ? $arr : null;
        if ($out[$o] === null) $GLOBALS['coleta_erros'][] = $o . ':' . ($code ?: curl_error($h));
        curl_multi_remove_handle($mh, $h);
        curl_close($h);
    }
    curl_multi_close($mh);
    return $out;
}

// Busca e tenta de novo as páginas que falharam (a API oficial oscila bastante).
function fetch_retry($s, $offs, &$stats, $deadline) {
    $before = count($GLOBALS['coleta_erros'] ?? []);
    $left = function () use ($deadline) { return $deadline - microtime(true); };
    $pages = fetch_pages($s, $offs, (int)max(5, min(25, $left())));
    $stats['paginas'] += count($offs);
    $miss = array_keys(array_filter($pages, function ($a) { return $a === null; }));
    if ($miss && $left() > 15) {
        // A API responde 429 quando pedimos rápido demais: espera um pouco antes de repetir.
        $limited = false;
        foreach (array_slice($GLOBALS['coleta_erros'] ?? [], $before) as $e) if (substr($e, -4) === ':429') $limited = true;
        sleep($limited ? 4 : 1);
        $again = fetch_pages($s, $miss, (int)max(5, min(25, $left())));
        foreach ($again as $o => $a) $pages[$o] = $a;
        $stats['repetidas'] = ($stats['repetidas'] ?? 0) + count($miss);
    }
    $stats['falhas'] += count(array_filter($pages, function ($a) { return $a === null; }));
    return $pages;
}

// Uma rodada de coleta: primeiro o que é novo, depois um pedaço do histórico.
function collect($s, $force = false) {
    $lockf = fopen(cache_dir() . "/coleta_$s.lock", 'c');
    if (!$lockf || !flock($lockf, LOCK_EX | LOCK_NB)) return ['ocupado' => true];
    @set_time_limit(280);
    @ignore_user_abort(true);
    $pdo = db($s);
    $last = (int)meta_get($pdo, 'last_run', 0);
    if (!$force && time() - $last < 60) { flock($lockf, LOCK_UN); return ['recente' => true]; }
    meta_set($pdo, 'last_run', time());
    $t0 = microtime(true);
    // O servidor corta tarefas longas em segundo plano: cada rodada termina em cerca de 70 s.
    $deadline = $t0 + 70;
    $stats = ['novas' => 0, 'paginas' => 0, 'falhas' => 0];
    $newest = (int)$pdo->query('SELECT MAX(t) FROM battles')->fetchColumn();

    meta_set($pdo, 'etapa', 'novidades ' . date('H:i:s'));
    // 1) Novidades: avança de 4 em 4 páginas até alcançar o que já estava salvo (com 30 min de folga para lutas em andamento).
    for ($off = 0; $off <= MAX_OFFSET && microtime(true) < $deadline - 25; $off += 200) {
        $pages = fetch_retry($s, range($off, min(MAX_OFFSET, $off + 150), 50), $stats, $deadline);
        $reached = false;
        $failed = false;
        foreach ($pages as $arr) {
            if ($arr === null) { $failed = true; continue; }
            [$n, $tmin] = ingest($pdo, $arr);
            $stats['novas'] += $n;
            if ($newest && $tmin && $tmin < $newest - 1800) $reached = true;
        }
        if (($reached && !$failed) || !$newest) break;
    }

    meta_set($pdo, 'etapa', 'historico ' . round(microtime(true) - $t0) . 's');
    // 2) Histórico: continua de onde parou, em blocos de 8 páginas, enquanto houver tempo.
    while (microtime(true) < $deadline - 25) {
        $bf = (int)meta_get($pdo, 'backfill_off', 0);
        if ($bf >= MAX_OFFSET) break;
        $from = max($bf, 200);
        $offs = range($from, min(MAX_OFFSET, $from + 350), 50);
        $pages = fetch_retry($s, $offs, $stats, $deadline);
        $upto = null;   // última página antes da primeira falha
        foreach ($offs as $o) {
            if ($pages[$o] === null) break;
            $upto = $o;
        }
        foreach ($pages as $arr) {
            if ($arr === null) continue;
            [$n] = ingest($pdo, $arr);
            $stats['novas'] += $n;
        }
        // Só avança até onde não houve buraco, e volta um pouco para cobrir o que andou na lista enquanto buscava.
        if ($upto === null) break;
        meta_set($pdo, 'backfill_off', $upto >= MAX_OFFSET ? MAX_OFFSET : max($bf + 50, $upto - 50));
        usleep(1200000);   // ritmo para não levar 429 da API
    }
    $stats['historico'] = (int)meta_get($pdo, 'backfill_off', 0) >= MAX_OFFSET ? 'completo' : 'coletando';

    meta_set($pdo, 'etapa', 'limpeza ' . round(microtime(true) - $t0) . 's');
    // 3) Limpeza diária do que passou do prazo.
    if (time() - (int)meta_get($pdo, 'last_prune', 0) > 86400) {
        $cut = time() - KEEP_DAYS * 86400;
        $pdo->prepare('DELETE FROM bg WHERE t < ?')->execute([$cut]);
        $pdo->prepare('DELETE FROM battles WHERE t < ?')->execute([$cut]);
        $pdo->prepare('DELETE FROM gp WHERE t < ?')->execute([$cut]);
        meta_set($pdo, 'last_prune', time());
    }
    meta_set($pdo, 'since', (int)$pdo->query('SELECT MIN(t) FROM battles')->fetchColumn());
    meta_set($pdo, 'last_ok', time());
    meta_set($pdo, 'etapa', 'ok ' . round(microtime(true) - $t0) . 's ' . json_encode($stats));
    $stats['segundos'] = round(microtime(true) - $t0, 1);
    if (!empty($GLOBALS['coleta_erros'])) $stats['erros'] = array_slice($GLOBALS['coleta_erros'], 0, 12);
    flock($lockf, LOCK_UN);
    return $stats;
}

// Responde já e continua trabalhando depois (para a coleta não atrasar a página).
function respond_then($body, $after) {
    @ignore_user_abort(true);
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { @ob_end_flush(); flush(); }
    $after();
}

function maybe_collect_after($s) {
    $pdo = db($s);
    return time() - (int)meta_get($pdo, 'last_run', 0) > 240;
}

// Cache de resposta com "serve o antigo e atualiza depois".
function cached_or_build($key, $ttl, $build, $s) {
    $f = cache_dir() . '/' . md5($key) . '.json';
    $age = is_file($f) ? time() - filemtime($f) : null;
    $collect = maybe_collect_after($s);
    if ($age !== null && $age < $ttl && !$collect) { echo file_get_contents($f); return; }
    if ($age !== null && $age < 3600) {
        // Tem uma versão recente o bastante: entrega e refaz por trás.
        respond_then(file_get_contents($f), function () use ($f, $build, $s, $collect, $age, $ttl) {
            if ($collect) collect($s);
            if ($age >= $ttl || $collect) @file_put_contents($f, json_encode($build()), LOCK_EX);
        });
        return;
    }
    $out = json_encode($build());
    @file_put_contents($f, $out, LOCK_EX);
    if ($collect) respond_then($out, function () use ($s) { collect($s); });
    else echo $out;
}

function hour_clause($h0, $h1) {
    if ($h0 === 0 && $h1 === 23) return ['', []];
    if ($h0 <= $h1) return [' AND h BETWEEN ? AND ?', [$h0, $h1]];
    return [' AND (h >= ? OR h <= ?)', [$h0, $h1]];
}

// Diretório de guildas: soma por guilda com os filtros, selo de escala e série por dia.
function guild_directory($s, $o) {
    $pdo = db($s);
    $now = time();
    $since = $now - $o['days'] * 86400;
    [$hc, $hp] = hour_clause($o['h0'], $o['h1']);
    $where = 'bg.t >= ? AND bg.bp BETWEEN ? AND ?' . $hc;
    $args = array_merge([$since, $o['min'], $o['max']], $hp);

    $sql = "SELECT bg.g, COUNT(*) b, SUM(bg.k) k, SUM(bg.d) d, SUM(bg.f) f, SUM(bg.p) ps, MAX(bg.p) pk, guilds.n, guilds.a
            FROM bg JOIN guilds ON guilds.g = bg.g WHERE $where GROUP BY bg.g HAVING b >= 1";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $q = mb_strtolower(trim($o['q']));
    if ($q !== '') $rows = array_values(array_filter($rows, function ($r) use ($q) {
        return mb_strpos(mb_strtolower($r['n']), $q) !== false || mb_strpos(mb_strtolower($r['a']), $q) !== false;
    }));

    // Membros vistos no período (todos os tamanhos de luta).
    $mem = [];
    $st = $pdo->prepare('SELECT g, COUNT(*) c FROM gp WHERE t >= ? GROUP BY g');
    $st->execute([$since]);
    foreach ($st->fetchAll() as $r) $mem[$r['g']] = (int)$r['c'];

    foreach ($rows as &$r) {
        $r['b'] = (int)$r['b']; $r['k'] = (int)$r['k']; $r['d'] = (int)$r['d']; $r['f'] = (int)$r['f'];
        $r['m'] = $mem[$r['g']] ?? 0;
        $r['kd'] = $r['d'] ? round($r['k'] / $r['d'], 2) : (float)$r['k'];
        $r['avg'] = $r['b'] ? round($r['ps'] / $r['b'], 1) : 0;
    }
    unset($r);
    $key = ['b' => 'b', 'f' => 'f', 'kd' => 'kd', 'm' => 'm', 'k' => 'k', 'avg' => 'avg'][$o['sort']] ?? 'b';
    usort($rows, function ($a, $b) use ($key) { return $b[$key] <=> $a[$key] ?: $b['b'] <=> $a['b']; });
    // Posição no ranking geral, antes da paginação.
    foreach ($rows as $i => &$r) $r['rank'] = $i + 1;
    unset($r);

    // Selo: melhor posição da guilda em número de batalhas dentro de uma faixa de tamanho.
    $badge = [];
    $cols = [];
    foreach (SCALES as $name => $rg) $cols[] = "SUM(CASE WHEN bp BETWEEN {$rg[0]} AND {$rg[1]} THEN 1 ELSE 0 END) AS $name";
    $st = $pdo->prepare('SELECT g, ' . implode(', ', $cols) . ' FROM bg WHERE t >= ? GROUP BY g');
    $st->execute([$since]);
    $per = $st->fetchAll();
    foreach (array_keys(SCALES) as $name) {
        usort($per, function ($a, $b) use ($name) { return $b[$name] <=> $a[$name]; });
        foreach (array_slice($per, 0, 10) as $i => $r) {
            if ((int)$r[$name] < 3) break;
            if (!isset($badge[$r['g']]) || $badge[$r['g']]['r'] > $i + 1) $badge[$r['g']] = ['r' => $i + 1, 'e' => $name];
        }
    }

    $total = count($rows);
    $per_page = $o['per'];
    $pages = max(1, (int)ceil($total / $per_page));
    $page = min($o['page'], $pages);
    $slice = array_slice($rows, ($page - 1) * $per_page, $per_page);

    // Série de batalhas por dia (ou por hora, no filtro de 24 h) só das guildas da página.
    $buckets = $o['days'] == 1 ? 24 : $o['days'];
    $step = $o['days'] == 1 ? 3600 : 86400;
    $start = $o['days'] == 1 ? intdiv($now, 3600) * 3600 - 23 * 3600 : intdiv($now, 86400) * 86400 - ($buckets - 1) * 86400;
    $series = [];
    if ($slice) {
        $ids = array_column($slice, 'g');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT g, (t - ?) / ? AS i, COUNT(*) c FROM bg WHERE g IN ($in) AND t >= ? AND bp BETWEEN ? AND ?$hc GROUP BY g, i");
        $st->execute(array_merge([$start, $step], $ids, [$start, $o['min'], $o['max']], $hp));
        foreach ($st->fetchAll() as $r) {
            $i = (int)$r['i'];
            if ($i < 0 || $i >= $buckets) continue;
            if (!isset($series[$r['g']])) $series[$r['g']] = array_fill(0, $buckets, 0);
            $series[$r['g']][$i] = (int)$r['c'];
        }
    }
    $list = array_map(function ($r) use ($badge, $series, $buckets) {
        return ['id' => $r['g'], 'n' => $r['n'], 'a' => $r['a'], 'rank' => $r['rank'], 'b' => $r['b'], 'm' => $r['m'], 'k' => $r['k'], 'd' => $r['d'],
            'kd' => $r['kd'], 'f' => $r['f'], 'avg' => $r['avg'], 'pk' => (int)$r['pk'], 'badge' => $badge[$r['g']] ?? null,
            's' => $series[$r['g']] ?? array_fill(0, $buckets, 0)];
    }, $slice);

    // Horário nobre: batalhas por hora UTC no período e na faixa de tamanho (sem o filtro de hora).
    $hours = array_fill(0, 24, 0);
    $st = $pdo->prepare('SELECT h, COUNT(*) c FROM (SELECT DISTINCT b, h FROM bg WHERE t >= ? AND bp BETWEEN ? AND ?) GROUP BY h');
    $st->execute([$since, $o['min'], $o['max']]);
    foreach ($st->fetchAll() as $r) $hours[(int)$r['h']] = (int)$r['c'];

    return [
        'total' => $total, 'page' => $page, 'pages' => $pages, 'guilds' => $list, 'hours' => $hours,
        'start' => $start, 'step' => $step,
        'since' => (int)meta_get($pdo, 'since', 0), 'updated' => (int)meta_get($pdo, 'last_ok', 0),
        'battles' => (int)$pdo->query('SELECT COUNT(*) FROM battles')->fetchColumn(),
        'historico' => (int)meta_get($pdo, 'backfill_off', 0) >= MAX_OFFSET ? 'completo' : 'coletando',
    ];
}

// Perfil de uma guilda a partir do banco.
function guild_profile($s, $gid, $days) {
    $pdo = db($s);
    $now = time();
    $since = $now - $days * 86400;
    $st = $pdo->prepare('SELECT * FROM guilds WHERE g = ?');
    $st->execute([$gid]);
    $g = $st->fetch();
    if (!$g) return ['erro' => 'guilda sem batalhas registradas'];
    $st = $pdo->prepare('SELECT COUNT(*) b, SUM(k) k, SUM(d) d, SUM(f) f, SUM(p) ps, MAX(p) pk FROM bg WHERE g = ? AND t >= ?');
    $st->execute([$gid, $since]);
    $tot = $st->fetch();
    $start = intdiv($now, 86400) * 86400 - ($days - 1) * 86400;
    $daysArr = array_fill(0, $days, ['b' => 0, 'k' => 0, 'd' => 0, 'f' => 0]);
    $st = $pdo->prepare('SELECT (t - ?) / 86400 AS i, COUNT(*) b, SUM(k) k, SUM(d) d, SUM(f) f FROM bg WHERE g = ? AND t >= ? GROUP BY i');
    $st->execute([$start, $gid, $start]);
    foreach ($st->fetchAll() as $r) {
        $i = (int)$r['i'];
        if ($i >= 0 && $i < $days) $daysArr[$i] = ['b' => (int)$r['b'], 'k' => (int)$r['k'], 'd' => (int)$r['d'], 'f' => (int)$r['f']];
    }
    $hours = array_fill(0, 24, 0);
    $st = $pdo->prepare('SELECT h, COUNT(*) c FROM bg WHERE g = ? AND t >= ? GROUP BY h');
    $st->execute([$gid, $since]);
    foreach ($st->fetchAll() as $r) $hours[(int)$r['h']] = (int)$r['c'];
    $scales = [];
    foreach (SCALES as $name => $rg) {
        $st = $pdo->prepare('SELECT COUNT(*) b, SUM(k) k, SUM(d) d FROM bg WHERE g = ? AND t >= ? AND bp BETWEEN ? AND ?');
        $st->execute([$gid, $since, $rg[0], $rg[1]]);
        $r = $st->fetch();
        $scales[$name] = ['b' => (int)$r['b'], 'k' => (int)$r['k'], 'd' => (int)$r['d']];
    }
    $st = $pdo->prepare('SELECT pl id, n, b, k, d, f, t FROM gp WHERE g = ? AND t >= ? ORDER BY b DESC, f DESC LIMIT 60');
    $st->execute([$gid, $since]);
    $members = $st->fetchAll();
    $st = $pdo->prepare('SELECT COUNT(*) FROM gp WHERE g = ? AND t >= ?');
    $st->execute([$gid, $since]);
    $mcount = (int)$st->fetchColumn();
    // Rivais: guildas que mais apareceram nas mesmas batalhas sem ser da mesma aliança.
    $st = $pdo->prepare('SELECT o.g, guilds.n, guilds.a, COUNT(*) c, SUM(o.k) ok, SUM(o.d) od FROM bg me JOIN bg o ON o.b = me.b AND o.g != me.g
        JOIN guilds ON guilds.g = o.g WHERE me.g = ? AND me.t >= ? AND (me.ai = \'\' OR o.ai != me.ai) GROUP BY o.g ORDER BY c DESC LIMIT 12');
    $st->execute([$gid, $since]);
    $rivals = $st->fetchAll();
    $st = $pdo->prepare('SELECT bg.b id, bg.t, bg.p, bg.k, bg.d, bg.f, bg.bp, battles.k tk, battles.f tf FROM bg JOIN battles ON battles.id = bg.b
        WHERE bg.g = ? AND bg.t >= ? ORDER BY bg.t DESC LIMIT 30');
    $st->execute([$gid, $since]);
    $recent = $st->fetchAll();
    return ['g' => $g, 'tot' => $tot, 'start' => $start, 'days' => $daysArr, 'hours' => $hours, 'scales' => $scales,
        'members' => $members, 'mcount' => $mcount, 'rivals' => $rivals, 'recent' => $recent,
        'since' => (int)meta_get($pdo, 'since', 0), 'updated' => (int)meta_get($pdo, 'last_ok', 0)];
}

// As últimas batalhas no formato antigo da rota "recent", lidas do banco.
function recent_from_db($s, $limit = 1000) {
    $pdo = db($s);
    $ids = $pdo->query('SELECT id, t, p, k, f FROM battles ORDER BY t DESC LIMIT ' . (int)$limit)->fetchAll();
    if (count($ids) < 200) return null;
    $tmin = end($ids)['t'];
    $st = $pdo->prepare('SELECT bg.b, bg.g, guilds.n, guilds.a, bg.ai, bg.p, bg.k, bg.d, bg.f FROM bg JOIN guilds ON guilds.g = bg.g WHERE bg.t >= ?');
    $st->execute([$tmin]);
    $by = [];
    foreach ($st->fetchAll() as $r) $by[$r['b']][] = ['id' => $r['g'], 'n' => $r['n'], 'a' => $r['a'], 'ai' => $r['ai'], 'p' => (int)$r['p'], 'k' => (int)$r['k'], 'd' => (int)$r['d'], 'f' => (int)$r['f']];
    return array_map(function ($b) use ($by) {
        return ['id' => (int)$b['id'], 't' => gmdate('Y-m-d\TH:i:s\Z', $b['t']), 'p' => (int)$b['p'], 'k' => (int)$b['k'], 'f' => (int)$b['f'], 'g' => $by[$b['id']] ?? []];
    }, $ids);
}

// Lista de batalhas para a página "Batalhas recentes", já agrupada por lado (aliança ou guilda).
function battle_list($s, $offset, $min, $q) {
    $pdo = db($s);
    $args = [$min];
    $where = 'p >= ?';
    if ($q !== '') {
        $where .= ' AND id IN (SELECT bg.b FROM bg JOIN guilds ON guilds.g = bg.g WHERE guilds.n LIKE ? OR guilds.a LIKE ?)';
        $args[] = "%$q%";
        $args[] = "%$q%";
    }
    $st = $pdo->prepare("SELECT id, t, p, k, f FROM battles WHERE $where ORDER BY t DESC LIMIT 50 OFFSET " . (int)$offset);
    $st->execute($args);
    $rows = $st->fetchAll();
    $out = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT bg.b, bg.p, guilds.n, guilds.a FROM bg JOIN guilds ON guilds.g = bg.g WHERE bg.b IN ($in)");
        $st->execute($ids);
        $sides = [];
        foreach ($st->fetchAll() as $r) {
            $key = $r['a'] !== '' ? $r['a'] : $r['n'];
            if (!isset($sides[$r['b']][$key])) $sides[$r['b']][$key] = ['n' => $key, 'p' => 0, 'g' => []];
            $sides[$r['b']][$key]['p'] += (int)$r['p'];
            $sides[$r['b']][$key]['g'][] = $r['n'];
        }
        foreach ($rows as $b) {
            $sd = array_values($sides[$b['id']] ?? []);
            usort($sd, function ($x, $y) { return $y['p'] <=> $x['p']; });
            $out[] = ['id' => (int)$b['id'], 'startTime' => gmdate('Y-m-d\TH:i:s\Z', $b['t']), 'n' => (int)$b['p'], 'totalKills' => (int)$b['k'], 'totalFame' => (int)$b['f'], 'sides' => $sd];
        }
    }
    return ['battles' => $out, 'updated' => (int)meta_get($pdo, 'last_ok', 0), 'total' => (int)$pdo->query('SELECT COUNT(*) FROM battles')->fetchColumn()];
}

// Batalhas da mesma luta: o Albion corta uma luta longa em várias batalhas. Junta as que
// começaram até 10 min uma da outra e têm um dos dois lados principais (aliança, ou guilda
// sem aliança, com mais jogadores) em comum.
function battle_main_sides($pdo, $lo, $hi) {
    $lado = "CASE WHEN ai IS NOT NULL AND ai <> '' THEN 'a:' || ai ELSE 'g:' || g END";
    $st = $pdo->prepare("SELECT b, MIN(t) t, $lado AS k, SUM(p) p FROM bg WHERE t BETWEEN ? AND ? GROUP BY b, k ORDER BY b, p DESC");
    $st->execute([$lo, $hi]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $b = (int)$r['b'];
        if (!isset($out[$b])) $out[$b] = ['t' => (int)$r['t'], 'k' => []];
        if (count($out[$b]['k']) < 2) $out[$b]['k'][] = $r['k'];
    }
    return $out;
}

function battle_neighbors($s, $id) {
    $pdo = db($s);
    $st = $pdo->prepare('SELECT t FROM battles WHERE id = ?');
    $st->execute([$id]);
    $t0 = $st->fetchColumn();
    if ($t0 === false) return ['battles' => []];
    $t0 = (int)$t0;
    $cand = battle_main_sides($pdo, $t0 - 3600, $t0 + 3600);
    if (!isset($cand[(int)$id])) return ['battles' => []];
    $set = [(int)$id => $t0];
    $mudou = true;
    while ($mudou && count($set) < 12) {
        $mudou = false;
        foreach ($cand as $b => $c) {
            if (isset($set[$b])) continue;
            foreach ($set as $m => $t) {
                if (abs($t - $c['t']) <= 600 && array_intersect($c['k'], $cand[$m]['k'])) { $set[$b] = $c['t']; $mudou = true; break; }
            }
            if (count($set) >= 12) break;
        }
    }
    $in = implode(',', array_map('intval', array_keys($set)));
    $rows = $pdo->query("SELECT id, t, p, k, f FROM battles WHERE id IN ($in) ORDER BY t")->fetchAll();
    $sides = [];
    foreach ($pdo->query("SELECT bg.b, bg.p, guilds.n, guilds.a FROM bg JOIN guilds ON guilds.g = bg.g WHERE bg.b IN ($in)")->fetchAll() as $r) {
        $key = $r['a'] !== '' ? $r['a'] : $r['n'];
        if (!isset($sides[$r['b']][$key])) $sides[$r['b']][$key] = ['n' => $key, 'p' => 0];
        $sides[$r['b']][$key]['p'] += (int)$r['p'];
    }
    $out = [];
    foreach ($rows as $b) {
        $sd = array_values($sides[$b['id']] ?? []);
        usort($sd, function ($x, $y) { return $y['p'] <=> $x['p']; });
        $out[] = ['id' => (int)$b['id'], 'startTime' => gmdate('Y-m-d\TH:i:s\Z', $b['t']), 'n' => (int)$b['p'], 'totalKills' => (int)$b['k'], 'totalFame' => (int)$b['f'], 'sides' => $sd];
    }
    return ['battles' => $out];
}

// Busca de guilda pelo nome no banco (instantânea). Exatas e começando pelo texto vêm primeiro.
function guild_search($s, $q) {
    $pdo = db($s);
    $q = trim($q);
    if (preg_match('/^[A-Za-z0-9_-]{22}$/', $q)) {
        $st = $pdo->prepare('SELECT g id, n, a, last FROM guilds WHERE g = ?');
        $st->execute([$q]);
        $one = $st->fetchAll();
        if ($one) return $one;
    }
    $st = $pdo->prepare("SELECT g id, n, a, last FROM guilds WHERE n LIKE ? ORDER BY (LOWER(n) = LOWER(?)) DESC, (n LIKE ?) DESC, last DESC LIMIT 15");
    $st->execute(["%$q%", $q, "$q%"]);
    return array_map(function ($r) { $r['last'] = (int)$r['last']; return $r; }, $st->fetchAll());
}
