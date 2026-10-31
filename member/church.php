<?php
declare(strict_types=1);

$pageTitle = 'Church Departments';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_listing.php';
require_permission('member.church.view');

$memberId = current_member_id();
$departments = [];
if (mp_table($conn, 'departments') && mp_table($conn, 'department_members')) {
    $departments = mp_rows(
        $conn,
        "SELECT d.name, d.description, d.status
         FROM departments d
         JOIN department_members dm ON dm.dept_id = d.id
         JOIN members m ON m.id = dm.member_id
         WHERE dm.member_id = ?
           AND COALESCE(m.status, 'Active') = 'Active'
           AND COALESCE(d.status, 'Active') = 'Active'
         ORDER BY d.name",
        'i',
        [$memberId]
    );
}

$stats = [
    ['label' => 'Departments', 'value' => count($departments), 'icon' => 'fa-people-group'],
    ['label' => 'Active', 'value' => count($departments), 'icon' => 'fa-circle-check'],
];

include __DIR__ . '/../includes/sidebar.php';
?>
<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>
    <div class="content-wrapper">
        <div class="card">
            <div class="card-header"><h3>Church Departments</h3></div>
            <div class="card-body">
                <?php mp_render_listing_page('My Departments', 'Departments you belong to.', $departments, $stats); ?>
            </div>
        </div>
    </div>
</div>
<?php
mp_standard_styles();
require_once __DIR__ . '/../includes/footer.php';
