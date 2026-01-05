#!/usr/local/bin/php-cgi -f
<?php
require_once('/etc/inc/config.inc');
require_once('/etc/inc/filter.inc');

echo "=== Manually Downloading URL Aliases ===" . PHP_EOL . PHP_EOL;

$aliases = config_get_path('aliases/alias', []);
foreach ($aliases as $alias) {
    if (strpos($alias['name'], 'PC_Service_') === 0 && $alias['type'] == 'url') {
        echo "Processing: " . $alias['name'] . PHP_EOL;
        
        $table_file = '/var/db/aliastables/' . $alias['name'] . '.txt';
        @mkdir('/var/db/aliastables', 0755, true);
        
        $all_ips = [];
        
        if (isset($alias['aliasurl']) && is_array($alias['aliasurl'])) {
            foreach ($alias['aliasurl'] as $url) {
                echo "  Downloading: " . $url . PHP_EOL;
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                
                $content = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($http_code == 200 && !empty($content)) {
                    $lines = explode("\n", $content);
                    $count = 0;
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line) || $line[0] == '#') continue;
                        $all_ips[] = $line;
                        $count++;
                    }
                    echo "    Status: SUCCESS (" . $count . " IPs)" . PHP_EOL;
                } else {
                    echo "    Status: FAILED (HTTP " . $http_code . ")" . PHP_EOL;
                }
            }
        }
        
        if (!empty($all_ips)) {
            file_put_contents($table_file, implode("\n", $all_ips) . "\n");
            chmod($table_file, 0644);
            echo "  Wrote " . count($all_ips) . " entries to table file" . PHP_EOL;
            
            exec('/sbin/pfctl -t ' . escapeshellarg($alias['name']) . ' -T replace -f ' . escapeshellarg($table_file) . ' 2>&1', $output, $ret);
            if ($ret == 0) {
                echo "  Loaded into pf table: SUCCESS" . PHP_EOL;
            } else {
                echo "  Loaded into pf table: FAILED - " . implode(', ', $output) . PHP_EOL;
            }
        }
        
        echo PHP_EOL;
    }
}

echo "=== Reloading Filter ===" . PHP_EOL;
send_event("filter reload");
sleep(2);

echo "=== Complete! ===" . PHP_EOL;
?>

