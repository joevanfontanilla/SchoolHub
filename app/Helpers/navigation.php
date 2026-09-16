<?php
/**
 * Portal navigation per role (docs/21), used by the sidebar and by the top-bar quick
 * search. It MIRRORS the authorization in routes/web.php; hiding a link is not
 * authorization.
 *
 *   nav      role => entries. An entry is [path, label, icon, available, extra active paths];
 *            a string entry is a group heading.
 *   extra    role => more pages the quick search can open: [path, label, icon].
 *   keywords path => words people may type for that page ("receipt" finds Payments).
 *
 * Use portal_nav() and portal_destinations() in functions.php.
 */

use App\Models\User;

return [
    'nav' => [
        User::ROLE_STUDENT => [
            ['/student/dashboard', 'Dashboard', 'dashboard', true],
            ['/student/profile', 'My Profile', 'user', true],
            ['/student/enrollment', 'Enrollment', 'enrollment', true, ['/student/status']],
            ['/student/requirements', 'Requirements', 'requirements', true],
            ['/student/grades', 'Academic Records', 'book-open', true, ['/student/schedule', '/student/attendance', '/student/history']],
            ['/student/account', 'Payments & Accounts', 'credit-card', true],
            ['/student/documents', 'My Documents', 'document', true],
            ['/student/credentials', 'Document Requests', 'inbox', true],
            ['/portal/announcements', 'Announcements', 'megaphone', true],
            ['/portal/notifications', 'Notifications', 'bell', true],
            ['/password/change', 'Password & Security', 'key', true],
        ],
        User::ROLE_TEACHER => [
            ['/teacher/dashboard', 'Dashboard', 'dashboard', true],
            'Teaching',
            ['/teacher/classes', 'My Classes', 'academic', true],
            ['/teacher/attendance', 'Attendance', 'attendance', true],
            ['/teacher/grades', 'Grades', 'chart-bar', true],
            ['/teacher/schedule', 'Schedule', 'calendar', true],
            'Communication',
            ['/portal/announcements', 'Announcements', 'megaphone', true],
            ['/teacher/announcements', 'Post to Classes', 'send', true],
            ['/portal/notifications', 'Notifications', 'bell', true],
            'Reports',
            ['/teacher/reports', 'Class Reports', 'presentation', true],
        ],
        User::ROLE_REGISTRAR => [
            ['/registrar/dashboard', 'Dashboard', 'dashboard', true],
            'Enrollment',
            ['/registrar/applications', 'Applications', 'inbox', true],
            ['/registrar/students', 'Students', 'users', true],
            ['/registrar/credentials', 'Credential Requests', 'inbox', true],
            'Academic catalog',
            ['/registrar/programs', 'Programs', 'library', true],
            ['/registrar/curricula', 'Curricula', 'layers', true],
            ['/registrar/subjects', 'Subjects', 'book', true],
            ['/registrar/sections', 'Sections', 'table', true, ['/registrar/classes']],
            ['/registrar/rooms', 'Rooms', 'door', true],
            ['/registrar/calendar', 'Academic Calendar', 'calendar', true],
            ['/registrar/grading', 'Grading Periods', 'calendar-check', true],
            'Communication',
            ['/manage/announcements', 'Announcements', 'megaphone', true, ['/portal/announcements']],
            ['/portal/notifications', 'Notifications', 'bell', true],
            'Reports',
            ['/registrar/reports', 'Reports', 'presentation', true],
        ],
        User::ROLE_CASHIER => [
            ['/cashier/dashboard', 'Dashboard', 'dashboard', true],
            'Finance',
            ['/cashier/payments', 'Online Payments', 'inbox', true],
            ['/cashier/accounts', 'Student Accounts', 'users', true],
            ['/cashier/fees', 'Fee Schedule', 'banknotes', true],
            ['/cashier/reports', 'Reports', 'presentation', true],
            'Communication',
            ['/portal/announcements', 'Announcements', 'megaphone', true],
            ['/portal/notifications', 'Notifications', 'bell', true],
        ],
        User::ROLE_SUPER_ADMIN => [
            ['/admin/dashboard', 'Dashboard', 'dashboard', true],
            'Administration',
            ['/admin/users', 'Users', 'users', true],
            ['/admin/roles', 'Roles & Permissions', 'shield', true],
            ['/admin/settings', 'System Settings', 'settings', true],
            'Monitoring',
            ['/admin/audit-logs', 'Audit Logs', 'list-check', true],
            ['/admin/login-activity', 'Sign-in Activity', 'key', true],
            ['/admin/backups', 'Backups', 'database', true],
            ['/admin/health', 'System Health', 'status', true],
            ['/admin/reports', 'Reports', 'presentation', true],
            'Communication',
            ['/manage/announcements', 'Announcements', 'megaphone', true, ['/portal/announcements']],
            ['/portal/notifications', 'Notifications', 'bell', true],
        ],
    ],

    'extra' => [
        User::ROLE_STUDENT => [
            ['/student/schedule', 'Class Schedule', 'calendar'],
            ['/student/attendance', 'Attendance', 'attendance'],
            ['/student/history', 'Academic History', 'history'],
            ['/student/status', 'Application Status', 'status'],
        ],
        User::ROLE_TEACHER => [
            ['/password/change', 'Password & Security', 'key'],
        ],
        User::ROLE_REGISTRAR => [
            ['/registrar/reports/enrollment', 'Enrollment List', 'users'],
            ['/registrar/reports/applications', 'Applicants by Status', 'inbox'],
            ['/registrar/reports/requirements', 'Requirements Status', 'requirements'],
            ['/registrar/reports/history', 'Enrollment History', 'history'],
            ['/password/change', 'Password & Security', 'key'],
        ],
        User::ROLE_CASHIER => [
            ['/cashier/reports/collections', 'Collections Report', 'banknotes'],
            ['/cashier/reports/outstanding', 'Outstanding Balances', 'credit-card'],
            ['/password/change', 'Password & Security', 'key'],
        ],
        User::ROLE_SUPER_ADMIN => [
            ['/admin/users/create', 'New Staff Account', 'plus'],
            ['/admin/reports/accounts', 'Account Review', 'users'],
            ['/admin/reports/sign-ins', 'Sign-in Summary', 'key'],
            ['/admin/reports/activity', 'Activity Summary', 'list-check'],
            ['/admin/reports/usage', 'System Usage', 'database'],
            ['/password/change', 'Password & Security', 'key'],
        ],
    ],

    'keywords' => [
        '/student/profile'          => 'personal information address guardian contact',
        '/student/enrollment'       => 'apply application subjects register term',
        '/student/requirements'     => 'upload documents birth certificate form 138 id photo',
        '/student/grades'           => 'grades gwa report card marks',
        '/student/schedule'         => 'timetable classes rooms',
        '/student/account'          => 'payment pay fees balance tuition receipt statement gcash',
        '/student/documents'        => 'files uploads receipts statements schedule proof download print',
        '/password/change'          => 'settings password security account',
        '/teacher/classes'          => 'roster students list',
        '/teacher/grades'           => 'prelim midterm finals grade sheet',
        '/teacher/announcements'    => 'post notice class announcement',
        '/teacher/reports'          => 'class list attendance grade sheet print export csv',
        '/registrar/applications'   => 'queue review approve enrollment applicants',
        '/registrar/students'       => 'records student number search credit transfer credited subjects',
        '/registrar/credentials'    => 'document requests tor transcript good moral certificate release',
        '/student/credentials'      => 'request document tor transcript good moral certificate of enrollment',
        '/registrar/sections'       => 'classes schedule blocks',
        '/registrar/calendar'       => 'semester term dates enrollment window academic year open',
        '/registrar/grading'        => 'periods open close weights',
        '/registrar/reports'        => 'enrollment list statistics export',
        '/cashier/payments'         => 'verify online gcash proof',
        '/cashier/accounts'         => 'assessment balance student ledger window payment',
        '/cashier/fees'             => 'tuition fee types amounts',
        '/cashier/reports'          => 'collections outstanding daily monthly export',
        '/admin/users'              => 'accounts staff create disable unlock',
        '/admin/roles'              => 'permissions access',
        '/admin/settings'           => 'school name logo configuration',
        '/admin/audit-logs'         => 'history activity trail',
        '/admin/backups'            => 'restore database export',
        '/manage/announcements'     => 'post notice news publish',
        '/portal/notifications'     => 'inbox alerts messages',
    ],
];
