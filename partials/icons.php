<?php
/**
 * partials/icons.php — inline SVG icon set.
 * Usage: <?= icon('dashboard') ?>
 */
function icon(string $name): string
{
    static $icons = [
        'dashboard' => '<path d="M3 3h8v8H3zM13 3h8v5h-8zM13 12h8v9h-8zM3 15h8v6H3z" fill="none" stroke="currentColor" stroke-width="2"/>',
        'requests'  => '<rect x="4" y="3" width="16" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="2" fill="none"/>',
        'approvals' => '<path d="M9 12l2 2 4-5" stroke="currentColor" stroke-width="2" fill="none"/><rect x="4" y="3" width="16" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>',
        'vehicle'   => '<path d="M5 11l1.5-4A2 2 0 018.4 5h7.2a2 2 0 011.9 2L19 11M5 11h14M5 11v7h2m10-7v7h-2M7 18a1.5 1.5 0 103 0m4 0a1.5 1.5 0 103 0" fill="none" stroke="currentColor" stroke-width="2"/>',
        'trips'     => '<path d="M4 17l6-6-6-6M12 19h8" fill="none" stroke="currentColor" stroke-width="2"/>',
        'map'       => '<path d="M9 4L3 6v14l6-2 6 2 6-2V4l-6 2-6-2zM9 4v14M15 6v14" fill="none" stroke="currentColor" stroke-width="2"/>',
        'report'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2" fill="none" stroke="currentColor" stroke-width="2"/>',
        'history'   => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 3" fill="none" stroke="currentColor" stroke-width="2"/>',
        'shield'    => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z" fill="none" stroke="currentColor" stroke-width="2"/>',
        'users'     => '<circle cx="9" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M2 21c0-4 3-6 7-6s7 2 7 6M17 4a4 4 0 010 8M22 21c0-3-1.5-5-4-5.5" fill="none" stroke="currentColor" stroke-width="2"/>',
        'bot'       => '<rect x="5" y="8" width="14" height="11" rx="3" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="9.5" cy="13" r="1.4" fill="currentColor"/><circle cx="14.5" cy="13" r="1.4" fill="currentColor"/><path d="M12 8V4m-9 9h2m16 0h2" stroke="currentColor" stroke-width="2"/>',
        'star'      => '<path d="M12 3l2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8L12 3z" fill="none" stroke="currentColor" stroke-width="2"/>',
        'settings'  => '<circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="M19 12a7 7 0 00-.1-1.2l2-1.6-2-3.4-2.4 1a7 7 0 00-2-1.2L14 3h-4l-.5 2.6a7 7 0 00-2 1.2l-2.4-1-2 3.4 2 1.6A7 7 0 005 12c0 .4 0 .8.1 1.2l-2 1.6 2 3.4 2.4-1a7 7 0 002 1.2L10 21h4l.5-2.6a7 7 0 002-1.2l2.4 1 2-3.4-2-1.6c.07-.4.1-.8.1-1.2z" fill="none" stroke="currentColor" stroke-width="2"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2"/>',
        'x'         => '<path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2"/>',
        'check'     => '<path d="M4 12l6 6L20 6" fill="none" stroke="currentColor" stroke-width="2.5"/>',
        'alert'     => '<path d="M12 9v4m0 4h.01M10.3 4l-8 13.5A2 2 0 004 20.5h16a2 2 0 001.7-3L13.7 4a2 2 0 00-3.4 0z" fill="none" stroke="currentColor" stroke-width="2"/>',
        'cloud'     => '<path d="M7 18a4.5 4.5 0 01-.4-9A6 6 0 0119 11a3.5 3.5 0 011 6.9M8 13l4-4m0 0l4 4m-4-4v9" fill="none" stroke="currentColor" stroke-width="2"/>',
        'file'      => '<path d="M6 3h8l4 4v14a1 1 0 01-1 1H6a1 1 0 01-1-1V4a1 1 0 011-1z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 3v4h4M9 12h6M9 16h6" fill="none" stroke="currentColor" stroke-width="2"/>',
        'trash'     => '<path d="M4 7h16M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-9 0l1 13a1 1 0 001 1h6a1 1 0 001-1l1-13M10 11v6m4-6v6" fill="none" stroke="currentColor" stroke-width="2"/>',
        'edit'      => '<path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5zM13 7l4 4" fill="none" stroke="currentColor" stroke-width="2"/>',
        'info'      => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 8h.01M12 11v5" stroke="currentColor" stroke-width="2"/>',
        'eye'       => '<path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/>',
        'eye-off'   => '<path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="M4 4l16 16" stroke="currentColor" stroke-width="2"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';
}
