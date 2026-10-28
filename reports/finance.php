<?php
require_once '../config/config.php';
requireLogin();

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($amount): string {
    return number_format((float)$amount);
}

function getSum(mysqli $conn, string $table, string $amountCol, string $dateCol, string $from, string $to): float {
    $allowed = '/^[a-zA-Z0-9_]+$/';

    if (!preg_match($allowed, $table) || !preg_match($allowed, $amountCol) || !preg_match($allowed, $dateCol)) {
        return 0;
    }

    $sql = "SELECT COALESCE(SUM($amountCol), 0) AS total FROM $table WHERE $dateCol BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float)($row['total'] ?? 0);
}

function getSingleValue(mysqli $conn, string $sql): float {
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }

    $row = $res->fetch_assoc();
    return (float)($row['v'] ?? 0);
}

$period = $_GET['period'] ?? 'month';
$period = in_array($period, ['month', 'quarter', 'year', 'custom'], true) ? $period : 'month';

$year  = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$qtr   = (int)($_GET['qtr'] ?? ceil(date('n') / 3));

$month = max(1, min(12, $month));
$qtr   = max(1, min(4, $qtr));

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

switch ($period) {
    case 'month':
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));
        $label = date('F Y', strtotime($from));
        break;

    case 'quarter':
        $qmStart = (($qtr - 1) * 3) + 1;
        $from = sprintf('%04d-%02d-01', $year, $qmStart);
        $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $qmStart + 2)));
        $label = "Q{$qtr} {$year}";
        break;

    case 'year':
        $from = "{$year}-01-01";
        $to = "{$year}-12-31";
        $label = "Year {$year}";
        break;

    default:
        $label = "{$from} to {$to}";
        break;
}

$inc_offertory     = getSum($conn, 'offertory', 'total_amount', 'service_date', $from, $to);
$inc_tithe         = getSum($conn, 'tithe', 'amount', 'tithe_date', $from, $to);
$inc_thanksgiving  = getSum($conn, 'thanksgiving', 'amount', 'thanksgiving_date', $from, $to);
$inc_contributions = getSum($conn, 'contributions', 'amount', 'contributed_on', $from, $to);
$inc_pledges       = getSum($conn, 'pledge_payments', 'amount', 'paid_on', $from, $to);
$inc_fundraising   = getSum($conn, 'fundraising_collections', 'amount', 'collection_date', $from, $to);

$total_income = $inc_offertory + $inc_tithe + $inc_thanksgiving + $inc_contributions + $inc_pledges + $inc_fundraising;

