<?php
declare(strict_types=1);
$currentScript = (string)($_SERVER['PHP_SELF'] ?? '');

$currentFile = basename($currentScript, '.php');
$currentFileWithExtension = basename($currentScript);

$currentDir = basename(
    dirname($currentScript)
);


/* ============================================================
 * HTML ESCAPE HELPER
 * ============================================================ */

if (!function_exists('topbar_h')) {
    function topbar_h(mixed $value): string
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


/* ============================================================
 * CHECK IF CURRENT USER IS A MEMBER
 * ============================================================ */

if (!function_exists('topbar_is_member')) {
    function topbar_is_member(): bool
    {
        /*
         * Use the main member helper when available.
         */
        if (function_exists('is_member_user')) {
            return is_member_user();
        }

        /*
         * Fallback when member helper has not been loaded.
         */
        $role = strtolower(
            trim(
                (string)($_SESSION['role'] ?? '')
            )
        );

        return in_array(
            $role,
            [
                'member',
                'church member'
            ],
            true
        );
    }
}




$directoryTitles = [

    /* --------------------------------------------------------
     * Main People / Registers
     * -------------------------------------------------------- */

    'members'           => 'Members',

    'baptism'           => 'Baptism Register',

    'visitors'          => 'Church Visitors Book',

    'wedding_vows'      => 'Wedding Vows Book',

    'sunday_school'     => 'Sunday School',

    'youth'             => 'Youth Registry',
    
    'converts' => 'Converts & Nurture',

    /* --------------------------------------------------------
     * Groups & Fellowships
     * -------------------------------------------------------- */

    'zones'             => 'Church Zones',

    'church_zones'      => 'Church Zones',

    'departments'       => 'Departments',


    /* --------------------------------------------------------
     * Finance & Giving
     * -------------------------------------------------------- */

    'offertory'         => 'Offertory',

    'tithe'             => 'Tithe',

    'thanksgiving'      => 'Thanksgiving',

    'pledges'           => 'Pledges',

    'pledge_management' => 'Pledge Management',

    'contributions'     => 'Contributions',

    'good_samaritan'    => 'Good Samaritan',

    'church_assets'     => 'Church Assets',


    /* --------------------------------------------------------
     * Projects
     * -------------------------------------------------------- */

    'projects'          => 'Church Projects',

    'fundraising'       => 'Fundraising',



    /* --------------------------------------------------------
     * Church Life
     * -------------------------------------------------------- */

    'attendance'        => 'Attendance',

    'events'            => 'Events',

    'missions'          => 'Missions & Outreach',

    'bulk_messages'     => 'Bulk Messages',


    /* --------------------------------------------------------
     * Programs & Services
     * -------------------------------------------------------- */

    'church_programs'   => 'Church Programs',

    'church_services'   => 'Church Services',

    'church_theme'      => 'Annual Church Theme',

    'announcements'     => 'Announcements',

    'harvests'          => 'Annual Harvests',


    /* --------------------------------------------------------
     * Reports
     * -------------------------------------------------------- */

    'reports'           => 'Reports',


    /* --------------------------------------------------------
     * Administration
     * -------------------------------------------------------- */

    'settings'          => 'Site Settings',

    'users'             => 'Manage Users',

    'permissions'       => 'User Permissions',

    'activity_logs'     => 'Activity Logs',


    /* --------------------------------------------------------
     * Member Portal
     * -------------------------------------------------------- */

    'member'            => 'Member Portal',
];


/* ============================================================
 * FILE-SPECIFIC TITLES
 * ============================================================
 *
 * File-specific titles take priority for non-index pages.
 *
 * Example:
 *
 * /church_services/details.php
 *
 * should display:
 *
 * Service Details
 *
 * rather than:
 *
 * Church Services
 */

$fileTitles = [

    /* Main */

    'dashboard'          => 'Dashboard',

    'index'              => 'Dashboard',


    /* Programs */

    'calendar'           => 'Programs Calendar',

    'programs'           => 'Church Programs',


    /* Services */

    'recordings'         => 'Service Uploads',

    'details'            => 'Service Details',

    'service-details'    => 'Service Details',

    'services'           => 'Live & Recorded Services',


    /* Church Information */

    'announcements'      => 'Announcements',

    'annual-theme'       => 'Annual Church Theme',

    'harvests'           => 'Church Harvests',


    /* Member Portal */

    'notebook'           => 'My Notebook',

    'giving'             => 'My Giving',

    'children'           => 'Children Registry',

    'church'             => 'Church Groups',

    'projects'           => 'Projects & Fundraising',

    'attendance'         => 'My Attendance',

    'events'             => 'Events',

    'profile'            => 'My Profile',

    'payment-details'    => 'Payment Details',

    'payment-gateways'   => 'Payment Gateways',

    'member-payments'    => 'Member Payment Details',


    /* Settings */

    'theme'              => 'Theme Colours',


    /* Reports */

    'finance'            => 'Financial Statements',


    /* Administration */

    'activity_logs'      => 'Activity Logs',

    'permissions'        => 'User Permissions',


    /* Church Visitors */

    'process-visitor'    => 'Church Visitors Book',

    'print'              => 'Print Register',

    'export'             => 'Export Records',


    /* Wedding Vows */

    'process-wedding'    => 'Wedding Vows Book',

    'record'             => 'Wedding Vows Record',
];


/* ============================================================
 * QUERY/TAB TITLES
 * ============================================================
 *
 * Some member pages use one PHP page for several modules.
 *
 * Example:
 *
 * member/children.php?tab=baptism
 */

$queryTitles = [

    /* --------------------------------------------------------
     * Children Registry
     * -------------------------------------------------------- */

    'children.php' => [

        'baptism' => 'Baptism Register',

        'sunday'  => 'Sunday School',

        'youth'   => 'Youth Registry',
    ],


    /* --------------------------------------------------------
     * Church Groups
     * -------------------------------------------------------- */

    'church.php' => [

        'zones'       => 'Church Zones',

        'departments' => 'Departments',
    ],


    /* --------------------------------------------------------
     * Member Giving
     * -------------------------------------------------------- */

    'giving.php' => [

        'tithe'         => 'My Tithe',

        'thanksgiving'  => 'My Thanksgiving',

        'pledges'       => 'My Pledges',

        'contributions' => 'My Contributions',
    ],
];


/* ============================================================
 * RESOLVE PAGE TITLE
 * ============================================================ */

$pageTitleResolved = '';


/*
 * ------------------------------------------------------------
 * 1. Explicit page title
 * ------------------------------------------------------------
 *
 * A page can define:
 *
 * $pageTitle = 'Custom Page Title';
 *
 * before including topbar.php.
 */

if (
    isset($pageTitle)
    && trim((string)$pageTitle) !== ''
) {
    $pageTitleResolved = trim(
        (string)$pageTitle
    );
}


/*
 * ------------------------------------------------------------
 * 2. Query / tab title
 * ------------------------------------------------------------
 */

if ($pageTitleResolved === '') {

    $tab = trim(
        (string)($_GET['tab'] ?? '')
    );

    if (
        $tab !== ''
        && isset(
            $queryTitles[$currentFileWithExtension][$tab]
        )
    ) {
        $pageTitleResolved =
            $queryTitles[$currentFileWithExtension][$tab];
    }
}


/*
 * ------------------------------------------------------------
 * 3. Non-index file-specific title
 * ------------------------------------------------------------
 *
 * This must happen before directory detection.
 *
 * Example:
 *
 * /church_services/details.php
 *
 * should display:
 *
 * Service Details
 */

if (
    $pageTitleResolved === ''
    && $currentFile !== 'index'
    && isset($fileTitles[$currentFile])
) {
    $pageTitleResolved =
        $fileTitles[$currentFile];
}


/*
 * ------------------------------------------------------------
 * 4. Directory/module title
 * ------------------------------------------------------------
 *
 * Important for:
 *
 * /visitors/index.php
 * /wedding_vows/index.php
 *
 * Otherwise index.php would incorrectly show Dashboard.
 */

if (
    $pageTitleResolved === ''
    && isset($directoryTitles[$currentDir])
) {
    $pageTitleResolved =
        $directoryTitles[$currentDir];
}


/*
 * ------------------------------------------------------------
 * 5. Generic file title
 * ------------------------------------------------------------
 */

if (
    $pageTitleResolved === ''
    && isset($fileTitles[$currentFile])
) {
    $pageTitleResolved =
        $fileTitles[$currentFile];
}


/*
 * ------------------------------------------------------------
 * 6. Human-readable fallback
 * ------------------------------------------------------------
 */

if (
    $pageTitleResolved === ''
    && $currentFile !== ''
) {
    $pageTitleResolved = ucwords(
        str_replace(
            ['_', '-'],
            ' ',
            $currentFile
        )
    );
}


/*
 * ------------------------------------------------------------
 * Final fallback
 * ------------------------------------------------------------
 */

if ($pageTitleResolved === '') {
    $pageTitleResolved = isset($siteName) && trim((string)$siteName) !== ''
        ? (string)$siteName
        : (defined('APP_NAME') ? APP_NAME : 'Church Management System');
}


/* ============================================================
 * USER INFORMATION
 * ============================================================ */

$isMember = topbar_is_member();

$fullName = trim(
    (string)($_SESSION['full_name'] ?? 'Admin')
);

if ($fullName === '') {
    $fullName = 'Admin';
}


/*
 * Get first name safely.
 */

$nameParts = preg_split(
    '/\s+/',
    $fullName
);

$firstName = trim(
    (string)($nameParts[0] ?? 'Admin')
);

if ($firstName === '') {
    $firstName = 'Admin';
}


/*
 * Avatar first letter.
 */

$avatarLetter = strtoupper(
    substr(
        $fullName,
        0,
        1
    )
);

if ($avatarLetter === '') {
    $avatarLetter = 'A';
}


/* ============================================================
 * REPORT PERMISSIONS
 * ============================================================
 *
 * Members never receive Finance/Reports buttons.
 *
 * If the permission system exists, user must have reports.view.
 *
 * If the permission system has not been loaded, retain backward
 * compatibility and allow non-members to see the buttons.
 */

$canViewReports = false;

if (!$isMember) {

    if (function_exists('current_user_can')) {

        $canViewReports =
            current_user_can('reports.view');

    } else {

        /*
         * Backward-compatible fallback.
         */
        $canViewReports = true;
    }
}

?>

<header class="topbar">

    <!-- ======================================================
         MOBILE SIDEBAR TOGGLE
         ====================================================== -->

    <button
        type="button"
        class="topbar-toggle"
        id="sidebarToggle"
        aria-label="Open navigation menu"
        aria-controls="sidebar"
        aria-expanded="false"
    >
        <i
            class="fas fa-bars"
            aria-hidden="true"
        ></i>
    </button>


    <!-- ======================================================
         CURRENT PAGE TITLE
         ====================================================== -->

    <div
        class="topbar-title"
        title="<?= topbar_h($pageTitleResolved) ?>"
    >
        <?= topbar_h($pageTitleResolved) ?>
    </div>


    <!-- ======================================================
         RIGHT SIDE
         ====================================================== -->

    <div class="topbar-right">

        <!-- Current date -->

        <span
            class="topbar-date"
            id="topbarDate"
            aria-live="polite"
        ></span>


        <!-- ==================================================
             FINANCE & REPORTS
             Hidden from members
             ================================================== -->

        <?php if ($canViewReports): ?>

            <a
                href="<?= topbar_h(
                    BASE_URL . 'reports/finance.php'
                ) ?>"
                class="topbar-btn"
                title="Financial Statements"
            >
                <i
                    class="fas fa-chart-line"
                    aria-hidden="true"
                ></i>

                <span>
                    Finance
                </span>
            </a>


            <a
                href="<?= topbar_h(
                    BASE_URL . 'reports/'
                ) ?>"
                class="topbar-btn"
                title="Reports and PDF"
            >
                <i
                    class="fas fa-print"
                    aria-hidden="true"
                ></i>

                <span>
                    Reports
                </span>
            </a>

        <?php endif; ?>


        <!-- ==================================================
             USER
             ================================================== -->

        <div
            class="topbar-user"
            title="<?= topbar_h($fullName) ?>"
        >

            <span
                class="topbar-avatar"
                aria-hidden="true"
            >
                <?= topbar_h($avatarLetter) ?>
            </span>


            <span class="topbar-username">
                <?= topbar_h($firstName) ?>
            </span>

        </div>

    </div>

