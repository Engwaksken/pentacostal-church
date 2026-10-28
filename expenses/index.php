<?php
require_once '../config/config.php';
requireLogin();

$conn->query("CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE NOT NULL,
    category VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    paid_to VARCHAR(150) DEFAULT NULL,
    payment_method VARCHAR(60) DEFAULT NULL,
    reference_no VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expense_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function ex_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$categories = ['Utilities', 'Transport', 'Salaries & Wages', 'Maintenance & Repairs', 'Office Supplies', 'Worship & Services', 'Pastoral Support', 'Events & Programs', 'Community Outreach', 'Equipment', 'Rent', 'Other'];
$paymentMethods = ['Cash', 'Bank Transfer', 'Cheque', 'Mobile Money', 'Card', 'Other'];
if (empty($_SESSION['expense_csrf'])) $_SESSION['expense_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['expense_csrf'];
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        setFlash('Your session expired. Please try again.', 'danger');
        redirect('index.php?from=' . urlencode($from) . '&to=' . urlencode($to));
    }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'delete' && $id > 0) {
        $stmt = $conn->prepare('DELETE FROM expenses WHERE id=?');
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        setFlash($ok ? 'Expense deleted.' : 'Unable to delete expense.', $ok ? 'success' : 'danger');
    } elseif (in_array($action, ['create', 'update'], true)) {
        $date = trim((string)($_POST['expense_date'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $paidTo = trim((string)($_POST['paid_to'] ?? ''));
        $method = trim((string)($_POST['payment_method'] ?? ''));
        $reference = trim((string)($_POST['reference_no'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($category, $categories, true) || !in_array($method, $paymentMethods, true) || $description === '' || $amount === false || $amount <= 0 || ($action === 'update' && $id < 1)) {
            setFlash('Check the expense details and try again.', 'danger');
        } else {
            if ($action === 'create') {
                $uid = (int)($_SESSION['user_id'] ?? 0);
                $stmt = $conn->prepare('INSERT INTO expenses (expense_date,category,description,amount,paid_to,payment_method,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->bind_param('sssdssssi', $date, $category, $description, $amount, $paidTo, $method, $reference, $notes, $uid);
            } else {
                $stmt = $conn->prepare('UPDATE expenses SET expense_date=?,category=?,description=?,amount=?,paid_to=?,payment_method=?,reference_no=?,notes=? WHERE id=?');
                $stmt->bind_param('sssdssssi', $date, $category, $description, $amount, $paidTo, $method, $reference, $notes, $id);
            }
            $ok = $stmt->execute();
            setFlash($ok ? ($action === 'create' ? 'Expense recorded.' : 'Expense updated.') : 'Unable to save this expense.', $ok ? 'success' : 'danger');
        }
    }
    redirect('index.php?from=' . urlencode($from) . '&to=' . urlencode($to));
}

$stmt = $conn->prepare('SELECT * FROM expenses WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date DESC,id DESC');
$stmt->bind_param('ss', $from, $to); $stmt->execute(); $expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$totalStmt = $conn->prepare('SELECT COALESCE(SUM(amount),0) total FROM expenses WHERE expense_date BETWEEN ? AND ?');
$totalStmt->bind_param('ss', $from, $to); $totalStmt->execute(); $total = (float)$totalStmt->get_result()->fetch_assoc()['total'];
$yearStmt = $conn->prepare('SELECT COALESCE(SUM(amount),0) total FROM expenses WHERE expense_date BETWEEN ? AND ?');
$yearFrom = date('Y-01-01'); $yearTo = date('Y-12-31');
$yearStmt->bind_param('ss', $yearFrom, $yearTo); $yearStmt->execute(); $yearTotal = (float)$yearStmt->get_result()->fetch_assoc()['total'];
$expenseCount = count($expenses);
$averageExpense = $expenseCount > 0 ? $total / $expenseCount : 0;
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
    <?php showFlash(); ?>
    <div class="page-header"><h2><i class="fas fa-receipt"></i> Expenses</h2><div class="d-flex flex-wrap" style="gap:8px"><a class="btn btn-outline-primary" href="../reports/expenditure_report.php"><i class="fas fa-chart-bar"></i> Expense Report</a><button class="btn btn-primary" type="button" onclick="openExpenseModal()"><i class="fas fa-plus"></i> Add Expense</button></div></div>
    <div class="stats-grid module-stats-grid">
        <div class="stat-card"><div class="stat-icon"><i class="fas fa-wallet"></i></div><div><div class="stat-value">UGX <?=number_format($total, 0)?></div><div class="stat-label">Selected Period</div></div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fas fa-receipt"></i></div><div><div class="stat-value"><?=number_format($expenseCount)?></div><div class="stat-label">Expense Records</div></div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fas fa-divide"></i></div><div><div class="stat-value">UGX <?=number_format($averageExpense, 0)?></div><div class="stat-label">Average Expense</div></div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fas fa-calendar"></i></div><div><div class="stat-value">UGX <?=number_format($yearTotal, 0)?></div><div class="stat-label">This Year</div></div></div>
    </div>
    <form class="page-toolbar" method="get"><div class="page-toolbar-filters"><input class="form-control" type="date" name="from" value="<?=ex_h($from)?>" title="From date" aria-label="From date"><input class="form-control" type="date" name="to" value="<?=ex_h($to)?>" title="To date" aria-label="To date"></div><div class="page-toolbar-actions"><button class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button><a href="index.php" class="btn btn-outline-primary page-toolbar-refresh"><i class="fas fa-rotate-right"></i> Refresh</a></div></form>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Paid to</th><th>Method</th><th>Reference</th><th class="text-right">Amount (UGX)</th><th class="text-right">Actions</th></tr></thead><tbody>
    <?php if (!$expenses): ?><tr><td colspan="8" class="text-center text-muted py-4">No expenses found for this date range.</td></tr><?php else: foreach ($expenses as $expense): ?><tr><td><?=ex_h($expense['expense_date'])?></td><td><span class="expense-category"><?=ex_h($expense['category'])?></span></td><td><?=ex_h($expense['description'])?></td><td><?=ex_h($expense['paid_to'] ?: '—')?></td><td><?=ex_h($expense['payment_method'] ?: '—')?></td><td><?=ex_h($expense['reference_no'] ?: '—')?></td><td class="text-right font-weight-bold"><?=number_format((float)$expense['amount'], 2)?></td><td class="text-right text-nowrap"><button type="button" class="btn btn-sm btn-outline-primary" data-edit-expense="<?=ex_h(json_encode($expense, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>" aria-label="Edit expense"><i class="fas fa-pen"></i></button> <button type="button" class="btn btn-sm btn-outline-danger" data-delete-id="<?=(int)$expense['id']?>" data-delete-description="<?=ex_h($expense['description'])?>" aria-label="Delete expense"><i class="fas fa-trash"></i></button></td></tr><?php endforeach; endif; ?>
    </tbody></table></div></div>
</div></div>

<div class="expense-modal-backdrop" id="expenseBackdrop"></div>
<div class="expense-modal" id="expenseModal" role="dialog" aria-modal="true" aria-labelledby="expenseModalTitle" aria-hidden="true"><div class="expense-dialog"><form method="post" id="expenseForm"><div class="expense-modal-head"><div><h3 id="expenseModalTitle">Add Expense</h3><p>Enter the payment details below.</p></div><button type="button" class="expense-close" data-close-modal aria-label="Close">&times;</button></div><div class="expense-modal-body"><input type="hidden" name="csrf_token" value="<?=ex_h($csrf)?>"><input type="hidden" name="action" id="expenseAction" value="create"><input type="hidden" name="id" id="expenseId"><div class="expense-form-grid">
<label>Date<input class="form-control" type="date" name="expense_date" id="expenseDate" value="<?=date('Y-m-d')?>" required></label>
<label>Category<select class="form-control" name="category" id="expenseCategory" required><option value="">Select category</option><?php foreach($categories as $option):?><option value="<?=ex_h($option)?>"><?=ex_h($option)?></option><?php endforeach;?></select></label>
<label class="expense-wide">Description<input class="form-control" name="description" id="expenseDescription" maxlength="255" required></label>
<label>Amount (UGX)<input class="form-control" type="number" min="0.01" step="0.01" name="amount" id="expenseAmount" required></label>
<label>Paid to<input class="form-control" name="paid_to" id="expensePaidTo" maxlength="150"></label>
<label>Payment method<select class="form-control" name="payment_method" id="expenseMethod" required><option value="">Select method</option><?php foreach($paymentMethods as $option):?><option value="<?=ex_h($option)?>"><?=ex_h($option)?></option><?php endforeach;?></select></label>
<label>Reference<input class="form-control" name="reference_no" id="expenseReference" maxlength="100"></label>
<label class="expense-wide">Notes<textarea class="form-control" name="notes" id="expenseNotes" rows="3"></textarea></label>
</div></div><div class="expense-modal-foot"><button type="button" class="btn btn-light" data-close-modal>Cancel</button><button class="btn btn-primary"><i class="fas fa-save"></i> Save Expense</button></div></form></div></div>
<div class="expense-modal" id="deleteExpenseModal" role="dialog" aria-modal="true" aria-labelledby="deleteExpenseTitle" aria-hidden="true"><div class="expense-dialog expense-dialog-sm"><form method="post"><div class="expense-modal-head"><div><h3 id="deleteExpenseTitle">Delete Expense</h3><p>This action cannot be undone.</p></div><button type="button" class="expense-close" data-close-modal aria-label="Close">&times;</button></div><div class="expense-modal-body"><input type="hidden" name="csrf_token" value="<?=ex_h($csrf)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteExpenseId"><p>Delete <strong id="deleteExpenseDescription"></strong>?</p></div><div class="expense-modal-foot"><button type="button" class="btn btn-light" data-close-modal>Cancel</button><button class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div></form></div></div>

<style>
.expense-total{margin-left:auto;color:#64748b;font-size:12px}.expense-total span{display:block;color:#172033;font-size:18px}.expense-category{display:inline-block;padding:4px 8px;border-radius:20px;background:var(--primary-fade,#f1ebfa);color:var(--primary);font-size:11px;font-weight:700}.expense-modal{position:fixed;inset:0;z-index:10001;display:none;align-items:center;justify-content:center;padding:16px;overflow:auto}.expense-modal.show{display:flex}.expense-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.58);z-index:10000;display:none}.expense-modal-backdrop.show{display:block}.expense-dialog{width:min(680px,100%);background:#fff;border-radius:14px;box-shadow:0 24px 70px rgba(0,0,0,.25);overflow:hidden}.expense-dialog-sm{width:min(430px,100%)}.expense-modal-head,.expense-modal-foot{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:16px 20px;background:#f8fafc;border-bottom:1px solid #e5eaf1}.expense-modal-head h3{margin:0;color:#172033;font-size:18px}.expense-modal-head p{margin:3px 0 0;color:#64748b;font-size:12px}.expense-close{border:0;background:transparent;font-size:27px;line-height:1;color:#64748b;cursor:pointer}.expense-modal-body{padding:20px;max-height:65vh;overflow:auto}.expense-modal-foot{justify-content:flex-end;border-top:1px solid #e5eaf1;border-bottom:0}.expense-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.expense-form-grid label{display:block;margin:0;font-size:12px;font-weight:700;color:#475569}.expense-form-grid .form-control{margin-top:5px}.expense-wide{grid-column:1/-1}.expense-modal-open{overflow:hidden}
@media(max-width:600px){.expense-total{width:100%;margin:4px 0 0}.expense-form-grid{grid-template-columns:1fr}.expense-wide{grid-column:auto}.expense-modal-body{padding:16px}.expense-modal-head,.expense-modal-foot{padding:14px 16px}}
</style>
<script>
(()=>{const modal=document.getElementById('expenseModal'),delModal=document.getElementById('deleteExpenseModal'),backdrop=document.getElementById('expenseBackdrop');const all=[modal,delModal];function show(target){all.forEach(m=>{m.classList.remove('show');m.setAttribute('aria-hidden','true')});target.classList.add('show');target.setAttribute('aria-hidden','false');backdrop.classList.add('show');document.body.classList.add('expense-modal-open')}function close(){all.forEach(m=>{m.classList.remove('show');m.setAttribute('aria-hidden','true')});backdrop.classList.remove('show');document.body.classList.remove('expense-modal-open')}window.openExpenseModal=function(data){const form=document.getElementById('expenseForm');form.reset();document.getElementById('expenseAction').value=data?'update':'create';document.getElementById('expenseId').value=data?.id||'';document.getElementById('expenseModalTitle').textContent=data?'Edit Expense':'Add Expense';if(data){document.getElementById('expenseDate').value=data.expense_date;document.getElementById('expenseCategory').value=data.category;document.getElementById('expenseDescription').value=data.description;document.getElementById('expenseAmount').value=data.amount;document.getElementById('expensePaidTo').value=data.paid_to||'';document.getElementById('expenseMethod').value=data.payment_method||'';document.getElementById('expenseReference').value=data.reference_no||'';document.getElementById('expenseNotes').value=data.notes||''}show(modal)};document.querySelectorAll('[data-edit-expense]').forEach(b=>b.addEventListener('click',()=>{try{window.openExpenseModal(JSON.parse(b.dataset.editExpense))}catch(e){}}));document.querySelectorAll('[data-delete-id]').forEach(b=>b.addEventListener('click',()=>{document.getElementById('deleteExpenseId').value=b.dataset.deleteId;document.getElementById('deleteExpenseDescription').textContent=b.dataset.deleteDescription||'this expense';show(delModal)}));document.querySelectorAll('[data-close-modal]').forEach(b=>b.addEventListener('click',close));backdrop.addEventListener('click',close);document.addEventListener('keydown',e=>{if(e.key==='Escape')close()});})();
</script>
<?php include '../includes/footer.php'; ?>
