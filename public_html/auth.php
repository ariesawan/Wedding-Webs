<?php
/**
 * Berkas nyasar dari pemasangan lama — salinan admin/ atau inc/ yang sempat
 * terekstrak ke root situs. Isinya diganti penolak ini supaya tidak bisa
 * dipanggil dari peramban. AMAN DIHAPUS dari server.
 */
http_response_code(404);
exit;
