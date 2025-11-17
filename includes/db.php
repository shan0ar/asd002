<?php
function getDb() {
    static $db = null;
    if ($db === null) {
        $db = new PDO("pgsql:host=localhost;port=5432;dbname=osintapp", "thomas", "thomas");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $db;
}

/**
 * Calculate the next run date/time based on frequency and parameters
 * 
 * @param string $frequency The frequency type (weekly, monthly, quarterly, semiannual, annual)
 * @param int|null $day_of_week Day of week (0=Monday, 6=Sunday) for weekly frequency
 * @param string $time Time in HH:MM:SS format
 * @param string|null $from_date Optional starting date (default: now)
 * @return string Next run date/time in 'Y-m-d H:i:s' format
 */
function calculateNextRun($frequency, $day_of_week, $time, $from_date = null) {
    // Parse time (default to 00:00:00 if invalid)
    $time_parts = explode(':', $time ?: '00:00:00');
    $hour = isset($time_parts[0]) ? intval($time_parts[0]) : 0;
    $minute = isset($time_parts[1]) ? intval($time_parts[1]) : 0;
    $second = isset($time_parts[2]) ? intval($time_parts[2]) : 0;
    
    // Start from the given date or now
    $base = $from_date ? strtotime($from_date) : time();
    
    switch ($frequency) {
        case 'weekly':
            // Find next occurrence of the specified day of week
            $target_day = ($day_of_week !== null) ? intval($day_of_week) : 0; // Default to Monday
            // PHP's date('N') returns 1=Monday, 7=Sunday, we use 0=Monday, 6=Sunday
            $current_day = (intval(date('N', $base)) - 1) % 7; // Convert to 0-6
            
            $days_until = ($target_day - $current_day + 7) % 7;
            
            // If it's today, check if the time has passed
            if ($days_until === 0) {
                $current_time = intval(date('H', $base)) * 3600 + intval(date('i', $base)) * 60 + intval(date('s', $base));
                $target_time = $hour * 3600 + $minute * 60 + $second;
                
                if ($current_time >= $target_time) {
                    // Time has passed today, schedule for next week
                    $days_until = 7;
                }
            }
            
            if ($days_until === 0) {
                // Today, at the specified time
                $next = mktime($hour, $minute, $second, date('m', $base), date('d', $base), date('Y', $base));
            } else {
                // Future day
                $next = mktime($hour, $minute, $second, date('m', $base), date('d', $base) + $days_until, date('Y', $base));
            }
            break;
            
        case 'monthly':
            // Schedule for the same day of next month (or the last day if not available)
            $current_day_of_month = intval(date('d', $base));
            $current_time = intval(date('H', $base)) * 3600 + intval(date('i', $base)) * 60 + intval(date('s', $base));
            $target_time = $hour * 3600 + $minute * 60 + $second;
            
            // Start with current month
            $next_month = intval(date('m', $base));
            $next_year = intval(date('Y', $base));
            
            // Check if we need to go to next month
            if ($current_time >= $target_time) {
                $next_month++;
                if ($next_month > 12) {
                    $next_month = 1;
                    $next_year++;
                }
            }
            
            // Handle months with fewer days
            $days_in_month = intval(date('t', mktime(0, 0, 0, $next_month, 1, $next_year)));
            $target_day = min($current_day_of_month, $days_in_month);
            
            $next = mktime($hour, $minute, $second, $next_month, $target_day, $next_year);
            break;
            
        case 'quarterly':
            // Every 3 months
            $current_day_of_month = intval(date('d', $base));
            $current_time = intval(date('H', $base)) * 3600 + intval(date('i', $base)) * 60 + intval(date('s', $base));
            $target_time = $hour * 3600 + $minute * 60 + $second;
            
            $next_month = intval(date('m', $base));
            $next_year = intval(date('Y', $base));
            
            // Check if time has passed today
            if ($current_time >= $target_time) {
                $next_month += 3;
            }
            
            // Handle year overflow
            while ($next_month > 12) {
                $next_month -= 12;
                $next_year++;
            }
            
            // Handle months with fewer days
            $days_in_month = intval(date('t', mktime(0, 0, 0, $next_month, 1, $next_year)));
            $target_day = min($current_day_of_month, $days_in_month);
            
            $next = mktime($hour, $minute, $second, $next_month, $target_day, $next_year);
            break;
            
        case 'semiannual':
            // Every 6 months
            $current_day_of_month = intval(date('d', $base));
            $current_time = intval(date('H', $base)) * 3600 + intval(date('i', $base)) * 60 + intval(date('s', $base));
            $target_time = $hour * 3600 + $minute * 60 + $second;
            
            $next_month = intval(date('m', $base));
            $next_year = intval(date('Y', $base));
            
            // Check if time has passed today
            if ($current_time >= $target_time) {
                $next_month += 6;
            }
            
            // Handle year overflow
            while ($next_month > 12) {
                $next_month -= 12;
                $next_year++;
            }
            
            // Handle months with fewer days
            $days_in_month = intval(date('t', mktime(0, 0, 0, $next_month, 1, $next_year)));
            $target_day = min($current_day_of_month, $days_in_month);
            
            $next = mktime($hour, $minute, $second, $next_month, $target_day, $next_year);
            break;
            
        case 'annual':
            // Once a year
            $next_year = intval(date('Y', $base));
            $current_time = intval(date('H', $base)) * 3600 + intval(date('i', $base)) * 60 + intval(date('s', $base));
            $target_time = $hour * 3600 + $minute * 60 + $second;
            
            // Check if time has passed today
            if ($current_time >= $target_time) {
                $next_year++;
            }
            
            $next = mktime($hour, $minute, $second, date('m', $base), date('d', $base), $next_year);
            break;
            
        default:
            // Default to 1 week from now
            $next = mktime($hour, $minute, $second, date('m', $base), date('d', $base) + 7, date('Y', $base));
            break;
    }
    
    return date('Y-m-d H:i:s', $next);
}
?>
