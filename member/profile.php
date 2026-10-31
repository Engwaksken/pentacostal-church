<?php
declare(strict_types=1);

$pageTitle = 'My Profile';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../includes/member-fields.php';

require_permission('member.profile.view');

$userId   = (int) ($_SESSION['user_id'] ?? 0);
$memberId = current_member_id();

if ($userId <= 0 || $memberId <= 0) {
    http_response_code(403);
    exit('A valid member account could not be identified.');
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['member_profile_csrf'])) {
    $_SESSION['member_profile_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['member_profile_csrf'];

// Schema check runs before any transaction, since ALTER TABLE implicitly commits.
$hasServiceColumn = member_fields_ensure($conn);

/*
|--------------------------------------------------------------------------
| Flash helpers
|--------------------------------------------------------------------------
*/

function profileFlash(string $message, string $type = 'success'): void
{
    $_SESSION['member_profile_flash'] = [
        'message' => $message,
        'type'    => $type,
    ];
}

function profileRedirect(): never
{
    header('Location: ' . BASE_URL . 'member/profile.php');
    exit;
}

function profilePostValue(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

/*
|--------------------------------------------------------------------------
| Discover the church-zones table
|--------------------------------------------------------------------------
|
| The system may use church_zones or zones depending on the existing
| database version. This code supports either table.
|
*/

$zoneTable = null;

foreach (['church_zones', 'zones'] as $possibleTable) {
    if (mp_table($conn, $possibleTable)) {
        $zoneTable = $possibleTable;
        break;
    }
}

$zoneIdColumn   = 'id';
$zoneNameColumn = null;
$zones          = [];

if ($zoneTable !== null) {
    $zoneColumns = mp_cols($conn, $zoneTable);

    foreach ([
        'zone_name',
        'name',
        'church_zone',
        'title',
        'zone'
    ] as $possibleColumn) {
        if (in_array($possibleColumn, $zoneColumns, true)) {
            $zoneNameColumn = $possibleColumn;
            break;
        }
    }

    if (
        in_array($zoneIdColumn, $zoneColumns, true) &&
        $zoneNameColumn !== null
    ) {
        $zones = mp_rows(
            $conn,
            "SELECT
                `{$zoneIdColumn}` AS id,
                `{$zoneNameColumn}` AS zone_name
             FROM `{$zoneTable}`
             ORDER BY `{$zoneNameColumn}` ASC"
        );
    }
}

/*
|--------------------------------------------------------------------------
| Process profile updates
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (
        $postedToken === '' ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        profileFlash(
            'Your form session expired. Refresh the page and try again.',
            'danger'
        );

        profileRedirect();
    }

    $action = profilePostValue('action');

    /*
    |--------------------------------------------------------------------------
    | Update contact information and church zone
    |--------------------------------------------------------------------------
    */

    if ($action === 'update_profile') {
        $email  = strtolower(profilePostValue('email'));
        $phone  = profilePostValue('phone');
        $serviceAttended = mb_substr(profilePostValue('service_attended'), 0, 120, 'UTF-8');
        $zoneId = filter_input(
            INPUT_POST,
            'church_zone_id',
            FILTER_VALIDATE_INT
        );

        $zoneId = $zoneId !== false && $zoneId > 0
            ? (int) $zoneId
            : null;

        $errors = [];

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        if ($phone === '') {
            $errors[] = 'Phone number is required.';
        } elseif (!preg_match('/^[0-9+\-\s()]{7,25}$/', $phone)) {
            $errors[] = 'Enter a valid phone number.';
        }

        if ($zoneId !== null && $zoneTable !== null) {
            $zoneExists = mp_value(
                $conn,
                "SELECT COUNT(*)
                 FROM `{$zoneTable}`
                 WHERE `{$zoneIdColumn}` = ?",
                'i',
                [$zoneId],
                0
            );

            if ((int) $zoneExists === 0) {
                $errors[] = 'The selected church zone is invalid.';
            }
        }

        /*
         * Check whether another user already uses the submitted email.
         */
        if ($email !== '') {
            $duplicateUser = mp_value(
                $conn,
                'SELECT COUNT(*)
                 FROM users
                 WHERE email = ?
                   AND id <> ?',
                'si',
                [$email, $userId],
                0
            );

            if ((int) $duplicateUser > 0) {
                $errors[] = 'That email address is already being used by another account.';
            }
        }

        if ($errors !== []) {
            profileFlash(implode(' ', $errors), 'danger');
            profileRedirect();
        }

        try {
            $conn->begin_transaction();

            /*
             * Update users table.
             */
            $userStatement = $conn->prepare(
                'UPDATE users
                 SET email = ?,
                     phone = ?
                 WHERE id = ?'
            );

            if (!$userStatement) {
                throw new RuntimeException($conn->error);
            }

            $userStatement->bind_param(
                'ssi',
                $email,
                $phone,
                $userId
            );

            if (!$userStatement->execute()) {
                throw new RuntimeException($userStatement->error);
            }

            $userStatement->close();

            /*
             * Build the members-table update according to available columns.
             */
            $memberColumns = mp_cols($conn, 'members');

            $memberUpdates = [];
            $memberTypes   = '';
            $memberValues  = [];

            if (in_array('email', $memberColumns, true)) {
                $memberUpdates[] = 'email = ?';
                $memberTypes    .= 's';
                $memberValues[]  = $email;
            }

            if (in_array('phone', $memberColumns, true)) {
                $memberUpdates[] = 'phone = ?';
                $memberTypes    .= 's';
                $memberValues[]  = $phone;
            }

            if (in_array('church_zone_id', $memberColumns, true)) {
                $memberUpdates[] = 'church_zone_id = ?';
                $memberTypes    .= 'i';
                $memberValues[]  = $zoneId;
            }

            if (in_array('service_attended', $memberColumns, true)) {
                $memberUpdates[] = 'service_attended = ?';
                $memberTypes    .= 's';
                $memberValues[]  = $serviceAttended !== '' ? $serviceAttended : null;
            }

            if ($memberUpdates !== []) {
                $memberTypes  .= 'i';
                $memberValues[] = $memberId;

                $memberSql = '
                    UPDATE members
                    SET ' . implode(', ', $memberUpdates) . '
                    WHERE id = ?
                ';

                $memberStatement = $conn->prepare($memberSql);

                if (!$memberStatement) {
                    throw new RuntimeException($conn->error);
                }

                $bindArguments = [$memberTypes];

                foreach ($memberValues as $key => &$value) {
                    $bindArguments[] = &$value;
                }

                unset($value);

                call_user_func_array(
                    [$memberStatement, 'bind_param'],
                    $bindArguments
                );

                if (!$memberStatement->execute()) {
                    throw new RuntimeException($memberStatement->error);
                }

                $memberStatement->close();
            }

            $conn->commit();

            $_SESSION['email'] = $email;
            $_SESSION['phone'] = $phone;

            profileFlash('Your contact information was updated successfully.');
        } catch (Throwable $exception) {
            $conn->rollback();

            error_log(
                '[Member Profile Update] User ' .
                $userId .
                ': ' .
                $exception->getMessage()
            );

            profileFlash(
                'Your profile could not be updated. Please try again.',
                'danger'
            );
        }

        profileRedirect();
    }

    /*
    |--------------------------------------------------------------------------
    | Update profile photo
    |--------------------------------------------------------------------------
    */

    if ($action === 'update_photo') {
        if (
            !isset($_FILES['photo']) ||
            !is_array($_FILES['photo'])
        ) {
            profileFlash('Select a profile photo to upload.', 'danger');
            profileRedirect();
        }

        $photo = $_FILES['photo'];

        if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'The selected photo is too large.',
                UPLOAD_ERR_FORM_SIZE  => 'The selected photo is too large.',
                UPLOAD_ERR_PARTIAL    => 'The photo upload was interrupted.',
                UPLOAD_ERR_NO_FILE    => 'Select a profile photo.',
                UPLOAD_ERR_NO_TMP_DIR => 'The server upload directory is unavailable.',
                UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded photo.',
                UPLOAD_ERR_EXTENSION  => 'The photo upload was blocked.',
            ];

            profileFlash(
                $uploadErrors[$photo['error']]
                    ?? 'The profile photo could not be uploaded.',
                'danger'
            );

            profileRedirect();
        }

        $maximumSize = 5 * 1024 * 1024;

        if ((int) ($photo['size'] ?? 0) > $maximumSize) {
            profileFlash(
                'The profile photo must not exceed 5 MB.',
                'danger'
            );

            profileRedirect();
        }

        $temporaryPath = (string) ($photo['tmp_name'] ?? '');

        if (
            $temporaryPath === '' ||
            !is_uploaded_file($temporaryPath)
        ) {
            profileFlash('The uploaded photo is invalid.', 'danger');
            profileRedirect();
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($temporaryPath);

        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowedTypes[$mime])) {
            profileFlash(
                'Upload a JPG, PNG, or WEBP profile photo.',
                'danger'
            );

            profileRedirect();
        }

        $imageInformation = @getimagesize($temporaryPath);

        if ($imageInformation === false) {
            profileFlash(
                'The selected file is not a valid image.',
                'danger'
            );

            profileRedirect();
        }

        $uploadDirectory = __DIR__ . '/../assets/uploads/members';

        if (
            !is_dir($uploadDirectory) &&
            !mkdir($uploadDirectory, 0775, true) &&
            !is_dir($uploadDirectory)
        ) {
            profileFlash(
                'The profile-photo directory could not be created.',
                'danger'
            );

            profileRedirect();
        }

        $extension = $allowedTypes[$mime];

        try {
            $randomName = bin2hex(random_bytes(12));
        } catch (Throwable) {
            $randomName = str_replace('.', '', uniqid('', true));
        }

        $fileName = sprintf(
            'member_%d_%s.%s',
            $memberId,
            $randomName,
            $extension
        );

        $destination = $uploadDirectory . '/' . $fileName;
        $relativePath = 'assets/uploads/members/' . $fileName;

        $oldPhoto = mp_value(
            $conn,
            'SELECT photo
             FROM members
             WHERE id = ?',
            'i',
            [$memberId],
            ''
        );

        if (!move_uploaded_file($temporaryPath, $destination)) {
            profileFlash(
                'The profile photo could not be saved.',
                'danger'
            );

            profileRedirect();
        }

        try {
            $statement = $conn->prepare(
                'UPDATE members
                 SET photo = ?
                 WHERE id = ?'
            );

            if (!$statement) {
                throw new RuntimeException($conn->error);
            }

            $statement->bind_param(
                'si',
                $relativePath,
                $memberId
            );

            if (!$statement->execute()) {
                throw new RuntimeException($statement->error);
            }

            $statement->close();

            /*
             * Remove the previous member-uploaded photo.
             */
            $oldPhoto = trim((string) $oldPhoto);

            if (
                $oldPhoto !== '' &&
                str_starts_with(
                    $oldPhoto,
                    'assets/uploads/members/'
                )
            ) {
                $oldAbsolutePath = realpath(
                    __DIR__ . '/../' . $oldPhoto
                );

                $uploadRoot = realpath($uploadDirectory);

                if (
                    $oldAbsolutePath !== false &&
                    $uploadRoot !== false &&
                    str_starts_with($oldAbsolutePath, $uploadRoot) &&
                    is_file($oldAbsolutePath)
                ) {
                    @unlink($oldAbsolutePath);
                }
            }

            profileFlash('Your profile photo was updated successfully.');
        } catch (Throwable $exception) {
            if (is_file($destination)) {
                @unlink($destination);
            }

            error_log(
                '[Member Photo Update] User ' .
                $userId .
                ': ' .
                $exception->getMessage()
            );

            profileFlash(
                'The profile photo could not be updated.',
                'danger'
            );
        }

        profileRedirect();
    }

    /*
    |--------------------------------------------------------------------------
    | Change password
    |--------------------------------------------------------------------------
    */

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword     = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $errors = [];

        $passwordHash = mp_value(
            $conn,
            'SELECT password
             FROM users
             WHERE id = ?
             LIMIT 1',
            'i',
            [$userId],
            ''
        );

        if (
            $passwordHash === '' ||
            !password_verify(
                $currentPassword,
                (string) $passwordHash
            )
        ) {
            $errors[] = 'Your current password is incorrect.';
        }

        if (strlen($newPassword) < 8) {
            $errors[] = 'The new password must contain at least 8 characters.';
        }

        if (!preg_match('/[A-Z]/', $newPassword)) {
            $errors[] = 'The new password must contain an uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $newPassword)) {
            $errors[] = 'The new password must contain a lowercase letter.';
        }

        if (!preg_match('/[0-9]/', $newPassword)) {
            $errors[] = 'The new password must contain a number.';
        }

        if ($newPassword !== $confirmPassword) {
            $errors[] = 'The new password confirmation does not match.';
        }

        if (
            $passwordHash !== '' &&
            password_verify($newPassword, (string) $passwordHash)
        ) {
            $errors[] = 'The new password must be different from your current password.';
        }

        if ($errors !== []) {
            profileFlash(implode(' ', $errors), 'danger');
            profileRedirect();
        }

        try {
            $newHash = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            $statement = $conn->prepare(
                'UPDATE users
                 SET password = ?
                 WHERE id = ?'
            );

            if (!$statement) {
                throw new RuntimeException($conn->error);
            }

            $statement->bind_param(
                'si',
                $newHash,
                $userId
            );

            if (!$statement->execute()) {
                throw new RuntimeException($statement->error);
            }

            $statement->close();

            require_once __DIR__ . '/../includes/account-setup.php';
            account_set_must_change($conn, (int)$userId, false);

            profileFlash('Your password was changed successfully.');
        } catch (Throwable $exception) {
            error_log(
                '[Member Password Update] User ' .
                $userId .
                ': ' .
                $exception->getMessage()
            );

            profileFlash(
                'Your password could not be changed. Please try again.',
                'danger'
            );
        }

        profileRedirect();
    }

    profileFlash('The requested profile action is invalid.', 'danger');
    profileRedirect();
}

