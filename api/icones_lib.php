<?php
// Funções do cache de ícones de item, usadas por icone.php (um ícone sob demanda)
// e pela rota "aquecer" do albion.php (baixa os ícones do catálogo aos poucos).

function icone_dir() {
    $dir = dirname(__DIR__) . '/icone';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function icone_arquivo($id, $q = 1) {
    return icone_dir() . '/' . $id . ($q > 1 ? "~q$q" : '') . '.webp';
}

function icone_url_render($id, $q = 1) {
    return 'https://render.albiononline.com/v1/item/' . rawurlencode($id) . '.png?count=1&quality=' . $q . '&size=180';
}

// Converte o PNG do render para WebP (com transparência) e grava sem deixar arquivo pela metade.
function icone_salvar($file, $png) {
    if (!$png || substr($png, 1, 3) !== 'PNG') return false;
    $tmp = $file . '.' . getmypid() . '.tmp';
    $ok = false;
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $im = @imagecreatefromstring($png);
        if ($im) {
            imagepalettetotruecolor($im);
            imagealphablending($im, false);
            imagesavealpha($im, true);
            $ok = imagewebp($im, $tmp, 86);
            imagedestroy($im);
        }
    }
    if (!$ok) $ok = file_put_contents($tmp, $png) !== false;   // sem GD: guarda o PNG (o navegador reconhece pelo conteúdo)
    if ($ok) return @rename($tmp, $file);
    @unlink($tmp);
    return false;
}

// Todas as variações (tier já vem no id; encantamentos .1 a .4) dos itens equipáveis do catálogo.
function icone_lista_catalogo() {
    $d = json_decode((string)@file_get_contents(dirname(__DIR__) . '/assets/data/itens.json'), true);
    $ordem = ['mainhand' => 0, 'offhand' => 1, 'head' => 2, 'chest' => 3, 'shoes' => 4, 'cape' => 5, 'bag' => 6, 'mount' => 7, 'food' => 8, 'potion' => 9];
    $sem_encanto = ['food' => 1, 'potion' => 1, 'mount' => 1];
    $ids = [];
    foreach (($d['items'] ?? []) as $id => $it) {
        $s = $it['s'] ?? '';
        if ($s === '' || strpos($id, '_TOOL_') !== false || !preg_match('/^[A-Za-z0-9_]+$/', $id)) continue;
        // Itens abaixo do tier 4 não têm encantamento.
        $tier = preg_match('/^T(\d)_/', $id, $tm) ? (int)$tm[1] : 0;
        $ids[] = [$ordem[$s] ?? 9, $id, isset($sem_encanto[$s]) || $tier < 4 ? 0 : 4];
    }
    usort($ids, function ($a, $b) { return $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]); });
    $out = [];
    foreach ($ids as $x) for ($e = 0; $e <= $x[2]; $e++) $out[] = $x[1] . ($e ? "@$e" : '');
    return $out;
}

// Baixa os ícones que ainda faltam, 10 por vez, até o prazo. Devolve quantos faltavam e quantos baixou.
function icone_aquecer($segundos, $paralelo = 4) {
    $fim = microtime(true) + $segundos;
    // Variações que o render do Albion não tem (404) ficam anotadas para não tentar de novo.
    $nf = dirname(__DIR__) . '/api/cache/icones_inexistentes.json';
    $inexistentes = json_decode((string)@file_get_contents($nf), true) ?: [];
    $falta = array_values(array_filter(icone_lista_catalogo(), function ($id) use ($inexistentes) { return !isset($inexistentes[$id]) && !is_file(icone_arquivo($id)); }));
    $total = count($falta);
    $feitos = 0;
    $erros = 0;
    $codigos = [];
    foreach (array_chunk($falta, $paralelo) as $lote) {
        if (microtime(true) > $fim - 8) break;
        $mh = curl_multi_init();
        $hs = [];
        foreach ($lote as $id) {
            $ch = curl_init(icone_url_render($id));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => 'EmporioDosBots/1.0']);
            curl_multi_add_handle($mh, $ch);
            $hs[$id] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $st == CURLM_OK);
        foreach ($hs as $id => $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ok = $code == 200 && icone_salvar(icone_arquivo($id), curl_multi_getcontent($ch));
            $ok ? $feitos++ : $erros++;
            if ($code == 404) $inexistentes[$id] = 1;
            if (!$ok) { $c = $code ?: 'tempo'; $codigos[$c] = ($codigos[$c] ?? 0) + 1; }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    if (!is_dir(dirname($nf))) @mkdir(dirname($nf), 0755, true);
    @file_put_contents($nf, json_encode($inexistentes), LOCK_EX);
    return ['faltavam' => $total, 'baixados' => $feitos, 'erros' => $erros, 'inexistentes' => count($inexistentes), 'restam' => $total - $feitos, 'codigos' => $codigos];
}