$conn->query("CREATE TABLE IF NOT EXISTS expenses (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, expense_date DATE NOT NULL, category VARCHAR(120) NOT NULL, description VARCHAR(255) NOT NULL, amount DECIMAL(14,2) NOT NULL, paid_to VARCHAR(150) DEFAULT NULL, payment_method VARCHAR(60) DEFAULT NULL, reference_no VARCHAR(100) DEFAULT NULL, notes TEXT DEFAULT NULL, created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_expense_date (expense_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$total_expenditure = getSum($conn, 'expenses', 'amount', 'expense_date', $from, $to);
$outstanding_pledges = getSingleValue($conn, "SELECT COALESCE(SUM(amount_pledged - amount_paid), 0) AS v FROM pledges WHERE status IN ('Pending','Partial')");

$trend = [];

for ($i = 11; $i >= 0; $i--) {
    $mDate = date('Y-m', strtotime("-{$i} months"));
    $mFrom = $mDate . '-01';
    $mTo = date('Y-m-t', strtotime($mFrom));

    $mOff = getSum($conn, 'offertory', 'total_amount', 'service_date', $mFrom, $mTo);
    $mTithe = getSum($conn, 'tithe', 'amount', 'tithe_date', $mFrom, $mTo);
    $mTgiv = getSum($conn, 'thanksgiving', 'amount', 'thanksgiving_date', $mFrom, $mTo);
    $mCont = getSum($conn, 'contributions', 'amount', 'contributed_on', $mFrom, $mTo);
    $mFund = getSum($conn, 'fundraising_collections', 'amount', 'collection_date', $mFrom, $mTo);

    $trend[] = [
        'label' => date('M y', strtotime($mFrom)),
        'total' => $mOff + $mTithe + $mTgiv + $mCont + $mFund,
        'offertory' => $mOff,
        'tithe' => $mTithe,
        'thanksgiving' => $mTgiv,
        'contributions' => $mCont,
        'fundraising' => $mFund,
    ];
}

$projStmt = $conn->prepare("
    SELECT 
        p.name,
        COALESCE(SUM(c.amount), 0) AS raised,
        p.target_amount
    FROM projects p
    LEFT JOIN contributions c 
        ON c.project_id = p.id 
        AND c.contributed_on BETWEEN ? AND ?
    GROUP BY p.id, p.name, p.target_amount
    ORDER BY raised DESC
");

$projStmt->bind_param("ss", $from, $to);
$projStmt->execute();
$proj_breakdown = $projStmt->get_result();

$income_lines = [
    ['Offertory', $inc_offertory, '#4a1e8f', 'fa-church'],
    ['Tithe', $inc_tithe, '#fd7e14', 'fa-coins'],
    ['Thanksgiving', $inc_thanksgiving, '#6610f2', 'fa-praying-hands'],
    ['Contributions', $inc_contributions, '#28a745', 'fa-hand-holding-usd'],
    ['Pledge Payments', $inc_pledges, '#20c997', 'fa-handshake'],
    ['Fundraising', $inc_fundraising, '#d4a017', 'fa-bullhorn'],
];

$trend_labels = array_column($trend, 'label');
$trend_totals = array_column($trend, 'total');
$trend_off = array_column($trend, 'offertory');
$trend_tithe = array_column($trend, 'tithe');
$trend_cont = array_column($trend, 'contributions');

$months_list = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$surplus_deficit = $total_income - $total_expenditure;

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">
<?php showFlash(); ?>

<div class="page-header">
    <h2><i class="fas fa-file-invoice-dollar"></i> Financial Statements</h2>

    <div class="d-flex gap-2 flex-wrap">
        <a href="income_statement.php?from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-primary">
            <i class="fas fa-file-alt"></i> Income Statement
        </a>

        <a href="income_statement.php?from=<?= e($from) ?>&to=<?= e($to) ?>&pdf=1" class="btn btn-danger">
            <i class="fas fa-file-pdf"></i> Export PDF
        </a>
    </div>
</div>

<div class="filter-bar mb-4">
    <form method="GET" class="form-inline flex-wrap" style="gap:8px">
        <label class="font-weight-bold">Period:</label>

        <select name="period" class="form-control form-control-sm" onchange="togglePeriod(this.value)">
            <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Monthly</option>
            <option value="quarter" <?= $period === 'quarter' ? 'selected' : '' ?>>Quarterly</option>
            <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Annual</option>
            <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Range</option>
        </select>

        <span id="grp_month" class="<?= $period === 'month' ? '' : 'd-none' ?>">
            <select name="month" class="form-control form-control-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>>
                        <?= e($months_list[$m]) ?>
                    </option>
                <?php endfor; ?>
            </select>
        </span>

        <span id="grp_qtr" class="<?= $period === 'quarter' ? '' : 'd-none' ?>">
            <select name="qtr" class="form-control form-control-sm">
                <?php for ($q = 1; $q <= 4; $q++): ?>
                    <option value="<?= $q ?>" <?= $qtr === $q ? 'selected' : '' ?>>Q<?= $q ?></option>
                <?php endfor; ?>
            </select>
        </span>

        <span id="grp_custom" class="<?= $period === 'custom' ? '' : 'd-none' ?> d-flex" style="gap:4px">
            <input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm">
            <span class="align-self-center">—</span>
            <input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm">
        </span>

        <select name="year" class="form-control form-control-sm">
            <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 5; $y--): ?>
                <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>

        <button class="btn btn-primary btn-sm">
            <i class="fas fa-search"></i> Apply
        </button>

        <strong class="text-primary"><?= e($label) ?></strong>
    </form>
</div>

<div class="row mb-4">
    <div class="col-6 col-md-3 mb-2">
        <div class="fin-kpi fin-kpi-success">
            <div class="fin-kpi-label"><i class="fas fa-arrow-down text-success"></i> Total Income</div>
            <div class="fin-kpi-value text-success">UGX <?= money($total_income) ?></div>
        </div>
    </div>

    <div class="col-6 col-md-3 mb-2">
        <div class="fin-kpi fin-kpi-danger">
            <div class="fin-kpi-label"><i class="fas fa-arrow-up text-danger"></i> Recorded Expenses</div>
            <div class="fin-kpi-value text-danger">UGX <?= money($total_expenditure) ?></div>
            <small class="text-muted">Actual expenses in selected period</small>
        </div>
    </div>

    <div class="col-6 col-md-3 mb-2">
        <div class="fin-kpi <?= $surplus_deficit >= 0 ? 'fin-kpi-success' : 'fin-kpi-danger' ?>">
            <div class="fin-kpi-label"><i class="fas fa-balance-scale"></i> Surplus / (Deficit)</div>

            <div class="fin-kpi-value <?= $surplus_deficit >= 0 ? 'text-success' : 'text-danger' ?>">
                <?= $surplus_deficit < 0 ? '(' : '' ?>UGX <?= money(abs($surplus_deficit)) ?><?= $surplus_deficit < 0 ? ')' : '' ?>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-3 mb-2">
        <div class="fin-kpi fin-kpi-warning">
            <div class="fin-kpi-label"><i class="fas fa-clock text-warning"></i> Outstanding Pledges</div>
            <div class="fin-kpi-value text-warning">UGX <?= money($outstanding_pledges) ?></div>
        </div>
    </div>
</div>

<!-- Tab navigation -->
<div class="fin-tabs-wrap">
    <ul class="fin-tabs" role="tablist">
        <li><button type="button" class="fin-tab-btn active" data-tab="tab-sources" role="tab" aria-selected="true">
            <i class="fas fa-list-ul"></i> Income by Source
        </button></li>
        <li><button type="button" class="fin-tab-btn" data-tab="tab-projects" role="tab" aria-selected="false">
            <i class="fas fa-project-diagram"></i> Projects
        </button></li>
        <li><button type="button" class="fin-tab-btn" data-tab="tab-trend" role="tab" aria-selected="false">
            <i class="fas fa-chart-line"></i> 12-Month Trend
        </button></li>
        <li><button type="button" class="fin-tab-btn" data-tab="tab-monthly" role="tab" aria-selected="false">
            <i class="fas fa-table"></i> Monthly Breakdown
        </button></li>
        <li><button type="button" class="fin-tab-btn" data-tab="tab-reports" role="tab" aria-selected="false">
            <i class="fas fa-print"></i> Formal Reports
        </button></li>
    </ul>

    <div class="fin-tab-panels">

        <!-- Income by Source -->
        <div class="fin-tab-panel active" id="tab-sources" role="tabpanel">
            <div class="row">
                <div class="col-md-7">
                    <h6 class="fin-panel-title">Income by Source — <?= e($label) ?></h6>

                    <table class="table mb-0">
                        <thead>
                        <tr>
                            <th>Source</th>
                            <th class="text-right">Amount (UGX)</th>
                            <th class="text-right">%</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($income_lines as $line): ?>
                            <?php $pct = $total_income > 0 ? round(($line[1] / $total_income) * 100, 1) : 0; ?>
                            <tr>
                                <td>
                                    <span class="dot" style="background:<?= e($line[2]) ?>"></span>
                                    <i class="fas <?= e($line[3]) ?>" style="color:<?= e($line[2]) ?>"></i>
                                    <?= e($line[0]) ?>
                                </td>
                                <td class="text-right font-weight-bold"><?= money($line[1]) ?></td>
                                <td class="text-right">
                                    <span class="badge" style="background:<?= e($line[2]) ?>;color:#fff">
                                        <?= e($pct) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>

                        <tfoot>
                        <tr style="background:#f8f4ff;font-weight:bold">
                            <td><i class="fas fa-equals"></i> TOTAL INCOME</td>
                            <td class="text-right text-success">UGX <?= money($total_income) ?></td>
                            <td class="text-right">100%</td>
                        </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="col-md-5 d-flex flex-column align-items-center justify-content-center">
                    <h6 class="fin-panel-title align-self-start w-100">Income Distribution</h6>
                    <canvas id="donutChart" width="240" height="240"></canvas>
                </div>
            </div>
        </div>

        <!-- Projects -->
        <div class="fin-tab-panel" id="tab-projects" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fin-panel-title mb-0">Contributions by Project</h6>
                <a href="../contributions/">Manage contributions →</a>
            </div>

            <table class="table table-sm mb-0">
                <thead>
                <tr>
                    <th>Project</th>
                    <th>Raised</th>
                    <th>Progress</th>
                </tr>
                </thead>

                <tbody>
                <?php if ($proj_breakdown && $proj_breakdown->num_rows > 0): ?>
                    <?php while ($pb = $proj_breakdown->fetch_assoc()): ?>
                        <?php
                        $target = (float)($pb['target_amount'] ?? 0);
                        $raised = (float)($pb['raised'] ?? 0);
                        $pp = $target > 0 ? min(100, round(($raised / $target) * 100)) : 0;
                        ?>
                        <tr>
                            <td><?= e($pb['name'] ?? '—') ?></td>
                            <td><?= money($raised) ?></td>
                            <td style="min-width:160px">
                                <div class="progress" style="height:8px">
                                    <div class="progress-bar bg-success" style="width:<?= $pp ?>%"></div>
                                </div>
                                <small><?= $pp ?>%</small>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted">No project records found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 12-Month Trend -->
        <div class="fin-tab-panel" id="tab-trend" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fin-panel-title mb-0">12-Month Income Trend</h6>

                <div>
                    <button type="button" class="btn btn-xs btn-outline-secondary chart-toggle active" data-target="line">Line</button>
                    <button type="button" class="btn btn-xs btn-outline-secondary chart-toggle" data-target="bar">Bar</button>
                </div>
            </div>

            <canvas id="trendChart" height="90"></canvas>
        </div>

        <!-- Monthly Breakdown -->
        <div class="fin-tab-panel" id="tab-monthly" role="tabpanel">
            <h6 class="fin-panel-title">Month-by-Month Breakdown (Last 12 Months)</h6>

            <div style="overflow-x:auto">
                <table class="table table-sm mb-0" style="min-width:700px">
                    <thead>
                    <tr>
                        <th>Month</th>
                        <th>Offertory</th>
                        <th>Tithe</th>
                        <th>Thanksgiving</th>
                        <th>Contributions</th>
                        <th>Fundraising</th>
                        <th class="text-right">Total</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php $grand = 0; ?>
                    <?php foreach ($trend as $mi): ?>
                        <?php $grand += $mi['total']; ?>
                        <tr>
                            <td class="font-weight-bold"><?= e($mi['label']) ?></td>
                            <td><?= money($mi['offertory']) ?></td>
                            <td><?= money($mi['tithe']) ?></td>
                            <td><?= money($mi['thanksgiving']) ?></td>
                            <td><?= money($mi['contributions']) ?></td>
                            <td><?= money($mi['fundraising']) ?></td>
                            <td class="text-right font-weight-bold text-success"><?= money($mi['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>

                    <tfoot>
                    <tr>
                        <th>TOTAL</th>
                        <th colspan="5"></th>
                        <th class="text-right text-success">UGX <?= money($grand) ?></th>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Formal Reports -->
        <div class="fin-tab-panel" id="tab-reports" role="tabpanel">
            <h6 class="fin-panel-title">Formal Financial Reports</h6>

            <?php
            $reps = [
                ['income_statement.php', 'fa-file-invoice-dollar', 'Income Statement', 'All income categorised by source', '#4a1e8f'],
                ['expenditure_report.php', 'fa-receipt', 'Expenditure Report', 'Recorded expenses and project budgets', '#dc3545'],
                ['balance_summary.php', 'fa-balance-scale', 'Fund Balance Summary', 'Running balance of all church funds', '#28a745'],
                ['tithe_report.php', 'fa-coins', 'Tithe Report', 'Per-member tithe history', '#fd7e14'],
                ['offertory_report.php', 'fa-church', 'Offertory Report', 'Service-by-service offertory', '#007bff'],
                ['thanksgiving_report.php', 'fa-praying-hands', 'Thanksgiving Report', 'All thanksgiving offerings', '#6610f2'],
                ['pledges_report.php', 'fa-handshake', 'Pledges Report', 'Pledge commitments & fulfilment', '#20c997'],
                ['fundraising_report.php', 'fa-bullhorn', 'Fundraising Report', 'Campaign-by-campaign summary', '#d4a017'],
            ];
            ?>

            <table class="table table-sm fin-reports-table mb-0">
                <tbody>
                <?php foreach ($reps as $r): ?>
                    <tr>
                        <td style="width:44px">
                            <span class="report-icon-sm" style="background:<?= e($r[4]) ?>">
                                <i class="fas <?= e($r[1]) ?>"></i>
                            </span>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?= e($r[2]) ?></div>
                            <div class="text-muted small"><?= e($r[3]) ?></div>
                        </td>
                        <td class="text-right" style="width:120px">
                            <a href="<?= e($r[0]) ?>?from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye"></i>
                            </a>

                            <a href="<?= e($r[0]) ?>?from=<?= e($from) ?>&to=<?= e($to) ?>&pdf=1" class="btn btn-sm btn-danger">
                                <i class="fas fa-file-pdf"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

</div>
</div>

<style>
.fin-kpi{
    background:#fff;
    border-radius:10px;
    padding:16px 20px;
    box-shadow:0 2px 8px rgba(0,0,0,.07);
    height:100%;
    border-top:4px solid #4a1e8f;
}
.fin-kpi-success{border-top-color:#28a745}
.fin-kpi-danger{border-top-color:#dc3545}
.fin-kpi-warning{border-top-color:#fd7e14}
.fin-kpi-label{
    font-size:.8rem;
    text-transform:uppercase;
    letter-spacing:.5px;
    color:#777;
    margin-bottom:6px;
}
.fin-kpi-value{
    font-size:1.25rem;
    font-weight:700;
}
.dot{
    display:inline-block;
    width:10px;
    height:10px;
    border-radius:50%;
    margin-right:6px;
}
.filter-bar{
    background:#fff;
    border-radius:10px;
    padding:10px 16px;
    box-shadow:0 2px 8px rgba(0,0,0,.07);
}
.btn-xs{
    padding:2px 8px;
    font-size:.75rem;
}
.chart-toggle.active{
    background:#4a1e8f;
    color:#fff;
    border-color:#4a1e8f;
}

/* Tabs */
.fin-tabs-wrap{
    background:#fff;
    border-radius:10px;
    box-shadow:0 2px 8px rgba(0,0,0,.07);
    overflow:hidden;
}
.fin-tabs{
    display:flex;
    flex-wrap:wrap;
    list-style:none;
    margin:0;
    padding:0 10px;
    border-bottom:1px solid #eee;
    background:#faf8ff;
}
.fin-tabs li{
    margin:0;
}
.fin-tab-btn{
    border:none;
    background:none;
    padding:14px 18px;
    font-size:.9rem;
    font-weight:600;
    color:#666;
    cursor:pointer;
    border-bottom:3px solid transparent;
    transition:color .15s, border-color .15s;
    white-space:nowrap;
}
.fin-tab-btn i{
    margin-right:6px;
}
.fin-tab-btn:hover{
    color:#4a1e8f;
}
.fin-tab-btn.active{
    color:#4a1e8f;
    border-bottom-color:#4a1e8f;
}
.fin-tab-panels{
    padding:20px;
}
.fin-tab-panel{
    display:none;
}
.fin-tab-panel.active{
    display:block;
    animation:fin-fade .15s ease;
}
@keyframes fin-fade{
    from{opacity:0}
    to{opacity:1}
}
.fin-panel-title{
    font-weight:700;
    color:#4a1e8f;
    margin-bottom:14px;
}
.report-icon-sm{
    width:34px;
    height:34px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:.9rem;
    color:#fff;
}
.fin-reports-table td{
    vertical-align:middle;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
const donutData = {
    labels: <?= json_encode(array_column($income_lines, 0)) ?>,
    datasets: [{
        data: <?= json_encode(array_map(fn($x) => round((float)$x[1], 2), $income_lines)) ?>,
        backgroundColor: <?= json_encode(array_column($income_lines, 2)) ?>,
        borderWidth: 2,
        borderColor: '#fff'
    }]
};

let donutChart = null;
let trendChart = null;

function renderDonut() {
    if (donutChart) return;
    donutChart = new Chart(document.getElementById('donutChart'), {
        type: 'doughnut',
        data: donutData,
        options: {
            responsive: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(c) {
                            return c.label + ': UGX ' + Number(c.parsed).toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

const labels = <?= json_encode($trend_labels) ?>;
const totals = <?= json_encode($trend_totals) ?>;
const offData = <?= json_encode($trend_off) ?>;
const titheData = <?= json_encode($trend_tithe) ?>;
const contData = <?= json_encode($trend_cont) ?>;

function renderTrend() {
    if (trendChart) return;
    const trendCtx = document.getElementById('trendChart').getContext('2d');

    trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Total Income',
                    data: totals,
                    borderColor: '#4a1e8f',
                    backgroundColor: 'rgba(74,30,143,.1)',
                    fill: true,
                    tension: .3,
                    borderWidth: 2,
                    pointRadius: 3
                },
                {
                    label: 'Offertory',
                    data: offData,
                    borderColor: '#28a745',
                    backgroundColor: 'transparent',
                    tension: .3,
                    borderWidth: 1.5,
                    borderDash: [4, 2]
                },
                {
                    label: 'Tithe',
                    data: titheData,
                    borderColor: '#fd7e14',
                    backgroundColor: 'transparent',
                    tension: .3,
                    borderWidth: 1.5,
                    borderDash: [4, 2]
                },
                {
                    label: 'Contributions',
                    data: contData,
                    borderColor: '#17a2b8',
                    backgroundColor: 'transparent',
                    tension: .3,
                    borderWidth: 1.5,
                    borderDash: [4, 2]
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: function(c) {
                            return c.dataset.label + ': UGX ' + Number(c.parsed.y).toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: function(v) {
                            return 'UGX ' + Number(v).toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

document.querySelectorAll('.chart-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.chart-toggle').forEach(function(b) {
            b.classList.remove('active');
        });

        this.classList.add('active');
        trendChart.config.type = this.dataset.target;
        trendChart.update();
    });
});

function togglePeriod(v) {
    ['month', 'qtr', 'custom'].forEach(function(k) {
        const el = document.getElementById('grp_' + k);
        if (el) el.classList.add('d-none');
    });

    if (v === 'month') {
        document.getElementById('grp_month')?.classList.remove('d-none');
    }

    if (v === 'quarter') {
        document.getElementById('grp_qtr')?.classList.remove('d-none');
    }

    if (v === 'custom') {
        document.getElementById('grp_custom')?.classList.remove('d-none');
    }
}

// Tab switching
document.querySelectorAll('.fin-tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const target = this.dataset.tab;

        document.querySelectorAll('.fin-tab-btn').forEach(function(b) {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
        });
        document.querySelectorAll('.fin-tab-panel').forEach(function(p) {
            p.classList.remove('active');
        });

        this.classList.add('active');
        this.setAttribute('aria-selected', 'true');
        document.getElementById(target).classList.add('active');

        if (target === 'tab-sources') renderDonut();
        if (target === 'tab-trend') renderTrend();
    });
});

// Render charts for whichever tab is visible first
renderDonut();
</script>

<?php
$projStmt->close();
include '../includes/footer.php';
?>
