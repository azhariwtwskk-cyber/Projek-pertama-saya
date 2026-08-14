<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

$newPassword = 'TMJ@2026';
$passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    'UPDATE admins
     SET password = :password
     WHERE username = :username'
);

$stmt->execute([
    ':password' => $passwordHash,
    ':username' => 'tmj_manager',
]);

if ($stmt->rowCount() === 1) {
    echo 'Password tmj_manager berjaya ditetapkan semula.';
} else {
    echo 'Akaun tmj_manager tidak dijumpai atau password tidak berubah.';
}