</header>


<script>
document.addEventListener('DOMContentLoaded', function () {

    'use strict';


    /* ========================================================
     * TOPBAR DATE
     * ======================================================== */

    const dateElement =
        document.getElementById('topbarDate');


    function updateTopbarDate() {

        if (!dateElement) {
            return;
        }


        const now = new Date();


        try {

            dateElement.textContent =
                new Intl.DateTimeFormat(
                    'en-UG',
                    {
                        weekday: 'short',
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric'
                    }
                ).format(now);

        } catch (error) {

            /*
             * Fallback for older browsers.
             */

            dateElement.textContent =
                now.toLocaleDateString();
        }
    }


    updateTopbarDate();


    /* ========================================================
     * SIDEBAR ELEMENTS
     * ======================================================== */

    const sidebarToggle =
        document.getElementById('sidebarToggle');

    const sidebar =
        document.getElementById('sidebar');


    /*
     * Use the existing overlay when available.
     */

    let overlay =
        document.querySelector('.sidebar-overlay');


    /*
     * If the page does not already contain an overlay,
     * automatically create one.
     */

    if (!overlay && sidebar) {

        overlay =
            document.createElement('div');

        overlay.className =
            'sidebar-overlay';

        overlay.setAttribute(
            'aria-hidden',
            'true'
        );

        document.body.appendChild(
            overlay
        );
    }


    /* ========================================================
     * SIDEBAR HELPERS
     * ======================================================== */

    function sidebarIsOpen() {

        if (!sidebar) {
            return false;
        }

        return sidebar.classList.contains('open');
    }


    function updateToggleState(isOpen) {

        if (!sidebarToggle) {
            return;
        }


        sidebarToggle.setAttribute(
            'aria-expanded',
            isOpen ? 'true' : 'false'
        );


        sidebarToggle.setAttribute(
            'aria-label',
            isOpen
                ? 'Close navigation menu'
                : 'Open navigation menu'
        );


        const icon =
            sidebarToggle.querySelector('i');


        if (icon) {

            icon.classList.toggle(
                'fa-bars',
                !isOpen
            );

            icon.classList.toggle(
                'fa-times',
                isOpen
            );
        }
    }


    function openSidebar() {

        if (!sidebar) {
            return;
        }


        sidebar.classList.add(
            'open'
        );


        /*
         * Additional body class allows CSS to disable
         * background scrolling on phones.
         */

        document.body.classList.add(
            'sidebar-open'
        );


        if (overlay) {

            overlay.classList.add(
                'show'
            );

            overlay.setAttribute(
                'aria-hidden',
                'false'
            );
        }


        updateToggleState(true);
    }


    function closeSidebar() {

        if (!sidebar) {
            return;
        }


        sidebar.classList.remove(
            'open'
        );


        document.body.classList.remove(
            'sidebar-open'
        );


        if (overlay) {

            overlay.classList.remove(
                'show'
            );

            overlay.setAttribute(
                'aria-hidden',
                'true'
            );
        }


        updateToggleState(false);
    }


    function toggleSidebar() {

        if (sidebarIsOpen()) {

            closeSidebar();

        } else {

            openSidebar();
        }
    }


    /* ========================================================
     * TOGGLE BUTTON
     * ======================================================== */

    if (
        sidebarToggle
        && sidebar
    ) {

        sidebarToggle.addEventListener(
            'click',
            function (event) {

                event.preventDefault();

                event.stopPropagation();

                toggleSidebar();
            }
        );
    }


    /* ========================================================
     * OVERLAY
     * ======================================================== */

    if (overlay) {

        overlay.addEventListener(
            'click',
            function () {

                closeSidebar();
            }
        );
    }


    /* ========================================================
     * ESC KEY
     * ======================================================== */

    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'Escape'
                && sidebarIsOpen()
            ) {
                closeSidebar();
            }
        }
    );


    /* ========================================================
     * CLOSE WHEN SIDEBAR LINK IS CLICKED ON MOBILE
     * ======================================================== */

    if (sidebar) {

        sidebar.addEventListener(
            'click',
            function (event) {

                const link =
                    event.target.closest('a');


                if (!link) {
                    return;
                }


                if (
                    window.innerWidth <= 1000
                ) {
                    closeSidebar();
                }
            }
        );
    }


    /* ========================================================
     * SCREEN RESIZE
     * ======================================================== */

    window.addEventListener(
        'resize',
        function () {

            /*
             * When returning to desktop, remove mobile
             * sidebar/overlay state.
             */

            if (
                window.innerWidth > 1000
                && sidebarIsOpen()
            ) {
                closeSidebar();
            }
        }
    );


    /* ========================================================
     * INITIAL STATE
     * ======================================================== */

    updateToggleState(
        sidebarIsOpen()
    );

});
</script>
