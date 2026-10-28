<?php
// Theme colours are set on the Appearance tab of Site Settings, which every page reads through site_theme_css().
require_once '../config/config.php';
requireLogin();
enforce_current_page_permission();
redirect('index.php?tab=appearance');
