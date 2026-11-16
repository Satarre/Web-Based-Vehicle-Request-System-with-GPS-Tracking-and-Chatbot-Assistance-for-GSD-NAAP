<?php
declare(strict_types=1);
/**
 * partials/nav.php — single source of truth for sidebar navigation.
 * sidebar.php renders nav_for($USER['role_id']); pages must NOT define $NAV.
 */
function nav_for(int $role_id): array
{
    return match ($role_id) {
        1 => [['label' => '', 'items' => [
            ['href' => BASE_URL . 'mis/dashboard.php', 'icon' => 'dashboard', 'label' => 'Dashboard'],
            ['href' => BASE_URL . 'mis/accounts.php',  'icon' => 'users',    'label' => 'Accounts & Roles'],
            ['href' => BASE_URL . 'mis/fleet.php',     'icon' => 'vehicle',  'label' => 'Vehicles & GPS Devices'],
            ['href' => BASE_URL . 'tracking.php',      'icon' => 'map',      'label' => 'Live Tracking'],
['href' => BASE_URL . 'mis/audit.php',   'icon' => 'history',  'label' => 'System Audit Trail'],
            ['href' => BASE_URL . 'mis/chatbot.php',   'icon' => 'bot',      'label' => 'Chatbot FAQs'],
            ['href' => BASE_URL . 'mis/feedback.php',  'icon' => 'star',     'label' => 'Feedback'],
            ['href' => BASE_URL . 'mis/reports.php',   'icon' => 'report',   'label' => 'Reports'],
            ['href' => BASE_URL . 'mis/settings.php',  'icon' => 'settings', 'label' => 'System Settings'],
        ]]],
        2 => [['label' => '', 'items' => [
            ['href' => BASE_URL . 'director/dashboard.php', 'icon' => 'dashboard', 'label' => 'SAO Dashboard', 'badge' => TripState::PENDING],
            ['href' => BASE_URL . 'director/approvals.php', 'icon' => 'approvals', 'label' => 'Approve Requests', 'badge' => TripState::PENDING],
            ['href' => BASE_URL . 'director/trips.php',     'icon' => 'trips',     'label' => 'Trip Tickets'],
            ['href' => BASE_URL . 'director/reports.php',   'icon' => 'report',    'label' => 'Reports'],
            ['href' => BASE_URL . 'director/vehicles.php',  'icon' => 'vehicle',   'label' => 'Vehicles'],
            ['href' => BASE_URL . 'director/feedback.php',  'icon' => 'star',      'label' => 'Feedback'],
            ['href' => BASE_URL . 'mis/chatbot.php',   'icon' => 'bot',       'label' => 'Chatbot FAQs'],
            ['href' => BASE_URL . 'tracking.php',           'icon' => 'map',       'label' => 'Live Tracking'],
        ]]],
        3 => [['label' => '', 'items' => [
            ['href' => BASE_URL . 'staff/dashboard.php',     'icon' => 'dashboard', 'label' => 'Dispatcher Dashboard', 'badge' => TripState::APPROVED],
            ['href' => BASE_URL . 'staff/assignments.php',   'icon' => 'vehicle',   'label' => 'Assignments', 'badge' => TripState::APPROVED],
            ['href' => BASE_URL . 'staff/trips.php',         'icon' => 'trips',     'label' => 'Trip Tickets'],
            ['href' => BASE_URL . 'mis/fleet.php',           'icon' => 'vehicle',   'label' => 'Vehicles & GPS Devices'],
            ['href' => BASE_URL . 'flags.php',               'icon' => 'alert',     'label' => 'Flags'],
            ['href' => BASE_URL . 'tracking.php',            'icon' => 'map',       'label' => 'Live Tracking'],
            ['href' => BASE_URL . 'director/reports.php',    'icon' => 'report',    'label' => 'Reports'],
        ]]],
        4 => [['label' => '', 'items' => [
            ['href' => BASE_URL . 'driver/dashboard.php', 'icon' => 'trips',    'label' => 'My Trips'],
            ['href' => BASE_URL . 'driver/posttrip.php',  'icon' => 'requests', 'label' => 'Post-Trip Report'],
        ]]],
        5 => [['label' => '', 'items' => [
            ['href' => BASE_URL . 'guard/desk.php',    'icon' => 'shield',  'label' => 'Departure / Return Desk'],
            ['href' => BASE_URL . 'guard/history.php', 'icon' => 'history', 'label' => 'Verification History'],
        ]]],
        6 => [['label' => '', 'items' => [
            ['href' => BASE_URL . 'personnel/requests.php', 'icon' => 'requests', 'label' => 'My Requests'],
            ['href' => BASE_URL . 'personnel/history.php',  'icon' => 'history',  'label' => 'Request History'],
            ['href' => BASE_URL . 'personnel/feedback.php', 'icon' => 'star',     'label' => 'Feedback'],
        ]]],
        7 => [['label' => '', 'items' => [
            ['href' => BASE_URL . 'tracker.php', 'icon' => 'map', 'label' => 'My Tracker'],
        ]]],
        default => [],
    };
}
