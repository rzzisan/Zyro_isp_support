package com.zyrotechbd.desk.walkie

import android.Manifest
import android.app.KeyguardManager
import android.content.Context
import android.content.Intent
import android.media.AudioManager
import android.media.ToneGenerator
import android.os.Build
import android.os.Bundle
import android.os.SystemClock
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.CallEnd
import androidx.compose.material.icons.filled.Mic
import androidx.compose.material.icons.filled.MicOff
import androidx.compose.material.icons.filled.VolumeUp
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.app.NotificationManagerCompat
import androidx.lifecycle.lifecycleScope
import com.twilio.audioswitch.AudioDevice
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.WalkieCallInfo
import com.zyrotechbd.desk.data.network.WalkieCallStart
import com.zyrotechbd.desk.ui.theme.DeskTheme
import io.livekit.android.LiveKit
import io.livekit.android.audio.AudioSwitchHandler
import io.livekit.android.events.RoomEvent
import io.livekit.android.events.collect
import io.livekit.android.room.Room
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.launch

/**
 * A private walkie-talkie call between two group members: its own LiveKit room, full duplex like a phone call (earpiece,
 * speaker button). Rings by push (full screen when locked) and stops ringing when the caller gives up; the group is
 * silent meanwhile. The server records the call. Hanging up, or leaving this screen, ends it.
 */
class WalkieCallActivity : ComponentActivity() {
    enum class Phase { Ringing, Calling, Connecting, Talking, Ended }

