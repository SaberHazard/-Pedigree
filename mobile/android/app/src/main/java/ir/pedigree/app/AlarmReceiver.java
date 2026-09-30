package ir.pedigree.app;

import android.Manifest;
import android.app.Notification;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;

import androidx.core.app.NotificationCompat;
import androidx.core.content.ContextCompat;

/**
 * زمان هشدار رسیده: اعلان پرصدا (صدای زنگ هشدار گوشی) که با لمس آن همان روز در تقویم باز می‌شود.
 * این گیرنده export نشده؛ فقط AlarmManager از طرف خود اپ آن را صدا می‌زند.
 */
public class AlarmReceiver extends BroadcastReceiver {

    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null || !AlarmScheduler.ACTION_ALARM.equals(intent.getAction())) {
            return;
        }
        String key = intent.getStringExtra(AlarmScheduler.EXTRA_KEY);
        if (key == null || !AlarmScheduler.KEY.matcher(key).matches()) {
            return;
        }
        AlarmScheduler.forget(context, key);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU
                && ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            return;
        }
        String title = AlarmScheduler.shorten(intent.getStringExtra(AlarmScheduler.EXTRA_TITLE), 120);
        String body = AlarmScheduler.shorten(intent.getStringExtra(AlarmScheduler.EXTRA_BODY), 400);
        String link = intent.getStringExtra(AlarmScheduler.EXTRA_LINK);
        if (link == null || !AlarmScheduler.LINK.matcher(link).matches()) {
            link = "#/calendar";
        }

        AlarmScheduler.ensureChannel(context);
        Intent open = new Intent(context, MainActivity.class)
                .setAction(Intent.ACTION_VIEW)
                .setData(Uri.parse(BuildConfig.BASE_URL + link))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        PendingIntent content = PendingIntent.getActivity(context, key.hashCode(), open,
                PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);

        Notification notification = new NotificationCompat.Builder(context, AlarmScheduler.CHANNEL)
                .setSmallIcon(R.drawable.ic_stat_alarm)
                .setContentTitle(title.isEmpty() ? context.getString(R.string.app_name) : title)
                .setContentText(body)
                .setStyle(new NotificationCompat.BigTextStyle().bigText(body))
                .setCategory(NotificationCompat.CATEGORY_ALARM)
                .setPriority(NotificationCompat.PRIORITY_MAX)
                .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
                .setAutoCancel(true)
                .setContentIntent(content)
                .build();
        NotificationManager nm = (NotificationManager) context.getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm != null) {
            nm.notify(key.hashCode(), notification);
        }
    }
}