/*
|--------------------------------------------------------------------------
| Load member profile
|--------------------------------------------------------------------------
*/

$memberColumns = mp_cols($conn, 'members');

$profile = mp_row(
    $conn,
    'SELECT
        m.*,
        u.username,
        u.email AS user_email,
        u.phone AS user_phone,
        u.status AS user_status,
        u.role AS user_role
     FROM members m
     INNER JOIN users u
        ON u.id = m.user_id
     WHERE m.id = ?
       AND u.id = ?
     LIMIT 1',
    'ii',
    [$memberId, $userId]
);

if ($profile === null) {
    http_response_code(404);
    exit('Your member profile could not be found.');
}

$zoneName = 'Not assigned';
$currentZoneId = isset($profile['church_zone_id'])
    ? (int) $profile['church_zone_id']
    : 0;

if (
    $currentZoneId > 0 &&
    $zoneTable !== null &&
    $zoneNameColumn !== null
) {
    $loadedZoneName = mp_value(
        $conn,
        "SELECT `{$zoneNameColumn}`
         FROM `{$zoneTable}`
         WHERE `{$zoneIdColumn}` = ?
         LIMIT 1",
        'i',
        [$currentZoneId],
        ''
    );

    if (trim((string) $loadedZoneName) !== '') {
        $zoneName = (string) $loadedZoneName;
    }
}

