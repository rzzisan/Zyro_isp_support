package com.zyrotechbd.desk.location

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.BatteryManager
import android.os.Build
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.squareup.moshi.Types
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.LocationBatch
import com.zyrotechbd.desk.data.network.LocationPoint
import com.zyrotechbd.desk.data.network.LocationState
import com.zyrotechbd.desk.data.network.StartRequest
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withTimeoutOrNull
import retrofit2.HttpException
import java.time.Instant
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import kotlin.coroutines.resume

/** A location fix: where, how exact (m), and whether a fake-GPS app made it. */
data class Fix(val lat: Double, val lng: Double, val accuracy: Float?, val mock: Boolean)

/**
 * A field technician's location, as the privacy policy accepted at sign-in says. While the server says to send (duty
 * hours and not yet at the office, on duty, or a job running; FieldDuty on the server) [LocationService] runs with a
 * quiet "ডিউটি চলছে" notification and sends a point every 1-2 minutes; Android allows no regular location for a closed
 * app without one. A 15-minute WorkManager check starts it again when the phone stopped it, and sends a point itself when
 * it can't. Points wait on the phone while there is no internet. Starting and finishing work send the place too.
 */
object FieldTracker {
    private const val PREFS = "field_tracker"
    private const val WORK = "field-location"
    private val lock = Mutex()
    private val checking = Mutex()

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private val adapter by lazy {
        ApiClient.moshi.adapter<List<LocationPoint>>(Types.newParameterizedType(List::class.java, LocationPoint::class.java))
    }

    fun hasPermission(context: Context) =
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