    companion object {
        private const val EXTRA_COMPANY = "company"
        private const val EXTRA_CALL = "call"
        private const val EXTRA_TO = "to"
        private const val EXTRA_NAME = "name"
        private const val EXTRA_ANSWER = "answer"

        /** A call ended on the other phone (push): its id. */
        val ended = MutableSharedFlow<Long>(extraBufferCapacity = 4)

        fun incoming(context: Context, company: String, callId: Long, name: String, answer: Boolean): Intent =
            Intent(context, WalkieCallActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra(EXTRA_COMPANY, company).putExtra(EXTRA_CALL, callId).putExtra(EXTRA_NAME, name)
                .putExtra(EXTRA_ANSWER, answer)

        fun outgoing(context: Context, company: String, userId: Long, name: String): Intent =
            Intent(context, WalkieCallActivity::class.java)
                .putExtra(EXTRA_COMPANY, company).putExtra(EXTRA_TO, userId).putExtra(EXTRA_NAME, name)

        fun notifyTag(callId: Long) = callId.toInt()
    }

    var phase by mutableStateOf(Phase.Connecting); private set
    var status by mutableStateOf(""); private set
    var since by mutableStateOf<Long?>(null); private set
    var muted by mutableStateOf(false); private set
    var speaker by mutableStateOf(false); private set
    val name get() = intent.getStringExtra(EXTRA_NAME).orEmpty()
    private val company get() = intent.getStringExtra(EXTRA_COMPANY).orEmpty()

    private var call: WalkieCallInfo? = null
    private var room: Room? = null
    private var roomJob: Job? = null
    private var ringback: Job? = null
    private val tones by lazy { runCatching { ToneGenerator(AudioManager.STREAM_VOICE_CALL, 60) }.getOrNull() }

    private val askMic = registerForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        when {
            !ok -> finishWith("মাইক্রোফোনের অনুমতি ছাড়া কল করা যায় না")
            phase == Phase.Ringing -> answer()
            else -> begin()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Build.VERSION.SDK_INT >= 27) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
            getSystemService(KeyguardManager::class.java)?.requestDismissKeyguard(this, null)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON)
        }
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() = hangUp()
        })
        setContent { DeskTheme { WalkieCallScreen(this) } }
        Walkie.setInCall(true)
        lifecycleScope.launch {
            ended.collect { id -> if (id == (call?.id ?: intent.getLongExtra(EXTRA_CALL, 0L))) finishWith("কল শেষ") }
        }
        val incoming = intent.getLongExtra(EXTRA_CALL, 0L)
        if (incoming > 0) {
            phase = Phase.Ringing
            status = "ওয়াকি-টকি কল"
            if (intent.getBooleanExtra(EXTRA_ANSWER, false)) answer()
        } else {
            status = "কল হচ্ছে…"
            if (Walkie.hasMic(this)) begin() else askMic.launch(Manifest.permission.RECORD_AUDIO)
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        if (phase == Phase.Ringing && intent.getBooleanExtra(EXTRA_ANSWER, false)) answer()
    }

    /** An outgoing call: the server rings the other phone; this one waits in the room and plays a ringback. */
    private fun begin() {
        lifecycleScope.launch {
            val c = runCatching { ApiClient.service.walkieCall(company, WalkieCallStart(intent.getLongExtra(EXTRA_TO, 0L))) }
                .getOrElse { e -> return@launch finishWith(ApiClient.errorMessage(e, "কল করা যায়নি", "ইন্টারনেট নেই")) }
            call = c
            phase = Phase.Calling
            status = "রিং হচ্ছে…"
            ringback = lifecycleScope.launch {
                while (true) { runCatching { tones?.startTone(ToneGenerator.TONE_SUP_RINGTONE, 1000) }; delay(3000) }
            }
            join(c)
            // the other side declined or never answered
            while (phase == Phase.Calling) {
                delay(2000)
                val now = runCatching { ApiClient.service.walkieCallInfo(company, c.id) }.getOrNull() ?: continue
                if (now.status !in listOf("ringing", "talking")) finishWith(if (now.status == "declined") "কল কেটে দিয়েছেন" else "ধরেননি")
            }
        }
    }

    fun answer() {
        if (phase != Phase.Ringing) return
        if (!Walkie.hasMic(this)) {
            askMic.launch(Manifest.permission.RECORD_AUDIO)  // answers once allowed
            return
        }
        phase = Phase.Connecting
        status = "যুক্ত হচ্ছে…"
        NotificationManagerCompat.from(this).cancel("walkie_call", notifyTag(intent.getLongExtra(EXTRA_CALL, 0L)))
        lifecycleScope.launch {
            val c = runCatching { ApiClient.service.walkieAnswer(company, intent.getLongExtra(EXTRA_CALL, 0L)) }
                .getOrElse { e -> return@launch finishWith(ApiClient.errorMessage(e, "কলটা আর নেই", "ইন্টারনেট নেই")) }
            call = c
            join(c)
        }
    }

    private fun join(c: WalkieCallInfo) {
        val url = c.url
        val token = c.token
        if (url.isNullOrBlank() || token.isNullOrBlank()) return finishWith("কলটা আর নেই")
        val r = LiveKit.create(applicationContext)
        room = r
        roomJob = lifecycleScope.launch {
            try {
                r.connect(url, token)
                r.localParticipant.setMicrophoneEnabled(true)
                if (r.remoteParticipants.isNotEmpty()) talking()
                r.events.collect { e ->
                    when (e) {
                        is RoomEvent.ParticipantConnected -> talking()
                        is RoomEvent.ParticipantDisconnected -> if (phase == Phase.Talking) hangUp()
                        is RoomEvent.Disconnected -> finishWith("কল শেষ")
                        else -> Unit
                    }
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                finishWith("সংযোগ হয়নি")
            }
        }
    }

    private fun talking() {
        if (phase == Phase.Talking) return
        ringback?.cancel()
        phase = Phase.Talking
        status = ""
        since = SystemClock.elapsedRealtime()
    }

    fun toggleMute() {
        muted = !muted
        lifecycleScope.launch { runCatching { room?.localParticipant?.setMicrophoneEnabled(!muted) } }
    }

    fun toggleSpeaker() {
        val h = room?.audioHandler as? AudioSwitchHandler ?: return
        speaker = !speaker
        val device = if (speaker) h.availableAudioDevices.firstOrNull { it is AudioDevice.Speakerphone }
        else h.availableAudioDevices.firstOrNull { it !is AudioDevice.Speakerphone }
        h.selectDevice(device)
    }

    /** Decline, cancel or hang up: the server tells the other phone. */
    fun hangUp() {
        val id = call?.id ?: intent.getLongExtra(EXTRA_CALL, 0L).takeIf { it > 0 }
        if (id != null) {
            val company = company
            // outlives this screen: the other phone must stop ringing even when we close at once
            com.zyrotechbd.desk.core.AppScope.launch { runCatching { ApiClient.service.walkieEnd(company, id) } }
            NotificationManagerCompat.from(this).cancel("walkie_call", notifyTag(id))
        }
        finishWith("কল শেষ")
    }

    private fun finishWith(text: String) {
        if (phase == Phase.Ended) return
        phase = Phase.Ended
        status = text
        ringback?.cancel()
        roomJob?.cancel()
        room?.let { runCatching { it.disconnect(); it.release() } }
        room = null
        Walkie.setInCall(false)
        lifecycleScope.launch {
            delay(1200)
            finish()
        }
    }

    override fun onDestroy() {
        if (phase != Phase.Ended) hangUp()
        tones?.release()
        super.onDestroy()
    }
}

