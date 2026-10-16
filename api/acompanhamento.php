<?php
// Guildas acompanhadas: para as guildas que alguém ligou no painel ou no bot,
// o site guarda cada abate e cada morte (com participantes, dano, cura, armas
// e itens da vítima) e uma foto diária dos membros (fama de PvE, coleta e
// craft). É isso que alimenta o dashboard de status da guilda no painel.
// Incluído pelo albion.php depois do coleta.php (usa db(), HOSTS, curl_handle()).

const TRACK_DAYS = 31;              // histórico guardado por guilda
const TRACK_MAX_GUILDS = 60;        // limite de guildas acompanhadas por servidor do Albion

function tdb($s) {
    static $ready = [];
    $pdo = db($s);
    if (isset($ready[$s])) return $pdo;
    $pdo->exec('CREATE TABLE IF NOT EXISTS tracked (g TEXT PRIMARY KEY, n TEXT, added INTEGER, checked INTEGER DEFAULT 0, mem_at INTEGER DEFAULT 0, list_off INTEGER DEFAULT 0, list_done INTEGER DEFAULT 0, seen INTEGER DEFAULT 0)');
    // Batalhas da guilda ainda por ler (done = 1 quando os eventos já foram lidos e a luta acabou).
    $pdo->exec('CREATE TABLE IF NOT EXISTS tb (g TEXT, b INTEGER, t INTEGER, done INTEGER DEFAULT 0, PRIMARY KEY (g, b))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS tb_todo ON tb(g, done, t)');
    // Um evento de morte visto do lado da guilda: k = a guilda matou, d = a guilda morreu.
    $pdo->exec('CREATE TABLE IF NOT EXISTS ev (id INTEGER, g TEXT, kind TEXT, t INTEGER, b INTEGER, fame INTEGER,
        kid TEXT, kn TEXT, kgid TEXT, kg TEXT, ka TEXT, kip INTEGER, kw TEXT,
        vid TEXT, vn TEXT, vgid TEXT, vg TEXT, va TEXT, vip INTEGER, vw TEXT,
        part TEXT, inv TEXT, veq TEXT, PRIMARY KEY (id, g))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ev_gt ON ev(g, t)');
    // Foto diária dos membros (estatísticas acumuladas do jogo). A diferença entre dois dias é o que fizeram no período.
    $pdo->exec('CREATE TABLE IF NOT EXISTS msnap (g TEXT, pl TEXT, day INTEGER, n TEXT, kf INTEGER, df INTEGER, pve INTEGER, gat INTEGER, craft INTEGER, fish INTEGER, farm INTEGER, PRIMARY KEY (g, pl, day))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS msnap_gd ON msnap(g, day)');
    $ready[$s] = true;
    return $pdo;
}

function track_guild($s, $gid, $name = '') {
    $pdo = tdb($s);
    $n = (int)$pdo->query('SELECT COUNT(*) FROM tracked')->fetchColumn();
    $st = $pdo->prepare('SELECT 1 FROM tracked WHERE g = ?');
    $st->execute([$gid]);
    if ($st->fetchColumn()) {
        $pdo->prepare('UPDATE tracked SET seen = ? WHERE g = ?')->execute([time(), $gid]);
        return ['ok' => true, 'novo' => false];
    }
    if ($n >= TRACK_MAX_GUILDS) return ['ok' => false, 'erro' => 'limite de guildas acompanhadas atingido'];
    if ($name === '') {
        $st = $pdo->prepare('SELECT n FROM guilds WHERE g = ?');
        $st->execute([$gid]);
        $name = (string)$st->fetchColumn();
    }
    $pdo->prepare('INSERT INTO tracked (g, n, added, seen) VALUES (?, ?, ?, ?)')->execute([$gid, $name, time(), time()]);
    return ['ok' => true, 'novo' => true];
}

