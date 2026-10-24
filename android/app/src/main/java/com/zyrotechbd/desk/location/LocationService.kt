package com.zyrotechbd.desk.location

import android.annotation.SuppressLint
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Build
import android.os.IBinder
import android.os.Looper
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
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Runs while the server says to send location (see [FieldTracker]), with a quiet "ডিউটি চলছে" notification: no sound,
 * lowest importance. Takes a point every interval (1 minute while a job runs or before reaching the office, 2 on duty),
 * the better of GPS and network, and stops once the server says duty is over.
 */
class LocationService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var listening = 0
    private var best: Location? = null
    private var lastKept = 0L

    private val listener = LocationListener { l: Location -> onFix(l) }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel(CHANNEL, "ডিউটি", NotificationManager.IMPORTANCE_MIN).apply {
            setSound(null, null)
            enableVibration(false)
            setShowBadge(false)
        })
        val tap = PendingIntent.getActivity(this, 7, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        val n = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_desk)
            .setColor(Primary.toArgb())
            .setContentTitle("ডিউটি চলছে")
            .setPriority(NotificationCompat.PRIORITY_MIN)
            .setSilent(true)
            .setOngoing(true)
            .setShowWhen(false)
            .setContentIntent(tap)
            .build()
        val type = if (Build.VERSION.SDK_INT >= 29) ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION else 0
        if (runCatching { ServiceCompat.startForeground(this, NOTIFY_ID, n, type) }.isFailure) {
            stopSelf()
            return
        }
        running = true
        // send what waits and ask whether to go on, every few intervals even without a fix (duty may have ended)
        scope.launch {
            while (true) {
                delay(FieldTracker.interval(this@LocationService) * 1000L)
                sendBest()
                val st = FieldTracker.flush(this@LocationService)
                if (st != null && !st.track) return@launch stopSelf()
                android.os.Handler(Looper.getMainLooper()).post { listen() }
            }
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        listen()
        return START_STICKY
    }

    @SuppressLint("MissingPermission")
    private fun listen() {
        val interval = FieldTracker.interval(this).coerceAtLeast(30)
        if (listening == interval) return
        if (!FieldTracker.hasPermission(this)) return stopSelf()
        val lm = getSystemService(LocationManager::class.java) ?: return stopSelf()
        lm.removeUpdates(listener)
        // fixes come a few times per interval; the best one in each interval is kept
        listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER).forEach { p ->
            runCatching { lm.requestLocationUpdates(p, interval * 1000L / 3, 0f, listener, Looper.getMainLooper()) }
        }
        listening = interval
    }

    private fun onFix(l: Location) {
        val b = best
        if (b == null || acc(l) <= acc(b) || l.time - b.time > 60_000) best = l
        if (System.currentTimeMillis() - lastKept >= FieldTracker.interval(this) * 1000L) scope.launch { sendBest() }
    }

    private fun acc(l: Location) = if (l.hasAccuracy()) l.accuracy else Float.MAX_VALUE

    private suspend fun sendBest() {
        val l = best ?: return
        best = null
        lastKept = System.currentTimeMillis()
        FieldTracker.keep(this, FieldTracker.point(this, l))
        FieldTracker.flush(this)
    }

    override fun onDestroy() {
        running = false
        runCatching { getSystemService(LocationManager::class.java)?.removeUpdates(listener) }
        scope.cancel()
        super.onDestroy()
    }

    companion object {
        const val CHANNEL = "field_duty"
        const val NOTIFY_ID = 4107

        @Volatile var running = false

        /** Start it (the app open, or the phone allows it from the background); false when Android refused. */
        fun start(context: Context): Boolean {
            if (!FieldTracker.hasPermission(context)) return false
            return runCatching { ContextCompat.startForegroundService(context, Intent(context, LocationService::class.java)) }.isSuccess
        }

        fun stop(context: Context) {
            if (running) context.stopService(Intent(context, LocationService::class.java))
        }
    }
}
