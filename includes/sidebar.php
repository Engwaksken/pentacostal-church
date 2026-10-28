<?php
declare(strict_types=1);

$currentScript = (string)($_SERVER['PHP_SELF'] ?? '');

$cur = basename($currentScript);

$dir = basename(
    dirname($currentScript)
);


/* ============================================================
 * BASE URL
 * ============================================================ */

$sidebarBaseUrl = defined('BASE_URL')
    ? (string)BASE_URL
    : '/';

if ($sidebarBaseUrl === '') {
    $sidebarBaseUrl = '/';
}


/* ============================================================
 * HTML ESCAPE
 * ============================================================ */

if (!function_exists('h')) {

    function h(mixed $value): string
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


/* ============================================================
 * SITE SETTINGS
 * ============================================================ */

if (!function_exists('setting_value')) {

    function setting_value(
        mysqli $conn,
        string $key,
        string $default = ''
    ): string {

        try {

            $tableCheck = $conn->query(
                "SHOW TABLES LIKE 'site_settings'"
            );

            if (
                !$tableCheck
                || $tableCheck->num_rows === 0
            ) {
                return $default;
            }


            /*
             * Make sure expected columns exist.
             */
            $columnCheck = $conn->query(
                "SHOW COLUMNS FROM site_settings"
            );

            if (!$columnCheck) {
                return $default;
            }


            $columns = [];

            while (
                $column =
                    $columnCheck->fetch_assoc()
            ) {
                $columns[] =
                    (string)($column['Field'] ?? '');
            }


            if (
                !in_array(
                    'setting_key',
                    $columns,
                    true
                )
                ||
                !in_array(
                    'setting_value',
                    $columns,
                    true
                )
            ) {
                return $default;
            }


            $stmt = $conn->prepare(
                "
                SELECT setting_value
                FROM site_settings
                WHERE setting_key = ?
                LIMIT 1
                "
            );

            if (!$stmt) {
                return $default;
            }


            $stmt->bind_param(
                's',
                $key
            );

            $stmt->execute();


            $result =
                $stmt->get_result();

            $row =
                $result
                    ? $result->fetch_assoc()
                    : null;


            $stmt->close();


            $value = trim(
                (string)(
                    $row['setting_value']
                    ?? ''
                )
            );


            return $value !== ''
                ? $value
                : $default;

        } catch (Throwable $e) {

            /*
             * Do not break the entire sidebar if
             * site_settings has a problem.
             */
            return $default;
        }
    }
}


/* ============================================================
 * MEMBER CHECK
 * ============================================================ */

if (!function_exists('sidebar_is_member')) {

    function sidebar_is_member(): bool
    {

        if (
            function_exists(
                'is_member_user'
            )
        ) {
            return (bool)is_member_user();
        }


        $role = strtolower(
            trim(
                (string)(
                    $_SESSION['role']
                    ?? ''
                )
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


/* ============================================================
 * CURRENT MEMBER ID
 * ============================================================ */

if (!function_exists(
    'sidebar_current_member_id'
)) {

    function sidebar_current_member_id(): int
    {

        if (
            function_exists(
                'current_member_id'
            )
        ) {

            try {

                return (int)
                    current_member_id();

            } catch (Throwable $e) {
                // fallback below
            }
        }


        return (int)(
            $_SESSION['member_id']
            ?? 0
        );
    }
}


/* ============================================================
 * MEMBER UNION CHECK
 * ============================================================ */

if (!function_exists(
    'sidebar_member_union_status'
)) {

    function sidebar_member_union_status(
        mysqli $conn,
        string $union,
        int $memberId
    ): bool {

        if ($memberId <= 0) {
            return false;
        }


        if (
            function_exists(
                'member_union_status'
            )
        ) {

            try {

                return (bool)
                    member_union_status(
                        $conn,
                        $union,
                        $memberId
                    );

            } catch (Throwable $e) {

                return false;
            }
        }


        return false;
    }
}


/* ============================================================
 * PERMISSION CHECK
 * ============================================================ */

if (!function_exists(
    'sidebar_can'
)) {

    function sidebar_can(
        string $permission
    ): bool {

        /*
         * If permission framework exists,
         * enforce it.
         */
        if (
            function_exists(
                'current_user_can'
            )
        ) {

            try {

                return (bool)
                    current_user_can(
                        $permission
                    );

            } catch (Throwable $e) {

                return false;
            }
        }


        /*
         * Backward compatibility for installations
         * without permission helpers.
         */
        return true;
    }
}


/* ============================================================
 * NORMALIZE URL PATH
 * ============================================================ */

if (!function_exists(
    'sidebar_normalize_path'
)) {

    function sidebar_normalize_path(
        string $url
    ): string {

        $path = parse_url(
            $url,
            PHP_URL_PATH
        );


        $path = trim(
            (string)$path,
            '/'
        );


        $base = '';

        if (defined('BASE_URL')) {

            $base = trim(
                (string)(
                    parse_url(
                        BASE_URL,
                        PHP_URL_PATH
                    )
                    ?? ''
                ),
                '/'
            );
        }


        if (
            $base !== ''
            && (
                $path === $base
                ||
                str_starts_with(
                    $path,
                    $base . '/'
                )
            )
        ) {

            $path = ltrim(
                substr(
                    $path,
                    strlen($base)
                ),
                '/'
            );
        }


        return rtrim(
            $path,
            '/'
        );
    }
}


/* ============================================================
 * NAVIGATION LINK
 * ============================================================ */

if (!function_exists('navLink')) {

    function navLink(
        string $href,
        string $icon,
        string $label,
        string $dir,
        string $cur,
        string $match,
        string $permission = 'dashboard.view',
        ?string $queryKey = null,
        ?string $queryValue = null
    ): void {

        if (
            !sidebar_can(
                $permission
            )
        ) {
            return;
        }


        $requestUri =
            (string)(
                $_SERVER['REQUEST_URI']
                ?? ''
            );


        $requestPath =
            sidebar_normalize_path(
                $requestUri
            );


        $hrefPath =
            sidebar_normalize_path(
                $href
            );


        $active = false;


        /* ====================================================
         * TAB/QUERY LINK
         * ==================================================== */

        if (
            $queryKey !== null
            && $queryValue !== null
        ) {

            $currentValue =
                (string)(
                    $_GET[$queryKey]
                    ?? ''
                );


            /*
             * Query parameters are removed by
             * parse_url() from hrefPath.
             */
            $active = (
                $requestPath === $hrefPath
                &&
                hash_equals(
                    $queryValue,
                    $currentValue
                )
            );

        } else {


            /* =================================================
             * EXACT PATH
             * ================================================= */

            $active =
                $requestPath ===
                $hrefPath;


            /* =================================================
             * /module/ versus /module/index.php
             * ================================================= */

            if (
                !$active
                &&
                str_ends_with(
                    $requestPath,
                    '/index.php'
                )
            ) {

                $active =
                    substr(
                        $requestPath,
                        0,
                        -strlen('/index.php')
                    )
                    ===
                    $hrefPath;
            }


            if (
                !$active
                &&
                str_ends_with(
                    $hrefPath,
                    '/index.php'
                )
            ) {

                $active =
                    $requestPath
                    ===
                    substr(
                        $hrefPath,
                        0,
                        -strlen('/index.php')
                    );
            }


            /* =================================================
             * MATCH MODULE DIRECTORY
             * ================================================= */

            if (
                !$active
                &&
                !str_contains(
                    basename($hrefPath),
                    '.'
                )
            ) {

                $active = (
                    $requestPath ===
                    $hrefPath

                    ||

                    $requestPath ===
                    $hrefPath .
                    '/index.php'
                );
            }


            /* =================================================
             * FALLBACK TO SUPPLIED MATCH
             * ================================================= */

            if (!$active) {

                if (
                    str_contains(
                        $match,
                        '.'
                    )
                ) {

                    $active =
                        $cur === $match;

                } else {

                    $active =
                        $dir === $match;
                }
            }
        }


        $class =
            $active
                ? 'active'
                : '';


        $aria =
            $active
                ? ' aria-current="page"'
                : '';


        echo
            '<a href="' .
            h($href) .
            '" class="' .
            $class .
            '"' .
            $aria .
            '>

                <span class="icon">
                    <i class="fas ' .
                    h($icon) .
                    '" aria-hidden="true"></i>
                </span>

                <span class="nav-label">' .
                    h($label) .
                '</span>

            </a>';
    }
}


/* ============================================================
 * SECTION TITLE
 * ============================================================ */

if (!function_exists(
    'sidebar_section'
)) {

    function sidebar_close_section(): void
    {
        global $sidebarSectionOpen;
        if ($sidebarSectionOpen ?? false) echo '</div></section>';
        $sidebarSectionOpen = false;
    }

    function sidebar_section(
        string $title
    ): void {
        global $sidebarSectionOpen;
        if ($sidebarSectionOpen ?? false) echo '</div></section>';
        $sidebarSectionOpen = true;
        $id = 'sidebar-group-' . substr(hash('sha256', $title), 0, 10);
        echo '<section class="sidebar-group is-open">'
            . '<button type="button" class="nav-section" aria-expanded="true" aria-controls="' . $id . '">'
            . '<span>' . h($title) . '</span><i class="fas fa-chevron-down" aria-hidden="true"></i>'
            . '</button><div class="sidebar-group-links" id="' . $id . '">';
    }
}


/* ============================================================
 * SITE INFORMATION
 * ============================================================ */

$siteName =
    'Church System';

$siteSubtitle =
    'Church Management System';

$logoPath = '';


if (
    isset($conn)
    &&
    $conn instanceof mysqli
) {

    $siteName =
        setting_value(
            $conn,
            'site_name',
            $siteName
        );


    $siteSubtitle =
        setting_value(
            $conn,
            'site_subtitle',
            $siteSubtitle
        );


    /*
     * Primary logo setting.
     */
    $logoPath =
        setting_value(
            $conn,
            'site_logo',
            ''
        );


    /*
     * Support older settings key.
     */
    if ($logoPath === '') {

        $logoPath =
            setting_value(
                $conn,
                'logo',
                ''
            );
    }
}


/* ============================================================
 * LOGO URL
 * ============================================================ */

$logoUrl = '';


if ($logoPath !== '') {

    if (
        preg_match(
            '/^https?:\/\//i',
            $logoPath
        )
    ) {

        $logoUrl =
            $logoPath;

    } else {

        $logoUrl =
            $sidebarBaseUrl .
            ltrim(
                $logoPath,
                '/'
            );
    }
}


/* ============================================================
 * CURRENT USER
 * ============================================================ */

$fullName = trim(
    (string)(
        $_SESSION['full_name']
        ?? 'Admin'
    )
);


if ($fullName === '') {
    $fullName = 'Admin';
}


$role = trim(
    (string)(
        $_SESSION['role']
        ?? ''
    )
);


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
 * USER TYPE
 * ============================================================ */

$isMember =
    sidebar_is_member();

$memberId =
    $isMember
        ? sidebar_current_member_id()
        : 0;

?>


<aside
    class="sidebar"
    id="sidebar"
    aria-label="Sidebar navigation"
>


    <!-- ======================================================
         BRAND
         ====================================================== -->

    <div class="sidebar-brand">

        <?php if ($logoUrl !== ''): ?>

            <img
                src="<?= h($logoUrl) ?>"
                alt="<?= h($siteName) ?> Logo"
                class="sidebar-logo"
            >

        <?php else: ?>

            <div
                class="cross"
                aria-hidden="true"
            >
                ✝
            </div>

        <?php endif; ?>


        <h2>
            <?= h($siteName) ?>
        </h2>


        <?php if ($siteSubtitle !== ''): ?>

            <p>
                <?= h($siteSubtitle) ?>
            </p>

        <?php endif; ?>

    </div>


    <!-- ======================================================
         CURRENT USER
         ====================================================== -->

    <div class="sidebar-user">

        <span
            class="avatar"
            aria-hidden="true"
        >
            <?= h($avatarLetter) ?>
        </span>


        <span class="info">

            <strong>
                <?= h($fullName) ?>
            </strong>


            <?php if ($role !== ''): ?>

                <span>
                    <?= h($role) ?>
                </span>

            <?php endif; ?>

        </span>

    </div>


    <!-- ======================================================
         NAVIGATION
         ====================================================== -->

    <nav
        class="sidebar-nav"
        aria-label="Main navigation"
    >


    <?php if ($isMember): ?>


        <!-- ==================================================
             MEMBER PORTAL
             ================================================== -->

        <?php sidebar_section(
            'Member Portal'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'dashboard.php',
            'fa-tachometer-alt',
            'Dashboard',
            $dir,
            $cur,
            'dashboard.php',
            'dashboard.view'
        );

        ?>


        <!-- ==================================================
             MY FAMILY / REGISTERS
             ================================================== -->

        <?php sidebar_section(
            'My Family & Church'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'member/children.php?tab=baptism',
            'fa-water',
            'Baptism Register',
            $dir,
            $cur,
            'children.php',
            'member.children.view',
            'tab',
            'baptism'
        );


        navLink(
            $sidebarBaseUrl .
            'member/children.php?tab=sunday',
            'fa-child',
            'Sunday School',
            $dir,
            $cur,
            'children.php',
            'member.children.view',
            'tab',
            'sunday'
        );


        navLink(
            $sidebarBaseUrl .
            'member/children.php?tab=youth',
            'fa-user-graduate',
            'Youth Registry',
            $dir,
            $cur,
            'children.php',
            'member.children.view',
            'tab',
            'youth'
        );


        navLink(
            $sidebarBaseUrl .
            'member/church.php?tab=zones',
            'fa-map-marker-alt',
            'Church Zones',
            $dir,
            $cur,
            'church.php',
            'member.church.view',
            'tab',
            'zones'
        );


        navLink(
            $sidebarBaseUrl .
            'member/church.php?tab=departments',
            'fa-sitemap',
            'Departments',
            $dir,
            $cur,
            'church.php',
            'member.church.view',
            'tab',
            'departments'
        );

        ?>


        <!-- ==================================================
             MEMBER UNIONS
             ================================================== -->

        <?php

        $showFathersUnion =
            isset($conn)
            &&
            $conn instanceof mysqli
            &&
            sidebar_member_union_status(
                $conn,
                'fathers_union',
                $memberId
            );


        $showMothersUnion =
            isset($conn)
            &&
            $conn instanceof mysqli
            &&
            sidebar_member_union_status(
                $conn,
                'mothers_union',
                $memberId
            );

        ?>


        <?php if ($showFathersUnion): ?>

            <?php

            navLink(
                $sidebarBaseUrl .
                'member/church.php?tab=fathers',
                'fa-male',
                "Fathers' Union",
                $dir,
                $cur,
                'church.php',
                'member.church.view',
                'tab',
                'fathers'
            );

            ?>

        <?php endif; ?>


        <?php if ($showMothersUnion): ?>

            <?php

            navLink(
                $sidebarBaseUrl .
                'member/church.php?tab=mothers',
                'fa-female',
                "Mothers' Union",
                $dir,
                $cur,
                'church.php',
                'member.church.view',
                'tab',
                'mothers'
            );

            ?>

        <?php endif; ?>



        <!-- ==================================================
             CHURCH PROGRAMS
             ================================================== -->

        <?php sidebar_section(
            'Church Programs'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'member/programs.php',
            'fa-calendar-days',
            'Church Programs',
            $dir,
            $cur,
            'programs.php',
            'member.programs.view'
        );


        navLink(
            $sidebarBaseUrl .
            'member/services.php',
            'fa-video',
            'Live & Recorded Services',
            $dir,
            $cur,
            'services.php',
            'member.services.view'
        );


        navLink(
            $sidebarBaseUrl .
            'member/service-details.php',
            'fa-book-open',
            'Service Details',
            $dir,
            $cur,
            'service-details.php',
            'member.services.view'
        );


        navLink(
            $sidebarBaseUrl .
            'member/announcements.php',
            'fa-bullhorn',
            'Announcements',
            $dir,
            $cur,
            'announcements.php',
            'member.announcements.view'
        );

        navLink(
            $sidebarBaseUrl .
            'member/appointments.php',
            'fa-calendar-check',
            'Appointment Requests',
            $dir,
            $cur,
            'appointments.php',
            'member.appointments.view'
        );


        navLink(
            $sidebarBaseUrl .
            'member/annual-theme.php',
            'fa-cross',
            'Annual Church Theme',
            $dir,
            $cur,
            'annual-theme.php',
            'member.theme.view'
        );


        navLink(
            $sidebarBaseUrl .
            'member/harvests.php',
            'fa-wheat-awn',
            'Church Harvests',
            $dir,
            $cur,
            'harvests.php',
            'member.harvests.view'
        );


        navLink(
            $sidebarBaseUrl .
            'member/order-of-service.php',
            'fa-list-ol',
            'Order of Service',
            $dir,
            $cur,
            'order-of-service.php',
            'member.worship.view'
        );


        navLink(
            $sidebarBaseUrl .
            'member/hymns.php',
            'fa-music',
            'Church Hymns',
            $dir,
            $cur,
            'hymns.php',
            'member.worship.view'
        );


        navLink(
            $sidebarBaseUrl .
            'member/tips.php?dept=health',
            'fa-heart-pulse',
            'Health Tips',
            $dir,
            $cur,
            'tips.php',
            'member.tips.view',
            'dept',
            'health'
        );


        navLink(
            $sidebarBaseUrl .
            'member/tips.php?dept=education',
            'fa-graduation-cap',
            'Education Tips',
            $dir,
            $cur,
            'tips.php',
            'member.tips.view',
            'dept',
            'education'
        );


        navLink(
            $sidebarBaseUrl .
            'member/notebook.php',
            'fa-book',
            'My Notebook',
            $dir,
            $cur,
            'notebook.php',
            'member.notebook.manage'
        );

        ?>



        <!-- ==================================================
             MY GIVING
             ================================================== -->

        <?php sidebar_section(
            'My Giving'
        ); ?>


        <?php



        navLink(
            $sidebarBaseUrl .
            'member/giving.php?tab=tithe',
            'fa-coins',
            'My Tithe',
            $dir,
            $cur,
            'giving.php',
            'member.giving.view',
            'tab',
            'tithe'
        );


        navLink(
            $sidebarBaseUrl .
            'member/giving.php?tab=thanksgiving',
            'fa-hands-praying',
            'My Thanksgiving',
            $dir,
            $cur,
            'giving.php',
            'member.giving.view',
            'tab',
            'thanksgiving'
        );


        navLink(
            $sidebarBaseUrl .
            'member/giving.php?tab=pledges',
            'fa-handshake',
            'My Pledges',
            $dir,
            $cur,
            'giving.php',
            'member.giving.view',
            'tab',
            'pledges'
        );


        navLink(
            $sidebarBaseUrl .
            'member/pledges.php',
            'fa-bell',
            'Pledge Management',
            $dir,
            $cur,
            'pledges.php',
            'member.pledges.manage'
        );


        navLink(
            $sidebarBaseUrl .
            'member/giving.php?tab=contributions',
            'fa-hand-holding-dollar',
            'My Contributions',
            $dir,
            $cur,
            'giving.php',
            'member.giving.view',
            'tab',
            'contributions'
        );


        ?>



        <!-- ==================================================
             CHURCH LIFE
             ================================================== -->

        <?php sidebar_section(
            'Church Life'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'member/projects.php',
            'fa-project-diagram',
            'Projects & Fundraising',
            $dir,
            $cur,
            'projects.php',
            'member.projects.view'
        );


        navLink(
            $sidebarBaseUrl .
            'member/attendance.php',
            'fa-calendar-check',
            'My Attendance',
            $dir,
            $cur,
            'attendance.php',
            'member.attendance.view'
        );


        navLink(
            $sidebarBaseUrl .
            'member/events.php',
            'fa-calendar-alt',
            'Events',
            $dir,
            $cur,
            'events.php',
            'events.view'
        );

        navLink(
            $sidebarBaseUrl .
            'member/event-certificates.php',
            'fa-certificate',
            'My Event Certificates',
            $dir,
            $cur,
            'event-certificates.php',
            'events.view'
        );

        ?>



        <!-- ==================================================
             ACCOUNT
             ================================================== -->

        <?php sidebar_section(
            'Account'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'member/payment-gateways.php',
            'fa-credit-card',
            'Payment Gateways',
            $dir,
            $cur,
            'payment-gateways.php',
            'member.payment_gateways.view'
        );


        navLink(
            $sidebarBaseUrl .
            'member/payment-details.php',
            'fa-building-columns',
            'Payment Details',
            $dir,
            $cur,
            'payment-details.php',
            'member.payments.view'
        );


        navLink(
            $sidebarBaseUrl .
            'member/profile.php',
            'fa-user-circle',
            'My Profile',
            $dir,
            $cur,
            'profile.php',
            'member.profile.view'
        );

        ?>



    <?php else: ?>


        <!-- ==================================================
             ADMIN / STAFF MAIN
             ================================================== -->

        <?php sidebar_section(
            'Main'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'dashboard.php',
            'fa-tachometer-alt',
            'Dashboard',
            $dir,
            $cur,
            'dashboard.php',
            'dashboard.view'
        );

        navLink(
            $sidebarBaseUrl .
            'appointments/',
            'fa-calendar-check',
            'Appointment Requests',
            $dir,
            $cur,
            'appointments',
            'dashboard.view'
        );

        ?>



        <!-- ==================================================
             PEOPLE & REGISTERS
             ================================================== -->

        <?php sidebar_section(
            'People & Registers'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'members/',
            'fa-users',
            'Members',
            $dir,
            $cur,
            'members',
            'members.view'
        );


        navLink(
            $sidebarBaseUrl .
            'baptism/',
            'fa-water',
            'Baptism Register',
            $dir,
            $cur,
            'baptism',
            'baptism.view'
        );


        /*
         * NEW:
         * Church Visitors Book
         */
        navLink(
            $sidebarBaseUrl .
            'visitors/',
            'fa-address-book',
            'Church Visitors Book',
            $dir,
            $cur,
            'visitors',
            'visitors.view'
        );


        /*
         * NEW:
         * Wedding Vows Book
         */
        navLink(
            $sidebarBaseUrl .
            'wedding_vows/',
            'fa-ring',
            'Wedding Vows Book',
            $dir,
            $cur,
            'wedding_vows',
            'wedding_vows.view'
        );


        navLink(
            $sidebarBaseUrl .
            'sunday_school/',
            'fa-child',
            'Sunday School',
            $dir,
            $cur,
            'sunday_school',
            'sunday_school.view'
        );


        navLink(
            $sidebarBaseUrl .
            'youth/',
            'fa-user-graduate',
            'Youth Registry',
            $dir,
            $cur,
            'youth',
            'youth.view'
        );
        
        navLink(
    BASE_URL . 'converts/',
    'fa-seedling',
    'Converts & Nurture',
    $dir,
    $cur,
    'converts',
    'converts.view'
);

        ?>



        <!-- ==================================================
             GROUPS & FELLOWSHIPS
             ================================================== -->

        <?php sidebar_section(
            'Groups & Fellowships'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'zones/',
            'fa-map-marker-alt',
            'Church Zones',
            $dir,
            $cur,
            'zones',
            'zones.view'
        );


        navLink(
            $sidebarBaseUrl .
            'departments/',
            'fa-sitemap',
            'Departments',
            $dir,
            $cur,
            'departments',
            'departments.view'
        );


        navLink(
            $sidebarBaseUrl .
            'fathers_union/',
            'fa-male',
            "Fathers' Union",
            $dir,
            $cur,
            'fathers_union',
            'fathers_union.view'
        );


        navLink(
            $sidebarBaseUrl .
            'mothers_union/',
            'fa-female',
            "Mothers' Union",
            $dir,
            $cur,
            'mothers_union',
            'mothers_union.view'
        );

        ?>



        <!-- ==================================================
             PROGRAMS & SERVICES
             ================================================== -->

        <?php sidebar_section(
            'Programs & Services'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'church_programs/',
            'fa-calendar-days',
            'Church Programs',
            $dir,
            $cur,
            'church_programs',
            'church_programs.manage'
        );


        navLink(
            $sidebarBaseUrl .
            'church_programs/calendar.php',
            'fa-calendar',
            'Programs Calendar',
            $dir,
            $cur,
            'calendar.php',
            'church_programs.manage'
        );


        navLink(
            $sidebarBaseUrl .
            'church_services/',
            'fa-video',
            'Live Services',
            $dir,
            $cur,
            'church_services',
            'church_services.manage'
        );


       

        navLink(
            $sidebarBaseUrl .
            'announcements/',
            'fa-bullhorn',
            'Announcements',
            $dir,
            $cur,
            'announcements',
            'announcements.manage'
        );


        navLink(
            $sidebarBaseUrl .
            'church_theme/',
            'fa-cross',
            'Annual Church Theme',
            $dir,
            $cur,
            'church_theme',
            'church_theme.manage'
        );


        navLink(
            $sidebarBaseUrl .
            'harvests/',
            'fa-wheat-awn',
            'Annual Harvests',
            $dir,
            $cur,
            'harvests',
            'harvests.manage'
        );

        ?>



        <!-- ==================================================
             MEMBER CONTENT
             ================================================== -->

        <?php if (
            sidebar_can('health_tips.manage')
            || sidebar_can('education_tips.manage')
            || sidebar_can('bible_verses.manage')
            || sidebar_can('order_of_service.manage')
            || sidebar_can('hymns.manage')
        ): ?>

        <?php sidebar_section(
            'Member Content'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'department_tips/index.php?dept=health',
            'fa-heart-pulse',
            'Health Tips',
            $dir,
            $cur,
            'department_tips',
            'health_tips.manage',
            'dept',
            'health'
        );


        navLink(
            $sidebarBaseUrl .
            'department_tips/index.php?dept=education',
            'fa-graduation-cap',
            'Education Tips',
            $dir,
            $cur,
            'department_tips',
            'education_tips.manage',
            'dept',
            'education'
        );


        navLink(
            $sidebarBaseUrl .
            'worship_resources/verses.php',
            'fa-book-bible',
            'Bible Verses',
            $dir,
            $cur,
            'verses.php',
            'bible_verses.manage'
        );


        navLink(
            $sidebarBaseUrl .
            'worship_resources/order-of-service.php',
            'fa-list-ol',
            'Order of Service',
            $dir,
            $cur,
            'order-of-service.php',
            'order_of_service.manage'
        );


        navLink(
            $sidebarBaseUrl .
            'worship_resources/hymns.php',
            'fa-music',
            'Church Hymns',
            $dir,
            $cur,
            'hymns.php',
            'hymns.manage'
        );

        ?>

        <?php endif; ?>



        <!-- ==================================================
             FINANCE & GIVING
             ================================================== -->

        <?php sidebar_section(
            'Finance & Giving'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'offertory/',
            'fa-church',
            'Offertory',
            $dir,
            $cur,
            'offertory',
            'offertory.view'
        );


        navLink(
            $sidebarBaseUrl .
            'tithe/',
            'fa-coins',
            'Tithe',
            $dir,
            $cur,
            'tithe',
            'tithe.view'
        );


        navLink(
            $sidebarBaseUrl .
            'thanksgiving/',
            'fa-hands-praying',
            'Thanksgiving',
            $dir,
            $cur,
            'thanksgiving',
            'thanksgiving.view'
        );


        navLink(
            $sidebarBaseUrl .
            'pledges/',
            'fa-handshake',
            'Pledges',
            $dir,
            $cur,
            'pledges',
            'pledges.view'
        );


       

        navLink(
            $sidebarBaseUrl .
            'contributions/',
            'fa-hand-holding-dollar',
            'Contributions',
            $dir,
            $cur,
            'contributions',
            'contributions.view'
        );

        navLink(
            $sidebarBaseUrl .
            'good_samaritan/',
            'fa-hands-helping',
            'Good Samaritan',
            $dir,
            $cur,
            'good_samaritan',
            'good_samaritan.view'
        );

        navLink(
            $sidebarBaseUrl .
            'expenses/',
            'fa-receipt',
            'Expenses',
            $dir,
            $cur,
            'expenses',
            'expenses.view'
        );

        navLink(
            $sidebarBaseUrl .
            'church_assets/',
            'fa-boxes-stacked',
            'Church Assets',
            $dir,
            $cur,
            'church_assets',
            'church_assets.view'
        );

        ?>



        <!-- ==================================================
             PROJECTS & CAMPAIGNS
             ================================================== -->

        <?php sidebar_section(
            'Projects & Campaigns'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'projects/',
            'fa-project-diagram',
            'Church Projects',
            $dir,
            $cur,
            'projects',
            'projects.view'
        );


        navLink(
            $sidebarBaseUrl .
            'fundraising/',
            'fa-bullhorn',
            'Fundraising',
            $dir,
            $cur,
            'fundraising',
            'fundraising.view'
        );

        ?>



        <!-- ==================================================
             COMMUNICATION
             ================================================== -->

        <?php sidebar_section(
            'Communication'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'bulk_messages/',
            'fa-paper-plane',
            'Bulk Messages',
            $dir,
            $cur,
            'bulk_messages',
            'bulk_messages.view'
        );

        ?>



        <!-- ==================================================
             CHURCH LIFE
             ================================================== -->

        <?php sidebar_section(
            'Church Life'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'attendance/',
            'fa-calendar-check',
            'Attendance',
            $dir,
            $cur,
            'attendance',
            'attendance.view'
        );


        navLink(
            $sidebarBaseUrl .
            'events/',
            'fa-calendar-alt',
            'Events',
            $dir,
            $cur,
            'events',
            'events.view'
        );


        navLink(
            $sidebarBaseUrl .
            'missions/',
            'fa-globe-africa',
            'Missions & Outreach',
            $dir,
            $cur,
            'missions',
            'missions.view'
        );

        ?>



        <!-- ==================================================
             REPORTS
             ================================================== -->

        <?php sidebar_section(
            'Reports'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'reports/finance.php',
            'fa-file-invoice-dollar',
            'Financial Statements',
            $dir,
            $cur,
            'finance.php',
            'reports.view'
        );


        navLink(
            $sidebarBaseUrl .
            'reports/',
            'fa-chart-bar',
            'Reports & PDF',
            $dir,
            $cur,
            'reports',
            'reports.view'
        );

        ?>



        <!-- ==================================================
             SETTINGS
             ================================================== -->

        <?php sidebar_section(
            'Settings'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'settings/',
            'fa-tools',
            'Site Settings',
            $dir,
            $cur,
            'settings',
            'settings.manage'
        );


        navLink(
            $sidebarBaseUrl .
            'settings/payment-gateways.php',
            'fa-credit-card',
            'Payment Gateways',
            $dir,
            $cur,
            'payment-gateways.php',
            'settings.payment_gateways.manage'
        );


        navLink(
            $sidebarBaseUrl .
            'settings/member-payments.php',
            'fa-building-columns',
            'Member Payment Details',
            $dir,
            $cur,
            'member-payments.php',
            'settings.manage'
        );


        navLink(
            $sidebarBaseUrl .
            'settings/theme.php',
            'fa-palette',
            'Theme Colours',
            $dir,
            $cur,
            'theme.php',
            'settings.manage'
        );

        ?>



        <!-- ==================================================
             USER MANAGEMENT
             ================================================== -->

        <?php sidebar_section(
            'User Management'
        ); ?>


        <?php

        navLink(
            $sidebarBaseUrl .
            'users/',
            'fa-user-cog',
            'Manage Users',
            $dir,
            $cur,
            'users',
            'users.manage'
        );


        navLink(
            $sidebarBaseUrl .
            'users/activity_logs.php',
            'fa-clock-rotate-left',
            'Activity Logs',
            $dir,
            $cur,
            'activity_logs.php',
            'users.manage'
        );


        navLink(
            $sidebarBaseUrl .
            'users/permissions.php',
            'fa-key',
            'User Permissions',
            $dir,
            $cur,
            'permissions.php',
            'users.manage'
        );

        ?>


    <?php endif; ?>


        <?php sidebar_close_section(); ?>
    </nav>

    <script>
    (function () {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        const groups = Array.from(sidebar.querySelectorAll('.sidebar-group'));
        const activeGroup = sidebar.querySelector('.sidebar-group a.active')?.closest('.sidebar-group');
        let stored = {};
        try { stored = JSON.parse(localStorage.getItem('couSidebarGroups') || '{}'); } catch (e) {}
        groups.forEach((group, index) => {
            const button = group.querySelector('.nav-section');
            const title = button?.textContent.trim() || String(index);
            const open = group === activeGroup || (Object.prototype.hasOwnProperty.call(stored, title) ? !!stored[title] : (!activeGroup && index === 0));
            group.classList.toggle('is-open', open);
            button?.setAttribute('aria-expanded', open ? 'true' : 'false');
            button?.addEventListener('click', function () {
                const nextOpen = !group.classList.contains('is-open');
                group.classList.toggle('is-open', nextOpen);
                button.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
                try {
                    stored[title] = nextOpen;
                    localStorage.setItem('couSidebarGroups', JSON.stringify(stored));
                } catch (e) {}
            });
        });
    })();
    </script>


    <!-- ======================================================
         SIDEBAR FOOTER
         ====================================================== -->

    <div class="sidebar-footer">

        <a
            href="<?= h(
                $sidebarBaseUrl .
                'logout.php'
            ) ?>"
        >

            <i
                class="fas fa-sign-out-alt"
                aria-hidden="true"
            ></i>

            <span>
                Logout
            </span>

        </a>

    </div>


</aside>


<!-- ==========================================================
     MOBILE OVERLAY

     The rewritten topbar.php will use this element when
     opening and closing the sidebar on phones/tablets.
     ========================================================== -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    aria-hidden="true"
></div>