    /** "Allow all the time": needed to read the location while the app is closed (Android 10+). */
    fun hasBackground(context: Context) = Build.VERSION.SDK_INT < 29 ||
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_BACKGROUND_LOCATION) == PackageManager.PERMISSION_GRANTED

    /** A field technician's company: keep the 15-minute check going and run one now. */
    fun schedule(context: Context, company: String) {
        prefs(context).edit().putString("company", company).apply()
        val wm = WorkManager.getInstance(context.applicationContext)
        val net = Constraints.Builder().setRequiredNetworkType(NetworkType.NOT_REQUIRED).build()
        wm.enqueueUniquePeriodicWork(WORK, ExistingPeriodicWorkPolicy.KEEP,
            PeriodicWorkRequestBuilder<FieldLocationWorker>(15, TimeUnit.MINUTES).setConstraints(net).build())
        wm.enqueueUniqueWork("$WORK-now", ExistingWorkPolicy.REPLACE, OneTimeWorkRequestBuilder<FieldLocationWorker>().build())
    }

    fun stop(context: Context) {
        LocationService.stop(context)
        val wm = WorkManager.getInstance(context.applicationContext)
        wm.cancelUniqueWork(WORK)
        wm.cancelUniqueWork("$WORK-now")
        prefs(context).edit().clear().apply()
    }

    /** Once per install: ask Android to leave the app out of battery saving, which otherwise stops the duty location. */
    @SuppressLint("BatteryLife")
    fun askBatteryOnce(context: Context) {
        val pm = context.getSystemService(Context.POWER_SERVICE) as? android.os.PowerManager ?: return
        if (pm.isIgnoringBatteryOptimizations(context.packageName) || context.getSharedPreferences("field_once", Context.MODE_PRIVATE).getBoolean("asked_battery", false)) return
        context.getSharedPreferences("field_once", Context.MODE_PRIVATE).edit().putBoolean("asked_battery", true).apply()
        runCatching {
            context.startActivity(android.content.Intent(android.provider.Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
                android.net.Uri.parse("package:" + context.packageName)))
        }
    }

    fun isMock(l: Location): Boolean =
        if (Build.VERSION.SDK_INT >= 31) l.isMock else @Suppress("DEPRECATION") l.isFromMockProvider

    private fun battery(context: Context): Int? =
        (context.getSystemService(Context.BATTERY_SERVICE) as? BatteryManager)
            ?.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)?.takeIf { it in 0..100 }

    /** One fix now: a fresh one within [timeoutMs], else a recent last-known one. */
    @SuppressLint("MissingPermission")
    suspend fun location(context: Context, timeoutMs: Long = 8_000): Location? {
        if (!hasPermission(context)) return null
        val lm = context.getSystemService(Context.LOCATION_SERVICE) as? LocationManager ?: return null
        val providers = listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
            .filter { runCatching { lm.isProviderEnabled(it) }.getOrDefault(false) }
        if (providers.isEmpty()) return null
        val fresh = if (Build.VERSION.SDK_INT >= 30) withTimeoutOrNull(timeoutMs) {
            suspendCancellableCoroutine<Location?> { cont ->
                val signal = android.os.CancellationSignal()
                cont.invokeOnCancellation { signal.cancel() }
                runCatching {
                    lm.getCurrentLocation(providers.first(), signal, Executors.newSingleThreadExecutor()) { cont.resume(it) }
                }.onFailure { cont.resume(null) }
            }
        } else null
        return fresh ?: providers.mapNotNull { runCatching { lm.getLastKnownLocation(it) }.getOrNull() }
            .filter { System.currentTimeMillis() - it.time < 10 * 60_000 }
            .minByOrNull { if (it.hasAccuracy()) it.accuracy else Float.MAX_VALUE }
    }

    /** A last-known fix from the last [maxAgeMs], without waiting for the GPS. */
    @SuppressLint("MissingPermission")
    fun recent(context: Context, maxAgeMs: Long = 60_000, maxAccuracy: Float = 50f): Location? {
        if (!hasPermission(context)) return null
        val lm = context.getSystemService(Context.LOCATION_SERVICE) as? LocationManager ?: return null
        return listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
            .mapNotNull { runCatching { lm.getLastKnownLocation(it) }.getOrNull() }
            .filter { System.currentTimeMillis() - it.time < maxAgeMs && it.hasAccuracy() && it.accuracy <= maxAccuracy }
            .minByOrNull { it.accuracy }
    }

    /**
     * The place for start / finish: a good fix (50 m) from the last minute right away (the job screen asks for one when it
     * opens), else at most 3 s for a fresh one, so the buttons don't wait on the GPS.
     */
    suspend fun fix(context: Context): Fix? =
        (recent(context) ?: location(context, 3_000))?.let { Fix(it.latitude, it.longitude, if (it.hasAccuracy()) it.accuracy else null, isMock(it)) }

    /** Wake the GPS when a job screen opens, so [fix] finds a recent place when a button is pressed. */
    suspend fun warm(context: Context) {
        location(context, 20_000)
    }

    fun startRequest(f: Fix?) = StartRequest(f?.lat, f?.lng, f?.accuracy, f?.mock)

    private fun pending(context: Context): List<LocationPoint> =
        prefs(context).getString("points", null)?.let { runCatching { adapter.fromJson(it) }.getOrNull() } ?: emptyList()

    private fun save(context: Context, points: List<LocationPoint>) {
        prefs(context).edit().putString("points", adapter.toJson(points.takeLast(2000))).apply()
    }

    fun company(context: Context): String? = prefs(context).getString("company", null)

    /** Seconds between points, as the server last said. */
    fun interval(context: Context): Int = prefs(context).getInt("interval", 300)

    fun point(context: Context, l: Location) = LocationPoint(l.latitude, l.longitude, if (l.hasAccuracy()) l.accuracy else null,
        isMock(l), battery(context), Instant.ofEpochMilli(l.time).toString())

    /** Keep a point until it is sent. */
    suspend fun keep(context: Context, p: LocationPoint) = lock.withLock { save(context, pending(context) + p) }

    /**
     * Send what waits (or just ask, when nothing does); the answer says whether to keep sending and how often.
     * Returns that answer, or null when the server could not be reached (the last answer stays).
     */
    suspend fun flush(context: Context): LocationState? {
        val company = company(context) ?: return null
        if (SessionStore.token() == null) { stop(context); return null }
        val batch = lock.withLock { pending(context) }.take(500)
        return try {
            val st = if (batch.isEmpty()) ApiClient.service.fieldLocation(company) else ApiClient.service.fieldLocations(company, LocationBatch(batch))
            if (batch.isNotEmpty()) lock.withLock { save(context, pending(context).drop(batch.size)) }
            prefs(context).edit().putBoolean("track", st.track).putInt("interval", st.interval).apply()
            st
        } catch (e: HttpException) {
            when (e.code()) {
                401, 403 -> stop(context)
                422 -> lock.withLock { save(context, emptyList()) }   // policy not accepted: these cannot go
            }
            null
        } catch (_: Exception) {
            null   // no internet: the points wait
        }
    }

    /**
     * One check (WorkManager, app start): ask the server; while it says to send, keep [LocationService] running, and when
     * the phone would not let it start, send one point from here. Runs one at a time, at most once a minute.
     */
    suspend fun check(context: Context) {
        if (company(context) == null) return
        if (!checking.tryLock()) return
        try {
            val now = System.currentTimeMillis()
            if (now - prefs(context).getLong("checked", 0) < 50_000) return
            prefs(context).edit().putLong("checked", now).apply()
            val track = flush(context)?.track ?: prefs(context).getBoolean("track", false)
            if (!track) return LocationService.stop(context)
            if (LocationService.running || LocationService.start(context)) return
            val l = location(context, 20_000) ?: return
            keep(context, point(context, l))
            flush(context)
        } finally {
            checking.unlock()
        }
    }
}

class FieldLocationWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        FieldTracker.check(applicationContext)
        return Result.success()
    }
}
