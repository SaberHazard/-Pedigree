package ir.pedigree.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/**
 * پس از روشن شدن دوباره گوشی، به‌روزرسانی اپ یا تغییر ساعت/منطقه زمانی، زنگ‌های ذخیره‌شده دوباره تنظیم می‌شوند
 * (اندروید در این مواقع همه زنگ‌های AlarmManager را پاک یا جابه‌جا می‌کند). فقط پیام‌های سیستمی پذیرفته می‌شوند.
 */
public class BootReceiver extends BroadcastReceiver {

    @Override
    public void onReceive(Context context, Intent intent) {
        String action = intent == null ? null : intent.getAction();
        if (Intent.ACTION_BOOT_COMPLETED.equals(action)
                || Intent.ACTION_MY_PACKAGE_REPLACED.equals(action)
                || Intent.ACTION_TIME_CHANGED.equals(action)
                || Intent.ACTION_TIMEZONE_CHANGED.equals(action)
                || "android.app.action.SCHEDULE_EXACT_ALARM_PERMISSION_STATE_CHANGED".equals(action)) {
            AlarmScheduler.scheduleSaved(context);
        }
    }
}
