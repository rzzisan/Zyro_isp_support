package com.zyrotechbd.desk.walkie

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.media.AudioManager
import android.media.ToneGenerator
import android.os.Build
import android.util.Log
import androidx.core.content.ContextCompat
import com.zyrotechbd.desk.core.AppContext
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.WalkieGroup
import com.zyrotechbd.desk.data.network.WalkieInfo
import io.livekit.android.AudioOptions
import io.livekit.android.AudioType
import io.livekit.android.LiveKit
import io.livekit.android.LiveKitOverrides
import io.livekit.android.RoomOptions
import io.livekit.android.audio.NoAudioHandler
import io.livekit.android.events.DisconnectReason
import io.livekit.android.events.RoomEvent
import io.livekit.android.events.collect
import io.livekit.android.room.Room
import io.livekit.android.room.participant.Participant
import io.livekit.android.room.track.LocalAudioTrackOptions
import io.livekit.android.room.track.Track
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.time.LocalTime
import java.time.ZoneId
import java.time.ZonedDateTime

/**
 * The walkie-talkie: the member listens to one group's LiveKit room all the time (speaker, media volume, no audio focus
 * taken, so it mixes with whatever else plays), and push-to-talk publishes the microphone while the button is held
 * (unpublished on release, so the mic is free in between). One person talks at a time: while someone's microphone is
 * live the button says busy. The connection lives as long as [WalkieService] runs; the server records everything.
 */
object Walkie {
    private const val TAG = "Walkie"
    val ZONE: ZoneId = ZoneId.of("Asia/Dhaka")

    data class State(
        val on: Boolean = false,           // the service runs (listening wanted)
        val connected: Boolean = false,
        val company: String = "",
        val me: Long = 0,
        val groups: List<WalkieGroup> = emptyList(),
        val groupId: Long? = null,
        val online: Set<Long> = emptySet(),
        val talker: Long? = null,          // a member whose microphone is live now
        val talking: Boolean = false,      // this phone's button is held
        val onAir: Boolean = false,        // this phone's microphone is live
        val muted: Boolean = false,        // a private call or a phone call: the group is not played
        val error: String? = null,
    ) {
        val group get() = groups.firstOrNull { it.id == groupId }
        fun name(id: Long?) = group?.members?.firstOrNull { it.id == id }?.name
    }

    val state = MutableStateFlow(State())

    /** The walkie-talkie notification was tapped: the app shell opens the screen and clears it. */
    val open = MutableStateFlow(false)

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private var room: Room? = null
    private var session: Job? = null
    private var talkJob: Job? = null
    private var inCall = false
    private var phoneCall = false
    private val tones by lazy { runCatching { ToneGenerator(AudioManager.STREAM_MUSIC, 70) }.getOrNull() }

    private val prefs get() = AppContext.app.getSharedPreferences("walkie", Context.MODE_PRIVATE)

    // ── the daily on/off times ──────────────────────────────────────────────────────────────────

    private fun at(hm: String?): LocalTime? = hm?.let { runCatching { LocalTime.parse(it.take(5)) }.getOrNull() }

    /** Within today's on hours, and not turned off by the member since they began: listening should run. */
    fun autoOn(info: WalkieInfo): Boolean {
        val now = ZonedDateTime.now(ZONE)
        val on = at(info.onAt) ?: return false
        val start = now.toLocalDate().atTime(on).atZone(ZONE)
        val end = at(info.offAt)?.let { now.toLocalDate().atTime(it).atZone(ZONE) }
        if (now.isBefore(start) || (end != null && end.isAfter(start) && !now.isBefore(end))) return false
        return prefs.getLong("off_at", 0L) < start.toInstant().toEpochMilli()
    }

    /** Turned off by the member in the last few minutes (a late on-push then leaves it off). */
    fun offRecently(): Boolean = System.currentTimeMillis() - prefs.getLong("off_at", 0L) < 10 * 60_000L

