<?php

$password = 'CHANGE_ME';

echo '<h3>Password:</h3>';
echo $password;

echo '<br><br><h3>Hash:</h3>';
echo password_hash($password, PASSWORD_DEFAULT);