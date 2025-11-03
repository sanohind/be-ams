# AMS (Arrival Management System) - Backend API

## Overview

The AMS Backend API is a Laravel-based system that manages arrival schedules, tracks deliveries, and integrates with multiple external systems including SCM, Visitor Management System, and ERP.

## Features

- **Dashboard**: Real-time arrival tracking and statistics
- **Arrival Management**: Schedule regular and additional arrivals
- **Arrival Check**: Driver check-in/check-out process
- **Item Scanning**: QR code scanning for delivery items
- **Check Sheet**: Quality control documentation
- **Level Stock**: ERP stock level monitoring
- **Arrival Schedule**: Historical arrival data and performance
- **Data Synchronization**: Automated sync with SCM system

## Database Connections

The system connects to multiple databases:

1. **AMS Database** (`be_ams`) - Main application data
2. **Sphere Database** (`be_sphere`) - User authentication and roles
3. **SCM Database** (`sanoh-scm`) - Supply chain management data
4. **Visitor Database** (`visitor`) - Visitor management system
5. **ERP Database** (`soi107`) - Enterprise resource planning data

## Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd be-ams
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Environment setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   php artisan jwt:secret
   ```

3.5. **Install SQL Server Drivers** (Required for ERP connection)
   
   The ERP database connection uses SQL Server, which requires PHP SQL Server drivers.
   
   **For Windows:**
   1. Download Microsoft ODBC Driver for SQL Server:
      - Go to: https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server
      - Download and install the latest ODBC Driver for SQL Server (msodbcsql.msi)
   
   2. Download PHP SQL Server Drivers:
      - Go to: https://github.com/Microsoft/msphpsql/releases
      - Download the appropriate DLL files for your PHP version and architecture:
        - `php_pdo_sqlsrv.dll`
        - `php_sqlsrv.dll`
        - Make sure the version matches your PHP version (check with `php -v`)
        - Make sure the architecture matches (x86 or x64, check with `php -r "echo PHP_INT_SIZE * 8;"`)
   
   3. Copy the DLL files to your PHP extension directory:
      - Find your PHP extension directory: `php -i | findstr extension_dir`
      - Copy the DLL files to that directory
   
   4. Enable the extensions in `php.ini`:
      - Find your php.ini: `php --ini`
      - Add these lines to php.ini:
        ```ini
        extension=pdo_sqlsrv
        extension=sqlsrv
        ```
      - Make sure to use the correct DLL filename (e.g., `extension=php_pdo_sqlsrv_81_ts_x64.dll` for PHP 8.1 Thread Safe x64)
   
   5. Restart your web server or PHP-FPM
   
   6. Verify installation:
      ```bash
      php -m | findstr sqlsrv
      ```
      Should show: `pdo_sqlsrv` and `sqlsrv`
   
   **For Linux:**
   ```bash
   # Install Microsoft ODBC Driver for SQL Server
   curl https://packages.microsoft.com/keys/microsoft.asc | sudo apt-key add -
   curl https://packages.microsoft.com/config/ubuntu/$(lsb_release -rs)/prod.list | sudo tee /etc/apt/sources.list.d/mssql-release.list
   sudo apt-get update
   sudo ACCEPT_EULA=Y apt-get install -y msodbcsql18
   
   # Install PHP SQL Server drivers
   sudo pecl install sqlsrv pdo_sqlsrv
   
   # Add extensions to php.ini
   echo "extension=sqlsrv.so" | sudo tee -a /etc/php/$(php -r 'echo PHP_VERSION;')/apache2/php.ini
   echo "extension=pdo_sqlsrv.so" | sudo tee -a /etc/php/$(php -r 'echo PHP_VERSION;')/apache2/php.ini
   
   # Restart Apache
   sudo systemctl restart apache2
   
   # Verify installation
   php -m | grep sqlsrv
   ```
   
   **Troubleshooting:**
   - If you get "could not find driver" error, verify the extensions are loaded: `php -m | grep sqlsrv`
   - Make sure the DLL file versions match your PHP version
   - Make sure the DLL files are in the correct extension directory
   - Make sure the extensions are enabled in php.ini

4. **Configure database connections**
   Update the `.env` file with your database credentials:
   ```env
   # AMS Database
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=be_ams
   DB_USERNAME=root
   DB_PASSWORD=123

   # Sphere Database
   DB_CONNECTION2=mysql
   DB_HOST2=127.0.0.1
   DB_PORT2=3306
   DB_DATABASE2=be_sphere
   DB_USERNAME2=root
   DB_PASSWORD2=123

   # SCM Database
   DB_CONNECTION3=mysql
   DB_HOST3=10.1.10.111
   DB_PORT3=3306
   DB_DATABASE3=sanoh-scm
   DB_USERNAME3=sanohscm
   DB_PASSWORD3=123

   # Visitor Database
   DB_CONNECTION4=mysql
   DB_HOST4=10.1.10.110
   DB_PORT4=3306
   DB_DATABASE4=visitor
   DB_USERNAME4=root
   DB_PASSWORD4=123

   # ERP Database
   DB_CONNECTION_SQLSRV=sqlsrv
   DB_HOST_SQLSRV=10.1.10.52
   DB_PORT_SQLSRV=1433
   DB_DATABASE_SQLSRV=soi107
   DB_USERNAME_SQLSRV=portal
   DB_PASSWORD_SQLSRV=123
   DB_ENCRYPT=yes
   DB_TRUST_SERVER_CERTIFICATE=true

   # JWT Configuration
   JWT_SECRET=your-jwt-secret-key
   JWT_TTL=60
   JWT_REFRESH_TTL=20160
   JWT_ALGO=HS256
   ```

5. **Run migrations**
   ```bash
   php artisan migrate
   ```

6. **Set up Supervisor** (Production)
   ```bash
   # Copy supervisor configurations
   sudo cp supervisor/*.conf /etc/supervisor/conf.d/
   
   # Update supervisor
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo supervisorctl start all
   ```

7. **Start the server** (Development)
   ```bash
   php artisan serve
   ```

## Authentication

The API uses JWT tokens from the Sphere super app. Include the token in the Authorization header:

```
Authorization: Bearer <jwt-token>
```

## User Roles and Permissions

### Superadmin
- Full access to all features
- Can manage sync processes
- Can access all modules

### Admin Warehouse
- Dashboard access
- Arrival Management
- Level Stock monitoring
- Arrival Schedule viewing

### Operator Warehouse
- Dashboard access
- Arrival Check (check-in/check-out)
- Item Scanning
- Check Sheet management
- Arrival Schedule viewing

## API Endpoints

### Dashboard
- `GET /api/dashboard` - Get dashboard data
- `GET /api/dashboard/dn-details` - Get DN details for a group

### Arrival Management
- `GET /api/arrival-manage` - Get arrival schedules
- `POST /api/arrival-manage` - Create arrival schedule
- `PUT /api/arrival-manage/{id}` - Update arrival schedule
- `DELETE /api/arrival-manage/{id}` - Delete arrival schedule
- `GET /api/arrival-manage/suppliers` - Get suppliers list
- `GET /api/arrival-manage/available-arrivals` - Get available arrivals for additional schedule

### Arrival Check
- `GET /api/arrival-check` - Get arrivals for check-in/check-out
- `POST /api/arrival-check/checkin` - Check in driver to warehouse
- `POST /api/arrival-check/checkout` - Check out driver from warehouse
- `POST /api/arrival-check/sync-visitor` - Sync visitor data

### Item Scanning
- `GET /api/item-scan` - Get arrivals for scanning
- `POST /api/item-scan/start-session` - Start scanning session
- `POST /api/item-scan/scan-item` - Scan item
- `POST /api/item-scan/complete-session` - Complete scanning session
- `GET /api/item-scan/session/{id}` - Get session details

### Check Sheet
- `GET /api/check-sheet` - Get arrivals for check sheet
- `POST /api/check-sheet/submit` - Submit check sheet
- `GET /api/check-sheet/details` - Get check sheet details

### Level Stock
- `GET /api/level-stock` - Get stock levels
- `GET /api/level-stock/summary` - Get stock summary
- `GET /api/level-stock/warehouses` - Get warehouses list
- `GET /api/level-stock/low-stock-alerts` - Get low stock alerts
- `GET /api/level-stock/export` - Export stock data

### Arrival Schedule
- `GET /api/arrival-schedule` - Get arrival schedule for date
- `GET /api/arrival-schedule/dn-details` - Get DN details
- `GET /api/arrival-schedule/performance` - Get performance data

### Sync
- `POST /api/sync/arrivals` - Sync arrival transactions
- `POST /api/sync/partners` - Sync business partners
- `POST /api/sync/manual` - Manual sync trigger
- `GET /api/sync/statistics` - Get sync statistics
- `GET /api/sync/logs` - Get sync logs
- `GET /api/sync/last-sync` - Get last sync status

## Data Synchronization

### Supervisor Configuration
The system uses Supervisor for process management instead of cron jobs. Set up the following supervisor configurations:

#### 1. Sync Process
```bash
# Copy supervisor config
sudo cp supervisor/ams-sync.conf /etc/supervisor/conf.d/

# Reload supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start ams-sync
```

#### 2. Queue Worker
```bash
# Copy supervisor config
sudo cp supervisor/ams-queue-worker.conf /etc/supervisor/conf.d/

# Reload supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start ams-queue-worker
```

#### 3. Scheduler
```bash
# Copy supervisor config
sudo cp supervisor/ams-sync-scheduler.conf /etc/supervisor/conf.d/

# Reload supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start ams-sync-scheduler
```

### Manual Sync Commands
```bash
# Sync all data
php artisan ams:sync-scm --type=all

# Sync only arrivals
php artisan ams:sync-scm --type=arrivals

# Sync only partners
php artisan ams:sync-scm --type=partners

# Generate daily report
php artisan ams:generate-daily-report

# Cleanup old logs
php artisan ams:cleanup-logs --days=30
```

## QR Code Scanning

### DN QR Code Format
```
DN0030176
```
- Format: `DN` followed by numbers
- Used to identify Delivery Note
- Scanned first to start scanning session

### Item QR Code Format
```
RL1IN047371BZ3000000;450;PL2502055080801018;TMI;7;1;DN0030176;4
```
- **Part Number**: `RL1IN047371BZ3000000` (Field 1)
- **Quantity**: `450` (Field 2)
- **Lot Number**: `PL2502055080801018` (Field 3)
- **Customer**: `TMI` (Field 4, can be empty)
- **Field 5**: `7` (Additional field)
- **Field 6**: `1` (Additional field)
- **DN Number**: `DN0030176` (Field 7, must match session DN)
- **Field 8**: `4` (Additional field)

### Scanning Process
1. **Start Session**: Scan DN QR code to create scanning session
2. **Scan Items**: Scan individual item QR codes
3. **Validation**: System validates DN matches and prevents duplicate scanning
4. **Complete Session**: Finish scanning and complete quality checks

### API Endpoints for Scanning
- `POST /api/item-scan/scan-dn` - Scan DN QR code
- `POST /api/item-scan/scan-item` - Scan item QR code
- `POST /api/item-scan/complete-session` - Complete scanning session

1. **Data Synchronization**: SCM data is synced daily to create arrival transactions
2. **Schedule Management**: Admin creates arrival schedules for suppliers
3. **Driver Arrival**: Driver checks in at security, then warehouse
4. **Item Scanning**: Operator scans items using QR codes
5. **Quality Check**: Operator completes check sheet for quality control
6. **Completion**: Driver checks out from warehouse and security

## Error Handling

The API returns consistent error responses:

```json
{
    "success": false,
    "message": "Error description",
    "errors": ["Detailed error messages"]
}
```

## Rate Limiting

API endpoints are rate-limited to prevent abuse. Default limits:
- 60 requests per minute per user
- 1000 requests per hour per user

## Logging

All API requests and sync operations are logged. Check the `storage/logs` directory for detailed logs.

## Testing

### Run Test Suite
```bash
php artisan test
```

### Test QR Code Parsing
```bash
php artisan test tests/Feature/QRCodeParsingTest.php
```

### Test Database Connections
```bash
php artisan test tests/Feature/AMSSetupTest.php
```

### Manual Testing

**Test Health Check**:
```bash
curl http://localhost:8000/api/public/health
```

**Test Authentication** (requires valid JWT token):
```bash
curl -H "Authorization: Bearer YOUR_JWT_TOKEN" \
     http://localhost:8000/api/user
```

**Test QR Code Scanning** (requires valid JWT token):
```bash
# Test DN scanning
curl -X POST \
     -H "Authorization: Bearer YOUR_JWT_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"arrival_id": 1, "qr_data": "DN0030176"}' \
     http://localhost:8000/api/item-scan/scan-dn

# Test item scanning
curl -X POST \
     -H "Authorization: Bearer YOUR_JWT_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"session_id": 1, "qr_data": "RL1IN047371BZ3000000;450;PL2502055080801018;TMI;7;1;DN0030176;4"}' \
     http://localhost:8000/api/item-scan/scan-item
```

## Deployment

1. **Production Environment**
   - Set `APP_ENV=production`
   - Set `APP_DEBUG=false`
   - Configure proper database credentials
   - Set up SSL certificates

2. **Web Server Configuration**
   - Point document root to `public` directory
   - Configure URL rewriting for Laravel

3. **Queue Processing**
   - Set up queue workers for background jobs
   - Configure supervisor for queue management

## Troubleshooting

### Common Issues

**1. JWT Token Issues**
```bash
# Generate JWT secret
php artisan jwt:secret

# Clear config cache
php artisan config:clear
```

**2. Database Connection Issues**
```bash
# Test database connections
php artisan tinker
>>> DB::connection('sphere')->getPdo();
>>> DB::connection('scm')->getPdo();
>>> DB::connection('visitor')->getPdo();
>>> DB::connection('erp')->getPdo();
```

**2.1. SQL Server Driver "could not find driver" Error**
This error occurs when the PHP SQL Server drivers are not installed or enabled.

**Symptoms:**
- Error: `could not find driver (Connection: erp, SQL: ...)`
- Error when querying ERP database connection

**Solution (Windows):**
1. Check if SQL Server extensions are loaded:
   ```bash
   php -m | findstr sqlsrv
   ```
   Should show: `pdo_sqlsrv` and `sqlsrv`

2. If extensions are not loaded:
   - Find your PHP version: `php -v`
   - Find your PHP architecture: `php -r "echo PHP_INT_SIZE * 8;"`
   - Download matching DLL files from: https://github.com/Microsoft/msphpsql/releases
   - Copy DLL files to PHP extension directory: `php -i | findstr extension_dir`
   - Enable in php.ini:
     ```ini
     extension=php_pdo_sqlsrv_XX_ts_x64.dll  # Replace XX with your PHP version
     extension=php_sqlsrv_XX_ts_x64.dll
     ```
   - Restart web server/PHP

3. If extensions are loaded but still getting error:
   - Verify ODBC Driver for SQL Server is installed
   - Check network connectivity to SQL Server: `telnet 10.1.10.52 1433`
   - Verify database credentials in `.env`

**Solution (Linux):**
1. Install Microsoft ODBC Driver:
   ```bash
   sudo ACCEPT_EULA=Y apt-get install -y msodbcsql18
   ```

2. Install PHP SQL Server drivers:
   ```bash
   sudo pecl install sqlsrv pdo_sqlsrv
   ```

3. Enable extensions in php.ini and restart Apache/Nginx

**3. Supervisor Issues**
```bash
# Check supervisor status
sudo supervisorctl status

# Restart processes
sudo supervisorctl restart all

# Check logs
sudo tail -f /var/log/supervisor/ams-sync.log
```

**4. QR Code Parsing Issues**
- Verify QR code format matches expected pattern
- Check for extra spaces or characters
- Ensure all required fields are present

**5. Sync Issues**
```bash
# Test sync manually
php artisan ams:sync-scm --type=all

# Check sync logs
php artisan tinker
>>> App\Models\SyncLog::latest()->first();
```

### Debug Commands
```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Check application logs
tail -f storage/logs/laravel.log

# Test database migrations
php artisan migrate:status
```

## Documentation

- [QR Code Scanning API](docs/QR_CODE_SCANNING_API.md)
- [Supervisor Setup Guide](docs/SUPERVISOR_SETUP.md)
- [API Endpoints Reference](docs/API_ENDPOINTS.md)

## Frontend Integration

The AMS frontend (`fe-ams`) has been integrated with the backend API:

### SSO Integration:
- ✅ **SSO Callback**: Route `/sso/callback` untuk menerima token dari Sphere
- ✅ **Authentication Context**: Global state management untuk user dan token
- ✅ **Protected Routes**: Role-based access control untuk semua halaman
- ✅ **User Dropdown**: Menampilkan informasi user dan logout functionality

### Role-Based Access Control:
- **Dashboard**: `admin-warehouse`, `superadmin`
- **Arrival Check**: `operator-warehouse`, `superadmin`
- **Arrival Schedule**: `admin-warehouse`, `operator-warehouse`, `superadmin`
- **Check Sheet**: `operator-warehouse`, `superadmin`
- **Level Stock**: `admin-warehouse`, `superadmin`
- **Arrival Manage**: `admin-warehouse`, `superadmin`
- **Item Scan**: `operator-warehouse`, `superadmin`

### Frontend Setup:
```bash
cd fe-ams
npm install
cp env.example .env.local
# Update .env.local with your configuration
npm run dev
```

### QR Code Scanning Flow:
1. **Scan DN**: Enter DN number (e.g., `DN0030176`)
2. **Start Session**: Automatically creates scanning session
3. **Scan Items**: Enter item QR data (e.g., `RL1IN047371BZ3000000;450;PL2502055080801018;TMI;7;1;DN0030176;4`)
4. **Complete Session**: Finish with quality checks

### API Integration Status:
- ✅ Dashboard API
- ✅ Item Scan API (DN + Item scanning)
- ✅ Arrival Management API
- ✅ Level Stock API
- ✅ Sync API
- ⏳ Arrival Check API (pending)
- ⏳ Arrival Schedule API (pending)
- ⏳ Check Sheet API (pending)

## Support

For technical support or questions, please contact the development team.

## License

This project is proprietary software. All rights reserved.