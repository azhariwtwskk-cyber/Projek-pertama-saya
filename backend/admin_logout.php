<?php

session_start();


// Kosongkan semua session

$_SESSION = [];


// Musnahkan session

session_destroy();


// Kembali ke halaman login

header("Location: admin_login.php");

exit();

?>