package com.zyrotechbd.desk.location

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.BatteryManager
import android.os.Build
import androidx.core.content.ContextCompat
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.LocationPoint
import com.zyrotechbd.desk.data.network.LocationState
import com.zyrotechbd.desk.data.network.StartRequest
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull
import java.time.Instant
import java.util.concurrent.Executors
import kotlin.coroutines.resume

/** A location fix: where, how exact (m), and whether a fake-GPS app made it. */
data class Fix(val lat: Double, val lng: Double, val accuracy: Float?, val mock: Boolean)

/**
 * A field technician's location, told to them: after they agree to the notice, the phone sends points while they are on
 * duty or a job runs, from a foreground service whose notification shows the whole time. The server says when to
 * track and how often (LocationState.track / interval); points wait on the phone while there is no internet.
 */
object FieldTracker {
    /** The last answer from the server, for the "আমার কাজ" card. */
    val state = MutableStateFlow<LocationState?>(null)

    private const val PREFS = "field_tracker"

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun hasPermission(context: Context) =
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

    fun company(context: Context): String? = prefs(context).getString("company", null)

    fun interval(context: Context): Int = prefs(context).getInt("interval", 300)

    /** Ask the server whether to track now and start or stop the service to match. */
    suspend fun refresh(context: Context, company: String) {
        val st = runCatching { ApiClient.service.fieldLocation(company) }.getOrNull() ?: return
        apply(context, company, st)
    }

    /** Start or stop the service for a server answer. */
    fun apply(context: Context, company: String, st: LocationState) {
        state.value = st
        prefs(context).edit().putString("company", company).putInt("interval", st.interval).apply()
        val app = context.applicationContext
        if (st.track && hasPermission(app)) {
            runCatching { ContextCompat.startForegroundService(app, Intent(app, LocationService::class.java)) }
        } else if (!st.track) {
            app.stopService(Intent(app, LocationService::class.java))
        }
    }

    fun stop(context: Context) {
        val app = context.applicationContext
        app.stopService(Intent(app, LocationService::class.java))
        prefs(app).edit().clear().apply()
        state.value = null
    }

    fun isMock(l: Location): Boolean =
        if (Build.VERSION.SDK_INT >= 31) l.isMock else @Suppress("DEPRECATION") l.isFromMockProvider

    fun battery(context: Context): Int? =
        (context.getSystemService(Context.BATTERY_SERVICE) as? BatteryManager)
            ?.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)?.takeIf { it in 0..100 }

    fun point(context: Context, l: Location) = LocationPoint(
        lat = l.latitude, lng = l.longitude, accuracy = if (l.hasAccuracy()) l.accuracy else null,
        mock = isMock(l), battery = battery(context), at = Instant.ofEpochMilli(l.time).toString(),
    )

    /** One fix now (for starting / finishing work): a fresh one within [timeoutMs], else a recent last-known one. */
    @SuppressLint("MissingPermission")
    suspend fun fix(context: Context, timeoutMs: Long = 8_000): Fix? {
        if (!hasPermission(context)) return null
        val lm = context.getSystemService(Context.LOCATION_SERVICE) as? LocationManager ?: return null
        val providers = listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER).filter { runCatching { lm.isProviderEnabled(it) }.getOrDefault(false) }
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
        val recent = fresh ?: providers.mapNotNull { runCatching { lm.getLastKnownLocation(it) }.getOrNull() }
            .filter { System.currentTimeMillis() - it.time < 10 * 60_000 }
            .minByOrNull { if (it.hasAccuracy()) it.accuracy else Float.MAX_VALUE }
        return recent?.let { Fix(it.latitude, it.longitude, if (it.hasAccuracy()) it.accuracy else null, isMock(it)) }
    }

    fun startRequest(f: Fix?) = StartRequest(f?.lat, f?.lng, f?.accuracy, f?.mock)
}
