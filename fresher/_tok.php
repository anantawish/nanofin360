<?php
$code = file_get_contents('C:/xampp/htdocs/nanofinance/fresher/customers.php');
$tokens = token_get_all($code);
for ($i=0;$i<12 && $i<count($tokens);$i++) {
    $t=$tokens[$i];
    if (is_array($t)) {
        echo token_name($t[0]) . '|' . str_replace(["\n","\r"],["\\n","\\r"],$t[1]) . "\n";
    } else {
        echo 'CHAR|' . $t . "\n";
    }
}
