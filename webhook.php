<?php
use Telegram\Bot\Api;
require 'vendor/autoload.php';
if(file_exists('../.env')) {
    $dotenv = new Dotenv\Dotenv(__DIR__.'/../');
    $dotenv->load();
}
if(isset($_GET['url'])) {
    $url = $_GET['url'];
} else {
    $url = $_SERVER['HTTP_HOST'];
}
if(filter_var('https://'.$url, FILTER_VALIDATE_URL) == false) {
    echo 'Invalid url for get certificate: '.$url;
    die();
}
$g = stream_context_create (array("ssl" => array(
    "capture_peer_cert" => true,
    "verify_peer" => false,
    "verify_peer_name" => false
)));
$r = stream_socket_client("ssl://{$url}:443", $errno, $errstr, 30,
    STREAM_CLIENT_CONNECT, $g);
if(!$r) {
    echo 'Domain dont exists or dont is over ssl';
    die();
}
$cont = stream_context_get_params($r);
openssl_x509_export($cont["options"]["ssl"]["peer_certificate"], $certificate);
$certificate = trim($certificate, "\n");
echo '<pre>';
echo $certificate;
echo '</pre>';
$telegram = new Api();
$response = $telegram->setWebhook([
    'url' => 'https://'.$url,
    'certificate' => $certificate
]);
echo '<pre>';
var_dump([
    'url' => 'https://'.$url,
    'response' => $response
]);
echo '</pre>';