<?php
require(__DIR__ . '/../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

header('Content-Type: application/json');

$core = [
    'version' => $CFG->release, // "4.5.5+ (Build: 20250718)"
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
            // dev-версії ігноруємо
            continue;
        }
        if (preg_match('/^(\d+\.\d+)/', $u->release, $matches)) {
            $relbranch = $matches[1];
        } else {
            $relbranch = '';
        }

        if (strpos($CFG->release, $relbranch) === 0) {
            // ця ж гілка (наприклад 4.5 → 4.5.6)
            if ($current_branch_update === null) {
                $current_branch_update = [
                    'release' => $u->release,
                    'url' => $u->url,
                    'download' => $u->download
                ];
            }
        } else {
            // інша major гілка (наприклад 5.0)
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

// список плагінів
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

echo json_encode([
    'core' => $core,
    'core_status' => $core_status,
    'current_branch_update' => $current_branch_update,
    'next_major_update' => $next_major_update,
    'plugins_outdated' => count($outdated),
    'outdated_list' => $outdated
], JSON_PRETTY_PRINT);