// Busca várias URLs da API em paralelo (no máximo 8 por vez). Devolve [url => array|null].
function fetch_urls($urls, $timeout) {
    $out = [];
    foreach (array_chunk($urls, 8) as $chunk) {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($chunk as $u) { $hs[$u] = curl_handle($u, $timeout); curl_multi_add_handle($mh, $hs[$u]); }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $st == CURLM_OK);
        foreach ($hs as $u => $h) {
            $code = curl_getinfo($h, CURLINFO_HTTP_CODE);
            $arr = $code == 200 ? json_decode((string)curl_multi_getcontent($h), true) : null;
            $out[$u] = is_array($arr) ? $arr : null;
            curl_multi_remove_handle($mh, $h);
            curl_close($h);
        }
        curl_multi_close($mh);
    }
    return $out;
}

function slim_item($it) {
    return $it ? [$it['Type'], (int)($it['Count'] ?? 1), (int)($it['Quality'] ?? 0)] : null;
}

// Grava os eventos de uma batalha que envolvem a guilda acompanhada.
function store_events($pdo, $gid, $events) {
    $ins = $pdo->prepare('INSERT OR REPLACE INTO ev (id, g, kind, t, b, fame, kid, kn, kgid, kg, ka, kip, kw, vid, vn, vgid, vg, va, vip, vw, part, inv, veq)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $n = 0;
    foreach ($events as $e) {
        $k = $e['Killer'] ?? [];
        $v = $e['Victim'] ?? [];
        $parts = $e['Participants'] ?? [];
        $ours = ($k['GuildId'] ?? '') === $gid;
        foreach ($parts as $p) if (($p['GuildId'] ?? '') === $gid) $ours = true;
        $died = ($v['GuildId'] ?? '') === $gid;
        if (!$ours && !$died) continue;
        // Briga entre membros da própria guilda (arena, duelo) não conta.
        if ($died && ($k['GuildId'] ?? '') === $gid) continue;
        if ($died) $ours = false;
        $part = array_map(function ($p) {
            return [$p['Id'] ?? '', $p['Name'] ?? '', $p['GuildId'] ?? '', round($p['DamageDone'] ?? 0), round($p['SupportHealingDone'] ?? 0),
                $p['Equipment']['MainHand']['Type'] ?? '', (int)($p['KillFame'] ?? 0), $p['GuildName'] ?? ''];
        }, $parts);
        $inv = array_values(array_filter(array_map('slim_item', $v['Inventory'] ?? [])));
        $veq = array_values(array_filter(array_map('slim_item', array_values($v['Equipment'] ?? []))));
        $t = strtotime($e['TimeStamp'] ?? '') ?: 0;
        $ins->execute([$e['EventId'], $gid, $died && !$ours ? 'd' : 'k', $t, (int)($e['BattleId'] ?? 0), (int)($e['TotalVictimKillFame'] ?? 0),
            $k['Id'] ?? '', $k['Name'] ?? '', $k['GuildId'] ?? '', $k['GuildName'] ?? '', $k['AllianceName'] ?? '', round($k['AverageItemPower'] ?? 0), $k['Equipment']['MainHand']['Type'] ?? '',
            $v['Id'] ?? '', $v['Name'] ?? '', $v['GuildId'] ?? '', $v['GuildName'] ?? '', $v['AllianceName'] ?? '', round($v['AverageItemPower'] ?? 0), $v['Equipment']['MainHand']['Type'] ?? '',
            json_encode($part), json_encode($inv), json_encode($veq)]);
        $n++;
    }
    return $n;
}

// Uma rodada das guildas acompanhadas, com prazo. Cada guilda: lista de batalhas novas,
// eventos das batalhas ainda não lidas e, a cada 6 h, a foto dos membros.
function collect_tracked($s, $deadline) {
    $pdo = tdb($s);
    $stats = ['guildas' => 0, 'batalhas' => 0, 'eventos' => 0, 'membros' => 0];
    $left = function () use ($deadline) { return $deadline - microtime(true); };
    $cut = time() - TRACK_DAYS * 86400;
    // Para de acompanhar quem ninguém abriu em 45 dias.
    $pdo->prepare('DELETE FROM tracked WHERE seen < ?')->execute([time() - 45 * 86400]);
    $list = $pdo->query('SELECT * FROM tracked ORDER BY checked ASC')->fetchAll();
    foreach ($list as $tg) {
        if ($left() < 15) break;
        $g = $tg['g'];
        $stats['guildas']++;
        $pdo->prepare('UPDATE tracked SET checked = ? WHERE g = ?')->execute([time(), $g]);

        // 1) Batalhas novas da guilda (e o histórico, aos poucos, até 31 dias).
        $offs = [0];
        if (!$tg['list_done']) $offs[] = max(50, (int)$tg['list_off']);
        $urls = [];
        foreach ($offs as $o) $urls[$o] = HOSTS[$s] . "/battles?guildId=$g&limit=50&offset=$o&sort=recent";
        $res = fetch_urls(array_values($urls), (int)max(5, min(25, $left() - 5)));
        $ib = $pdo->prepare('INSERT OR IGNORE INTO tb (g, b, t) VALUES (?, ?, ?)');
        foreach ($urls as $o => $u) {
            $arr = $res[$u];
            if ($arr === null) continue;
            $oldest = PHP_INT_MAX;
            foreach ($arr as $b) {
                $t = strtotime($b['startTime'] ?? '') ?: 0;
                $oldest = min($oldest, $t);
                if ($t >= $cut) $ib->execute([$g, (int)$b['id'], $t]);
            }
            if ($o > 0) {
                $done = count($arr) < 50 || $oldest < $cut || $o + 50 > MAX_OFFSET;
                $pdo->prepare('UPDATE tracked SET list_off = ?, list_done = ? WHERE g = ?')->execute([$o + 50, $done ? 1 : 0, $g]);
            }
        }

        // 2) Foto dos membros a cada 6 h, antes das batalhas (a rota de membros é a mais lenta da API).
        if (time() - (int)$tg['mem_at'] > 21600 && $left() > 20) {
            $u = HOSTS[$s] . "/guilds/$g/members";
            $r = fetch_urls([$u], (int)max(5, min(45, $left() - 10)));
            if (is_array($r[$u])) {
                $day = intdiv(time(), 86400);
                $im = $pdo->prepare('INSERT OR REPLACE INTO msnap (g, pl, day, n, kf, df, pve, gat, craft, fish, farm) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $pdo->beginTransaction();
                foreach ($r[$u] as $m) {
                    $ls = $m['LifetimeStatistics'] ?? [];
                    $im->execute([$g, $m['Id'], $day, $m['Name'], (int)($m['KillFame'] ?? 0), (int)($m['DeathFame'] ?? 0), (int)($ls['PvE']['Total'] ?? 0),
                        (int)($ls['Gathering']['All']['Total'] ?? 0), (int)($ls['Crafting']['Total'] ?? 0), (int)($ls['FishingFame'] ?? 0), (int)($ls['FarmingFame'] ?? 0)]);
                }
                $pdo->commit();
                $pdo->prepare('UPDATE tracked SET mem_at = ? WHERE g = ?')->execute([time(), $g]);
                $stats['membros'] += count($r[$u]);
            }
        }

        // 3) Eventos das batalhas ainda não lidas: as mais novas primeiro.
        $todo = $pdo->prepare('SELECT b, t FROM tb WHERE g = ? AND done = 0 AND b NOT IN (SELECT value FROM json_each(?)) ORDER BY t DESC LIMIT 16');
        $tried = [];
        while ($left() > 25) {
        $todo->execute([$g, json_encode($tried)]);
        $bs = $todo->fetchAll();
        if (!$bs) break;
        foreach ($bs as $b) $tried[] = (int)$b['b'];
        {
            $urls = [];
            foreach ($bs as $b) $urls[$b['b']] = HOSTS[$s] . '/events/battle/' . $b['b'] . '?offset=0&limit=51';
            $res = fetch_urls(array_values($urls), (int)max(5, min(25, $left() - 5)));
            // Lutas grandes têm mais de 51 mortes: busca as páginas seguintes.
            $more = [];
            foreach ($urls as $bid => $u) if (is_array($res[$u]) && count($res[$u]) >= 51) for ($o = 51; $o < 1000; $o += 51) $more[$bid][] = HOSTS[$s] . "/events/battle/$bid?offset=$o&limit=51";
            $extra = [];
            if ($more && $left() > 10) $extra = fetch_urls(array_merge(...array_values(array_map(function ($l) { return array_slice($l, 0, 6); }, $more))), (int)max(5, min(25, $left() - 5)));
            $pdo->beginTransaction();
            foreach ($bs as $b) {
                $arr = $res[$urls[$b['b']]];
                if ($arr === null) continue;
                foreach (array_slice($more[$b['b']] ?? [], 0, 6) as $u2) if (!empty($extra[$u2])) $arr = array_merge($arr, $extra[$u2]);
                $stats['eventos'] += store_events($pdo, $g, $arr);
                $stats['batalhas']++;
                // Luta de menos de 30 min atrás pode ainda ter mortes chegando: lê de novo na próxima rodada.
                if (time() - $b['t'] > 1800) $pdo->prepare('UPDATE tb SET done = 1 WHERE g = ? AND b = ?')->execute([$g, $b['b']]);
            }
            $pdo->commit();
        }
        usleep(800000);   // ritmo para não levar 429 da API
        }

    }
    if (time() - (int)meta_get($pdo, 'track_prune', 0) > 86400) {
        $pdo->prepare('DELETE FROM ev WHERE t < ?')->execute([$cut]);
        $pdo->prepare('DELETE FROM tb WHERE t < ?')->execute([$cut]);
        $pdo->prepare('DELETE FROM msnap WHERE day < ?')->execute([intdiv($cut, 86400) - 1]);
        meta_set($pdo, 'track_prune', time());
    }
    return $stats;
}

function collect_tracked_locked($s) {
    $lockf = fopen(cache_dir() . "/acomp_$s.lock", 'c');
    if (!$lockf || !flock($lockf, LOCK_EX | LOCK_NB)) return ['ocupado' => true];
    @set_time_limit(120);
    @ignore_user_abort(true);
    $t0 = microtime(true);
    $st = collect_tracked($s, $t0 + 70);
    $st['segundos'] = round(microtime(true) - $t0, 1);
    meta_set(tdb($s), 'track_last', time());
    flock($lockf, LOCK_UN);
    return $st;
}

// ---------------------------------------------------------------------------
// Dashboard: tudo de um período [from, to] para uma guilda acompanhada.
// ---------------------------------------------------------------------------
function guild_dashboard($s, $gid, $from, $to) {
    $pdo = tdb($s);
    $st = $pdo->prepare('SELECT * FROM tracked WHERE g = ?');
    $st->execute([$gid]);
    $tg = $st->fetch();
    if (!$tg) return ['acompanhada' => false];
    $pdo->prepare('UPDATE tracked SET seen = ? WHERE g = ?')->execute([time(), $gid]);

    $st = $pdo->prepare('SELECT * FROM ev WHERE g = ? AND t BETWEEN ? AND ? ORDER BY t');
    $st->execute([$gid, $from, $to]);
    $evs = $st->fetchAll();

    $span = $to - $from;
    $step = $span <= 2 * 86400 ? 3600 : 86400;
    $start = intdiv($from, $step) * $step;
    $nb = (int)ceil(($to - $start + 1) / $step);
    $series = array_fill(0, $nb, ['k' => 0, 'd' => 0, 'kf' => 0, 'df' => 0]);

    $tot = ['k' => 0, 'd' => 0, 'kf' => 0, 'df' => 0, 'dmg' => 0, 'heal' => 0];
    $players = [];   // id => stats
    $rivals = [];    // guilda inimiga => stats
    $battles = [];   // id => stats
    $wUse = [];      // arma usada pelos membros
    $wKilledUs = []; // arma que matou membros
    $loot = [];      // itens que estavam com os inimigos abatidos
    $lost = [];      // itens perdidos pelos membros
    $hours = array_fill(0, 24, 0);
    $pl = function (&$players, $id, $name) {
        if (!isset($players[$id])) $players[$id] = ['id' => $id, 'n' => $name, 'k' => 0, 'a' => 0, 'd' => 0, 'kf' => 0, 'df' => 0, 'dmg' => 0, 'heal' => 0, 'b' => [], 'w' => [], 'ip' => 0, 'ipn' => 0];
        return $id;
    };
    $add = function (&$arr, $key, $n = 1) { if ($key === '' || $key === null) return; $arr[$key] = ($arr[$key] ?? 0) + $n; };

    foreach ($evs as $e) {
        $part = json_decode($e['part'], true) ?: [];
        $i = (int)(($e['t'] - $start) / $step);
        $hours[(int)gmdate('G', $e['t'])]++;
        $bid = (int)$e['b'];
        if (!isset($battles[$bid])) $battles[$bid] = ['id' => $bid, 't' => (int)$e['t'], 'k' => 0, 'd' => 0, 'kf' => 0, 'df' => 0, 'dmg' => [], 'kills' => [], 'heal' => [], 'enemies' => [], 'w' => [], 'players' => []];
        $B = &$battles[$bid];
        $B['t'] = min($B['t'], (int)$e['t']);
        if ($e['kind'] === 'k') {
            $tot['k']++; $tot['kf'] += $e['fame'];
            if (isset($series[$i])) { $series[$i]['k']++; $series[$i]['kf'] += $e['fame']; }
            $B['k']++; $B['kf'] += $e['fame'];
            $rk = $e['vg'] !== '' ? $e['vg'] : 'Sem guilda';
            if (!isset($rivals[$rk])) $rivals[$rk] = ['n' => $rk, 'a' => $e['va'], 'k' => 0, 'd' => 0, 'kf' => 0, 'df' => 0, 'b' => []];
            $rivals[$rk]['k']++; $rivals[$rk]['kf'] += $e['fame']; $rivals[$rk]['b'][$bid] = 1;
            $add($B['enemies'], $rk);
            // Quem deu o golpe final leva o abate; os outros da guilda contam como assistência.
            if ($e['kgid'] === $gid) {
                $pl($players, $e['kid'], $e['kn']);
                $players[$e['kid']]['k']++; $players[$e['kid']]['b'][$bid] = 1;
                $add($B['kills'], $e['kn']); $B['players'][$e['kn']] = 1;
                // Sem a lista de participantes (abate sozinho), a fama inteira é de quem matou.
                if (!array_filter($part, function ($p) use ($e) { return $p[0] === $e['kid']; })) { $players[$e['kid']]['kf'] += $e['fame']; if ($e['kw'] !== '') { $add($players[$e['kid']]['w'], $e['kw']); $add($wUse, $e['kw']); $add($B['w'], $e['kw']); } }
            }
            foreach ($part as $p) {
                if ($p[2] !== $gid) continue;
                $pl($players, $p[0], $p[1]);
                $P = &$players[$p[0]];
                if ($p[0] !== $e['kid']) $P['a']++;
                // A API não manda a fama de cada um: o jogo divide a fama do abate entre quem participou.
                $P['dmg'] += $p[3]; $P['heal'] += $p[4]; $P['kf'] += (int)round($e['fame'] / max(1, count($part))); $P['b'][$bid] = 1;
                if ($p[5] !== '') { $add($P['w'], $p[5]); $add($wUse, $p[5]); $add($B['w'], $p[5]); }
                unset($P);
                $tot['dmg'] += $p[3]; $tot['heal'] += $p[4];
                $add($B['dmg'], $p[1], $p[3]); $add($B['heal'], $p[1], $p[4]);
                $B['players'][$p[1]] = 1;
            }
            if ($e['kgid'] === $gid && $e['kip']) { $players[$e['kid']]['ip'] += $e['kip']; $players[$e['kid']]['ipn']++; }
            foreach (array_merge(json_decode($e['inv'], true) ?: [], json_decode($e['veq'], true) ?: []) as $it) $add($loot, $it[0], $it[1]);
        } else {
            $tot['d']++; $tot['df'] += $e['fame'];
            if (isset($series[$i])) { $series[$i]['d']++; $series[$i]['df'] += $e['fame']; }
            $B['d']++; $B['df'] += $e['fame'];
            $rk = $e['kg'] !== '' ? $e['kg'] : 'Sem guilda';
            if (!isset($rivals[$rk])) $rivals[$rk] = ['n' => $rk, 'a' => $e['ka'], 'k' => 0, 'd' => 0, 'kf' => 0, 'df' => 0, 'b' => []];
            $rivals[$rk]['d']++; $rivals[$rk]['df'] += $e['fame']; $rivals[$rk]['b'][$bid] = 1;
            $add($B['enemies'], $rk);
            $pl($players, $e['vid'], $e['vn']);
            $players[$e['vid']]['d']++; $players[$e['vid']]['df'] += $e['fame']; $players[$e['vid']]['b'][$bid] = 1;
            if ($e['vip']) { $players[$e['vid']]['ip'] += $e['vip']; $players[$e['vid']]['ipn']++; }
            if ($e['vw'] !== '') { $add($players[$e['vid']]['w'], $e['vw']); $add($B['w'], $e['vw']); }
            $B['players'][$e['vn']] = 1;
            $add($wKilledUs, $e['kw']);
            foreach (array_merge(json_decode($e['inv'], true) ?: [], json_decode($e['veq'], true) ?: []) as $it) $add($lost, $it[0], $it[1]);
        }
        unset($B);
    }

    $top = function ($arr, $n) { arsort($arr); return array_slice($arr, 0, $n, true); };
    $pairs = function ($arr) { $o = []; foreach ($arr as $k => $v) $o[] = [$k, $v]; return $o; };
    $best = function ($arr) { if (!$arr) return null; arsort($arr); $k = array_key_first($arr); return [$k, $arr[$k]]; };

    // Fama de PvE, coleta e craft: diferença entre a primeira e a última foto dos membros no período.
    $d0 = intdiv($from, 86400); $d1 = intdiv($to, 86400);
    $st = $pdo->prepare('SELECT * FROM msnap WHERE g = ? AND day BETWEEN ? AND ? ORDER BY day');
    $st->execute([$gid, $d0 - 1, $d1]);
    $first = []; $last = [];
    foreach ($st->fetchAll() as $r) { if (!isset($first[$r['pl']])) $first[$r['pl']] = $r; $last[$r['pl']] = $r; }
    $snapDays = $first ? [min(array_column($first, 'day')), max(array_column($last, 'day'))] : null;
    $fame = ['pve' => 0, 'gat' => 0, 'craft' => 0, 'fish' => 0, 'farm' => 0, 'pvp' => 0];
    $memberStats = [];
    foreach ($last as $id => $r) {
        $f = $first[$id];
        $dl = [];
        foreach (['pve', 'gat', 'craft', 'fish', 'farm', 'kf'] as $c) $dl[$c] = max(0, (int)$r[$c] - (int)$f[$c]);
        foreach (['pve', 'gat', 'craft', 'fish', 'farm'] as $c) $fame[$c] += $dl[$c];
        $fame['pvp'] += $dl['kf'];
        $memberStats[$id] = ['n' => $r['n'], 'pve' => $dl['pve'], 'gat' => $dl['gat'], 'craft' => $dl['craft'], 'fish' => $dl['fish'], 'farm' => $dl['farm'], 'pvp' => $dl['kf'],
            'tpve' => (int)$r['pve'], 'tgat' => (int)$r['gat'], 'tcraft' => (int)$r['craft'], 'tkf' => (int)$r['kf']];
    }

    $fameTotal = ['pve' => 0, 'gat' => 0, 'craft' => 0, 'pvp' => 0, 'membros' => count($last)];
    foreach ($memberStats as $ms) { $fameTotal['pve'] += $ms['tpve']; $fameTotal['gat'] += $ms['tgat']; $fameTotal['craft'] += $ms['tcraft']; $fameTotal['pvp'] += $ms['tkf']; }

    $plist = [];
    foreach ($players as $p) {
        $w = $best($p['w']);
        $ms = $memberStats[$p['id']] ?? null;
        $plist[] = ['id' => $p['id'], 'n' => $p['n'], 'k' => $p['k'], 'a' => $p['a'], 'd' => $p['d'], 'kf' => $p['kf'], 'df' => $p['df'], 'dmg' => $p['dmg'], 'heal' => $p['heal'],
            'b' => count($p['b']), 'w' => $w ? $w[0] : '', 'ip' => $p['ipn'] ? round($p['ip'] / $p['ipn']) : 0,
            'pve' => $ms['pve'] ?? null, 'gat' => $ms['gat'] ?? null, 'craft' => $ms['craft'] ?? null, 'tpve' => $ms['tpve'] ?? null, 'tcraft' => $ms['tcraft'] ?? null, 'tgat' => $ms['tgat'] ?? null];
    }
    // Membros sem luta no período ainda aparecem pela fama de PvE, coleta e craft.
    $seen = array_flip(array_column($plist, 'id'));
    foreach ($memberStats as $id => $ms) {
        if (isset($seen[$id])) continue;
        $plist[] = ['id' => $id, 'n' => $ms['n'], 'k' => 0, 'a' => 0, 'd' => 0, 'kf' => 0, 'df' => 0, 'dmg' => 0, 'heal' => 0, 'b' => 0, 'w' => '', 'ip' => 0,
            'pve' => $ms['pve'], 'gat' => $ms['gat'], 'craft' => $ms['craft'], 'tpve' => $ms['tpve'], 'tcraft' => $ms['tcraft'], 'tgat' => $ms['tgat']];
    }
    usort($plist, function ($a, $b) { return $b['kf'] <=> $a['kf'] ?: $b['k'] <=> $a['k'] ?: $b['pve'] <=> $a['pve']; });

    $blist = [];
    foreach ($battles as $B) {
        $md = $best($B['dmg']); $mk = $best($B['kills']); $mh = $best($B['heal']);
        $blist[] = ['id' => $B['id'], 't' => $B['t'], 'k' => $B['k'], 'd' => $B['d'], 'kf' => $B['kf'], 'df' => $B['df'], 'n' => count($B['players']),
            'dmg' => $md, 'killer' => $mk, 'healer' => ($mh && $mh[1] > 0) ? $mh : null,
            'enemies' => $pairs($top($B['enemies'], 4)), 'w' => $pairs($top($B['w'], 6)),
            'dmgs' => $pairs($top($B['dmg'], 5))];
    }
    usort($blist, function ($a, $b) { return $b['t'] <=> $a['t']; });

    $rlist = array_values(array_map(function ($r) { $r['b'] = count($r['b']); return $r; }, $rivals));
    usort($rlist, function ($a, $b) { return ($b['k'] + $b['d']) <=> ($a['k'] + $a['d']); });

    $hl = function ($key) use ($plist) {
        $b = null;
        foreach ($plist as $p) if ($p[$key] !== null && $p[$key] > 0 && (!$b || $p[$key] > $b[$key])) $b = $p;
        return $b ? ['n' => $b['n'], 'v' => $b[$key], 'w' => $b['w']] : null;
    };

    $st = $pdo->prepare('SELECT COUNT(*) FROM tb WHERE g = ? AND t BETWEEN ? AND ?');
    $st->execute([$gid, $from, $to]);
    $nb2 = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM tb WHERE g = ? AND done = 0');
    $st->execute([$gid]);
    $pending = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT MIN(t) FROM ev WHERE g = ?');
    $st->execute([$gid]);

    return [
        'acompanhada' => true, 'desde' => (int)$tg['added'], 'primeiro' => (int)$st->fetchColumn(), 'pendentes' => $pending, 'historico' => (int)$tg['list_done'] ? 'completo' : 'coletando',
        'atualizado' => (int)$tg['checked'], 'from' => $from, 'to' => $to, 'start' => $start, 'step' => $step,
        'tot' => $tot + ['b' => max($nb2, count($battles)), 'ativos' => count(array_filter($plist, function ($p) { return $p['b'] > 0; }))],
        'series' => $series, 'hours' => $hours,
        'fama' => $fame, 'fama_total' => $fameTotal, 'fotos' => $snapDays,
        'destaques' => ['abates' => $hl('k'), 'dano' => $hl('dmg'), 'cura' => $hl('heal'), 'fama' => $hl('kf'), 'mortes' => $hl('d'), 'assist' => $hl('a'), 'pve' => $hl('pve'), 'craft' => $hl('craft'), 'coleta' => $hl('gat')],
        'jogadores' => array_slice($plist, 0, 300),
        'lutas' => array_slice($blist, 0, 80),
        'rivais' => array_slice($rlist, 0, 30),
        'armas' => $pairs($top($wUse, 20)), 'armas_inimigas' => $pairs($top($wKilledUs, 15)),
        'loot' => $pairs($top($loot, 40)), 'perdido' => $pairs($top($lost, 40)),
    ];
}
