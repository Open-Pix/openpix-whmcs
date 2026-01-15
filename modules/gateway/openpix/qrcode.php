<?php
/**
 * Gerador de QR Code
 */

if (!isset($_GET['code']) || empty($_GET['code'])) {
    http_response_code(400);
    die('Código não fornecido');
}

require_once __DIR__ . '/vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

$brCode = $_GET['code'];
$size = isset($_GET['size']) ? (int)$_GET['size'] : 300;

if (strlen($brCode) < 50) {
    http_response_code(400);
    die('Código PIX inválido');
}

try {
    $options = new QROptions([
        'outputType' => QRCode::OUTPUT_IMAGE_PNG,
        'eccLevel' => QRCode::ECC_M,
        'scale' => max(5, min(20, intval($size / 50))),
        'imageBase64' => false,
        'quietzoneSize' => 2,
    ]);

    $qrcode = new QRCode($options);
    
    $etag = md5($brCode . $size);
    header('ETag: "' . $etag . '"');
    header('Cache-Control: public, max-age=86400');
    
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
        http_response_code(304);
        exit;
    }
    
    header('Content-Type: image/png');
    header('Content-Disposition: inline; filename="pix-qrcode.png"');
    
    echo $qrcode->render($brCode);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log('Erro ao gerar QR Code PIX: ' . $e->getMessage());
    die('Erro ao gerar QR Code');
}
