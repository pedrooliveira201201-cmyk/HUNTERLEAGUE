<?php
/* Volta da autorização do GoTo (OAuth). Cria o canal de aviso e a assinatura das mensagens do WhatsApp. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';
require __DIR__ . '/_goto.php';
header('X-Robots-Tag: noindex');
goto_migrar();
goto_retorno();
