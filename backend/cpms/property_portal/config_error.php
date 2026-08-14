<?php
declare(strict_types=1);
$bootstrapFile = dirname(__DIR__) . '/includes/cpms_bootstrap.php';
if (!is_file($bootstrapFile)) { http_response_code(500); exit('CPMS bootstrap could not be found.'); }
require_once $bootstrapFile;
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($conn) || !($conn instanceof mysqli)) { http_response_code(500); exit('CPMS database connection is unavailable.'); }
$conn->set_charset('utf8mb4');
require_once dirname(__DIR__) . '/includes/admin_language.php';
function systemOwnerEscape(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function systemOwnerRedirect(string $path): never { header('Location: '.$path); exit; }
function systemOwnerCsrfToken(): string { if (empty($_SESSION['system_owner_csrf'])) { $_SESSION['system_owner_csrf']=bin2hex(random_bytes(32)); } return (string)$_SESSION['system_owner_csrf']; }
function systemOwnerVerifyCsrf(?string $token): bool { return is_string($token) && isset($_SESSION['system_owner_csrf']) && hash_equals((string)$_SESSION['system_owner_csrf'],$token); }
function systemOwnerAccountExists(mysqli $conn): bool { $r=$conn->query("SELECT 1 FROM system_users WHERE role IN ('system_owner','system_admin') LIMIT 1"); return $r instanceof mysqli_result && $r->num_rows>0; }
function systemOwnerPropertyCount(mysqli $conn): int { $r=$conn->query('SELECT COUNT(*) AS total FROM cpms_properties'); if(!($r instanceof mysqli_result)){return 0;} $row=$r->fetch_assoc(); return (int)($row['total']??0); }
