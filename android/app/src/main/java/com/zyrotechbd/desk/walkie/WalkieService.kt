package com.zyrotechbd.desk.walkie

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.net.wifi.WifiManager
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import androidx.compose.ui.graphics.toArgb
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import com.zyrotechbd.desk.MainActivity
import com.zyrotechbd.desk.R
import com.zyrotechbd.desk.ui.theme.Primary
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch

/**
 * Keeps the walkie-talkie listening with the app closed and the phone locked: a quiet "ওয়াকি-টকি চালু" notification
 * (who is talking, and a বন্ধ button), the CPU and Wi-Fi kept awake. Android lets a service use the microphone in the
 * background only when it was started (or refreshed) while the app was open; one started by the 10:00 push listens
 * only, until the member opens the app once.
 */
class WalkieService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private var wake: PowerManager.WakeLock? = null
    private var wifi: WifiManager.WifiLock? = null
    private var mic = false

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        getSystemService(NotificationManager::class.java).createNotificationChannel(
            NotificationChannel(CHANNEL, "ওয়াকি-টকি", NotificationManager.IMPORTANCE_LOW).apply {
                setSound(null, null)
                enableVibration(false)
                setShowBadge(false)
            })
        wake = getSystemService(PowerManager::class.java)?.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "zyro:walkie")
            ?.apply { setReferenceCounted(false); acquire() }
        wifi = (applicationContext.getSystemService(Context.WIFI_SERVICE) as? WifiManager)?.let { wm ->
            @Suppress("DEPRECATION")
            val mode = if (Build.VERSION.SDK_INT >= 29) WifiManager.WIFI_MODE_FULL_LOW_LATENCY else WifiManager.WIFI_MODE_FULL_HIGH_PERF
            wm.createWifiLock(mode, "zyro:walkie").apply { setReferenceCounted(false); acquire() }
        }
        running = true
        scope.launch {
            Walkie.state.map { Triple(it.group?.name, it.name(it.talker), it.connected to it.onAir) }.distinctUntilChanged()
                .collect { notifyNow() }
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_OFF) {
            Walkie.markOff()
            stopSelf()
            return START_NOT_STICKY
        }
        val company = intent?.getStringExtra(EXTRA_COMPANY).orEmpty().ifEmpty { Walkie.state.value.company }
        if (company.isEmpty()) {
            stopSelf()
            return START_NOT_STICKY
        }
        // the microphone may be added only while the app is in front (Android 14); a push start just listens
        mic = mic || (intent?.getBooleanExtra(EXTRA_FOREGROUND, false) == true && Walkie.hasMic(this))
        val type = if (Build.VERSION.SDK_INT >= 30) {
            ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK or (if (mic) ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE else 0)
        } else 0
        if (runCatching { ServiceCompat.startForeground(this, NOTIFY_ID, build(), type) }.isFailure) {
            stopSelf()
            return START_NOT_STICKY
        }
        Walkie.connect(company)
        return START_REDELIVER_INTENT
    }

    private fun build(): android.app.Notification {
        val s = Walkie.state.value
        val open = PendingIntent.getActivity(this, 8, Intent(this, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            .putExtra(MainActivity.EXTRA_WALKIE, true), PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        val off = PendingIntent.getService(this, 9, Intent(this, WalkieService::class.java).setAction(ACTION_OFF),
            PendingIntent.FLAG_IMMUTABLE)
        val talker = s.name(s.talker)
        val text = when {
            s.onAir -> "আপনি কথা বলছেন"
            talker != null -> "$talker কথা বলছেন"
            !s.connected -> "সংযোগ হচ্ছে…"
            !mic -> "শুনছে · কথা বলতে অ্যাপ খুলুন"
            else -> "শুনছে"
        }
        return NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_desk)
            .setColor(Primary.toArgb())
            .setContentTitle("ওয়াকি-টকি" + (s.group?.name?.let { ": $it" } ?: ""))
            .setContentText(text)
            .setOngoing(true)
            .setSilent(true)
            .setShowWhen(false)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setContentIntent(open)
            .addAction(0, "বন্ধ করুন", off)
            .build()
    }

    private fun notifyNow() {
        if (running) runCatching { getSystemService(NotificationManager::class.java).notify(NOTIFY_ID, build()) }
    }

    override fun onDestroy() {
        running = false
        Walkie.disconnect()
        scope.cancel()
        runCatching { wake?.release() }
        runCatching { wifi?.release() }
        super.onDestroy()
    }

    companion object {
        const val CHANNEL = "walkie"
        const val NOTIFY_ID = 4108
        private const val ACTION_OFF = "com.zyrotechbd.desk.walkie.OFF"
        private const val EXTRA_COMPANY = "company"
        private const val EXTRA_FOREGROUND = "foreground"

        @Volatile var running = false

        /** Start listening (or, from the open app, add the microphone to a running service); false when Android refused. */
        fun start(context: Context, company: String, foreground: Boolean): Boolean =
            runCatching {
                ContextCompat.startForegroundService(context, Intent(context, WalkieService::class.java)
                    .putExtra(EXTRA_COMPANY, company).putExtra(EXTRA_FOREGROUND, foreground))
            }.isSuccess

        fun stop(context: Context) {
            context.stopService(Intent(context, WalkieService::class.java))
        }
    }
}
