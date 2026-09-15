<?php
/**
 * The portal icon set (docs/21): 24 × 24 outline icons drawn for SchoolHub from
 * simple shapes, rendered with a round 1.8 stroke in currentColor. No icon font,
 * no library (docs/00). Use icon('name') — see functions.php.
 *
 * Icons are decorative: icon() marks them aria-hidden. Whatever an icon means must
 * also be said in text next to it.
 *
 * @return array<string,string> name => inner SVG markup
 */

return [
    // Navigation
    'home'          => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
    'dashboard'     => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/>',
    'user'          => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'users'         => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.7a3.5 3.5 0 0 1 0 6.6"/><path d="M18.2 14.3A6.5 6.5 0 0 1 21.5 20"/>',
    'enrollment'    => '<path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/><rect x="9" y="2.5" width="6" height="3.5" rx="1"/><path d="m9 13.5 2 2 4-4"/>',
    'requirements'  => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="m9 14.5 2 2 4-4"/>',
    'document'      => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6M9 9h1.5"/>',
    'academic'      => '<path d="M2 9.5 12 5l10 4.5L12 14z"/><path d="M6 11.3V16c0 1.7 2.7 3 6 3s6-1.3 6-3v-4.7"/><path d="M22 9.5V15"/>',
    'book-open'     => '<path d="M2.5 5.5C5 4.3 8.2 4.1 12 6c3.8-1.9 7-1.7 9.5-.5V19c-2.5-1.2-5.7-1.4-9.5.5-3.8-1.9-7-1.7-9.5-.5z"/><path d="M12 6v13.5"/>',
    'book'          => '<path d="M5 4.5A1.5 1.5 0 0 1 6.5 3H19v15H6.5A1.5 1.5 0 0 0 5 19.5z"/><path d="M5 19.5A1.5 1.5 0 0 0 6.5 21H19v-3"/><path d="M9 7.5h6"/>',
    'calendar'      => '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/><path d="M7.5 13.5h2M11 13.5h2M14.5 13.5h2M7.5 17h2M11 17h2"/>',
    'calendar-check'=> '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/><path d="m9 15 2 2 4-4"/>',
    'chart-bar'     => '<path d="M4 20.5h16"/><rect x="5.5" y="11" width="3" height="6.5" rx=".8"/><rect x="10.5" y="5" width="3" height="12.5" rx=".8"/><rect x="15.5" y="13" width="3" height="4.5" rx=".8"/>',
    'attendance'    => '<circle cx="12" cy="12" r="9"/><path d="m8 12.3 2.7 2.7L16 9.7"/>',
    'history'       => '<path d="M3.5 12a8.5 8.5 0 1 0 2.5-6"/><path d="M3 3.5V8h4.5"/><path d="M12 8v4.5l3 1.8"/>',
    'clock'         => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'credit-card'   => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6 15h4"/>',
    'banknotes'     => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9.5h.01M18 14.5h.01"/>',
    'receipt'       => '<path d="M5 3h14v18l-2.5-1.5L14 21l-2-1.5L10 21l-2.5-1.5L5 21z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
    'megaphone'     => '<path d="M3 10v4a1 1 0 0 0 1 1h3l7 4.5V4.5L7 9H4a1 1 0 0 0-1 1z"/><path d="M17.5 9a4.5 4.5 0 0 1 0 6"/><path d="M7 15.5 8.5 20h2.5"/>',
    'bell'          => '<path d="M6 16v-5a6 6 0 1 1 12 0v5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
    'settings'      => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
    'logout'        => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="m15 8 4 4-4 4"/><path d="M19 12H9"/>',
    'inbox'         => '<path d="M3 13.5 5.5 5h13l2.5 8.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 13.5h5l1.5 2.5h5l1.5-2.5h5"/>',
    'library'       => '<path d="M3 21h18M5 18h14M12 3 4 7.5h16z"/><path d="M6.5 10.5v5M10 10.5v5M14 10.5v5M17.5 10.5v5"/>',
    'layers'        => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 12.5 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
    'table'         => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9.5 10v10"/>',
    'door'          => '<path d="M6 21V4a1 1 0 0 1 1-1h8.5L18 4.3V21"/><path d="M3.5 21h17"/><path d="M14.5 12.5h.01"/>',
    'presentation'  => '<path d="M3 4h18M4.5 4v10a1 1 0 0 0 1 1h13a1 1 0 0 0 1-1V4"/><path d="M12 15v3M8.5 21l3.5-3 3.5 3"/><path d="m8 11.5 2.5-2.5 2 2L16 7.5"/>',
    'shield'        => '<path d="M12 3 4.5 6v5.5c0 4.5 3.2 8.2 7.5 9.5 4.3-1.3 7.5-5 7.5-9.5V6z"/><path d="m9 12 2 2 4-4"/>',
    'database'      => '<ellipse cx="12" cy="5.5" rx="8" ry="2.5"/><path d="M4 5.5V12c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5V5.5"/><path d="M4 12v6.5c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5V12"/>',
    'list-check'    => '<path d="M11 6h9M11 12h9M11 18h9"/><path d="m3.5 6 1.3 1.3L7.5 4.6M3.5 12l1.3 1.3 2.7-2.7M3.5 18l1.3 1.3 2.7-2.7"/>',
    'status'        => '<path d="M3 12h4l2.5-6 5 12 2.5-6h4"/>',

    // Actions and objects
    'search'        => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    'menu'          => '<path d="M4 6.5h16M4 12h11M4 17.5h16"/>',
    'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
    'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
    'arrow-left'    => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
    'arrow-right'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'bolt'          => '<path d="M13 2.5 4.5 13.5H11l-1 8 8.5-11H12z"/>',
    'download'      => '<path d="M12 3.5v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M4 17v2.5A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5V17"/>',
    'eye'           => '<path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z"/><circle cx="12" cy="12" r="3"/>',
    'eye-off'       => '<path d="M10.6 5.1A10 10 0 0 1 12 5c6 0 9.5 7 9.5 7a17 17 0 0 1-3 3.9M6.6 6.6A16.5 16.5 0 0 0 2.5 12S6 19 12 19a9.6 9.6 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="m3 3 18 18"/>',
    'lock'          => '<rect x="4.5" y="10.5" width="15" height="10.5" rx="2"/><path d="M8 10.5v-3a4 4 0 0 1 8 0v3"/>',
    'key'           => '<circle cx="8" cy="15" r="4.5"/><path d="m11.2 11.8 8.3-8.3M16.5 6.5 19 9M14 9l2 2"/>',
    'target'        => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
    'map-pin'       => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    'pencil'        => '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
    'plus'          => '<path d="M12 5v14M5 12h14"/>',
    'check'         => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    'x'             => '<path d="M6 6l12 12M18 6 6 18"/>',
    'mail'          => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 7 8.5-7"/>',
    'send'          => '<path d="M21 3 10.5 13.5"/><path d="m21 3-6.5 18-4-7.5L3 9.5z"/>',
    'upload'        => '<path d="M12 15.5v-11"/><path d="m7.5 8.5 4.5-4.5 4.5 4.5"/><path d="M4 17v2.5A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5V17"/>',
    'alert'         => '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17.5h.01"/>',
    'info'          => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5h.01"/>',
    'trophy'        => '<path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 6H4.5v1.5A3.5 3.5 0 0 0 8 11M16 6h3.5v1.5A3.5 3.5 0 0 1 16 11"/><path d="M12 13v4M8.5 21h7M9.5 17h5v4h-5z"/>',
    'sparkles'      => '<path d="M11 3.5 12.6 8 17 9.5l-4.4 1.6L11 15.5l-1.6-4.4L5 9.5 9.4 8z"/><path d="M18.5 14.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/>',
];
