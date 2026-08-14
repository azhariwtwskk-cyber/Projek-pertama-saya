<?php
/** CPMSPro Genesis Global UI v1
 * Presentation helper only. Does not alter sessions, roles, permissions,
 * property_id, SQL queries, authentication, or multi-property scoping.
 */
declare(strict_types=1);
function cpmsGenesisBaseUrl(): string {
    $script = str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos = strpos($script, '/cpms/');
    return $pos === false ? '/cpms/' : substr($script,0,$pos).'/cpms/';
}
function cpmsGenesisHead(): void {
    $b=htmlspecialchars(cpmsGenesisBaseUrl(),ENT_QUOTES,'UTF-8');
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap">';
    echo '<link rel="stylesheet" href="'.$b.'assets/genesis/cpms-genesis-core.css?v=1.0.0">';
}
function cpmsGenesisFooter(): void {
    $b=htmlspecialchars(cpmsGenesisBaseUrl(),ENT_QUOTES,'UTF-8');
    echo '<script src="'.$b.'assets/genesis/cpms-genesis.js?v=1.0.0" defer></script>';
}
