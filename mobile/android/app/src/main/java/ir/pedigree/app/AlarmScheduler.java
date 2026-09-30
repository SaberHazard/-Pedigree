package ir.pedigree.app;

import android.app.AlarmManager;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.media.AudioAttributes;
import android.media.RingtoneManager;
import android.net.Uri;
import android.os.Build;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.util.regex.Pattern;

/**
 * هشدارهای محلی گوشی: فهرست زنگ‌های ۳۰ روز آینده (از سایت) با AlarmManager سر ثانیه زده می‌شوند؛
 * حتی وقتی اپ بسته است، گوشی در حالت کم‌مصرف است یا اینترنت ندارد. پس از روشن شدن دوباره گوشی،
 * به‌روزرسانی اپ یا تغییر ساعت/منطقه زمانی دوباره تنظیم می‌شوند.
 *
 * امنیت: فقط داده سایت خودمان (پل جاوااسکریپت بررسی می‌کند)، حداکثر ۶۴ مورد، حداکثر ۶۲ روز آینده، کلید و لینک
 * با الگوی سخت‌گیرانه و متن کوتاه‌شده؛ گیرنده زنگ export نشده و برنامه‌های دیگر نمی‌توانند اعلان جعلی بسازند.
 */
final class AlarmScheduler {

    static final String CHANNEL = "alarms";
    static final String ACTION_ALARM = BuildConfig.APPLICATION_ID + ".ALARM";
    static final String EXTRA_KEY = "key";
    static final String EXTRA_TITLE = "title";
    static final String EXTRA_BODY = "body";
    static final String EXTRA_LINK = "link";

    private static final String PREFS = "alarms";
    private static final String KEY_ITEMS = "items";
    private static final int MAX_ITEMS = 64;
    private static final long MAX_AHEAD_MS = 62L * 24 * 3600 * 1000;
    static final Pattern KEY = Pattern.compile("[A-Za-z0-9-]{1,64}");
    static final Pattern LINK = Pattern.compile("#/[A-Za-z0-9/?=&._@%-]{0,200}");

    private AlarmScheduler() {
    }

    /** جایگزینی کامل فهرست زنگ‌ها با فهرست تازه سایت؛ تعداد زنگ‌های تنظیم‌شده */
    static int replaceAll(Context context, String json) {
        JSONArray clean = new JSONArray();
        try {
            JSONArray items = new JSONArray(json);
            long now = System.currentTimeMillis();
            for (int i = 0; i < items.length() && clean.length() < MAX_ITEMS; i++) {
                JSONObject item = items.optJSONObject(i);
                JSONObject valid = item == null ? null : validate(item, now);
                if (valid != null) {
                    clean.put(valid);
                }
            }
        } catch (JSONException e) {
            return -1;
        }
        cancelSaved(context);
        prefs(context).edit().putString(KEY_ITEMS, clean.toString()).apply();
        scheduleSaved(context);
        return clean.length();
    }

