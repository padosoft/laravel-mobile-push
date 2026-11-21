<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

/** The `.env` variables a first setup needs, rendered by `mobile-push:install`. */
final class EnvChecklist
{
    /** Environment variable of each optional module (the module switch). */
    public const MODULE_VARIABLES = [
        'devices' => ['MOBILE_PUSH_DEVICES_ENABLED=true', 'device registry: MobilePush::to($user)'],
        'live-activities' => ['MOBILE_PUSH_LIVE_ACTIVITIES_ENABLED=true', 'Live Activities registry and channels'],
        'logs' => ['MOBILE_PUSH_LOG_SINKS=database', 'send log in the mobile_push_logs table (use log,database to also write to a log channel)'],
        'campaigns' => ['MOBILE_PUSH_CAMPAIGNS_ENABLED=true', 'campaigns'],
        'receipts' => ['MOBILE_PUSH_RECEIPTS_ENABLED=true', 'client receipts (needs the durable profile)'],
    ];

    /**
     * @param  list<string>  $modules
     * @return list<string>
     */
    public static function lines(array $modules, string $profile): array
    {
        $lines = [
            '# --- one APNs connection (iOS), token authentication with a .p8 key ---',
            'MOBILE_PUSH_APNS_TEAM_ID=            # 10-character Apple team id (developer account, Membership)',
            'MOBILE_PUSH_APNS_KEY_ID=             # 10-character id of the .p8 key',
            'MOBILE_PUSH_APNS_KEY_PATH=           # absolute path of AuthKey_XXXX.p8 (or use MOBILE_PUSH_APNS_KEY_BASE64=)',
            'MOBILE_PUSH_APNS_BUNDLE_ID=          # bundle id of the app, e.g. com.example.shop',
            'MOBILE_PUSH_APNS_ENVIRONMENT=production   # production, or sandbox for Xcode / TestFlight-less debug builds',
            '',
            '# --- one FCM connection (Android) ---',
            'MOBILE_PUSH_FCM_CREDENTIALS_PATH=    # absolute path of the service-account JSON (or MOBILE_PUSH_FCM_CREDENTIALS_BASE64=)',
            'MOBILE_PUSH_FCM_PROJECT_ID=          # Firebase project id (optional: read from the JSON when empty)',
            '',
            '# --- behaviour ---',
            'MOBILE_PUSH_MODE=auto                # auto = live only when APP_ENV=production, otherwise simulated (nothing is sent)',
            'MOBILE_PUSH_DELIVERY_PROFILE='.$profile.'   # light or durable',
        ];

        foreach ($modules as $module) {
            if (isset(self::MODULE_VARIABLES[$module])) {
                [$variable, $what] = self::MODULE_VARIABLES[$module];
                $lines[] = $variable.'   # '.$what;
            }
        }

        $lines[] = '';
        $lines[] = "# Then uncomment the 'ios' and 'android' connections in config/mobile-push.php: they read the variables above.";

        return $lines;
    }
}
