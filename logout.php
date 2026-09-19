<?php
declare(strict_types=1);
require __DIR__ . '/lib/core.php';
rnw_core_auth();
header('Location: ' . hub_logout_url());
exit;
