<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';
header('Cache-Control: public, max-age=3600');
json_out(['cidades' => cidades_liberadas()]);