$fullName = trim((string)($profile['full_name'] ?? ''));

if ($fullName === '') {
    $fullName = 'Member';
}

$email = trim(
    (string) (
        $profile['email']
        ?? $profile['user_email']
        ?? ''
    )
);

if ($email === '') {
    $email = trim((string) ($profile['user_email'] ?? ''));
}

$phone = trim(
    (string) (
        $profile['phone']
        ?? $profile['user_phone']
        ?? ''
    )
);

if ($phone === '') {
    $phone = trim((string) ($profile['user_phone'] ?? ''));
}

$photoPath = trim((string) ($profile['photo'] ?? ''));

$photoUrl = $photoPath !== ''
    ? BASE_URL . ltrim($photoPath, '/')
    : BASE_URL . 'assets/img/avatar.png';

$flash = $_SESSION['member_profile_flash'] ?? null;
unset($_SESSION['member_profile_flash']);

include __DIR__ . '/../includes/sidebar.php';
?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="content-area profile-page">
        <?php if (is_array($flash)): ?>
            <div class="alert alert-<?= mp_h($flash['type'] ?? 'success') ?>">
                <i class="fas fa-circle-info"></i>
                <?= mp_h($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <div class="profile-heading">
            <div>
                <span class="profile-eyebrow">Account</span>
                <h2>My Profile</h2>
                <p>
                    Manage your personal contact information, profile photo,
                    church zone and account password.
                </p>
            </div>

            <div class="profile-status">
                <span class="status-dot"></span>
                <?= mp_h(
                    ucfirst(
                        strtolower(
                            (string) ($profile['user_status'] ?? 'Active')
                        )
                    )
                ) ?>
            </div>
        </div>

        <div class="profile-layout">
            <aside class="profile-sidebar-card">
                <div class="profile-photo-wrap">
                    <img
                        src="<?= mp_h($photoUrl) ?>"
                        alt="<?= mp_h($fullName) ?>"
                        class="profile-photo"
                        id="profilePhotoPreview"
                    >

                    <span class="photo-status">
                        <i class="fas fa-camera"></i>
                    </span>
                </div>

                <h3><?= mp_h($fullName) ?></h3>

                <p class="profile-username">
                    @<?= mp_h((string) ($profile['username'] ?? 'member')) ?>
                </p>

                <span class="profile-role">
                    <i class="fas fa-user"></i>
                    <?= mp_h(
                        ucwords(
                            str_replace(
                                '_',
                                ' ',
                                (string) ($profile['user_role'] ?? 'Member')
                            )
                        )
                    ) ?>
                </span>

                <div class="profile-identity-list">
                    <div>
                        <span>Member number</span>
                        <strong>
                            <?= mp_h(
                                (string) ($profile['member_no'] ?? '-')
                            ) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Church zone</span>
                        <strong><?= mp_h($zoneName) ?></strong>
                    </div>

                    <?php if (!empty($profile['pledge_no'])): ?>
                        <div>
                            <span>Pledge number</span>
                            <strong><?= mp_h((string) $profile['pledge_no']) ?></strong>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($profile['service_attended'])): ?>
                        <div>
                            <span>Service</span>
                            <strong><?= mp_h((string) $profile['service_attended']) ?></strong>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($profile['joined_date'])): ?>
                        <div>
                            <span>Member since</span>
                            <strong>
                                <?= mp_h(
                                    mp_date($profile['joined_date'])
                                ) ?>
                            </strong>
                        </div>
                    <?php endif; ?>
                </div>

                <form
                    method="post"
                    enctype="multipart/form-data"
                    class="photo-form"
                    id="photoForm"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= mp_h($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="update_photo"
                    >

                    <label class="photo-upload-field">
                        <i class="fas fa-cloud-arrow-up"></i>

                        <span>
                            <strong>Choose new photo</strong>
                            <small>JPG, PNG or WEBP, maximum 5 MB</small>
                        </span>

                        <input
                            type="file"
                            name="photo"
                            id="profilePhotoInput"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            required
                        >
                    </label>

                    <button
                        type="submit"
                        class="btn btn-primary photo-save-button"
                        id="photoSubmitButton"
                        disabled
                    >
                        <i class="fas fa-image"></i>
                        Update Profile Photo
                    </button>
                </form>
            </aside>

            <main class="profile-content">
                <div class="profile-tabs" role="tablist">
                    <button
                        type="button"
                        class="profile-tab active"
                        data-profile-tab="personal-information"
                    >
                        <i class="fas fa-address-card"></i>
                        Personal Information
                    </button>

                    <button
                        type="button"
                        class="profile-tab"
                        data-profile-tab="account-security"
                    >
                        <i class="fas fa-lock"></i>
                        Account Security
                    </button>
                </div>

                <section
                    class="profile-panel active"
                    id="personal-information"
                >
                    <div class="profile-card">
                        <div class="profile-card-header">
                            <div class="profile-card-icon">
                                <i class="fas fa-user-pen"></i>
                            </div>

                            <div>
                                <h3>Contact and Church Information</h3>
                                <p>
                                    Keep your contact information and church zone
                                    up to date.
                                </p>
                            </div>
                        </div>

                        <form method="post" id="profileInformationForm">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= mp_h($csrfToken) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="update_profile"
                            >

                            <div class="profile-form-grid">
                                <div class="form-group">
                                    <label>Full Name</label>

                                    <div class="input-with-icon read-only-input">
                                        <i class="fas fa-user"></i>

                                        <input
                                            type="text"
                                            class="form-control"
                                            value="<?= mp_h($fullName) ?>"
                                            readonly
                                        >
                                    </div>

                                    <small>
                                        Contact the church administrator to
                                        change your official name.
                                    </small>
                                </div>

                                <div class="form-group">
                                    <label>Member Number</label>

                                    <div class="input-with-icon read-only-input">
                                        <i class="fas fa-id-card"></i>

                                        <input
                                            type="text"
                                            class="form-control"
                                            value="<?= mp_h(
                                                (string) (
                                                    $profile['member_no']
                                                    ?? ''
                                                )
                                            ) ?>"
                                            readonly
                                        >
                                    </div>
                                </div>

                                <?php if ($hasServiceColumn): ?>
                                    <div class="form-group">
                                        <label>Pledge Number</label>

                                        <div class="input-with-icon read-only-input">
                                            <i class="fas fa-hand-holding-heart"></i>

                                            <input
                                                type="text"
                                                class="form-control"
                                                value="<?= mp_h(
                                                    (string) (
                                                        $profile['pledge_no']
                                                        ?? ''
                                                    )
                                                ) ?>"
                                                placeholder="Not assigned"
                                                readonly
                                            >
                                        </div>

                                        <small>
                                            Assigned by the church administrator.
                                        </small>
                                    </div>

                                    <div class="form-group">
                                        <label for="serviceAttended">
                                            Service You Attend
                                        </label>

                                        <div class="input-with-icon">
                                            <i class="fas fa-church"></i>

                                            <input
                                                type="text"
                                                id="serviceAttended"
                                                name="service_attended"
                                                class="form-control"
                                                list="serviceAttendedOptions"
                                                value="<?= mp_h(
                                                    (string) (
                                                        $profile['service_attended']
                                                        ?? ''
                                                    )
                                                ) ?>"
                                                maxlength="120"
                                                placeholder="e.g. First Service"
                                            >
                                        </div>

                                        <datalist id="serviceAttendedOptions">
                                            <?php foreach (member_fields_service_options($conn) as $serviceOption): ?>
                                                <option value="<?= mp_h($serviceOption) ?>">
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>
                                <?php endif; ?>

                                <div class="form-group">
                                    <label for="profileEmail">
                                        Email Address
                                    </label>

                                    <div class="input-with-icon">
                                        <i class="fas fa-envelope"></i>

                                        <input
                                            type="email"
                                            id="profileEmail"
                                            name="email"
                                            class="form-control"
                                            value="<?= mp_h($email) ?>"
                                            maxlength="190"
                                            placeholder="member@example.com"
                                            autocomplete="email"
                                        >
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="profilePhone">
                                        Phone Number
                                        <span class="required">*</span>
                                    </label>

                                    <div class="input-with-icon">
                                        <i class="fas fa-phone"></i>

                                        <input
                                            type="tel"
                                            id="profilePhone"
                                            name="phone"
                                            class="form-control"
                                            value="<?= mp_h($phone) ?>"
                                            maxlength="25"
                                            placeholder="+256 700 000000"
                                            autocomplete="tel"
                                            required
                                        >
                                    </div>
                                </div>

                                <div class="form-group profile-zone-field">
                                    <label for="churchZone">
                                        Church Zone
                                    </label>

                                    <div class="input-with-icon">
                                        <i class="fas fa-location-dot"></i>

                                        <?php if ($zones !== []): ?>
                                            <select
                                                name="church_zone_id"
                                                id="churchZone"
                                                class="form-control"
                                            >
                                                <option value="">
                                                    Select church zone
                                                </option>

                                                <?php foreach ($zones as $zone): ?>
                                                    <?php
                                                    $listedZoneId =
                                                        (int) (
                                                            $zone['id']
                                                            ?? 0
                                                        );
                                                    ?>
                                                    <option
                                                        value="<?= $listedZoneId ?>"
                                                        <?= $listedZoneId === $currentZoneId
                                                            ? 'selected'
                                                            : '' ?>
                                                    >
                                                        <?= mp_h(
                                                            (string) (
                                                                $zone['zone_name']
                                                                ?? ''
                                                            )
                                                        ) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <input
                                                type="text"
                                                class="form-control"
                                                value="<?= mp_h($zoneName) ?>"
                                                readonly
                                            >
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($zones === []): ?>
                                        <small>
                                            Church zones have not yet been
                                            configured by the administrator.
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="profile-form-footer">
                                <span>
                                    <i class="fas fa-shield-halved"></i>
                                    Your contact information is protected and
                                    only visible to authorized users.
                                </span>

                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                >
                                    <i class="fas fa-floppy-disk"></i>
                                    Save Profile Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <section
                    class="profile-panel"
                    id="account-security"
                >
                    <div class="profile-card">
                        <div class="profile-card-header">
                            <div class="profile-card-icon">
                                <i class="fas fa-key"></i>
                            </div>

                            <div>
                                <h3>Change Password</h3>
                                <p>
                                    Use a strong password that you do not use
                                    for another account.
                                </p>
                            </div>
                        </div>

                        <div class="password-guidance">
                            <i class="fas fa-shield-halved"></i>

                            <div>
                                <strong>Password requirements</strong>

                                <span>
                                    Use at least 8 characters, including an
                                    uppercase letter, lowercase letter and
                                    number.
                                </span>
                            </div>
                        </div>

                        <form
                            method="post"
                            id="passwordForm"
                            class="password-form"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= mp_h($csrfToken) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="change_password"
                            >

                            <div class="form-group">
                                <label for="currentPassword">
                                    Current Password
                                </label>

                                <div class="password-input">
                                    <i class="fas fa-lock"></i>

                                    <input
                                        type="password"
                                        id="currentPassword"
                                        name="current_password"
                                        class="form-control"
                                        autocomplete="current-password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="password-toggle"
                                        data-password-toggle="currentPassword"
                                        aria-label="Show current password"
                                    >
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="password-grid">
                                <div class="form-group">
                                    <label for="newPassword">
                                        New Password
                                    </label>

                                    <div class="password-input">
                                        <i class="fas fa-key"></i>

                                        <input
                                            type="password"
                                            id="newPassword"
                                            name="new_password"
                                            class="form-control"
                                            minlength="8"
                                            autocomplete="new-password"
                                            required
                                        >

                                        <button
                                            type="button"
                                            class="password-toggle"
                                            data-password-toggle="newPassword"
                                            aria-label="Show new password"
                                        >
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="confirmPassword">
                                        Confirm New Password
                                    </label>

                                    <div class="password-input">
                                        <i class="fas fa-check-circle"></i>

                                        <input
                                            type="password"
                                            id="confirmPassword"
                                            name="confirm_password"
                                            class="form-control"
                                            minlength="8"
                                            autocomplete="new-password"
                                            required
                                        >

                                        <button
                                            type="button"
                                            class="password-toggle"
                                            data-password-toggle="confirmPassword"
                                            aria-label="Show password confirmation"
                                        >
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="password-match-message"
                                id="passwordMatchMessage"
                            ></div>

                            <div class="profile-form-footer">
                                <span>
                                    <i class="fas fa-circle-info"></i>
                                    You will use the new password the next time
                                    you sign in.
                                </span>

                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                >
                                    <i class="fas fa-lock"></i>
                                    Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            </main>
        </div>
    </div>
