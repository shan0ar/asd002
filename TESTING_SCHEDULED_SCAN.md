# Test Instructions: Schedule Scan in 3 Minutes Feature

## Feature Overview
This feature allows users to schedule a scan to run exactly 3 minutes after clicking the button. The scheduled scan uses the same assets and tools configuration as the "Lancer un scan maintenant" (Launch scan now) button.

## Implementation Details

### Files Modified
1. **client.php**
   - Added new POST handler for `action=schedule_scan_3min`
   - Added new button "Lancer le scan dans 3 minutes" next to existing "Lancer un scan maintenant" button
   - Added success message display for scheduled scans

2. **scripts/cron_scanner.sh**
   - Updated to use `asset_scan_settings` table (same as scan_now button)
   - Ensures scheduled scans execute with the same tool/asset configuration

### Database Tables Used
- **scan_schedules**: Stores the scheduled scan with `next_run` timestamp
- **asset_scan_settings**: Determines which tools to run for which assets
- **scans**: Records the scan execution results

## Manual Testing Instructions

### Prerequisites
1. Access to the application web interface
2. A client already configured with assets and tool settings
3. Database access to verify entries (optional but recommended)
4. Cron job or manual execution of `scripts/cron_scanner.sh` every minute

### Test Case 1: Schedule a New Scan

**Steps:**
1. Navigate to `client.php?id=<client_id>` (replace `<client_id>` with a valid client ID)
2. Ensure some assets have tools enabled in the "Paramètres assets & outils" section
3. Click the button "Lancer le scan dans 3 minutes" (orange/yellow button)
4. Verify a success message appears: "✓ Scan programmé avec succès pour le [timestamp] (dans 3 minutes)"
5. Note the scheduled timestamp shown in the success message

**Expected Results:**
- Green success message appears with the scheduled time
- Page reloads with `scan_scheduled=1` parameter in URL
- No errors displayed

**Database Verification (Optional):**
```sql
-- Check the scan_schedules table
SELECT id, client_id, frequency, next_run 
FROM scan_schedules 
WHERE client_id = <client_id>;
```
Expected: One row with `next_run` approximately 3 minutes after the current time

### Test Case 2: Verify Scan Execution

**Steps:**
1. After scheduling a scan (Test Case 1), wait for 3 minutes
2. Ensure `scripts/cron_scanner.sh` is executed (either via cron or manually):
   ```bash
   php /var/www/html/asd002/scripts/cron_scanner.sh
   ```
3. Check the logs at `/opt/asd002-logs/cron_scanner.log`
4. Refresh the client page and check if the scan appears in the calendar

**Expected Results:**
- Cron scanner creates a new scan entry in the `scans` table
- Scan executes with status 'running' then 'done'
- Scan results appear on the client page calendar
- The scan uses the enabled tools from `asset_scan_settings`

**Database Verification (Optional):**
```sql
-- Check if scan was created and executed
SELECT id, client_id, scan_date, scheduled, status 
FROM scans 
WHERE client_id = <client_id> 
AND scheduled = true 
ORDER BY scan_date DESC 
LIMIT 1;

-- Check if next_run was cleared after execution
SELECT id, client_id, next_run 
FROM scan_schedules 
WHERE client_id = <client_id>;
```
Expected: 
- Scan entry with `scheduled=true` and `status='done'`
- `next_run` field in scan_schedules should be NULL (cleared after execution)

### Test Case 3: Schedule Multiple Times (Overwrite)

**Steps:**
1. Schedule a scan (click "Lancer le scan dans 3 minutes")
2. Immediately schedule another scan (click the button again)
3. Check the database

**Expected Results:**
- Only one scheduled scan entry exists for the client
- The `next_run` timestamp is updated to the most recent schedule (3 minutes from the second click)
- Previous schedule is overwritten

**Database Verification:**
```sql
SELECT id, client_id, next_run, updated_at 
FROM scan_schedules 
WHERE client_id = <client_id>;
```
Expected: Only one row with the most recent `next_run` timestamp

### Test Case 4: Compare with "Scan Now" Behavior

**Steps:**
1. Configure specific tools/assets in "Paramètres assets & outils"
2. Schedule a scan using "Lancer le scan dans 3 minutes"
3. Wait for execution (or manually trigger cron_scanner.sh after 3 minutes)
4. Click "Lancer un scan maintenant"
5. Compare the results of both scans

**Expected Results:**
- Both scans should use the same assets and tools
- Both scans should produce similar results (assuming assets haven't changed)
- Both scans should appear in the calendar with the same scan types

### Test Case 5: No Assets/Tools Configured

**Steps:**
1. Ensure a client has no assets or all tools are disabled in asset_scan_settings
2. Schedule a scan
3. Wait for execution

**Expected Results:**
- Scan is created but no tools are executed (empty scan)
- No errors occur
- Scan status becomes 'done' immediately

## Security Considerations

### Authentication/Authorization
- The scheduled scan feature reuses the same authentication/authorization as the existing "scan now" button
- Users must be logged in and have access to the client to schedule scans
- The client_id is validated from the URL parameter

### CSRF Protection
- Forms use POST method (CSRF token should be added if available in the system)
- No CSRF tokens were found in the existing "scan now" implementation, so none were added to maintain consistency

### SQL Injection Prevention
- All database queries use prepared statements with parameterized queries
- User input (`client_id`) is properly escaped and validated

## Troubleshooting

### Scan Not Executing After 3 Minutes
**Possible Causes:**
1. Cron job not configured or not running
2. Check if `cron_scanner.sh` is being executed every minute
3. Verify `next_run` timestamp in database is in the past

**Solution:**
```bash
# Manually execute the cron scanner
php /var/www/html/asd002/scripts/cron_scanner.sh

# Check cron configuration
crontab -l | grep cron_scanner
```

### Success Message Not Appearing
**Possible Causes:**
1. JavaScript disabled
2. URL parameters not being passed correctly

**Solution:**
- Check browser console for errors
- Verify URL contains `scan_scheduled=1&scheduled_time=...`

### Logs Location
- Application logs: `/opt/asd002-logs/cron_scanner.log`
- PHP execution logs: `/opt/asd002-logs/php_exec.log`
- Scan runner logs: `/opt/asd002-logs/scan_runner_*.log`

## Notes for Developers

### Code Reusability
The implementation reuses the existing scan execution logic from the "scan now" button:
- Same database structure
- Same asset/tool selection logic
- Same scan scripts execution

### Future Enhancements
Possible improvements:
1. Add custom scheduling times (not just 3 minutes)
2. Add CSRF token protection
3. Add validation to prevent scheduling if no assets/tools are configured
4. Add email notification when scheduled scan completes
5. Show scheduled scans in a dedicated UI section

### Migration Note
No database migration is required as the feature uses existing tables:
- `scan_schedules` (already exists)
- `scans` (already exists)
- `asset_scan_settings` (already exists)
