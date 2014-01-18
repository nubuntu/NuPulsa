<?php
include("db.php");
include("php2ym.php");
include("pulsa.php");
$pulsa = NuPulsa::getObj();
$pulsa->run();
?>