</div>

<style>
.profile-page {
    --profile-primary: var(--primary);
    --profile-primary-soft: #f4effb;
    --profile-border: #e2e6ec;
    --profile-text: #19202b;
    --profile-muted: #697386;
    --profile-surface: #ffffff;
}

.profile-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 22px;
}

.profile-eyebrow {
    display: block;
    margin-bottom: 5px;
    color: var(--profile-primary);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.profile-heading h2 {
    margin: 0 0 6px;
    color: var(--profile-text);
    font-size: 27px;
}

.profile-heading p {
    max-width: 680px;
    margin: 0;
    color: var(--profile-muted);
}

.profile-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 13px;
    border: 1px solid #c9ead8;
    border-radius: 999px;
    background: #f1fbf5;
    color: #187645;
    font-size: 13px;
    font-weight: 700;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #22a65a;
    box-shadow: 0 0 0 4px rgba(34, 166, 90, .13);
}

.profile-layout {
    display: grid;
    grid-template-columns: 300px minmax(0, 1fr);
    gap: 22px;
    align-items: start;
}

.profile-sidebar-card,
.profile-card {
    border: 1px solid var(--profile-border);
    border-radius: 16px;
    background: var(--profile-surface);
    box-shadow: 0 8px 24px rgba(25, 32, 43, .05);
}

