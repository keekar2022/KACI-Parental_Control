# Solution: Switch from Anchors to pfSense Tables

## Problem
- pfSense anchors require being referenced in main pf configuration
- No GUI way to add anchor references
- Filter generation hook not being called reliably
- **Result:** Blocking doesn't work due to rule evaluation order

## Root Cause
pfSense evaluates rules top-to-bottom. LAN allow rules come before our anchor,
so our anchor is never evaluated. We can't inject anchor references via GUI.

## Solution: Use pfSense Tables (Aliases)

### What are pfSense Tables?
- Built-in pfSense feature for dynamic IP lists
- Can be updated in real-time without filter reload
- Properly integrated with GUI and rule system
- Support for both IPv4 and IPv6

### How It Works

1. **Create Firewall Alias (Table)**
   - Name: `parental_control_blocked`
   - Type: Host(s)
   - Contains: List of currently blocked IPs
   - Updated dynamically by our package

2. **Create ONE Floating Rule (via code)**
   - Action: Block
   - Interface: LAN
   - Direction: in
   - Source: parental_control_blocked (alias)
   - Destination: Any
   - Description: "Parental Control - Dynamic Blocking"
   - **This rule IS in GUI and IS properly ordered**

3. **Update Table Dynamically**
   - Instead of: `pfctl -a parental_control -f /tmp/rules.parental_control`
   - Use: `pfctl -t parental_control_blocked -T add 192.168.1.111`
   - Remove: `pfctl -t parental_control_blocked -T delete 192.168.1.111`
   - List: `pfctl -t parental_control_blocked -T show`

### Advantages
- ✅ Rules in proper evaluation order (floating rules evaluated first)
- ✅ Visible in pfSense GUI
- ✅ No anchor reference needed
- ✅ Fast updates (no filter reload)
- ✅ Native pfSense feature
- ✅ Survives reboot (if persisted to config)

### Implementation

```php
// Create alias during install/sync
function pc_create_blocking_alias() {
    // Check if alias exists
    $aliases = config_get_path('aliases/alias', []);
    $alias_exists = false;
    
    foreach ($aliases as $alias) {
        if ($alias['name'] === 'parental_control_blocked') {
            $alias_exists = true;
            break;
        }
    }
    
    if (!$alias_exists) {
        // Create new alias
        $new_alias = array(
            'name' => 'parental_control_blocked',
            'type' => 'host',
            'address' => '',  // Initially empty
            'descr' => 'Parental Control - Blocked Devices (Auto-managed)',
            'detail' => ''
        );
        
        $aliases[] = $new_alias;
        config_set_path('aliases/alias', $aliases);
        write_config('Parental Control: Created blocking alias');
        
        // Reload filter to activate alias
        filter_configure();
    }
}

// Create floating rule during install/sync
function pc_create_blocking_rule() {
    $rules = config_get_path('filter/rule', []);
    $rule_exists = false;
    
    foreach ($rules as $rule) {
        if (isset($rule['descr']) && $rule['descr'] === 'Parental Control - Dynamic Blocking') {
            $rule_exists = true;
            break;
        }
    }
    
    if (!$rule_exists) {
        // Create floating rule
        $new_rule = array(
            'type' => 'block',
            'interface' => 'lan',
            'ipprotocol' => 'inet',
            'direction' => 'in',
            'floating' => 'yes',
            'quick' => 'yes',
            'source' => array(
                'address' => 'parental_control_blocked'  // Use our alias
            ),
            'destination' => array(
                'any' => true
            ),
            'descr' => 'Parental Control - Dynamic Blocking',
            'created' => array(
                'time' => time(),
                'username' => 'system@parentalcontrol'
            )
        );
        
        // Add at beginning (high priority)
        array_unshift($rules, $new_rule);
        config_set_path('filter/rule', $rules);
        write_config('Parental Control: Added blocking rule');
        filter_configure();
    }
}

// Block device by adding to table
function pc_add_device_block_table($device, $reason, $state) {
    $mac = pc_normalize_mac($device['mac_address']);
    $ip = isset($state['mac_to_ip_cache'][$mac]) ? $state['mac_to_ip_cache'][$mac] : null;
    
    if (empty($ip)) {
        return false;  // Device offline
    }
    
    // Add IP to pfSense table
    exec("/sbin/pfctl -t parental_control_blocked -T add {$ip} 2>&1", $output, $return_code);
    
    if ($return_code === 0) {
        pc_log("Blocked {$ip} via table", 'info', array(
            'event.action' => 'device_blocked',
            'device.ip' => $ip,
            'device.mac' => $mac,
            'block.reason' => $reason,
            'method' => 'pf_table'
        ));
        return true;
    }
    
    return false;
}

// Unblock device by removing from table
function pc_remove_device_block_table($mac, $state) {
    $ip = isset($state['mac_to_ip_cache'][$mac]) ? $state['mac_to_ip_cache'][$mac] : null;
    
    if (empty($ip)) {
        return true;  // Nothing to remove
    }
    
    // Remove IP from pfSense table
    exec("/sbin/pfctl -t parental_control_blocked -T delete {$ip} 2>&1", $output, $return_code);
    
    if ($return_code === 0) {
        pc_log("Unblocked {$ip} via table", 'info', array(
            'event.action' => 'device_unblocked',
            'device.ip' => $ip,
            'device.mac' => $mac,
            'method' => 'pf_table'
        ));
        return true;
    }
    
    return false;
}
```

## Migration Path

1. Keep existing anchor-based code as fallback
2. Implement table-based approach
3. Create alias and rule during next sync
4. Switch blocking functions to use tables
5. Test thoroughly
6. Remove anchor code in future version

## Version

- Current: v1.1.7 (anchor-based, broken)
- Target: v1.1.8 (table-based, working)

