<?php
// Cache de ícones de item: /icone/<ID>[~q<qualidade>].webp
// Na primeira vez que alguém pede um ícone, busca no render do Albion, converte
// para WebP e grava em /icone/. Daí em diante o arquivo existe e o servidor web
// entrega direto (o .htaccess só manda para cá quando o arquivo ainda não existe).
require __DIR__ . '/icones_lib.php';

$f = (string)($_GET['f'] ?? '');
if (!preg_match('/^([A-Za-z0-9_]{3,80}(?:@[1-4])?)(?:~q([1-5]))?$/', $f, $m)) {
    http_response_code(400);
    exit;
}
$id = $m[1];
$q = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 1;
$file = icone_arquivo($id, $q);

if (!is_file($file)) {
    $ch = curl_init(icone_url_render($id, $q));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => 'EmporioDosBots/1.0']);
    $png = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code != 200 || !icone_salvar($file, $png)) {
        http_response_code(404);
        header('Cache-Control: public, max-age=600');
        exit;
    }
}

$body = file_get_contents($file);
header('Content-Type: ' . (substr($body, 0, 4) === 'RIFF' ? 'image/webp' : 'image/png'));
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . strlen($body));
echo $body;
