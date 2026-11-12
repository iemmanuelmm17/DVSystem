<?php
declare(strict_types=1);

// Only this directory belongs under the web server document root.
$private = getenv('DVAPP_PRIVATE_DIR') ?: 'D:/private/dvapp';
require $private . '/app.php';
run();
