<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
demo_migrate(demo_pdo(demo_config()));
echo "schema up to date\n";