.profile-sidebar-card {
    position: sticky;
    top: 90px;
    padding: 26px 22px;
    text-align: center;
}

.profile-photo-wrap {
    position: relative;
    width: 126px;
    height: 126px;
    margin: 0 auto 16px;
}

.profile-photo {
    width: 126px;
    height: 126px;
    border: 5px solid #fff;
    border-radius: 50%;
    background: #edf0f4;
    object-fit: cover;
    box-shadow: 0 7px 22px rgba(31, 25, 45, .18);
}

.photo-status {
    position: absolute;
    right: 3px;
    bottom: 5px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border: 3px solid #fff;
    border-radius: 50%;
    background: var(--profile-primary);
    color: #fff;
    font-size: 13px;
}

.profile-sidebar-card h3 {
    margin: 0 0 4px;
    color: var(--profile-text);
    font-size: 20px;
}

.profile-username {
    margin: 0 0 12px;
    color: var(--profile-muted);
}

.profile-role {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 11px;
    border-radius: 999px;
    background: var(--profile-primary-soft);
    color: var(--profile-primary);
    font-size: 12px;
    font-weight: 800;
}

.profile-identity-list {
    margin: 22px 0;
    border-top: 1px solid var(--profile-border);
    border-bottom: 1px solid var(--profile-border);
    text-align: left;
}

