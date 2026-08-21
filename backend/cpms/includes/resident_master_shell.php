<?php
if (!function_exists('cpmsResidentMasterShell')) {
function cpmsResidentMasterShell(string $active = 'dashboard'): string {
    $items = [
        'dashboard' => ['resident_dashboard.php','Dashboard'],
        'requests' => ['resident_requests.php','Requests'],
        'facilities' => ['facility_calendar.php','Facilities'],
        'visitors' => ['visitor_passes.php','Visitors'],
        'announcements' => ['resident_announcements.php','Announcements'],
        'notifications' => ['resident_notifications.php','Notifications'],
        'profile' => ['resident_profile.php','Profile'],
    ];
    $html='<nav class="rms-nav" aria-label="Resident navigation">';
    foreach ($items as $key=>$item) {
        $cls=$key===$active?' class="active"':'';
        $html.='<a'.$cls.' href="'.htmlspecialchars($item[0],ENT_QUOTES,'UTF-8').'">'.htmlspecialchars($item[1],ENT_QUOTES,'UTF-8').'</a>';
    }
    $html.='</nav>';
    return $html;
}}
