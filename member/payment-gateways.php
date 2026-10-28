<?php
declare(strict_types=1);

$pageTitle = 'Payment Gateways';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_permission('member.payment_gateways.view');

$memberId = current_member_id();
$memberNumber = '';
if ($memberId > 0) {
    $stmt = $conn->prepare('SELECT member_no FROM members WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $memberNumber = (string) (($stmt->get_result()->fetch_assoc()['member_no'] ?? ''));
        $stmt->close();
    }
}

$gateways = [];
if (mp_table($conn, 'payment_gateways')) {
    $result = $conn->query(
        'SELECT gateway_type, gateway_name, provider_name, account_name, account_number,
                phone_number, merchant_code, branch_name, currency, payment_reference_label, instructions
         FROM payment_gateways
         WHERE is_active = 1
         ORDER BY sort_order ASC, gateway_name ASC, id ASC'
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $gateways[] = $row;
        }
    }
}

include __DIR__ . '/../includes/sidebar.php';
?>
<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>
    <div class="content-area payment-gateways-page">
        <div class="gateway-hero">
            <div>
                <h3>Official Church Payment Gateways</h3>
                <p>Use only the payment details shown on this page. Confirm the provider and account name before sending money.</p>
            </div>
            <i class="fas fa-shield-alt"></i>
        </div>

        <?php if ($memberNumber !== ''): ?>
            <div class="member-reference">
                <span>Your recommended payment reference</span>
                <strong id="memberReference"><?= mp_h($memberNumber) ?></strong>
                <button type="button" id="copyReference"><i class="fas fa-copy"></i> Copy</button>
            </div>
        <?php endif; ?>

        <?php if ($gateways === []): ?>
            <div class="alert alert-info">The administrator has not yet published any payment gateways.</div>
        <?php else: ?>
            <div class="gateway-grid">
                <?php foreach ($gateways as $gateway): ?>
                    <?php
                    $type = (string) $gateway['gateway_type'];
                    $typeLabel = match ($type) {
                        'mobile_money' => 'Mobile Money',
                        'merchant_code' => 'Merchant Code',
                        default => 'Bank Account',
                    };
                    $icon = match ($type) {
                        'mobile_money' => 'fa-mobile-alt',
                        'merchant_code' => 'fa-qrcode',
                        default => 'fa-university',
                    };
                    $primaryValue = $type === 'bank'
                        ? (string) $gateway['account_number']
                        : ($type === 'mobile_money' ? (string) $gateway['phone_number'] : (string) $gateway['merchant_code']);
                    $primaryLabel = $type === 'bank' ? 'Account Number' : ($type === 'mobile_money' ? 'Mobile Money Number' : 'Merchant Code');
                    ?>
                    <article class="gateway-card">
                        <div class="gateway-card-head">
                            <span class="gateway-icon"><i class="fas <?= mp_h($icon) ?>"></i></span>
                            <div><small><?= mp_h($typeLabel) ?></small><h4><?= mp_h((string) $gateway['gateway_name']) ?></h4></div>
                        </div>
                        <div class="gateway-provider"><?= mp_h((string) $gateway['provider_name']) ?></div>
                        <div class="gateway-detail important">
                            <span><?= mp_h($primaryLabel) ?></span>
                            <strong><?= mp_h($primaryValue) ?></strong>
                            <button type="button" class="copy-value" data-copy="<?= mp_h($primaryValue) ?>"><i class="fas fa-copy"></i></button>
                        </div>
                        <?php if (!empty($gateway['account_name'])): ?>
                            <div class="gateway-detail"><span>Account Name</span><strong><?= mp_h((string) $gateway['account_name']) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($type === 'bank' && !empty($gateway['branch_name'])): ?>
                            <div class="gateway-detail"><span>Branch</span><strong><?= mp_h((string) $gateway['branch_name']) ?></strong></div>
                        <?php endif; ?>
                        <div class="gateway-detail"><span>Currency</span><strong><?= mp_h((string) $gateway['currency']) ?></strong></div>
                        <?php if (!empty($gateway['payment_reference_label'])): ?>
                            <div class="gateway-reference"><i class="fas fa-tag"></i> <?= mp_h((string) $gateway['payment_reference_label']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($gateway['instructions'])): ?>
                            <div class="gateway-instructions"><?= nl2br(mp_h((string) $gateway['instructions'])) ?></div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<style>
.gateway-hero{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:22px 24px;margin-bottom:18px;border-radius:14px;background:linear-gradient(135deg,var(--primary),var(--primary-lt));color:#fff}.gateway-hero h3{margin:0 0 6px}.gateway-hero p{margin:0;opacity:.9}.gateway-hero>i{font-size:46px;opacity:.28}.member-reference{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 16px;margin-bottom:18px;border:1px solid #d8c9ef;border-radius:10px;background:#faf7ff}.member-reference span{color:#666}.member-reference strong{font-size:18px;color:var(--primary)}.member-reference button,.copy-value{border:0;background:var(--primary);color:#fff;border-radius:6px;padding:7px 10px;cursor:pointer}.gateway-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:16px}.gateway-card{border:1px solid #e0e3e8;border-radius:14px;background:#fff;padding:18px;box-shadow:0 5px 16px rgba(30,35,45,.06)}.gateway-card-head{display:flex;align-items:center;gap:12px}.gateway-card-head small{color:#6b7280}.gateway-card-head h4{margin:2px 0 0}.gateway-icon{width:46px;height:46px;display:flex;align-items:center;justify-content:center;border-radius:12px;background:var(--primary-fade,#f1ebfa);color:var(--primary);font-size:20px}.gateway-provider{margin:14px 0;padding:8px 10px;border-radius:7px;background:#f6f7f9;font-weight:700}.gateway-detail{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;padding:9px 0;border-bottom:1px dashed #e4e6ea}.gateway-detail span{color:#6b7280}.gateway-detail strong{text-align:right;word-break:break-word}.gateway-detail.important{grid-template-columns:1fr auto auto}.gateway-reference{margin-top:14px;padding:10px;border-radius:8px;background:#fff7df;color:#7c5800}.gateway-instructions{margin-top:12px;color:#555;line-height:1.5}@media(max-width:600px){.gateway-hero{align-items:flex-start}.gateway-hero>i{display:none}.gateway-detail,.gateway-detail.important{grid-template-columns:1fr auto}.gateway-detail.important .copy-value{grid-column:2}}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const copyText = async value => {
        if (!value) return;
        try { await navigator.clipboard.writeText(value); }
        catch (e) {
            const area = document.createElement('textarea'); area.value = value; document.body.appendChild(area); area.select(); document.execCommand('copy'); area.remove();
        }
    };
    document.querySelectorAll('.copy-value').forEach(button => button.addEventListener('click', () => copyText(button.dataset.copy || '')));
    document.getElementById('copyReference')?.addEventListener('click', () => copyText(document.getElementById('memberReference')?.textContent || ''));
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
