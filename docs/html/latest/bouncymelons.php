<?php

Header("Content-Type: text/x-php");
Header('Content-Disposition: attachment; filename="bouncymelons.php"');
echo file_get_contents("bouncymelons-0.2.php");
