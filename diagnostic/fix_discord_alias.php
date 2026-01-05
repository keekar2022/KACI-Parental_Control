#!/usr/local/bin/php-cgi -f
<?php
require_once('/etc/inc/config.inc');
require_once('/etc/inc/filter.inc');

echo "=== Fixing Discord Alias Name ===" . PHP_EOL . PHP_EOL;

$aliases = config_get_path('aliases/alias', []);
$fixed = false;

foreach ($aliases as $idx => $alias) {
    if (isset($alias['name']) && $alias['name'] === 'Parental_Control_Discord') {
        echo "Found: Parental_Control_Discord" . PHP_EOL;
        echo "Renaming to: PC_Service_Discord" . PHP_EOL . PHP_EOL;
        
        // Update the alias name
        $aliases[$idx]['name'] = 'PC_Service_Discord';
        
        // Update description to match our convention
        $aliases[$idx]['descr'] = 'Added By KACI Parental Control (DO NOT EDIT DIRECTLY) - Discord';
        
        $fixed = true;
        break;
    }
}

if ($fixed) {
    // Save back to config
    config_set_path('aliases/alias', $aliases);
    write_config("Renamed Discord alias to match PC_Service_* pattern");
    
    echo "✓ Alias renamed successfully" . PHP_EOL;
    echo "✓ Config saved" . PHP_EOL . PHP_EOL;
    
    // Update pf table name (rename the table)
    echo "Updating pf tables..." . PHP_EOL;
    
    // Get IPs from old table
    exec('/sbin/pfctl -t Parental_Control_Discord -T show 2>&1', $old_ips, $ret1);
    
    if ($ret1 == 0 && !empty($old_ips)) {
        echo "  Found " . count($old_ips) . " IPs in old table" . PHP_EOL;
        
        // Create temp file with IPs
        $temp_file = '/tmp/discord_ips.txt';
        file_put_contents($temp_file, implode("\n", $old_ips) . "\n");
        
        // Load into new table
        exec('/sbin/pfctl -t PC_Service_Discord -T add -f ' . escapeshellarg($temp_file) . ' 2>&1', $output, $ret2);
        
        if ($ret2 == 0) {
            echo "  ✓ Loaded IPs into PC_Service_Discord table" . PHP_EOL;
        }
        
        // Delete old table
        exec('/sbin/pfctl -t Parental_Control_Discord -T kill 2>&1');
        echo "  ✓ Removed old table" . PHP_EOL;
        
        unlink($temp_file);
    }
    
    // Rename table file if it exists
    $old_file = '/var/db/aliastables/Parental_Control_Discord.txt';
    $new_file = '/var/db/aliastables/PC_Service_Discord.txt';
    
    if (file_exists($old_file)) {
        rename($old_file, $new_file);
        echo "  ✓ Renamed table file" . PHP_EOL;
    }
    
    echo PHP_EOL . "Reloading filter..." . PHP_EOL;
    send_event("filter reload");
    sleep(2);
    
    echo PHP_EOL . "✅ Complete! Discord will now show in Profiles page." . PHP_EOL;
} else {
    echo "❌ Discord alias not found or already has correct name." . PHP_EOL;
}
?>

