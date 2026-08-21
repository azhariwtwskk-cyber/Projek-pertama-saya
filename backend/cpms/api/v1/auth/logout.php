<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
cpmsApiMethod('POST');
$identity = cpmsApiAuth();
$db = cpmsApiDatabase();
$tokenId = (int) $identity['token_id'];
$stmt = $db->prepare(
    'UPDATE cpms_api_tokens SET revoked_at=NOW() WHERE id=? AND revoked_at IS NULL'
);
if (!$stmt) {
    throw new RuntimeException('Unable to prepare logout.');
}
$stmt->bind_param('i', $tokenId);
$stmt->execute();
$stmt->close();
cpmsApiAudit($db, $identity, 'workforce.logout', 'success', 'api_token', $tokenId);
cpmsApiRespond(['logged_out' => true]);

