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
 * A field technician's location, as the privacy policy accepted at sign-in says: every 15 minutes (WorkManager) the
 * phone asks the server whether to send (duty hours and not yet at the office, on duty, or a job running; FieldDuty
 * on the server) and sends a point when so. Points wait on the phone while there is no internet. Starting and finishing
 * work send the place too.
 */
object FieldTracker {
    private const val PREFS = "field_tracker"
    private const val WORK = "field-location"
    private val lock = Mutex()

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
        val wm = WorkManager.getInstance(context.applicationContext)
        wm.cancelUniqueWork(WORK)
        wm.cancelUniqueWork("$WORK-now")
        prefs(context).edit().clear().apply()
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

    suspend fun fix(context: Context): Fix? =
        location(context)?.let { Fix(it.latitude, it.longitude, if (it.hasAccuracy()) it.accuracy else null, isMock(it)) }

    fun startRequest(f: Fix?) = StartRequest(f?.lat, f?.lng, f?.accuracy, f?.mock)

    private fun pending(context: Context): List<LocationPoint> =
        prefs(context).getString("points", null)?.let { runCatching { adapter.fromJson(it) }.getOrNull() } ?: emptyList()

    private fun save(context: Context, points: List<LocationPoint>) {
        prefs(context).edit().putString("points", adapter.toJson(points.takeLast(2000))).apply()
    }

    /** One check: ask whether to send, and send a point (with any that waited) when so; offline, the last answer counts. */
    suspend fun check(context: Context) {
        val company = prefs(context).getString("company", null) ?: return
        if (SessionStore.token() == null) return stop(context)
        val track = try {
            ApiClient.service.fieldLocation(company).track.also { prefs(context).edit().putBoolean("track", it).apply() }
        } catch (e: HttpException) {
            if (e.code() == 403) stop(context)
            return
        } catch (_: Exception) {
            prefs(context).getBoolean("track", false)
        }
        if (!track) return
        val l = location(context, 20_000) ?: return
        val p = LocationPoint(l.latitude, l.longitude, if (l.hasAccuracy()) l.accuracy else null, isMock(l), battery(context),
            Instant.ofEpochMilli(l.time).toString())
        val batch = lock.withLock { (pending(context) + p).also { save(context, it) } }.take(500)
        try {
            ApiClient.service.fieldLocations(company, LocationBatch(batch))
            lock.withLock { save(context, pending(context).drop(batch.size)) }
        } catch (e: HttpException) {
            if (e.code() == 422) lock.withLock { save(context, emptyList()) }   // policy not accepted: these cannot go
        } catch (_: Exception) {
            // no internet: the points wait for the next check
        }
    }
}

class FieldLocationWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        FieldTracker.check(applicationContext)
        return Result.success()
    }
}
