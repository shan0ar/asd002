# Feature Implementation: Schedule Scan in 3 Minutes

## Summary
This PR adds a new button "Lancer le scan dans 3 minutes" (Launch scan in 3 minutes) that allows users to schedule a scan to run exactly 3 minutes after clicking the button. The scheduled scan uses the same configuration as the "Launch scan now" button.

## Changes Made

### 1. client.php
**New POST handler (lines 180-205):**
- Handles `action=schedule_scan_3min` POST requests
- Uses database timestamp function `NOW() + INTERVAL '3 minutes'` to avoid race conditions
- Updates or inserts into `scan_schedules` table
- Returns scheduled timestamp using RETURNING clause
- Redirects with success message

**New UI Button (lines 849-858):**
- Added orange/yellow button next to existing "Lancer un scan maintenant"
- Uses flexbox layout for side-by-side display
- Includes distinct styling to differentiate from immediate scan button

**Success Message (lines 861-867):**
- Green confirmation message showing scheduled time
- Displayed when redirected with `scan_scheduled=1` parameter

**Security Enhancement (lines 163-170):**
- Added tool whitelist validation to prevent command injection
- Logs rejected tools for security auditing

### 2. scripts/cron_scanner.sh
**SQL Injection Fix (line 6):**
- Changed from direct string interpolation to prepared statement
- Uses `NOW()` database function instead of PHP date formatting

**Command Injection Protection (lines 39-45):**
- Added whitelist validation for allowed tools
- Same list as client.php: whois, amass, dig_bruteforce, dig_mx, dig_txt, dig_a, whatweb, nmap, dork
- Logs rejected tools for security monitoring

**Logic Update (lines 18-47):**
- Now uses `asset_scan_settings` table (same as scan_now button)
- Ensures scheduled scans execute with the same tool/asset configuration as immediate scans
- More consistent behavior across scheduled and immediate scans

### 3. TESTING_SCHEDULED_SCAN.md
Comprehensive testing documentation including:
- Feature overview and implementation details
- 5 detailed test cases with steps and expected results
- Database verification queries
- Security considerations
- Troubleshooting guide
- Developer notes and future enhancement suggestions

## Technical Details

### Database Tables Used
- **scan_schedules**: Stores scheduled scans with `next_run` timestamp
- **asset_scan_settings**: Determines which tools to run for which assets
- **scans**: Records scan execution results

### Security Measures
1. **SQL Injection Prevention**: All queries use prepared statements with parameter binding
2. **Command Injection Prevention**: Tool names validated against whitelist before execution
3. **Race Condition Prevention**: Timestamp calculated by database using `NOW() + INTERVAL`
4. **Authentication**: Reuses existing session authentication (requires login)
5. **Authorization**: Client ID validated from URL parameter

### No Database Migration Required
The feature uses existing database tables and columns:
- `scan_schedules.next_run` (already exists)
- `scan_schedules.frequency` (already exists)
- `scans` table (already exists)

## How It Works

1. **User Action**: User clicks "Lancer le scan dans 3 minutes" button
2. **Backend Processing**: 
   - POST request with `action=schedule_scan_3min`
   - Database calculates `next_run = NOW() + INTERVAL '3 minutes'`
   - Inserts/updates entry in `scan_schedules` table
3. **Confirmation**: Success message displays scheduled time
4. **Execution**: 
   - Cron job runs `cron_scanner.sh` every minute
   - Checks for scheduled scans where `next_run <= NOW()`
   - Creates scan entry and executes configured tools
   - Clears `next_run` after execution

## Testing Instructions

See `TESTING_SCHEDULED_SCAN.md` for comprehensive manual testing guide.

### Quick Test
1. Navigate to a client page: `client.php?id=<client_id>`
2. Ensure some tools are enabled in asset settings
3. Click "Lancer le scan dans 3 minutes"
4. Verify success message appears
5. Wait 3 minutes and verify scan executes

## Compatibility

- **Database**: PostgreSQL (uses RETURNING clause and INTERVAL syntax)
- **PHP Version**: Compatible with PHP 5.6+ (same as existing code)
- **Browser**: Works in all modern browsers (uses standard HTML forms)

## Deployment Notes

1. Ensure cron job is configured to run `scripts/cron_scanner.sh` every minute:
   ```
   * * * * * php /var/www/html/asd002/scripts/cron_scanner.sh >> /opt/asd002-logs/cron_scanner.log 2>&1
   ```

2. Verify log directories exist and are writable:
   - `/opt/asd002-logs/`

3. No database schema changes required

## Future Enhancements

Potential improvements for future PRs:
- Custom scheduling times (not just 3 minutes)
- CSRF token protection
- Validation to prevent scheduling if no assets/tools configured
- Email notification when scheduled scan completes
- UI section showing upcoming scheduled scans
- Cancel scheduled scan feature

## Code Review Feedback Addressed

All code review comments have been addressed:
- ✅ SQL injection vulnerability fixed (cron_scanner.sh line 6)
- ✅ Command injection vulnerability fixed (added whitelist validation)
- ✅ Race condition fixed (using database timestamp function)
- ℹ️ Database compatibility (PostgreSQL-specific RETURNING is intentional for this project)

## Security Summary

**Vulnerabilities Fixed:**
1. SQL injection in cron_scanner.sh - Fixed by using prepared statements
2. Command injection risk - Fixed by adding tool whitelist validation

**Security Measures:**
- All user inputs validated and sanitized
- Prepared statements used throughout
- Tool names validated against whitelist
- Authentication reused from existing implementation
- Logging of security events (rejected tools)

**No New Vulnerabilities Introduced:**
- CodeQL analysis passed (no issues detected)
- Manual security review completed
- All database queries use parameterized queries
- All shell arguments properly escaped
