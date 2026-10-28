<?php
declare(strict_types=1);

$pageTitle = 'Site Settings';

require_once '../includes/header.php';
require_once '../includes/sidebar.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('st_h')) {
    function st_h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('st_table_exists')) {
    function st_table_exists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?'
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $table);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['total'] ?? 0) > 0;
    }
}

if (!function_exists('st_value')) {
    function st_value(mysqli $conn, string $key, string $default = ''): string
    {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS site_settings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_site_settings_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $stmt = $conn->prepare(
            'SELECT setting_value
             FROM site_settings
             WHERE setting_key = ?
             LIMIT 1'
        );

        if (!$stmt) {
            return $default;
        }

        $stmt->bind_param('s', $key);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $value = (string)($row['setting_value'] ?? '');

        return $value !== '' ? $value : $default;
    }
}

if (!function_exists('st_asset_url')) {
    function st_asset_url(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        return BASE_URL . ltrim($path, '/');
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['csrf_token'];

$settings = [
    'site_name'        => st_value($conn, 'site_name', APP_NAME),
    'site_subtitle'    => st_value($conn, 'site_subtitle', 'Church Management System'),
    'site_logo'        => st_value($conn, 'site_logo', ''),
    'site_favicon'     => st_value($conn, 'site_favicon', ''),
    'primary_color'    => st_value($conn, 'primary_color', '#4a1e8f'),
    'secondary_color'  => st_value($conn, 'secondary_color', '#d4a017'),
    'background_color' => st_value($conn, 'background_color', '#f3f2f8'),
    'text_color'       => st_value($conn, 'text_color', '#1e1e2d'),
];

$email = [
    'smtp_host'   => '',
    'smtp_port'   => 587,
    'smtp_user'   => '',
    'smtp_secure' => 'tls',
    'from_email'  => '',
    'from_name'   => $settings['site_name'],
    'is_active'   => 1,
];

if (st_table_exists($conn, 'email_settings')) {
    $result = $conn->query(
        'SELECT *
         FROM email_settings
         ORDER BY id DESC
         LIMIT 1'
    );

    if ($result && $result->num_rows > 0) {
        $email = array_merge($email, $result->fetch_assoc());
    }
}

$sms = [
    'provider_name' => '',
    'api_url'       => '',
    'sender_id'     => '',
    'http_method'   => 'POST',
    'phone_param'   => 'to',
    'message_param' => 'message',
    'sender_param'  => 'sender',
    'auth_header'   => '',
    'is_active'     => 1,
];

if (st_table_exists($conn, 'sms_settings')) {
    $result = $conn->query(
        'SELECT *
         FROM sms_settings
         ORDER BY id DESC
         LIMIT 1'
    );

    if ($result && $result->num_rows > 0) {
        $sms = array_merge($sms, $result->fetch_assoc());
    }
}

$logoUrl = st_asset_url($settings['site_logo']);
$faviconUrl = st_asset_url($settings['site_favicon']);

$allowedTabs = ['general', 'appearance', 'email', 'sms'];
$requestedTab = strtolower(trim((string)($_GET['tab'] ?? 'general')));
$activeTab = in_array($requestedTab, $allowedTabs, true)
    ? $requestedTab
    : 'general';
?>

<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/theme.css">
<link rel="stylesheet" href="../assets/css/church-admin.css">

<div class="main-content">
    <?php include '../includes/topbar.php'; ?>

    <div class="content-area settings-page">

        <?= function_exists('showFlash') ? showFlash() : '' ?>

        <div class="church-page-head">
            <div>
                <h2>
                    <i class="fas fa-tools"></i>
                    Site Settings
                </h2>
                <p>
                    Manage church branding, appearance, email and SMS configuration.
                </p>
            </div>
        </div>

        <div class="settings-shell">

            <div class="settings-tabs-wrap">
                <div
                    class="settings-tabs"
                    role="tablist"
                    aria-label="Site settings sections"
                >
                    <button
                        type="button"
                        class="settings-tab <?= $activeTab === 'general' ? 'active' : '' ?>"
                        id="tab-general"
                        role="tab"
                        aria-controls="panel-general"
                        aria-selected="<?= $activeTab === 'general' ? 'true' : 'false' ?>"
                        data-settings-tab="general"
                    >
                        <i class="fas fa-building"></i>
                        General
                    </button>

                    <button
                        type="button"
                        class="settings-tab <?= $activeTab === 'appearance' ? 'active' : '' ?>"
                        id="tab-appearance"
                        role="tab"
                        aria-controls="panel-appearance"
                        aria-selected="<?= $activeTab === 'appearance' ? 'true' : 'false' ?>"
                        data-settings-tab="appearance"
                    >
                        <i class="fas fa-palette"></i>
                        Appearance
                    </button>

                    <button
                        type="button"
                        class="settings-tab <?= $activeTab === 'email' ? 'active' : '' ?>"
                        id="tab-email"
                        role="tab"
                        aria-controls="panel-email"
                        aria-selected="<?= $activeTab === 'email' ? 'true' : 'false' ?>"
                        data-settings-tab="email"
                    >
                        <i class="fas fa-envelope"></i>
                        Email
                    </button>

                    <button
                        type="button"
                        class="settings-tab <?= $activeTab === 'sms' ? 'active' : '' ?>"
                        id="tab-sms"
                        role="tab"
                        aria-controls="panel-sms"
                        aria-selected="<?= $activeTab === 'sms' ? 'true' : 'false' ?>"
                        data-settings-tab="sms"
                    >
                        <i class="fas fa-comment-sms"></i>
                        SMS API
                    </button>
                </div>
            </div>

            <div class="settings-tab-panels">

                <!-- General Tab -->
                <section
                    class="settings-tab-panel <?= $activeTab === 'general' ? 'active' : '' ?>"
                    id="panel-general"
                    role="tabpanel"
                    aria-labelledby="tab-general"
                    <?= $activeTab !== 'general' ? 'hidden' : '' ?>
                >
                    <form
                        id="generalForm"
                        enctype="multipart/form-data"
                        autocomplete="off"
                    >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= st_h($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="save_branding"
                        >

                        <input
                            type="hidden"
                            name="primary_color"
                            value="<?= st_h($settings['primary_color']) ?>"
                        >

                        <input
                            type="hidden"
                            name="secondary_color"
                            value="<?= st_h($settings['secondary_color']) ?>"
                        >

                        <input
                            type="hidden"
                            name="background_color"
                            value="<?= st_h($settings['background_color']) ?>"
                        >

                        <input
                            type="hidden"
                            name="text_color"
                            value="<?= st_h($settings['text_color']) ?>"
                        >

                        <div id="generalAlert" class="ajax-alert"></div>

                        <div class="settings-card-header">
                            <div>
                                <h3>
                                    <i class="fas fa-church"></i>
                                    Church Branding
                                </h3>
                                <p class="settings-card-subtitle">
                                    Configure the church name, subtitle, logo and favicon.
                                </p>
                            </div>
                        </div>

                        <div class="settings-form-grid">
                            <div class="settings-form-group">
                                <label for="siteName">Site Name</label>
                                <input
                                    type="text"
                                    id="siteName"
                                    name="site_name"
                                    class="form-control"
                                    maxlength="150"
                                    required
                                    value="<?= st_h($settings['site_name']) ?>"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="siteSubtitle">Site Subtitle</label>
                                <input
                                    type="text"
                                    id="siteSubtitle"
                                    name="site_subtitle"
                                    class="form-control"
                                    maxlength="180"
                                    value="<?= st_h($settings['site_subtitle']) ?>"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="siteLogo">Church Logo</label>
                                <input
                                    type="file"
                                    id="siteLogo"
                                    name="site_logo"
                                    class="form-control"
                                    accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                >
                                <span class="settings-form-help">
                                    Recommended: transparent PNG or WebP, maximum 2 MB.
                                </span>
                            </div>

                            <div class="settings-form-group">
                                <label for="siteFavicon">Site Favicon</label>
                                <input
                                    type="file"
                                    id="siteFavicon"
                                    name="site_favicon"
                                    class="form-control"
                                    accept=".ico,image/png,image/jpeg,image/webp"
                                >
                                <span class="settings-form-help">
                                    Recommended square image: 32×32 or 64×64 pixels.
                                </span>
                            </div>
                        </div>

                        <div class="settings-preview-grid">
                            <div class="settings-preview-box">
                                <h5>Current Logo</h5>

                                <?php if ($logoUrl !== ''): ?>
                                    <div class="settings-logo-preview">
                                        <img
                                            src="<?= st_h($logoUrl) ?>"
                                            alt="<?= st_h($settings['site_name']) ?> logo"
                                            id="logoPreviewImage"
                                        >
                                    </div>
                                <?php else: ?>
                                    <div
                                        class="settings-empty-preview"
                                        id="logoPreviewEmpty"
                                    >
                                        <i class="fas fa-image"></i>
                                        <span>No logo uploaded</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="settings-preview-box">
                                <h5>Current Favicon</h5>

                                <?php if ($faviconUrl !== ''): ?>
                                    <div class="settings-favicon-preview">
                                        <img
                                            src="<?= st_h($faviconUrl) ?>"
                                            alt="Site favicon"
                                            id="faviconPreviewImage"
                                        >
                                    </div>
                                <?php else: ?>
                                    <div
                                        class="settings-empty-preview"
                                        id="faviconPreviewEmpty"
                                    >
                                        <i class="fas fa-icons"></i>
                                        <span>No favicon uploaded</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="settings-preview-box">
                                <h5>Brand Preview</h5>

                                <div class="settings-theme-preview">
                                    <div class="settings-theme-preview-header">
                                        <span id="brandPreviewName">
                                            <?= st_h($settings['site_name']) ?>
                                        </span>
                                    </div>

                                    <div class="settings-theme-preview-body">
                                        <strong id="brandPreviewSubtitle">
                                            <?= st_h($settings['site_subtitle']) ?>
                                        </strong>

                                        <div class="settings-theme-preview-button">
                                            Church Management System
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="settings-actions settings-actions-sticky">
                            <button
                                type="submit"
                                class="btn btn-primary"
                                id="saveGeneralBtn"
                            >
                                <i class="fas fa-save"></i>
                                Save General Settings
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Appearance Tab -->
                <section
                    class="settings-tab-panel <?= $activeTab === 'appearance' ? 'active' : '' ?>"
                    id="panel-appearance"
                    role="tabpanel"
                    aria-labelledby="tab-appearance"
                    <?= $activeTab !== 'appearance' ? 'hidden' : '' ?>
                >
                    <form id="appearanceForm" autocomplete="off">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= st_h($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="save_branding"
                        >

                        <input
                            type="hidden"
                            name="site_name"
                            value="<?= st_h($settings['site_name']) ?>"
                        >

                        <input
                            type="hidden"
                            name="site_subtitle"
                            value="<?= st_h($settings['site_subtitle']) ?>"
                        >

                        <div id="appearanceAlert" class="ajax-alert"></div>

                        <div class="settings-card-header">
                            <div>
                                <h3>
                                    <i class="fas fa-palette"></i>
                                    Theme Colours
                                </h3>
                                <p class="settings-card-subtitle">
                                    Change the colours used throughout the admin and member portals.
                                </p>
                            </div>
                        </div>

                        <div class="settings-form-grid">
                            <div class="settings-form-group">
                                <label for="primaryColor">Primary Colour</label>

                                <div class="settings-color-row">
                                    <span
                                        class="settings-color-swatch"
                                        id="primarySwatch"
                                    ></span>

                                    <input
                                        type="color"
                                        id="primaryColor"
                                        name="primary_color"
                                        class="form-control settings-color-input"
                                        value="<?= st_h($settings['primary_color']) ?>"
                                    >
                                </div>
                            </div>

                            <div class="settings-form-group">
                                <label for="secondaryColor">Secondary Colour</label>

                                <div class="settings-color-row">
                                    <span
                                        class="settings-color-swatch"
                                        id="secondarySwatch"
                                    ></span>

                                    <input
                                        type="color"
                                        id="secondaryColor"
                                        name="secondary_color"
                                        class="form-control settings-color-input"
                                        value="<?= st_h($settings['secondary_color']) ?>"
                                    >
                                </div>
                            </div>

                            <div class="settings-form-group">
                                <label for="backgroundColor">Background Colour</label>

                                <div class="settings-color-row">
                                    <span
                                        class="settings-color-swatch"
                                        id="backgroundSwatch"
                                    ></span>

                                    <input
                                        type="color"
                                        id="backgroundColor"
                                        name="background_color"
                                        class="form-control settings-color-input"
                                        value="<?= st_h($settings['background_color']) ?>"
                                    >
                                </div>
                            </div>

                            <div class="settings-form-group">
                                <label for="textColor">Text Colour</label>

                                <div class="settings-color-row">
                                    <span
                                        class="settings-color-swatch"
                                        id="textSwatch"
                                    ></span>

                                    <input
                                        type="color"
                                        id="textColor"
                                        name="text_color"
                                        class="form-control settings-color-input"
                                        value="<?= st_h($settings['text_color']) ?>"
                                    >
                                </div>
                            </div>
                        </div>

                        <div
                            class="settings-theme-preview"
                            id="themePreview"
                        >
                            <div class="settings-theme-preview-header">
                                <?= st_h($settings['site_name']) ?>
                            </div>

                            <div class="settings-theme-preview-body">
                                <h4>Theme Preview</h4>
                                <p>
                                    This preview shows how your selected primary,
                                    secondary, background and text colours work together.
                                </p>

                                <span class="settings-theme-preview-button">
                                    Sample Button
                                </span>
                            </div>
                        </div>

                        <div class="settings-actions settings-actions-sticky">
                            <button
                                type="button"
                                class="btn btn-outline"
                                id="resetThemeBtn"
                            >
                                <i class="fas fa-rotate-left"></i>
                                Reset Preview
                            </button>

                            <button
                                type="submit"
                                class="btn btn-primary"
                                id="saveAppearanceBtn"
                            >
                                <i class="fas fa-save"></i>
                                Save Theme Colours
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Email Tab -->
                <section
                    class="settings-tab-panel <?= $activeTab === 'email' ? 'active' : '' ?>"
                    id="panel-email"
                    role="tabpanel"
                    aria-labelledby="tab-email"
                    <?= $activeTab !== 'email' ? 'hidden' : '' ?>
                >
                    <form id="emailForm" autocomplete="off">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= st_h($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="save_email"
                        >

                        <div id="emailAlert" class="ajax-alert"></div>

                        <div class="settings-card-header">
                            <div>
                                <h3>
                                    <i class="fas fa-envelope"></i>
                                    Email Configuration
                                </h3>
                                <p class="settings-card-subtitle">
                                    Configure SMTP for system emails, notifications and reminders.
                                </p>
                            </div>
                        </div>

                        <div class="settings-form-grid">
                            <div class="settings-form-group">
                                <label for="smtpHost">SMTP Host</label>
                                <input
                                    type="text"
                                    id="smtpHost"
                                    name="smtp_host"
                                    class="form-control"
                                    required
                                    value="<?= st_h($email['smtp_host']) ?>"
                                    placeholder="smtp.example.com"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="smtpPort">SMTP Port</label>
                                <input
                                    type="number"
                                    id="smtpPort"
                                    name="smtp_port"
                                    class="form-control"
                                    min="1"
                                    max="65535"
                                    required
                                    value="<?= st_h($email['smtp_port']) ?>"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="smtpUser">SMTP Username</label>
                                <input
                                    type="text"
                                    id="smtpUser"
                                    name="smtp_user"
                                    class="form-control"
                                    autocomplete="username"
                                    required
                                    value="<?= st_h($email['smtp_user']) ?>"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="smtpPassword">SMTP Password</label>
                                <input
                                    type="password"
                                    id="smtpPassword"
                                    name="smtp_password"
                                    class="form-control"
                                    autocomplete="new-password"
                                    placeholder="Leave blank to keep current password"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="smtpSecure">SMTP Security</label>
                                <select
                                    id="smtpSecure"
                                    name="smtp_secure"
                                    class="form-control"
                                >
                                    <option
                                        value="tls"
                                        <?= (string)$email['smtp_secure'] === 'tls' ? 'selected' : '' ?>
                                    >
                                        TLS
                                    </option>

                                    <option
                                        value="ssl"
                                        <?= (string)$email['smtp_secure'] === 'ssl' ? 'selected' : '' ?>
                                    >
                                        SSL
                                    </option>

                                    <option
                                        value=""
                                        <?= (string)$email['smtp_secure'] === '' ? 'selected' : '' ?>
                                    >
                                        None
                                    </option>
                                </select>
                            </div>

                            <div class="settings-form-group">
                                <label for="fromEmail">From Email</label>
                                <input
                                    type="email"
                                    id="fromEmail"
                                    name="from_email"
                                    class="form-control"
                                    required
                                    value="<?= st_h($email['from_email']) ?>"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="fromName">From Name</label>
                                <input
                                    type="text"
                                    id="fromName"
                                    name="from_name"
                                    class="form-control"
                                    value="<?= st_h($email['from_name']) ?>"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="emailStatus">Status</label>
                                <select
                                    id="emailStatus"
                                    name="is_active"
                                    class="form-control"
                                >
                                    <option
                                        value="1"
                                        <?= (int)$email['is_active'] === 1 ? 'selected' : '' ?>
                                    >
                                        Active
                                    </option>

                                    <option
                                        value="0"
                                        <?= (int)$email['is_active'] === 0 ? 'selected' : '' ?>
                                    >
                                        Inactive
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="settings-actions settings-actions-sticky">
                            <button
                                type="submit"
                                class="btn btn-primary"
                                id="saveEmailBtn"
                            >
                                <i class="fas fa-save"></i>
                                Save Email Settings
                            </button>
                        </div>
                    </form>
                </section>

                <!-- SMS Tab -->
                <section
                    class="settings-tab-panel <?= $activeTab === 'sms' ? 'active' : '' ?>"
                    id="panel-sms"
                    role="tabpanel"
                    aria-labelledby="tab-sms"
                    <?= $activeTab !== 'sms' ? 'hidden' : '' ?>
                >
                    <form id="smsForm" autocomplete="off">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= st_h($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="save_sms"
                        >

                        <div id="smsAlert" class="ajax-alert"></div>

                        <div class="settings-card-header">
                            <div>
                                <h3>
                                    <i class="fas fa-comment-sms"></i>
                                    SMS API Configuration
                                </h3>
                                <p class="settings-card-subtitle">
                                    Configure the external SMS gateway used for reminders and notifications.
                                </p>
                            </div>
                        </div>

                        <div class="settings-form-grid">
                            <div class="settings-form-group">
                                <label for="providerName">Provider Name</label>
                                <input
                                    type="text"
                                    id="providerName"
                                    name="provider_name"
                                    class="form-control"
                                    value="<?= st_h($sms['provider_name']) ?>"
                                    placeholder="SMS provider name"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="apiUrl">API URL</label>
                                <input
                                    type="url"
                                    id="apiUrl"
                                    name="api_url"
                                    class="form-control"
                                    required
                                    value="<?= st_h($sms['api_url']) ?>"
                                    placeholder="https://api.example.com/send"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="apiKey">API Key</label>
                                <input
                                    type="password"
                                    id="apiKey"
                                    name="api_key"
                                    class="form-control"
                                    autocomplete="new-password"
                                    placeholder="Leave blank to keep current key"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="senderId">Sender ID</label>
                                <input
                                    type="text"
                                    id="senderId"
                                    name="sender_id"
                                    class="form-control"
                                    maxlength="30"
                                    value="<?= st_h($sms['sender_id']) ?>"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="httpMethod">HTTP Method</label>
                                <select
                                    id="httpMethod"
                                    name="http_method"
                                    class="form-control"
                                >
                                    <option
                                        value="POST"
                                        <?= strtoupper((string)$sms['http_method']) === 'POST' ? 'selected' : '' ?>
                                    >
                                        POST
                                    </option>

                                    <option
                                        value="GET"
                                        <?= strtoupper((string)$sms['http_method']) === 'GET' ? 'selected' : '' ?>
                                    >
                                        GET
                                    </option>
                                </select>
                            </div>

                            <div class="settings-form-group">
                                <label for="phoneParam">Phone Parameter</label>
                                <input
                                    type="text"
                                    id="phoneParam"
                                    name="phone_param"
                                    class="form-control"
                                    required
                                    value="<?= st_h($sms['phone_param']) ?>"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="messageParam">Message Parameter</label>
                                <input
                                    type="text"
                                    id="messageParam"
                                    name="message_param"
                                    class="form-control"
                                    required
                                    value="<?= st_h($sms['message_param']) ?>"
                                >
                            </div>

                            <div class="settings-form-group">
                                <label for="senderParam">Sender Parameter</label>
                                <input
                                    type="text"
                                    id="senderParam"
                                    name="sender_param"
                                    class="form-control"
                                    value="<?= st_h($sms['sender_param']) ?>"
                                >
                            </div>

                            <div class="settings-form-group settings-full">
                                <label for="authHeader">Authorization Header</label>
                                <input
                                    type="text"
                                    id="authHeader"
                                    name="auth_header"
                                    class="form-control"
                                    value="<?= st_h($sms['auth_header']) ?>"
                                    placeholder="Authorization: Bearer {API_KEY}"
                                >
                                <span class="settings-form-help">
                                    Use the format required by your SMS provider.
                                </span>
                            </div>

                            <div class="settings-form-group">
                                <label for="smsStatus">Status</label>
                                <select
                                    id="smsStatus"
                                    name="is_active"
                                    class="form-control"
                                >
                                    <option
                                        value="1"
                                        <?= (int)$sms['is_active'] === 1 ? 'selected' : '' ?>
                                    >
                                        Active
                                    </option>

                                    <option
                                        value="0"
                                        <?= (int)$sms['is_active'] === 0 ? 'selected' : '' ?>
                                    >
                                        Inactive
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="settings-actions settings-actions-sticky">
                            <button
                                type="submit"
                                class="btn btn-primary"
                                id="saveSmsBtn"
                            >
                                <i class="fas fa-save"></i>
                                Save SMS Settings
                            </button>
                        </div>
                    </form>
                </section>

            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const tabButtons = Array.from(
        document.querySelectorAll('[data-settings-tab]')
    );

    const tabPanels = Array.from(
        document.querySelectorAll('.settings-tab-panel')
    );

    function activateTab(tabName, updateUrl = true) {
        tabButtons.forEach(function (button) {
            const isActive = button.dataset.settingsTab === tabName;

            button.classList.toggle('active', isActive);
            button.setAttribute(
                'aria-selected',
                isActive ? 'true' : 'false'
            );
        });

        tabPanels.forEach(function (panel) {
            const isActive = panel.id === 'panel-' + tabName;

            panel.classList.toggle('active', isActive);
            panel.hidden = !isActive;
        });

        if (updateUrl && window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url.toString());
        }

        try {
            localStorage.setItem('siteSettingsActiveTab', tabName);
        } catch (error) {
            // Local storage may be disabled.
        }
    }

    tabButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            activateTab(button.dataset.settingsTab || 'general');
        });
    });

    const queryTab = new URLSearchParams(window.location.search).get('tab');
    const allowedTabs = ['general', 'appearance', 'email', 'sms'];

    if (!queryTab) {
        try {
            const storedTab = localStorage.getItem('siteSettingsActiveTab');

            if (storedTab && allowedTabs.includes(storedTab)) {
                activateTab(storedTab, false);
            }
        } catch (error) {
            // Local storage may be disabled.
        }
    }

    function showAlert(box, message, type) {
        if (!box) {
            return;
        }

        box.className = 'ajax-alert ' + (
            type === 'error' ? 'error' : 'success'
        );

        box.textContent = message;
        box.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest'
        });
    }

    function clearAlert(box) {
        if (!box) {
            return;
        }

        box.className = 'ajax-alert';
        box.textContent = '';
    }

    async function parseResponse(response) {
        const text = await response.text();

        let data;

        try {
            data = JSON.parse(text);
        } catch (error) {
            throw new Error(
                text
                    ? 'Invalid server response: ' + text.substring(0, 250)
                    : 'Empty server response.'
            );
        }

        if (!response.ok) {
            throw new Error(data.message || 'Request failed.');
        }

        return data;
    }

    async function sendForm(form, alertBox, submitButton) {
        clearAlert(alertBox);

        const originalHtml = submitButton
            ? submitButton.innerHTML
            : '';

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML =
                '<i class="fas fa-spinner fa-spin"></i> Saving...';
        }

        try {
            const response = await fetch('process-settings.php', {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await parseResponse(response);

            if (!data.success) {
                throw new Error(data.message || 'Unable to save settings.');
            }

            showAlert(
                alertBox,
                data.message || 'Settings saved successfully.',
                'success'
            );

            if (data.reload === true) {
                setTimeout(function () {
                    window.location.reload();
                }, 700);
            }
        } catch (error) {
            showAlert(
                alertBox,
                error.message || 'Unable to save settings.',
                'error'
            );
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = originalHtml;
            }
        }
    }

    const formMap = [
        ['generalForm', 'generalAlert', 'saveGeneralBtn'],
        ['appearanceForm', 'appearanceAlert', 'saveAppearanceBtn'],
        ['emailForm', 'emailAlert', 'saveEmailBtn'],
        ['smsForm', 'smsAlert', 'saveSmsBtn']
    ];

    formMap.forEach(function (item) {
        const form = document.getElementById(item[0]);
        const alertBox = document.getElementById(item[1]);
        const submitButton = document.getElementById(item[2]);

        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            sendForm(form, alertBox, submitButton);
        });
    });

    const siteName = document.getElementById('siteName');
    const siteSubtitle = document.getElementById('siteSubtitle');
    const brandPreviewName = document.getElementById('brandPreviewName');
    const brandPreviewSubtitle = document.getElementById(
        'brandPreviewSubtitle'
    );

    siteName?.addEventListener('input', function () {
        if (brandPreviewName) {
            brandPreviewName.textContent =
                siteName.value.trim() || 'Church Name';
        }
    });

    siteSubtitle?.addEventListener('input', function () {
        if (brandPreviewSubtitle) {
            brandPreviewSubtitle.textContent =
                siteSubtitle.value.trim() || 'Church Management System';
        }
    });

    function bindImagePreview(inputId, previewId, wrapperClass) {
        const input = document.getElementById(inputId);

        input?.addEventListener('change', function () {
            const file = input.files && input.files[0];

            if (!file || !file.type.startsWith('image/')) {
                return;
            }

            const reader = new FileReader();

            reader.onload = function (event) {
                const currentImage = document.getElementById(previewId);
                const previewBox = input.closest('.settings-tab-panel')
                    ?.querySelector(
                        inputId === 'siteLogo'
                            ? '.settings-preview-box:nth-child(1)'
                            : '.settings-preview-box:nth-child(2)'
                    );

                if (currentImage) {
                    currentImage.src = String(event.target?.result || '');
                    return;
                }

                if (!previewBox) {
                    return;
                }

                previewBox.innerHTML =
                    '<h5>' +
                    (inputId === 'siteLogo'
                        ? 'Current Logo'
                        : 'Current Favicon') +
                    '</h5>' +
                    '<div class="' + wrapperClass + '">' +
                    '<img id="' + previewId + '" src="' +
                    String(event.target?.result || '') +
                    '" alt="Preview">' +
                    '</div>';
            };

            reader.readAsDataURL(file);
        });
    }

    bindImagePreview(
        'siteLogo',
        'logoPreviewImage',
        'settings-logo-preview'
    );

    bindImagePreview(
        'siteFavicon',
        'faviconPreviewImage',
        'settings-favicon-preview'
    );

    const primaryColor = document.getElementById('primaryColor');
    const secondaryColor = document.getElementById('secondaryColor');
    const backgroundColor = document.getElementById('backgroundColor');
    const textColor = document.getElementById('textColor');

    const themeDefaults = {
        primary: <?= json_encode($settings['primary_color']) ?>,
        secondary: <?= json_encode($settings['secondary_color']) ?>,
        background: <?= json_encode($settings['background_color']) ?>,
        text: <?= json_encode($settings['text_color']) ?>
    };

    function updateThemePreview() {
        const primary = primaryColor?.value || themeDefaults.primary;
        const secondary = secondaryColor?.value || themeDefaults.secondary;
        const background = backgroundColor?.value || themeDefaults.background;
        const text = textColor?.value || themeDefaults.text;

        const preview = document.getElementById('themePreview');
        const primarySwatch = document.getElementById('primarySwatch');
        const secondarySwatch = document.getElementById('secondarySwatch');
        const backgroundSwatch = document.getElementById('backgroundSwatch');
        const textSwatch = document.getElementById('textSwatch');

        preview?.style.setProperty('--preview-primary', primary);
        preview?.style.setProperty('--preview-secondary', secondary);
        preview?.style.setProperty('--preview-bg', background);
        preview?.style.setProperty('--preview-text', text);

        primarySwatch?.style.setProperty('--swatch', primary);
        secondarySwatch?.style.setProperty('--swatch', secondary);
        backgroundSwatch?.style.setProperty('--swatch', background);
        textSwatch?.style.setProperty('--swatch', text);
    }

    [
        primaryColor,
        secondaryColor,
        backgroundColor,
        textColor
    ].forEach(function (input) {
        input?.addEventListener('input', updateThemePreview);
    });

    document
        .getElementById('resetThemeBtn')
        ?.addEventListener('click', function () {
            if (primaryColor) {
                primaryColor.value = themeDefaults.primary;
            }

            if (secondaryColor) {
                secondaryColor.value = themeDefaults.secondary;
            }

            if (backgroundColor) {
                backgroundColor.value = themeDefaults.background;
            }

            if (textColor) {
                textColor.value = themeDefaults.text;
            }

            updateThemePreview();
        });

    updateThemePreview();
});
</script>

<?php require_once '../includes/footer.php'; ?>
