#!/bin/sh
#
# parental_control_captive.sh
# RC script for Parental Control Captive Portal Server
#
# PROVIDE: parental_control_captive
# REQUIRE: DAEMON
# KEYWORD: shutdown
#
# Add the following line to /etc/rc.conf to enable this service:
# parental_control_captive_enable="YES"

. /etc/rc.subr

name="parental_control_captive"
rcvar="${name}_enable"
desc="Parental Control Captive Portal Server"

# Load rc.conf variables
load_rc_config $name

# Set defaults
: ${parental_control_captive_enable:="NO"}
: ${parental_control_captive_port:="1008"}
: ${parental_control_captive_host:="0.0.0.0"}
: ${parental_control_captive_docroot:="/usr/local/www"}

# Paths
pidfile="/var/run/${name}.pid"
logfile="/var/log/${name}.log"
php_bin="/usr/local/bin/php"
captive_script="/usr/local/www/parental_control_captive.php"

# Command to start
command="/usr/sbin/daemon"
command_args="-P ${pidfile} -o ${logfile} ${php_bin} -S ${parental_control_captive_host}:${parental_control_captive_port} -t ${parental_control_captive_docroot} ${captive_script}"

# Functions
start_cmd="${name}_start"
stop_cmd="${name}_stop"
status_cmd="${name}_status"
restart_cmd="${name}_restart"

parental_control_captive_start()
{
	if [ -f "${pidfile}" ]; then
		pid=$(cat ${pidfile} 2>/dev/null)
		if [ -n "$pid" ] && kill -0 $pid 2>/dev/null; then
			echo "${name} is already running (pid: $pid)"
			return 1
		else
			echo "Removing stale PID file..."
			rm -f ${pidfile}
		fi
	fi
	
	echo "Starting ${name}..."
	
	# NEW v1.4.45: Rotate log if it exceeds 10MB (prevents indefinite growth)
	if [ -f "${logfile}" ]; then
		log_size=$(stat -f%z "${logfile}" 2>/dev/null || echo 0)
		max_size=$((10 * 1024 * 1024))  # 10MB in bytes
		
		if [ "$log_size" -gt "$max_size" ]; then
			echo "Log file exceeds 10MB, rotating..."
			timestamp=$(date +%Y%m%d_%H%M%S)
			rotated_log="${logfile}.${timestamp}"
			
			# Move current log to rotated file
			mv "${logfile}" "${rotated_log}"
			
			# Compress rotated log in background
			gzip "${rotated_log}" &
			
			echo "Log rotated to: ${rotated_log}.gz"
			
			# Keep only last 5 rotated logs
			ls -t ${logfile}.*.gz 2>/dev/null | tail -n +6 | xargs rm -f 2>/dev/null
		fi
	fi
	
	# Verify PHP is available
	if [ ! -x "${php_bin}" ]; then
		echo "ERROR: PHP not found at ${php_bin}"
		return 1
	fi
	
	# Verify captive script exists
	if [ ! -f "${captive_script}" ]; then
		echo "ERROR: Captive script not found at ${captive_script}"
		return 1
	fi
	
	# Check if port is already in use
	if sockstat -4 -l | grep -q ":${parental_control_captive_port}"; then
		echo "ERROR: Port ${parental_control_captive_port} is already in use"
		return 1
	fi
	
	# Start the server
	${command} ${command_args}
	
	# Wait a moment and verify it started
	sleep 2
	
	if [ -f "${pidfile}" ]; then
		pid=$(cat ${pidfile} 2>/dev/null)
		if [ -n "$pid" ] && kill -0 $pid 2>/dev/null; then
			echo "${name} started successfully (pid: $pid)"
			echo "Listening on http://${parental_control_captive_host}:${parental_control_captive_port}"
			return 0
		fi
	fi
	
	echo "ERROR: Failed to start ${name}"
	return 1
}

parental_control_captive_stop()
{
	if [ ! -f "${pidfile}" ]; then
		echo "${name} is not running (no PID file)"
		return 1
	fi
	
	pid=$(cat ${pidfile} 2>/dev/null)
	
	if [ -z "$pid" ]; then
		echo "Invalid PID file, removing..."
		rm -f ${pidfile}
		return 1
	fi
	
	if ! kill -0 $pid 2>/dev/null; then
		echo "${name} is not running (stale PID file), removing..."
		rm -f ${pidfile}
		return 1
	fi
	
	echo "Stopping ${name} (pid: $pid)..."
	
	# Try graceful shutdown first
	kill -TERM $pid 2>/dev/null
	
	# Wait up to 10 seconds for process to stop
	for i in 1 2 3 4 5 6 7 8 9 10; do
		if ! kill -0 $pid 2>/dev/null; then
			echo "${name} stopped"
			rm -f ${pidfile}
			return 0
		fi
		sleep 1
	done
	
	# Force kill if still running
	echo "Forcing ${name} to stop..."
	kill -KILL $pid 2>/dev/null
	sleep 1
	
	if ! kill -0 $pid 2>/dev/null; then
		echo "${name} stopped (forced)"
		rm -f ${pidfile}
		return 0
	fi
	
	echo "ERROR: Failed to stop ${name}"
	return 1
}

parental_control_captive_status()
{
	if [ ! -f "${pidfile}" ]; then
		echo "${name} is not running"
		return 1
	fi
	
	pid=$(cat ${pidfile} 2>/dev/null)
	
	if [ -z "$pid" ]; then
		echo "${name} is not running (invalid PID file)"
		return 1
	fi
	
	if kill -0 $pid 2>/dev/null; then
		echo "${name} is running (pid: $pid)"
		echo "Listening on http://${parental_control_captive_host}:${parental_control_captive_port}"
		
		# Show port status
		if sockstat -4 -l | grep -q ":${parental_control_captive_port}"; then
			echo "Port ${parental_control_captive_port} is active"
		else
			echo "WARNING: Process running but port not listening!"
		fi
		
		# Show recent log entries
		if [ -f "${logfile}" ]; then
			echo ""
			echo "Recent log entries:"
			tail -5 ${logfile}
		fi
		
		return 0
	else
		echo "${name} is not running (stale PID file)"
		return 1
	fi
}

parental_control_captive_restart()
{
	echo "Restarting ${name}..."
	parental_control_captive_stop
	sleep 2
	parental_control_captive_start
}

# Extra commands
extra_commands="status"

run_rc_command "$1"