    /** The member turned it off themselves: it stays off until the next day's on time (or until they turn it on). */
    fun markOff() = prefs.edit().putLong("off_at", System.currentTimeMillis()).apply()

    fun hasMic(context: Context) =
        ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED

    /** The screen opened while it is off: show the groups and members anyway. */
    suspend fun load(company: String) {
        if (state.value.on && state.value.company == company && state.value.groups.isNotEmpty()) return
        val info = runCatching { ApiClient.service.walkie(company) }.getOrNull() ?: return
        val saved = prefs.getLong("group_$company", 0L)
        val group = info.groups.firstOrNull { it.id == saved } ?: info.groups.firstOrNull()
        state.update { if (it.on && it.company == company) it else it.copy(company = company, me = info.me, groups = info.groups, groupId = group?.id) }
    }

    // ── the group room ───────────────────────────────────────────────────────────────────────────

    /** Join (or rejoin) the chosen group of [company]; called by [WalkieService]. Retries while the service runs. */
    fun connect(company: String) {
        if (session?.isActive == true && state.value.company == company) return
        session?.cancel()
        state.update { it.copy(on = true, company = company, error = null) }
        session = scope.launch {
            var wait = 2_000L
            while (state.value.on) {
                val stop = runCatching { join(company) }.getOrElse { e ->
                    if (e is CancellationException) throw e
                    Log.w(TAG, "walkie connect failed", e)
                    state.update { it.copy(connected = false, error = "সংযোগ হচ্ছে না, আবার চেষ্টা করছে…") }
                    false
                }
                if (stop) break
                delay(wait)
                wait = (wait * 2).coerceAtMost(60_000L)
            }
        }
    }

    /** One connection, until it drops; true when it should not be tried again (no group, signed in elsewhere). */
    private suspend fun join(company: String): Boolean {
        val info = ApiClient.service.walkie(company)
        val saved = prefs.getLong("group_$company", 0L)
        val group = info.groups.firstOrNull { it.id == saved } ?: info.groups.firstOrNull()
        if (group == null || info.url.isNullOrBlank()) {
            state.update { it.copy(groups = info.groups, groupId = null, error = "কোনো ওয়াকি-টকি গ্রুপে নেই") }
            WalkieService.stop(AppContext.app)
            return true
        }
        state.update { it.copy(me = info.me, groups = info.groups, groupId = group.id) }
        val r = LiveKit.create(AppContext.app,
            RoomOptions(audioTrackCaptureDefaults = LocalAudioTrackOptions(noiseSuppression = true, echoCancellation = true,
                autoGainControl = true)),
            LiveKitOverrides(audioOptions = AudioOptions(audioOutputType = AudioType.MediaAudioType(), audioHandler = NoAudioHandler())))
        room = r
        try {
            r.connect(info.url, group.token)
            r.setSpeakerMute(inCall || phoneCall)
            state.update { it.copy(connected = true, error = null, muted = inCall || phoneCall) }
            refresh(r)
            var reason: DisconnectReason? = null
            try {
                r.events.collect { e ->
                    when (e) {
                        is RoomEvent.Disconnected -> { reason = e.reason; throw StopCollect() }
                        is RoomEvent.ParticipantConnected, is RoomEvent.ParticipantDisconnected, is RoomEvent.TrackPublished,
                        is RoomEvent.TrackUnpublished, is RoomEvent.TrackMuted, is RoomEvent.TrackUnmuted,
                        is RoomEvent.TrackSubscribed, is RoomEvent.TrackUnsubscribed, is RoomEvent.Reconnected -> refresh(r)
                        else -> Unit
                    }
                }
            } catch (_: StopCollect) {
            }
            return reason == DisconnectReason.DUPLICATE_IDENTITY || reason == DisconnectReason.PARTICIPANT_REMOVED
        } finally {
            talkJob?.cancel()
            runCatching { r.disconnect(); r.release() }
            if (room === r) room = null
            state.update { it.copy(connected = false, talker = null, online = emptySet(), talking = false, onAir = false) }
        }
    }

