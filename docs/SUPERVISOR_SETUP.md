# AMS Supervisor Setup Guide

## Overview
This guide explains how to set up Supervisor for the AMS (Arrival Management System) backend processes.

## Prerequisites
- Ubuntu/Debian server with Supervisor installed
- AMS application deployed
- Proper file permissions set

## Installation

### 1. Install Supervisor
```bash
sudo apt update
sudo apt install supervisor
```

### 2. Verify Installation
```bash
sudo systemctl status supervisor
sudo supervisorctl status
```

## Configuration

### 1. Copy Configuration Files
```bash
# Copy AMS supervisor configurations
sudo cp supervisor/ams-sync.conf /etc/supervisor/conf.d/
sudo cp supervisor/ams-queue-worker.conf /etc/supervisor/conf.d/
sudo cp supervisor/ams-sync-scheduler.conf /etc/supervisor/conf.d/
```

### 2. Update Configuration Paths
Edit each configuration file and update the paths:

**ams-sync.conf**:
```ini
[program:ams-sync]
command=php /var/www/be-ams/artisan ams:sync-scm --type=arrivals
directory=/var/www/be-ams
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/ams-sync.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
environment=LARAVEL_ENV="production"
```

**Note**: This configuration is optional. The scheduled sync (`ams-sync-scheduler`) already handles arrival data sync automatically. Business partners are queried directly from SCM database by the frontend.

**ams-queue-worker.conf**:
```ini
[program:ams-queue-worker]
command=php /var/www/be-ams/artisan queue:work --sleep=3 --tries=3 --max-time=3600
directory=/var/www/be-ams
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/ams-queue-worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
environment=LARAVEL_ENV="production"
```

**ams-sync-scheduler.conf**:
```ini
[program:ams-sync-scheduler]
command=php /var/www/be-ams/artisan schedule:work
directory=/var/www/be-ams
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/ams-sync-scheduler.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
environment=LARAVEL_ENV="production"
```

### 3. Set Proper Permissions
```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/be-ams

# Set permissions
sudo chmod -R 755 /var/www/be-ams
sudo chmod -R 777 /var/www/be-ams/storage
sudo chmod -R 777 /var/www/be-ams/bootstrap/cache
```

## Supervisor Management

### 1. Reload Configuration
```bash
sudo supervisorctl reread
sudo supervisorctl update
```

### 2. Start Processes
```bash
# Start all AMS processes
sudo supervisorctl start ams-sync
sudo supervisorctl start ams-queue-worker
sudo supervisorctl start ams-sync-scheduler

# Or start all at once
sudo supervisorctl start all
```

### 3. Check Status
```bash
# Check all processes
sudo supervisorctl status

# Check specific process
sudo supervisorctl status ams-sync
sudo supervisorctl status ams-queue-worker
sudo supervisorctl status ams-sync-scheduler
```

### 4. Control Processes
```bash
# Stop processes
sudo supervisorctl stop ams-sync
sudo supervisorctl stop ams-queue-worker
sudo supervisorctl stop ams-sync-scheduler

# Restart processes
sudo supervisorctl restart ams-sync
sudo supervisorctl restart ams-queue-worker
sudo supervisorctl restart ams-sync-scheduler

# Stop all processes
sudo supervisorctl stop all
```

## Log Management

### 1. View Logs
```bash
# View sync logs
sudo tail -f /var/log/supervisor/ams-sync.log

# View queue worker logs
sudo tail -f /var/log/supervisor/ams-queue-worker.log

# View scheduler logs
sudo tail -f /var/log/supervisor/ams-sync-scheduler.log
```

### 2. Log Rotation
Create logrotate configuration:

**/etc/logrotate.d/ams-supervisor**:
```
/var/log/supervisor/ams-*.log {
    daily
    missingok
    rotate 7
    compress
    delaycompress
    notifempty
    create 644 www-data www-data
    postrotate
        sudo supervisorctl restart ams-sync ams-queue-worker ams-sync-scheduler
    endscript
}
```

## Monitoring

### 1. Process Monitoring
```bash
# Monitor all processes
watch -n 5 'sudo supervisorctl status'

# Monitor specific process
watch -n 5 'sudo supervisorctl status ams-sync'
```

### 2. Log Monitoring
```bash
# Monitor all AMS logs
sudo tail -f /var/log/supervisor/ams-*.log

# Monitor with filtering
sudo tail -f /var/log/supervisor/ams-sync.log | grep ERROR
```

### 3. Health Checks
Create a health check script:

**/usr/local/bin/ams-health-check.sh**:
```bash
#!/bin/bash

# Check if processes are running
if ! sudo supervisorctl status ams-sync | grep -q "RUNNING"; then
    echo "AMS Sync process is not running"
    exit 1
fi

if ! sudo supervisorctl status ams-queue-worker | grep -q "RUNNING"; then
    echo "AMS Queue Worker process is not running"
    exit 1
fi

if ! sudo supervisorctl status ams-sync-scheduler | grep -q "RUNNING"; then
    echo "AMS Sync Scheduler process is not running"
    exit 1
fi

echo "All AMS processes are running"
exit 0
```

Make it executable:
```bash
sudo chmod +x /usr/local/bin/ams-health-check.sh
```

## Troubleshooting

### 1. Common Issues

**Process won't start**:
```bash
# Check configuration syntax
sudo supervisorctl reread

# Check logs
sudo tail -f /var/log/supervisor/supervisord.log
```

**Permission denied**:
```bash
# Fix ownership
sudo chown -R www-data:www-data /var/www/be-ams

# Fix permissions
sudo chmod -R 755 /var/www/be-ams
```

**Process keeps restarting**:
```bash
# Check application logs
sudo tail -f /var/www/be-ams/storage/logs/laravel.log

# Check supervisor logs
sudo tail -f /var/log/supervisor/ams-sync.log
```

### 2. Debug Commands
```bash
# Test sync command manually
cd /var/www/be-ams
sudo -u www-data php artisan ams:sync-scm --type=arrivals

# Test queue worker manually
sudo -u www-data php artisan queue:work --once

# Test scheduler manually
sudo -u www-data php artisan schedule:run
```

### 3. Restart Everything
```bash
# Restart supervisor
sudo systemctl restart supervisor

# Restart all AMS processes
sudo supervisorctl restart all
```

## Security Considerations

1. **File Permissions**: Ensure proper file permissions are set
2. **User Isolation**: Run processes as www-data user
3. **Log Security**: Secure log files and directories
4. **Network Security**: Ensure proper firewall rules

## Maintenance

### 1. Regular Maintenance
```bash
# Weekly log cleanup
sudo find /var/log/supervisor -name "ams-*.log" -mtime +7 -delete

# Monthly configuration review
sudo supervisorctl reread
sudo supervisorctl update
```

### 2. Updates
```bash
# Update application
cd /var/www/be-ams
sudo -u www-data git pull
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

# Restart processes
sudo supervisorctl restart all
```

## Backup

### 1. Configuration Backup
```bash
# Backup supervisor configurations
sudo cp -r /etc/supervisor/conf.d/ /backup/supervisor-config-$(date +%Y%m%d)
```

### 2. Log Backup
```bash
# Backup logs
sudo tar -czf /backup/ams-logs-$(date +%Y%m%d).tar.gz /var/log/supervisor/ams-*.log
```
