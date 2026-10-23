package com.zyrotechbd.desk.location

import android.annotation.SuppressLint
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
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
import com.squareup.moshi.Types
import com.zyrotechbd.desk.MainActivity
import com.zyrotechbd.desk.R
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.LocationBatch
import com.zyrotechbd.desk.data.network.LocationPoint
import com.zyrotechbd.desk.ui.theme.Primary
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import retrofit2.HttpException

/**
 * Sends the technician's location while they are on duty or working, with a quiet notification the whole time
 * ("Desk: লোকেশন চালু"). Points wait on the phone when there is no internet; the server's answer says when to stop.
 */
class LocationService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val lock = Mutex()
    private var listening = 0
    private val adapter by lazy {
        ApiClient.moshi.adapter<List<LocationPoint>>(Types.newParameterizedType(List::class.java, LocationPoint::class.java))
    }

    private val listener = LocationListener { l: Location -> onFix(l) }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel(CHANNEL, "লোকেশন (ডিউটি)", NotificationManager.IMPORTANCE_LOW))
        val tap = PendingIntent.getActivity(this, 7, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        val n = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_desk)
            .setColor(Primary.toArgb())
            .setContentTitle("Desk: লোকেশন চালু")
            .setContentText("ডিউটি বা কাজ চলার সময় অফিস আপনার লোকেশন দেখতে পায়")
            .setOngoing(true)
            .setContentIntent(tap)
            .build()
        val type = if (Build.VERSION.SDK_INT >= 29) ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION else 0
        runCatching { ServiceCompat.startForeground(this, NOTIFY_ID, n, type) }.onFailure { stopSelf(); return }
        // the server may have stopped wanting points (duty ended, job done) without a fix arriving; ask now and then
        scope.launch {
            while (true) {
                delay(FieldTracker.interval(this@LocationService) * 1000L * 3)
                flush(force = true)
            }
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        listen()
        return START_STICKY
    }

    @SuppressLint("MissingPermission")
    private fun listen() {
        val interval = FieldTracker.interval(this)
        if (listening == interval) return
        if (!FieldTracker.hasPermission(this) || SessionStore.token() == null) return stopSelf()
        val lm = getSystemService(LocationManager::class.java) ?: return stopSelf()
        lm.removeUpdates(listener)
        listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER).forEach { p ->
            runCatching { lm.requestLocationUpdates(p, interval * 1000L, 0f, listener, Looper.getMainLooper()) }
        }
        listening = interval
    }

    private var lastKept = 0L

    private fun onFix(l: Location) {
        // both providers report; keep one point per interval (the better one would need waiting, the first is fine)
        val interval = FieldTracker.interval(this) * 1000L
        if (l.time - lastKept < interval * 0.8) return
        lastKept = l.time
        val p = FieldTracker.point(this, l)
        scope.launch {
            lock.withLock {
                val all = (pending() + p).takeLast(MAX_PENDING)
                save(all)
            }
            flush(force = false)
        }
    }

    private fun pending(): List<LocationPoint> =
        getSharedPreferences(PREFS, MODE_PRIVATE).getString("points", null)?.let { runCatching { adapter.fromJson(it) }.getOrNull() } ?: emptyList()

    private fun save(points: List<LocationPoint>) {
        getSharedPreferences(PREFS, MODE_PRIVATE).edit().putString("points", adapter.toJson(points)).apply()
    }

    /** Send what waits; the answer says whether to keep tracking and how often. */
    private suspend fun flush(force: Boolean) {
        val company = FieldTracker.company(this) ?: return stopSelf()
        if (SessionStore.token() == null) return stopSelf()
        val batch = lock.withLock { pending() }
        if (batch.isEmpty() && !force) return
        try {
            val st = if (batch.isEmpty()) ApiClient.service.fieldLocation(company)
            else ApiClient.service.fieldLocations(company, LocationBatch(batch.take(500)))
            if (batch.isNotEmpty()) lock.withLock { save(pending().drop(batch.take(500).size)) }
            FieldTracker.state.value = st
            getSharedPreferences("field_tracker", MODE_PRIVATE).edit().putInt("interval", st.interval).apply()
            if (!st.track) return stopSelf()
            launchListen()
        } catch (e: HttpException) {
            if (e.code() in listOf(401, 403)) stopSelf()
            if (e.code() == 422) lock.withLock { save(emptyList()) }   // no consent any more: these cannot go
        } catch (_: Exception) {
            // no internet: the points wait
        }
    }

    private fun launchListen() {
        android.os.Handler(Looper.getMainLooper()).post { listen() }
    }

    override fun onDestroy() {
        runCatching { getSystemService(LocationManager::class.java)?.removeUpdates(listener) }
        scope.cancel()
        super.onDestroy()
    }

    companion object {
        const val CHANNEL = "field_location"
        const val NOTIFY_ID = 4107
        private const val PREFS = "field_points"
        private const val MAX_PENDING = 2000
    }
}