.profile-identity-list > div {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 0;
    border-bottom: 1px solid #edf0f4;
}

.profile-identity-list > div:last-child {
    border-bottom: 0;
}

.profile-identity-list span {
    color: var(--profile-muted);
    font-size: 13px;
}

.profile-identity-list strong {
    color: var(--profile-text);
    font-size: 13px;
    text-align: right;
}

.photo-upload-field {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 12px;
    border: 1px dashed #b8bfca;
    border-radius: 11px;
    background: #fafbfc;
    color: var(--profile-text);
    text-align: left;
    cursor: pointer;
    transition: .2s ease;
}

.photo-upload-field:hover {
    border-color: var(--profile-primary);
    background: var(--profile-primary-soft);
}

.photo-upload-field > i {
    color: var(--profile-primary);
    font-size: 20px;
}

.photo-upload-field span {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.photo-upload-field strong {
    font-size: 13px;
}

.photo-upload-field small {
    margin-top: 2px;
    color: var(--profile-muted);
    font-size: 11px;
}

.photo-upload-field input {
    display: none;
}

.photo-save-button {
    width: 100%;
    margin-top: 10px;
}

.photo-save-button:disabled {
    opacity: .6;
    cursor: not-allowed;
}

.profile-content {
    min-width: 0;
}

.profile-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 14px;
    padding: 5px;
    border: 1px solid var(--profile-border);
    border-radius: 12px;
    background: #f7f8fa;
}

