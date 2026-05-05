<?php
$p = 'C:/xampp/htdocs/nanofinance/fresher/customers.php';
$h = fopen($p, 'rb');
$b = fread($h, 16);
fclose($h);
echo bin2hex($b), PHP_EOL;