    /** تنظیم دوباره زنگ‌های ذخیره‌شده آینده (پس از روشن شدن گوشی یا تغییر ساعت) */
    static void scheduleSaved(Context context) {
        AlarmManager manager = (AlarmManager) context.getSystemService(Context.ALARM_SERVICE);
        if (manager == null) {
            return;
        }
        ensureChannel(context);
        JSONArray items = saved(context);
        JSONArray future = new JSONArray();
        long now = System.currentTimeMillis();
        for (int i = 0; i < items.length(); i++) {
            JSONObject item = items.optJSONObject(i);
            if (item == null || item.optLong("at") <= now) {
                continue;
            }
            future.put(item);
            PendingIntent pending = pending(context, item, true);
            if (pending == null) {
                continue;
            }
            long at = item.optLong("at");
            try {
                if (exactAllowed(context)) {
                    manager.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, pending);
                } else {
                    // بدون اجازه «زنگ دقیق»: اندروید ممکن است چند دقیقه جابه‌جا کند
                    manager.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, pending);
                }
            } catch (SecurityException e) {
                manager.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, pending);
            }
        }
        prefs(context).edit().putString(KEY_ITEMS, future.toString()).apply();
    }

    /** زنگ زده شد؛ از فهرست ذخیره حذف شود */
    static void forget(Context context, String key) {
        JSONArray items = saved(context);
        JSONArray rest = new JSONArray();
        for (int i = 0; i < items.length(); i++) {
            JSONObject item = items.optJSONObject(i);
            if (item != null && !key.equals(item.optString("key"))) {
                rest.put(item);
            }
        }
        prefs(context).edit().putString(KEY_ITEMS, rest.toString()).apply();
    }

    /** اجازه زنگ دقیق دارد؟ (اندروید ۱۲ به بعد) */
    static boolean exactAllowed(Context context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) {
            return true;
        }
        AlarmManager manager = (AlarmManager) context.getSystemService(Context.ALARM_SERVICE);
        return manager != null && manager.canScheduleExactAlarms();
    }

    /** کانال اعلان «هشدارها» با صدای زنگ هشدار گوشی و لرزش */
    static void ensureChannel(Context context) {
        NotificationManager nm = (NotificationManager) context.getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm == null || nm.getNotificationChannel(CHANNEL) != null) {
            return;
        }
        NotificationChannel channel = new NotificationChannel(CHANNEL, context.getString(R.string.alarm_channel), NotificationManager.IMPORTANCE_HIGH);
        channel.setDescription(context.getString(R.string.alarm_channel_description));
        Uri sound = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM);
        if (sound == null) {
            sound = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION);
        }
        channel.setSound(sound, new AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_ALARM)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build());
        channel.enableVibration(true);
        channel.setVibrationPattern(new long[]{0, 600, 300, 600, 300, 600});
        channel.setLockscreenVisibility(android.app.Notification.VISIBILITY_PRIVATE);
        nm.createNotificationChannel(channel);
    }

    private static JSONObject validate(JSONObject item, long now) throws JSONException {
        String key = item.optString("key", "");
        long at = item.optLong("at", 0);
        String link = item.optString("link", "");
        if (!KEY.matcher(key).matches() || at <= now || at > now + MAX_AHEAD_MS) {
            return null;
        }
        if (!LINK.matcher(link).matches()) {
            link = "#/calendar";
        }
        JSONObject out = new JSONObject();
        out.put("key", key);
        out.put("at", at);
        out.put("title", shorten(item.optString("title", ""), 120));
        out.put("body", shorten(item.optString("body", ""), 400));
        out.put("link", link);
        return out;
    }

    static String shorten(String text, int max) {
        String clean = text == null ? "" : text.replaceAll("[\\p{Cntrl}&&[^\n]]", " ").trim();
        return clean.length() > max ? clean.substring(0, max) : clean;
    }

    private static void cancelSaved(Context context) {
        AlarmManager manager = (AlarmManager) context.getSystemService(Context.ALARM_SERVICE);
        JSONArray items = saved(context);
        for (int i = 0; i < items.length(); i++) {
            JSONObject item = items.optJSONObject(i);
            PendingIntent pending = item == null ? null : pending(context, item, false);
            if (pending != null) {
                if (manager != null) {
                    manager.cancel(pending);
                }
                pending.cancel();
            }
        }
    }

    private static PendingIntent pending(Context context, JSONObject item, boolean create) {
        Intent intent = new Intent(context, AlarmReceiver.class)
                .setAction(ACTION_ALARM)
                // هر زنگ یک PendingIntent جدا (بر اساس کلید)
                .setData(Uri.parse("pedigree-alarm://" + item.optString("key")))
                .putExtra(EXTRA_KEY, item.optString("key"))
                .putExtra(EXTRA_TITLE, item.optString("title"))
                .putExtra(EXTRA_BODY, item.optString("body"))
                .putExtra(EXTRA_LINK, item.optString("link"));
        int flags = PendingIntent.FLAG_IMMUTABLE | (create ? PendingIntent.FLAG_UPDATE_CURRENT : PendingIntent.FLAG_NO_CREATE);
        return PendingIntent.getBroadcast(context, 0, intent, flags);
    }

    private static JSONArray saved(Context context) {
        try {
            return new JSONArray(prefs(context).getString(KEY_ITEMS, "[]"));
        } catch (JSONException e) {
            return new JSONArray();
        }
    }

    private static SharedPreferences prefs(Context context) {
        return context.getApplicationContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }
}