    private class StopCollect : Exception()

    private fun Participant.userId(): Long? = identity?.value?.takeIf { it.startsWith("u") }?.drop(1)?.toLongOrNull()

    /** Who is in the room and whose microphone is live (a beep when someone starts and when they finish). */
    private fun refresh(r: Room) {
        val online = r.remoteParticipants.values.mapNotNull { it.userId() }.toSet()
        val talker = r.remoteParticipants.values.firstOrNull { p ->
            p.getTrackPublication(Track.Source.MICROPHONE)?.let { !it.muted } == true
        }?.userId()
        val before = state.value.talker
        if (talker != before && !state.value.muted) tone(if (talker != null) ToneGenerator.TONE_PROP_BEEP else ToneGenerator.TONE_PROP_ACK)
        state.update { it.copy(online = online + it.me, talker = talker) }
    }

    fun disconnect() {
        state.update { it.copy(on = false) }
        session?.cancel()
        session = null
    }

    fun switchGroup(id: Long) {
        val s = state.value
        if (id == s.groupId || s.company.isEmpty()) return
        prefs.edit().putLong("group_${s.company}", id).apply()
        state.update { it.copy(groupId = id) }
        if (s.on) {
            session?.cancel()
            session = null
            connect(s.company)
        }
    }

    // ── push to talk ────────────────────────────────────────────────────────────────────────────

    /** The button went down; false (with a short buzz from the screen) when someone else is talking or it can't. */
    fun press(): Boolean {
        val s = state.value
        val r = room
        if (r == null || !s.connected || s.talker != null || s.muted || !hasMic(AppContext.app)) return false
        state.update { it.copy(talking = true) }
        talkJob?.cancel()
        talkJob = scope.launch {
            runCatching { r.localParticipant.setMicrophoneEnabled(true) }
                .onFailure { e -> if (e is CancellationException) throw e; Log.w(TAG, "mic failed", e) }
            if (state.value.talking) {
                state.update { it.copy(onAir = true) }
                tone(ToneGenerator.TONE_PROP_BEEP2)   // on air: speak now
            }
        }
        return true
    }

    /** The button came up: the microphone track goes away (the recording of this transmission ends with it). */
    fun release() {
        if (!state.value.talking) return
        state.update { it.copy(talking = false) }
        val r = room ?: return
        val pending = talkJob
        scope.launch {
            pending?.join()
            r.localParticipant.getTrackPublication(Track.Source.MICROPHONE)?.track?.let { r.localParticipant.unpublishTrack(it) }
            state.update { it.copy(onAir = false) }
        }
    }

    // ── other audio ─────────────────────────────────────────────────────────────────────────────

    /** A private call took the audio (true) or gave it back. */
    fun setInCall(active: Boolean) {
        inCall = active
        applyMute()
    }

    /** A phone call (GSM, WhatsApp, the Desk hotline) is on: the group is not played meanwhile. */
    fun setPhoneCall(active: Boolean) {
        if (phoneCall == active) return
        phoneCall = active
        applyMute()
    }

    private fun applyMute() {
        val mute = inCall || phoneCall
        if (mute) release()
        room?.setSpeakerMute(mute)
        state.update { it.copy(muted = mute) }
    }

    private fun tone(kind: Int) {
        runCatching { tones?.startTone(kind, 120) }
    }

    /** Android 12+: follow the phone's call state through the audio mode. */
    fun watchPhoneCalls(context: Context) {
        if (Build.VERSION.SDK_INT < 31) return
        val am = context.getSystemService(AudioManager::class.java) ?: return
        runCatching {
            am.addOnModeChangedListener(ContextCompat.getMainExecutor(context)) { mode ->
                setPhoneCall(!inCall && (mode == AudioManager.MODE_IN_CALL || mode == AudioManager.MODE_IN_COMMUNICATION || mode == AudioManager.MODE_RINGTONE))
            }
        }
    }
}