.profile-tab {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    padding: 11px 14px;
    border: 0;
    border-radius: 9px;
    background: transparent;
    color: var(--profile-muted);
    font-weight: 700;
    cursor: pointer;
}

.profile-tab.active {
    background: #fff;
    color: var(--profile-primary);
    box-shadow: 0 3px 10px rgba(25, 32, 43, .08);
}

.profile-panel {
    display: none;
}

.profile-panel.active {
    display: block;
}

.profile-card {
    overflow: hidden;
}

.profile-card-header {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 21px 23px;
    border-bottom: 1px solid var(--profile-border);
}

.profile-card-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 42px;
    width: 42px;
    height: 42px;
    border-radius: 11px;
    background: var(--profile-primary-soft);
    color: var(--profile-primary);
}

.profile-card-header h3 {
    margin: 0 0 3px;
    color: var(--profile-text);
    font-size: 18px;
}

.profile-card-header p {
    margin: 0;
    color: var(--profile-muted);
    font-size: 13px;
}

.profile-card form {
    padding: 23px;
}

.profile-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.profile-zone-field {
    grid-column: 1 / -1;
}

.profile-card .form-group {
    margin: 0;
}

.profile-card .form-group label {
    display: block;
    margin-bottom: 7px;
    color: #303847;
    font-size: 13px;
    font-weight: 700;
}

.profile-card .form-group small {
    display: block;
    margin-top: 6px;
    color: var(--profile-muted);
    font-size: 11px;
}

.required {
    color: #c93838;
}

.input-with-icon,
.password-input {
    position: relative;
}

.input-with-icon > i,
.password-input > i {
    position: absolute;
    z-index: 2;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: #8992a1;
    pointer-events: none;
}

.input-with-icon .form-control,
.password-input .form-control {
    min-height: 45px;
    padding-left: 39px;
    border: 1px solid #d5dae2;
    border-radius: 9px;
    background: #fff;
}

.input-with-icon select.form-control {
    appearance: auto;
}

.input-with-icon .form-control:focus,
.password-input .form-control:focus {
    border-color: var(--profile-primary);
    box-shadow: 0 0 0 3px rgba(75, 31, 145, .11);
}

.read-only-input .form-control {
    background: #f7f8fa;
    color: #666f7d;
}

.profile-form-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin: 23px -23px -23px;
    padding: 16px 23px;
    border-top: 1px solid var(--profile-border);
    background: #fafbfc;
}

.profile-form-footer > span {
    display: inline-flex;
    align-items: flex-start;
    gap: 8px;
    max-width: 540px;
    color: var(--profile-muted);
    font-size: 12px;
}

.profile-form-footer > span i {
    margin-top: 2px;
    color: var(--profile-primary);
}

.password-guidance {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin: 22px 23px 0;
    padding: 14px;
    border: 1px solid #d8caee;
    border-radius: 10px;
    background: #f8f5fc;
}

.password-guidance > i {
    margin-top: 2px;
    color: var(--profile-primary);
}

.password-guidance div {
    display: flex;
    flex-direction: column;
}

.password-guidance strong {
    color: #33224e;
    font-size: 13px;
}

.password-guidance span {
    margin-top: 3px;
    color: #6f6281;
    font-size: 12px;
}

.password-form {
    max-width: none;
}

.password-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    margin-top: 18px;
}

.password-input .form-control {
    padding-right: 44px;
}

.password-toggle {
    position: absolute;
    right: 5px;
    top: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    transform: translateY(-50%);
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: #727b89;
    cursor: pointer;
}

