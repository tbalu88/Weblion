<?php
/**
 * A Converter for Media („Bypassing Nginx” mód) kiszolgáláskor a képcímbe az ELSŐ .jpg/.png elé szúrja be a „-optimized” jelölést.
 * A többszörös kiterjesztésű fájlneveknél (pl. kep.jpg-3-768x1024.jpeg) ez a név közepére kerül, a bővítmény szerverszabálya nem
 * fordítja vissza, és a kép 404 lesz. Ez a fájl az ilyen kéréseket a valódi fájlra irányítja (301), ha az létezik. Kikapcsolás: törlés.
 */
if (!defined('ABSPATH')) { exit; }
(function () {
	$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
	if (strpos($uri, '-optimized') === false || strpos($uri, '/wp-content/uploads/') === false) { return; }
	$path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
	if (!preg_match('#^/wp-content/uploads/(\d{4}/\d{2}/[^/]+)$#', $path, $m) || !preg_match('/\.(jpe?g|png|gif|webp)$/i', $m[1])) { return; }
	$fixed = preg_replace('/-optimized(?=\.(?:jpe?g|png|gif|webp))/i', '', $m[1], 1);
	if ($fixed === $m[1] || strpos($fixed, '..') !== false) { return; }
	$file = WP_CONTENT_DIR . '/uploads/' . $fixed;
	if (!is_file($file)) { return; }
	header('Location: ' . content_url('uploads/' . str_replace('%2F', '/', rawurlencode($fixed))), true, 301);
	header('Cache-Control: public, max-age=2592000');
	exit;
})();
