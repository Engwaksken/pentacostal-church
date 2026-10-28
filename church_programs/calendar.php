<?php
declare(strict_types=1);

$pageTitle = 'Programs Calendar';

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../church_admin/common.php';
require_once '../church_admin/install.php';

ce_require_admin();
ce_install($conn);


$conn->query("CREATE TABLE IF NOT EXISTS church_program_departments (program_id INT NOT NULL,department_id INT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(program_id,department_id),KEY idx_program_department(department_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$departmentNames=[];
if($conn->query("SHOW TABLES LIKE 'departments'")?->num_rows){
    $columns=[];$cr=$conn->query('SHOW COLUMNS FROM departments');while($cr&&$col=$cr->fetch_assoc())$columns[]=(string)$col['Field'];
    $idCol=in_array('id',$columns,true)?'id':(in_array('department_id',$columns,true)?'department_id':'');
    $nameCol=in_array('department_name',$columns,true)?'department_name':(in_array('name',$columns,true)?'name':(in_array('title',$columns,true)?'title':''));
    if($idCol&&$nameCol){$dr=$conn->query("SELECT `$idCol` id, `$nameCol` name FROM departments");while($dr&&$d=$dr->fetch_assoc())$departmentNames[(int)$d['id']]=(string)$d['name'];}
}

$monthInput = trim((string)($_GET['month'] ?? date('Y-m')));

if (!preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
    $monthInput = date('Y-m');
}

$monthStart = $monthInput . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$previousMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
$monthTitle = date('F Y', strtotime($monthStart));

$stmt = $conn->prepare(
    "SELECT *
     FROM church_programs
     WHERE program_date BETWEEN ? AND ?
     ORDER BY program_date ASC, start_time ASC, id ASC"
);
$stmt->bind_param('ss', $monthStart, $monthEnd);
$stmt->execute();
$programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();
$programMappings=[];$mr=$conn->query('SELECT program_id,department_id FROM church_program_departments');while($mr&&$m=$mr->fetch_assoc())$programMappings[(int)$m['program_id']][]=(int)$m['department_id'];
foreach($programs as &$program){$names=[];foreach($programMappings[(int)$program['id']]??[] as $departmentId)if(isset($departmentNames[$departmentId]))$names[]=$departmentNames[$departmentId];$program['audience_label']=$names?implode(', ',$names):'All Members';}unset($program);

$programsByDate = [];

foreach ($programs as $program) {
    $programsByDate[(string)$program['program_date']][] = $program;
}

$firstWeekday = (int)date('N', strtotime($monthStart));
$daysInMonth = (int)date('t', strtotime($monthStart));
$today = date('Y-m-d');
?>

<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/church-admin.css">
<link rel="stylesheet" href="../assets/css/church-programs.css">

<div class="main-content">
    <?php include '../includes/topbar.php'; ?>

    <div class="content-area church-programs-page">
        <?php ce_show_flash(); ?>

        <div class="church-page-head">
            <div>
                <h2>
                    <i class="fas fa-calendar"></i>
                    Programs Calendar
                </h2>
                <p>View church programs in a monthly calendar.</p>
            </div>

            <div class="church-page-actions">
                <a href="./" class="btn btn-outline">
                    <i class="fas fa-list"></i>
                    Programs Register
                </a>

                <a href="./?open=add" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Add Program
                </a>
            </div>
        </div>

        <section class="church-card church-calendar-card">
            <div class="church-calendar-toolbar">
                <a href="?month=<?= ce_h($previousMonth) ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-chevron-left"></i>
                    Previous
                </a>

                <div class="church-calendar-title">
                    <h3><?= ce_h($monthTitle) ?></h3>
                    <span><?= count($programs) ?> program<?= count($programs) === 1 ? '' : 's' ?></span>
                </div>

                <a href="?month=<?= ce_h($nextMonth) ?>" class="btn btn-outline btn-sm">
                    Next
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>

            <form method="get" class="church-calendar-jump">
                <label for="calendarMonth">Jump to month</label>
                <input
                    type="month"
                    id="calendarMonth"
                    name="month"
                    class="form-control"
                    value="<?= ce_h($monthInput) ?>"
                >
                <button class="btn btn-primary btn-sm" type="submit">Go</button>
                <a href="calendar.php" class="btn btn-outline btn-sm">Current Month</a>
            </form>

            <div class="church-calendar-wrap">
                <div class="church-calendar-grid church-calendar-weekdays">
                    <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $weekday): ?>
                        <div><?= ce_h($weekday) ?></div>
                    <?php endforeach; ?>
                </div>

                <div class="church-calendar-grid church-calendar-days">
                    <?php for ($blank = 1; $blank < $firstWeekday; $blank++): ?>
                        <div class="church-calendar-day church-calendar-day-empty"></div>
                    <?php endfor; ?>

                    <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                        <?php
                        $date = sprintf('%s-%02d', $monthInput, $day);
                        $dayPrograms = $programsByDate[$date] ?? [];
                        ?>
                        <div class="church-calendar-day <?= $date === $today ? 'is-today' : '' ?>">
                            <div class="church-calendar-day-number">
                                <span><?= $day ?></span>
                                <?php if ($dayPrograms): ?>
                                    <small><?= count($dayPrograms) ?></small>
                                <?php endif; ?>
                            </div>

                            <div class="church-calendar-events">
                                <?php foreach ($dayPrograms as $program): ?>
                                    <?php
                                    $status = (string)($program['status'] ?? 'Draft');
                                    $eventClass = match ($status) {
                                        'Published' => 'is-published',
                                        'Cancelled' => 'is-cancelled',
                                        default => 'is-draft',
                                    };
                                    ?>
                                    <a
                                        href="./?program=<?= (int)$program['id'] ?>"
                                        class="church-calendar-event <?= ce_h($eventClass) ?>"
                                        title="<?= ce_h($program['title']) ?>"
                                    >
                                        <time><?= ce_h(substr((string)($program['start_time'] ?? ''), 0, 5) ?: '--:--') ?></time>
                                        <span><?= ce_h($program['title']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </section>

        <section class="church-card church-calendar-agenda">
            <div class="church-card-header">
                <div>
                    <h3><i class="fas fa-list-check"></i> <?= ce_h($monthTitle) ?> Agenda</h3>
                    <p class="church-card-subtitle">All programs scheduled for this month.</p>
                </div>
            </div>

            <?php if ($programs): ?>
                <div class="church-list">
                    <?php foreach ($programs as $program): ?>
                        <div class="church-list-item church-calendar-agenda-item">
                            <div class="church-calendar-agenda-date">
                                <strong><?= ce_h(date('d', strtotime((string)$program['program_date']))) ?></strong>
                                <span><?= ce_h(date('M', strtotime((string)$program['program_date']))) ?></span>
                            </div>

                            <div class="church-calendar-agenda-content">
                                <div class="church-list-title"><?= ce_h($program['title']) ?></div>
                                <div class="church-list-meta">
                                    <span><i class="fas fa-clock"></i> <?= ce_h(substr((string)($program['start_time'] ?? ''), 0, 5) ?: 'Time not set') ?></span>
                                    <span><i class="fas fa-tag"></i> <?= ce_h($program['program_type']) ?></span>
                                    <span><i class="fas fa-location-dot"></i> <?= ce_h($program['venue'] ?: 'Venue not set') ?></span>
                                    <span><i class="fas fa-users"></i> <?= ce_h($program['audience_label'] ?? 'All Members') ?></span>
                                    <span class="church-badge"><?= ce_h($program['status']) ?></span>
                                </div>
                            </div>

                            <?php if (!empty($program['meeting_link'])): ?>
                                <a
                                    href="<?= ce_h($program['meeting_link']) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="btn btn-success btn-sm"
                                >
                                    <i class="fas fa-video"></i>
                                    Join
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="church-empty">
                    <i class="fas fa-calendar-xmark"></i>
                    No programs are scheduled for <?= ce_h($monthTitle) ?>.
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
