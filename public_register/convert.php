<?php
declare(strict_types=1);

require_once '../config/config.php';
require_once '../includes/church-book-functions.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

$token = cb_clean($_GET['token'] ?? $_POST['token'] ?? '');
$invite = cb_registration_link_lookup($conn, 'convert_registration_links', $token, false);
$csrf = cb_csrf_token();
$errors = [];
$success = false;
$created = null;

$site = cb_site_setting($conn, 'site_name', 'Church');
$logo = cb_site_setting($conn, 'site_logo', cb_site_setting($conn, 'logo', ''));
if ($logo !== '' && !preg_match('~^https?://~i', $logo)) {
    $logo = cb_absolute_base_url() . ltrim($logo, '/');
}

$prefillMember = null;
$prefillVisitor = null;
$assignedMember = null;

if ($invite) {
    if (!empty($invite['linked_member_id']) && cb_table_exists($conn, 'members')) {
        $prefillMember = cb_fetch_one(
            $conn,
            "SELECT id, member_no, full_name, gender, phone, email, address
             FROM members WHERE id=? LIMIT 1",
            'i',
            [(int)$invite['linked_member_id']]
        );
    }

    if (!empty($invite['linked_visitor_id']) && cb_table_exists($conn, 'church_visitors')) {
        $prefillVisitor = cb_fetch_one(
            $conn,
            "SELECT id, full_name, gender, phone, email, address
             FROM church_visitors WHERE id=? LIMIT 1",
            'i',
            [(int)$invite['linked_visitor_id']]
        );
    }

    if (!empty($invite['assigned_member_id']) && cb_table_exists($conn, 'members')) {
        $assignedMember = cb_fetch_one(
            $conn,
            "SELECT id, member_no, full_name, phone
             FROM members WHERE id=? LIMIT 1",
            'i',
            [(int)$invite['assigned_member_id']]
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite) {
    cb_verify_csrf();

    // Honeypot: legitimate users never fill this field.
    if (cb_clean($_POST['website'] ?? '') !== '') {
        http_response_code(400);
        exit('Invalid submission.');
    }

    $conversionDate = cb_clean($_POST['conversion_date'] ?? date('Y-m-d'));
    $personType = cb_clean($_POST['person_type'] ?? ($invite['person_type'] ?? 'Visitor'));
    $memberNo = cb_clean($_POST['member_no'] ?? '');
    $fullName = cb_clean($_POST['full_name'] ?? '');
    $gender = cb_clean($_POST['gender'] ?? '');
    $phone = cb_clean($_POST['phone'] ?? '');
    $email = cb_clean($_POST['email'] ?? '');
    $address = cb_clean($_POST['address'] ?? '');
    $decisionType = cb_clean($_POST['decision_type'] ?? 'New Convert');
    $context = cb_clean($_POST['conversion_context'] ?? '');
    $testimony = cb_clean($_POST['testimony'] ?? '');
    $prayerRequest = cb_clean($_POST['prayer_request'] ?? '');
    $baptismInterest = isset($_POST['baptism_interest']) ? 1 : 0;
    $notes = cb_clean($_POST['notes'] ?? '');

    if (!in_array($personType, ['Member', 'Visitor'], true)) {
        $personType = 'Visitor';
    }
    if (!empty($invite['person_type']) && in_array($invite['person_type'], ['Member', 'Visitor'], true)) {
        $personType = (string)$invite['person_type'];
    }
    if (!in_array($gender, ['Male', 'Female', 'Other', 'Prefer not to say'], true)) {
        $gender = '';
    }
    if (!in_array($decisionType, ['New Convert', 'Recommitment', 'Transferred In', 'Other'], true)) {
        $decisionType = 'New Convert';
    }

    if ($fullName === '') {
        $errors[] = 'Your full name is required.';
    }
    if (!cb_valid_date($conversionDate)) {
        $errors[] = 'Enter a valid conversion date.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if (!$errors) {
        $conn->begin_transaction();

        try {
            $locked = cb_registration_link_lookup($conn, 'convert_registration_links', $token, true);
            if (!$locked) {
                throw new RuntimeException('This registration link is no longer available.');
            }

            $memberId = !empty($locked['linked_member_id']) ? (int)$locked['linked_member_id'] : null;
            $visitorId = !empty($locked['linked_visitor_id']) ? (int)$locked['linked_visitor_id'] : null;

            if ($personType === 'Member') {
                $visitorId = null;

                if (!$memberId && $memberNo !== '' && cb_table_exists($conn, 'members')) {
                    $member = cb_fetch_one(
                        $conn,
                        "SELECT id, full_name, gender, phone, email, address
                         FROM members WHERE member_no=? LIMIT 1 FOR UPDATE",
                        's',
                        [$memberNo]
                    );
                    if ($member) {
                        $memberId = (int)$member['id'];
                    }
                }
            } else {
                $memberId = null;

                if (!$visitorId && cb_table_exists($conn, 'church_visitors')) {
                    if ($phone !== '') {
                        $visitor = cb_fetch_one(
                            $conn,
                            "SELECT id FROM church_visitors WHERE phone=? ORDER BY id DESC LIMIT 1 FOR UPDATE",
                            's',
                            [$phone]
                        );
                        if ($visitor) $visitorId = (int)$visitor['id'];
                    }

                    if (!$visitorId && $email !== '') {
                        $visitor = cb_fetch_one(
                            $conn,
                            "SELECT id FROM church_visitors WHERE email=? ORDER BY id DESC LIMIT 1 FOR UPDATE",
                            's',
                            [$email]
                        );
                        if ($visitor) $visitorId = (int)$visitor['id'];
                    }
                }
            }

            $assignedMemberId = !empty($locked['assigned_member_id']) ? (int)$locked['assigned_member_id'] : null;
            $weeks = max(1, min(104, (int)($locked['nurture_period_weeks'] ?? 12)));
            $nurtureStart = $conversionDate;
            $nurtureEndDate = new DateTime($nurtureStart);
            $nurtureEndDate->modify('+' . $weeks . ' weeks');
            $nurtureEnd = $nurtureEndDate->format('Y-m-d');
            $status = $assignedMemberId ? 'In Nurture' : 'Pending Assignment';
            $nextFollowUpDate = null;
            $discipleshipClass = '';
            $linkId = (int)$locked['id'];
            $genderDb = $gender !== '' ? $gender : null;
            $createdBy = null;
            $selfRegistered = 1;
            $notes = trim('Self-registered using public convert QR/link.' . ($notes !== '' ? ' ' . $notes : ''));

            $stmt = $conn->prepare(
                "INSERT INTO church_converts (
                    conversion_date, person_type, member_id, visitor_id, full_name, gender, phone, email, address,
                    decision_type, conversion_context, testimony, prayer_request, assigned_member_id,
                    nurture_start_date, nurture_end_date, nurture_period_weeks, status, next_follow_up_date,
                    baptism_interest, discipleship_class, notes, self_registered, registration_link_id, created_by
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );

            if (!$stmt) {
                throw new RuntimeException('Unable to prepare convert registration.');
            }

            $stmt->bind_param(
                'ssiisssssssssissississiii',
                $conversionDate,
                $personType,
                $memberId,
                $visitorId,
                $fullName,
                $genderDb,
                $phone,
                $email,
                $address,
                $decisionType,
                $context,
                $testimony,
                $prayerRequest,
                $assignedMemberId,
                $nurtureStart,
                $nurtureEnd,
                $weeks,
                $status,
                $nextFollowUpDate,
                $baptismInterest,
                $discipleshipClass,
                $notes,
                $selfRegistered,
                $linkId,
                $createdBy
            );

            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to save your registration: ' . $stmt->error);
            }
            $convertId = (int)$conn->insert_id;
            $stmt->close();

            // When the convert came from an existing visitor record, keep the visitor follow-up in sync.
            if ($visitorId && cb_table_exists($conn, 'church_visitors')) {
                $assignedName = '';
                if ($assignedMemberId) {
                    $assigned = cb_fetch_one(
                        $conn,
                        "SELECT full_name FROM members WHERE id=? LIMIT 1",
                        'i',
                        [$assignedMemberId]
                    );
                    $assignedName = trim((string)($assigned['full_name'] ?? ''));
                }

                $followStatus = $assignedMemberId ? 'Contacted' : 'Pending';
                $stmt = $conn->prepare(
                    "UPDATE church_visitors
                     SET follow_up_required=1, follow_up_status=?, assigned_to=CASE WHEN ?<>'' THEN ? ELSE assigned_to END
                     WHERE id=?"
                );
                if ($stmt) {
                    $stmt->bind_param('sssi', $followStatus, $assignedName, $assignedName, $visitorId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            cb_consume_registration_link($conn, 'convert_registration_links', $locked);
            $conn->commit();

            $created = [
                'id' => $convertId,
                'weeks' => $weeks,
                'end_date' => $nurtureEnd,
                'assigned_name' => $assignedMember
                    ? trim((string)$assignedMember['full_name'])
                    : '',
            ];
            $success = true;
        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = $e->getMessage();
        }
    }
}

$defaultType = (string)($_POST['person_type'] ?? $invite['person_type'] ?? 'Visitor');
if (!in_array($defaultType, ['Member', 'Visitor'], true)) $defaultType = 'Visitor';

$defaultName = (string)($_POST['full_name'] ?? $invite['recipient_name'] ?? '');
$defaultPhone = (string)($_POST['phone'] ?? $invite['recipient_phone'] ?? '');
$defaultEmail = (string)($_POST['email'] ?? '');
$defaultAddress = (string)($_POST['address'] ?? '');
$defaultGender = (string)($_POST['gender'] ?? '');
$defaultMemberNo = (string)($_POST['member_no'] ?? '');

if ($prefillMember) {
    if ($defaultName === '') $defaultName = trim((string)$prefillMember['full_name']);
    if ($defaultPhone === '') $defaultPhone = (string)($prefillMember['phone'] ?? '');
    if ($defaultEmail === '') $defaultEmail = (string)($prefillMember['email'] ?? '');
    if ($defaultAddress === '') $defaultAddress = (string)($prefillMember['address'] ?? '');
    if ($defaultGender === '') $defaultGender = (string)($prefillMember['gender'] ?? '');
    if ($defaultMemberNo === '') $defaultMemberNo = (string)($prefillMember['member_no'] ?? '');
}
if ($prefillVisitor) {
    if ($defaultName === '') $defaultName = (string)($prefillVisitor['full_name'] ?? '');
    if ($defaultPhone === '') $defaultPhone = (string)($prefillVisitor['phone'] ?? '');
    if ($defaultEmail === '') $defaultEmail = (string)($prefillVisitor['email'] ?? '');
    if ($defaultAddress === '') $defaultAddress = (string)($prefillVisitor['address'] ?? '');
    if ($defaultGender === '') $defaultGender = (string)($prefillVisitor['gender'] ?? '');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Convert Registration - <?= cb_h($site) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/public-registration.css">
    <style>
        .pr-plan{background:#f0fdf4;border:1px solid #abefc6;border-radius:12px;padding:13px 14px;margin-bottom:16px;color:#05603a}
        .pr-plan strong{color:#044e3a}.pr-honeypot{position:absolute!important;left:-10000px!important;width:1px!important;height:1px!important;overflow:hidden!important}
        .pr-check{display:flex;align-items:flex-start;gap:9px;padding:10px 0}.pr-check input{margin-top:3px}
    </style>
<?= site_theme_css() ?></head>
<body>
<div class="pr-wrap">
    <div class="pr-card">
        <div class="pr-head">
            <?php if ($logo): ?><img class="pr-logo" src="<?= cb_h($logo) ?>" alt="<?= cb_h($site) ?> Logo"><?php endif; ?>
            <h1>Convert Registration & Nurture</h1>
            <p><?= cb_h($site) ?></p>
        </div>

        <div class="pr-body">
            <?php if ($success): ?>
                <div class="pr-success-card">
                    <i class="fas fa-circle-check"></i>
                    <h2>Registration received</h2>
                    <p>Thank you. Your conversion and nurture registration has been received.</p>
                    <?php if ($created): ?>
                        <div class="pr-plan" style="text-align:left;margin-top:18px">
                            <strong>Nurture period:</strong> <?= (int)$created['weeks'] ?> weeks<br>
                            <strong>Expected completion:</strong> <?= cb_h(date('d M Y', strtotime($created['end_date']))) ?>
                            <?php if ($created['assigned_name'] !== ''): ?><br><strong>Assigned nurture member:</strong> <?= cb_h($created['assigned_name']) ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <p>The church follow-up team will contact you regarding the next steps.</p>
                </div>

            <?php elseif (!$invite): ?>
                <div class="pr-disabled">
                    <div class="pr-alert pr-error">This registration invitation is invalid, expired, inactive, or has reached its usage limit.</div>
                    <p>Please request a new QR code or registration link from the church office.</p>
                </div>

            <?php else: ?>
                <?php foreach ($errors as $error): ?>
                    <div class="pr-alert pr-error"><?= cb_h($error) ?></div>
                <?php endforeach; ?>

                <?php if ($assignedMember): ?>
                    <div class="pr-plan">
                        <strong>Your nurture plan:</strong>
                        You will be assigned to <?= cb_h(trim((string)$assignedMember['full_name'])) ?>
                        for <?= (int)($invite['nurture_period_weeks'] ?? 12) ?> weeks after registration.
                    </div>
                <?php else: ?>
                    <div class="pr-plan">
                        <strong>Your nurture plan:</strong>
                        After registration, the church will assign a member to walk with you during your <?= (int)($invite['nurture_period_weeks'] ?? 12) ?>-week nurture period.
                    </div>
                <?php endif; ?>

                <p class="pr-note">Complete the form below. Fields marked * are required.</p>

                <form method="post" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?= cb_h($csrf) ?>">
                    <input type="hidden" name="token" value="<?= cb_h($token) ?>">
                    <div class="pr-honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>

                    <div class="pr-grid">
                        <div class="pr-section">Your Details</div>

                        <div class="pr-field">
                            <label>Conversion / Decision Date *</label>
                            <input class="pr-input" type="date" name="conversion_date" value="<?= cb_h($_POST['conversion_date'] ?? date('Y-m-d')) ?>" required>
                        </div>

                        <div class="pr-field">
                            <label>I am a *</label>
                            <?php if (!empty($invite['person_type'])): ?>
                                <input type="hidden" name="person_type" value="<?= cb_h($defaultType) ?>">
                                <input class="pr-input" value="<?= cb_h($defaultType) ?>" readonly>
                            <?php else: ?>
                                <select class="pr-select" name="person_type" id="convertPersonType" required>
                                    <option value="Visitor" <?= $defaultType === 'Visitor' ? 'selected' : '' ?>>Visitor</option>
                                    <option value="Member" <?= $defaultType === 'Member' ? 'selected' : '' ?>>Registered Church Member</option>
                                </select>
                            <?php endif; ?>
                        </div>

                        <div class="pr-field" id="memberNoField">
                            <label>Member Number</label>
                            <input class="pr-input" name="member_no" maxlength="80" value="<?= cb_h($defaultMemberNo) ?>" <?= $prefillMember ? 'readonly' : '' ?> placeholder="If you are already registered">
                        </div>

                        <div class="pr-field">
                            <label>Full Name *</label>
                            <input class="pr-input" name="full_name" required maxlength="180" value="<?= cb_h($defaultName) ?>">
                        </div>

                        <div class="pr-field">
                            <label>Gender</label>
                            <select class="pr-select" name="gender">
                                <option value="">Select</option>
                                <?php foreach (['Male','Female','Other','Prefer not to say'] as $g): ?>
                                    <option value="<?= cb_h($g) ?>" <?= $defaultGender === $g ? 'selected' : '' ?>><?= cb_h($g) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pr-field">
                            <label>Phone</label>
                            <input class="pr-input" name="phone" maxlength="50" value="<?= cb_h($defaultPhone) ?>">
                        </div>

                        <div class="pr-field">
                            <label>Email</label>
                            <input class="pr-input" type="email" name="email" maxlength="180" value="<?= cb_h($defaultEmail) ?>">
                        </div>

                        <div class="pr-field span-2">
                            <label>Address / Residence</label>
                            <input class="pr-input" name="address" maxlength="255" value="<?= cb_h($defaultAddress) ?>">
                        </div>

                        <div class="pr-section">Your Decision</div>

                        <div class="pr-field">
                            <label>Decision Type *</label>
                            <select class="pr-select" name="decision_type" required>
                                <?php foreach (['New Convert','Recommitment','Transferred In','Other'] as $d): ?>
                                    <option value="<?= cb_h($d) ?>" <?= ($_POST['decision_type'] ?? 'New Convert') === $d ? 'selected' : '' ?>><?= cb_h($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pr-field">
                            <label>Service / Event / Place</label>
                            <input class="pr-input" name="conversion_context" maxlength="180" value="<?= cb_h($_POST['conversion_context'] ?? '') ?>" placeholder="Where you made the decision">
                        </div>

                        <div class="pr-field span-2">
                            <label>Brief Testimony / What led to your decision?</label>
                            <textarea class="pr-textarea" name="testimony"><?= cb_h($_POST['testimony'] ?? '') ?></textarea>
                        </div>

                        <div class="pr-field span-2">
                            <label>Prayer Request / Support Needed</label>
                            <textarea class="pr-textarea" name="prayer_request"><?= cb_h($_POST['prayer_request'] ?? '') ?></textarea>
                        </div>

                        <div class="pr-field span-2">
                            <label class="pr-check">
                                <input type="checkbox" name="baptism_interest" value="1" <?= isset($_POST['baptism_interest']) ? 'checked' : '' ?>>
                                <span>I would like to receive information about baptism / baptism preparation.</span>
                            </label>
                        </div>

                        <div class="pr-field span-2">
                            <label>Additional Notes</label>
                            <textarea class="pr-textarea" name="notes"><?= cb_h($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="pr-actions">
                        <button class="pr-btn" type="submit"><i class="fas fa-paper-plane"></i> Register for Nurture</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    const personType = document.getElementById('convertPersonType');
    const memberNoField = document.getElementById('memberNoField');
    function syncMemberField() {
        const value = personType ? personType.value : <?= json_encode($defaultType) ?>;
        if (memberNoField) memberNoField.style.display = value === 'Member' ? '' : 'none';
    }
    personType?.addEventListener('change', syncMemberField);
    syncMemberField();
})();
</script>
</body>
</html>
