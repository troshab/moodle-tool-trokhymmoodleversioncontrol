<?php
define('NO_MOODLE_COOKIES', true); // щоб не створювати сесії при webservice-виклику
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/filelib.php');

header('Content-Type: application/json');

global $DB;

// Перевірка чи переданий webservice token
$token = optional_param('wstoken', '', PARAM_ALPHANUMEXT);
$validtoken = false;
if (!empty($token)) {
    try {
        $tokendata = $DB->get_record('external_tokens', ['token' => $token, 'tokentype' => EXTERNAL_TOKEN_PERMANENT]);
        if ($tokendata) {
            $validtoken = true;
            // Завантажуємо користувача токена
            $USER = $DB->get_record('user', ['id' => $tokendata->userid]);
            // Перевірка чи є права на адміністрування
            if (!is_siteadmin($USER->id)) {
                http_response_code(403);
                echo json_encode(['error' => 'User has no admin rights']);
                exit;
            }
            // Логінюємо користувача
            \core\session\manager::set_user($USER);
        }
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }
}

if (!$validtoken) {
    // Якщо токену немає → звичайна авторизація через сесію
    require_login();
    require_capability('moodle/site:config', context_system::instance());
}

// --- Збираємо дані ---
$core = [
    'version' => $CFG->release, // приклад: "4.5.5+ (Build: 20250718)"
    'build'   => $CFG->version
];

$checker = \core\update\checker::instance();
$updates = $checker->get_update_info('core');

$core_status = 'up-to-date';
$current_branch_update = null;
$next_major_update = null;

if (!empty($updates)) {
    foreach ($updates as $u) {
        if ($u->maturity == 50) {
            // dev версії не показуємо
            continue;
        }
        if (preg_match('/^(\d+\.\d+)/', $u->release, $matches)) {
            $relbranch = $matches[1];
        } else {
            $relbranch = '';
        }

        if (strpos($CFG->release, $relbranch) === 0) {
            if ($current_branch_update === null) {
                $current_branch_update = [
                    'release' => $u->release,
                    'url' => $u->url,
                    'download' => $u->download
                ];
            }
        } else {
            if ($next_major_update === null) {
                $next_major_update = [
                    'release' => $u->release,
                    'url' => $u->url,
                    'download' => $u->download
                ];
            }
        }
    }
    $core_status = 'outdated';
}

// --- Плагіни ---
$pluginman = core_plugin_manager::instance();
$plugins = $pluginman->get_plugins();

$outdated = [];
foreach ($plugins as $type => $list) {
    foreach ($list as $name => $plugin) {
        $updates = $plugin->available_updates();
        if (!empty($updates)) {
            $updates = array_filter($updates, function($u) {
                return $u->maturity != 50; // dev відкидаємо
            });
            if (!empty($updates)) {
                $outdated[] = [
                    'type'    => $type,
                    'name'    => $name,
                    'version' => $plugin->versiondisk,
                    'updates' => $updates
                ];
            }
        }
    }
}

// --- Відповідь ---
echo json_encode([
    'core' => $core,
    'core_status' => $core_status,
    'current_branch_update' => $current_branch_update,
    'next_major_update' => $next_major_update,
    'plugins_outdated' => count($outdated),
    'outdated_list' => $outdated
], JSON_PRETTY_PRINT);
