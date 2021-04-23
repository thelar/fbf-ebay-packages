<?php
/**
 * Class responsible for scheduling and un-scheduling events (cron jobs).
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Plugin_Name
 * @subpackage Plugin_Name/includes
 */
/**
 * Class responsible for scheduling and un-scheduling events (cron jobs).
 *
 * This class defines all code necessary to schedule and un-schedule cron jobs.
 *
 * @since      1.0.0
 * @package    Plugin_Name
 * @subpackage Plugin_Name/includes
 * @author     Your Name <email@example.com>
 */
class Fbf_Ebay_Packages_Cron {
    const FBF_EBAY_PACKAGES_EVENT_DAILY_HOOK = 'fbf_ebay_packages_event_daily';
    const FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK = 'fbf_ebay_packages_event_hourly';
    /**
     * Check if already scheduled, and schedule if not.
     */
    public static function schedule() {
        if ( ! self::next_scheduled_hourly() ) {
            self::hourly_schedule();
        }
    }
    /**
     * Unschedule.
     */
    public static function unschedule() {
        wp_clear_scheduled_hook( self::FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK );
        wp_clear_scheduled_hook( self::FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK, [self::FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK] );
        wp_clear_scheduled_hook( self::FBF_EBAY_PACKAGES_EVENT_DAILY_HOOK );
        wp_clear_scheduled_hook( self::FBF_EBAY_PACKAGES_EVENT_DAILY_HOOK, [self::FBF_EBAY_PACKAGES_EVENT_DAILY_HOOK] );
    }
    /**
     * @return false|int Returns false if not scheduled, or timestamp of next run.
     */
    private static function next_scheduled_daily() {
        return wp_next_scheduled( self::FBF_EBAY_PACKAGES_EVENT_DAILY_HOOK );
    }
    /**
     * Create new schedule.
     */
    private static function daily_schedule() {
        wp_schedule_event( time(), 'daily', self::FBF_EBAY_PACKAGES_EVENT_DAILY_HOOK );
    }
    /**
     * @return false|int Returns false if not scheduled, or timestamp of next run.
     */
    private static function next_scheduled_hourly() {
        return wp_next_scheduled( self::FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK );
    }
    /**
     * Create new schedule.
     */
    private static function hourly_schedule() {
        wp_schedule_event( time(), 'hourly', self::FBF_EBAY_PACKAGES_EVENT_HOURLY_HOOK );
    }
}