.password-toggle:hover {
    background: #f0edf5;
    color: var(--profile-primary);
}

.password-match-message {
    min-height: 20px;
    margin-top: 10px;
    font-size: 12px;
    font-weight: 700;
}

.password-match-message.success {
    color: #16844a;
}

.password-match-message.danger {
    color: #bd3434;
}

@media (max-width: 1050px) {
    .profile-layout {
        grid-template-columns: 260px minmax(0, 1fr);
    }

    .profile-form-grid,
    .password-grid {
        grid-template-columns: 1fr;
    }

    .profile-zone-field {
        grid-column: auto;
    }
}

@media (max-width: 820px) {
    .profile-layout {
        grid-template-columns: 1fr;
    }

    .profile-sidebar-card {
        position: static;
    }

    .profile-heading,
    .profile-form-footer {
        align-items: stretch;
        flex-direction: column;
    }

    .profile-form-footer .btn {
        width: 100%;
    }
}

@media (max-width: 560px) {
    .profile-tabs {
        flex-direction: column;
    }

    .profile-tab {
        justify-content: flex-start;
    }

    .profile-card-header {
        align-items: flex-start;
        padding: 18px;
    }

    .profile-card form {
        padding: 18px;
    }

    .profile-form-footer {
        margin: 20px -18px -18px;
        padding: 15px 18px;
    }

    .profile-heading h2 {
        font-size: 23px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabs = Array.from(
        document.querySelectorAll('.profile-tab')
    );

    const panels = Array.from(
        document.querySelectorAll('.profile-panel')
    );

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            const targetId = tab.dataset.profileTab;

            tabs.forEach(function (item) {
                item.classList.remove('active');
            });

            panels.forEach(function (panel) {
                panel.classList.remove('active');
            });

            tab.classList.add('active');

            document
                .getElementById(targetId)
                ?.classList.add('active');
        });
    });

    /*
     * Preview the profile photo before upload.
     */
    const photoInput = document.getElementById(
        'profilePhotoInput'
    );

    const photoPreview = document.getElementById(
        'profilePhotoPreview'
    );

    const photoSubmitButton = document.getElementById(
        'photoSubmitButton'
    );

    photoInput?.addEventListener('change', function () {
        const file = photoInput.files?.[0];

        if (!file) {
            if (photoSubmitButton) {
                photoSubmitButton.disabled = true;
            }

            return;
        }

        const allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/webp'
        ];

        if (!allowedTypes.includes(file.type)) {
            alert('Select a JPG, PNG or WEBP image.');
            photoInput.value = '';

            if (photoSubmitButton) {
                photoSubmitButton.disabled = true;
            }

            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            alert('The profile photo must not exceed 5 MB.');
            photoInput.value = '';

            if (photoSubmitButton) {
                photoSubmitButton.disabled = true;
            }

            return;
        }

        const reader = new FileReader();

        reader.onload = function (event) {
            if (
                photoPreview &&
                typeof event.target?.result === 'string'
            ) {
                photoPreview.src = event.target.result;
            }
        };

        reader.readAsDataURL(file);

        if (photoSubmitButton) {
            photoSubmitButton.disabled = false;
        }
    });

    /*
     * Show or hide password fields.
     */
    document
        .querySelectorAll('[data-password-toggle]')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                const targetId = button.dataset.passwordToggle;
                const input = document.getElementById(targetId);

                if (!(input instanceof HTMLInputElement)) {
                    return;
                }

                const showPassword = input.type === 'password';

                input.type = showPassword
                    ? 'text'
                    : 'password';

                const icon = button.querySelector('i');

                if (icon) {
                    icon.className = showPassword
                        ? 'fas fa-eye-slash'
                        : 'fas fa-eye';
                }
            });
        });

    /*
     * Validate password confirmation while typing.
     */
    const newPassword = document.getElementById(
        'newPassword'
    );

    const confirmPassword = document.getElementById(
        'confirmPassword'
    );

    const matchMessage = document.getElementById(
        'passwordMatchMessage'
    );

    function checkPasswordMatch() {
        if (
            !(newPassword instanceof HTMLInputElement) ||
            !(confirmPassword instanceof HTMLInputElement) ||
            !matchMessage
        ) {
            return;
        }

        matchMessage.classList.remove(
            'success',
            'danger'
        );

        if (confirmPassword.value === '') {
            matchMessage.textContent = '';
            return;
        }

        if (newPassword.value === confirmPassword.value) {
            matchMessage.textContent =
                'The passwords match.';

            matchMessage.classList.add('success');
        } else {
            matchMessage.textContent =
                'The passwords do not match.';

            matchMessage.classList.add('danger');
        }
    }

    newPassword?.addEventListener(
        'input',
        checkPasswordMatch
    );

    confirmPassword?.addEventListener(
        'input',
        checkPasswordMatch
    );

    document
        .getElementById('passwordForm')
        ?.addEventListener('submit', function (event) {
            if (
                newPassword instanceof HTMLInputElement &&
                confirmPassword instanceof HTMLInputElement &&
                newPassword.value !== confirmPassword.value
            ) {
                event.preventDefault();

                checkPasswordMatch();

                confirmPassword.focus();
            }
        });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