@Composable
private fun WalkieCallScreen(a: WalkieCallActivity) {
    var now by remember { mutableLongStateOf(SystemClock.elapsedRealtime()) }
    LaunchedEffect(a.phase) {
        while (a.phase == WalkieCallActivity.Phase.Talking) { now = SystemClock.elapsedRealtime(); delay(1000) }
    }
    Box(Modifier.fillMaxSize().background(Color(0xFF0F172A)).systemBarsPadding()) {
        Column(Modifier.fillMaxWidth().padding(top = 72.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(96.dp).background(Color(0xFF1E293B), CircleShape), contentAlignment = Alignment.Center) {
                Text(a.name.take(1).uppercase(), color = Color.White, fontSize = 40.sp, fontWeight = FontWeight.SemiBold)
            }
            Spacer(Modifier.height(20.dp))
            Text(a.name, color = Color.White, fontSize = 26.sp, fontWeight = FontWeight.SemiBold)
            Spacer(Modifier.height(8.dp))
            val since = a.since
            val line = if (a.phase == WalkieCallActivity.Phase.Talking && since != null) {
                val s = ((now - since) / 1000).coerceAtLeast(0)
                "%02d:%02d".format(s / 60, s % 60)
            } else a.status
            Text(line, color = Color(0xFFCBD5E1), fontSize = 16.sp)
        }
        Row(Modifier.fillMaxWidth().align(Alignment.BottomCenter).padding(bottom = 56.dp),
            horizontalArrangement = Arrangement.SpaceEvenly, verticalAlignment = Alignment.CenterVertically) {
            when (a.phase) {
                WalkieCallActivity.Phase.Ringing -> {
                    RoundButton(Icons.Filled.CallEnd, Color(0xFFDC2626), "কাটুন") { a.hangUp() }
                    RoundButton(Icons.Filled.Call, Color(0xFF16A34A), "ধরুন") { a.answer() }
                }
                WalkieCallActivity.Phase.Ended -> Unit
                else -> {
                    RoundButton(if (a.muted) Icons.Filled.MicOff else Icons.Filled.Mic,
                        if (a.muted) Color(0xFFF59E0B) else Color(0xFF334155), if (a.muted) "মিউট" else "মাইক") { a.toggleMute() }
                    RoundButton(Icons.Filled.CallEnd, Color(0xFFDC2626), "রাখুন") { a.hangUp() }
                    RoundButton(Icons.Filled.VolumeUp, if (a.speaker) Color(0xFF2563EB) else Color(0xFF334155), "স্পিকার") { a.toggleSpeaker() }
                }
            }
        }
    }
}

@Composable
private fun RoundButton(icon: ImageVector, color: Color, label: String, onClick: () -> Unit) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        IconButton(onClick = onClick, modifier = Modifier.size(68.dp).background(color, CircleShape)) {
            Icon(icon, contentDescription = label, tint = Color.White, modifier = Modifier.size(30.dp))
        }
        Spacer(Modifier.height(6.dp))
        Text(label, color = Color(0xFFCBD5E1), fontSize = 13.sp)
    }
}
