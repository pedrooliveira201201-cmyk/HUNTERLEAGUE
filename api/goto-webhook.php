<?php
/* O GoTo avisa aqui cada mensagem do WhatsApp da CallNet (endereço com chave secreta ?k=). */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
require __DIR__ . '/../admin/_lib.php';
require __DIR__ . '/_goto.php';
header('X-Robots-Tag: noindex');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => true]); /* o GoTo testa o endereço com GET */
goto_migrar();
goto_webhook();
