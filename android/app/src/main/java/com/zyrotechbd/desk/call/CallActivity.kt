package com.zyrotechbd.desk.call

import android.Manifest
import android.annotation.SuppressLint
import android.app.KeyguardManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.media.AudioDeviceInfo
import android.media.AudioManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.SystemClock
import android.view.WindowManager
import android.webkit.JavascriptInterface
import android.webkit.PermissionRequest
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.zyrotechbd.desk.BuildConfig
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.CallInfo
import com.zyrotechbd.desk.data.network.LiveInfo
import com.zyrotechbd.desk.push.Push
import com.zyrotechbd.desk.ui.theme.DeskTheme
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONObject

/** Where the call is: ringing here, connecting, the other side ringing, talking, over. */
enum class CallPhase { Ringing, Connecting, Calling, Talking, Ended }

/**
 * Desk phone. The call itself runs in a hidden WebView of the panel's softphone page (panel/public/softphone/phone.html,
 * JsSIP over wss into Asterisk), fed its login through window.ZyroApp, which reports the call's state back. The screen
 * (CallScreen.kt) is native: who the caller is (their lines, MikroTik/ONU now, open tickets, a new ticket) and the call
 * controls. Opened ringing by a WhatsApp call push (ধরুন takes it, if nobody else did) or straight away to call a
 * customer through the hotline extension.
 */
class CallActivity : ComponentActivity() {
    companion object {
        private const val EXTRA_COMPANY = "company"
        private const val EXTRA_TARGET = "target"
        private const val EXTRA_TITLE = "title"
        private const val EXTRA_NUMBER = "number"
        private const val EXTRA_UID = "uid"
        private const val EXTRA_ANSWER = "answer"
        private const val RING_SECONDS = 45_000L

        /** A ringing WhatsApp call; answer = the notification's ধরুন was tapped. */
        fun incoming(context: Context, company: String, uid: String, title: String, number: String, answer: Boolean): Intent =
            Intent(context, CallActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra(EXTRA_COMPANY, company).putExtra(EXTRA_UID, uid).putExtra(EXTRA_NUMBER, number)
                .putExtra(EXTRA_TARGET, "take-$uid").putExtra(EXTRA_TITLE, title).putExtra(EXTRA_ANSWER, answer)

        fun outgoing(context: Context, company: String, number: String, title: String): Intent =
            Intent(context, CallActivity::class.java)
                .putExtra(EXTRA_COMPANY, company).putExtra(EXTRA_TARGET, number).putExtra(EXTRA_NUMBER, number)
                .putExtra(EXTRA_TITLE, title).putExtra(EXTRA_ANSWER, true)
    }

    // ── what CallScreen shows ──
    var phase by mutableStateOf(CallPhase.Connecting); private set
    var status by mutableStateOf(""); private set
    var talkingSince by mutableStateOf<Long?>(null); private set
    var muted by mutableStateOf(false); private set
    var speaker by mutableStateOf(false); private set
    var info by mutableStateOf<CallInfo?>(null); private set
    var infoError by mutableStateOf<String?>(null); private set
    var selected by mutableStateOf(0); private set
    var live by mutableStateOf<LiveInfo?>(null); private set
    var liveLoading by mutableStateOf(false); private set
    var liveError by mutableStateOf<String?>(null); private set
    var web by mutableStateOf<WebView?>(null); private set

    private var config: String? = null
    private var oldAudioMode = AudioManager.MODE_NORMAL
    private var liveJob: Job? = null

    // a ComponentActivity with no fragments; lint only sees an old fragment library pulled in transitively
    @SuppressLint("InvalidFragmentVersionForActivityResult")
    private val mic = registerForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        if (ok) connect() else end("মাইক্রোফোনের অনুমতি ছাড়া কল করা যায় না")
    }

    val company get() = intent.getStringExtra(EXTRA_COMPANY).orEmpty()
    private val target get() = intent.getStringExtra(EXTRA_TARGET).orEmpty()
    val title get() = intent.getStringExtra(EXTRA_TITLE).orEmpty()
    val number get() = intent.getStringExtra(EXTRA_NUMBER) ?: target.takeUnless { it.startsWith("take-") }.orEmpty()
    val whatsapp get() = target.startsWith("take-")
    private val uid get() = intent.getStringExtra(EXTRA_UID)

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
        setContent { DeskTheme { CallScreen(this) } }

        // the call was taken elsewhere (or by the bot) or the caller gave up: stop ringing here
        lifecycleScope.launch {
            Push.callTaken.collect { if (it == uid && web == null) end(null) }
        }
        start(intent)
        loadInfo()
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        if (web == null) {
            setIntent(intent)
            start(intent)
        }
    }

    private fun start(intent: Intent) {
        if (intent.getBooleanExtra(EXTRA_ANSWER, false)) answer()
        else {
            phase = CallPhase.Ringing
            lifecycleScope.launch {
                delay(RING_SECONDS)
                if (web == null && phase == CallPhase.Ringing) end(null)
            }
        }
    }

    fun answer() {
        phase = CallPhase.Connecting
        uid?.let { CallNotifications.cancel(this, it) }
        status = "সংযোগ হচ্ছে…"
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) connect()
        else mic.launch(Manifest.permission.RECORD_AUDIO)
    }

    /** Who the number is: their lines and open tickets; then the live box of the chosen line. */
    fun loadInfo() {
        val n = number.filter { it.isDigit() }
        if (n.length < 8) return
        lifecycleScope.launch {
            infoError = null
            try {
                val i = ApiClient.service.callInfo(company, n)
                info = i
                if (selected >= i.lines.size) selected = 0
                if (live == null) loadLive(false)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                infoError = ApiClient.errorMessage(e, "কাস্টমারের তথ্য আনা যায়নি", "ইন্টারনেট নেই")
            }
        }
    }

    fun select(i: Int) {
        if (i == selected) return
        selected = i
        live = null
        loadLive(false)
    }

    fun loadLive(fresh: Boolean) {
        val line = info?.lines?.getOrNull(selected) ?: return
        liveJob?.cancel()
        liveJob = lifecycleScope.launch {
            liveLoading = true
            liveError = null
            try {
                live = ApiClient.service.customerLive(company, line.customer.headerId, if (fresh) 1 else null)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                liveError = ApiClient.errorMessage(e, "রাউটার/ONU-এর তথ্য আনা যায়নি", "ইন্টারনেট নেই")
            } finally {
                liveLoading = false
            }
        }
    }

    /** Fetch this member's softphone login, then load the page that dials. */
    private fun connect() {
        lifecycleScope.launch {
            val cfg = runCatching { ApiClient.service.softphone(company) }.getOrElse {
                end("ফোন চালু করা যায়নি: ${it.message ?: ""}")
                return@launch
            }
            if (!whatsapp && !cfg.canCallOut) {
                // no hotline line in Desk: the phone's own dialer then
                startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$target")))
                finish()
                return@launch
            }
            config = JSONObject().put("ws", cfg.ws).put("domain", cfg.domain).put("user", cfg.user)
                .put("password", cfg.password).put("name", cfg.name).put("target", target).put("title", title).toString()
            val am = getSystemService(AudioManager::class.java)
            oldAudioMode = am.mode
            am.mode = AudioManager.MODE_IN_COMMUNICATION
            web = newWebView().also { it.loadUrl(BuildConfig.DESK_URL + "softphone/phone.html") }
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun newWebView() = WebView(this).apply {
        settings.javaScriptEnabled = true
        settings.domStorageEnabled = true
        settings.mediaPlaybackRequiresUserGesture = false
        // the page gets the login: it never leaves the Desk
        webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest) =
                request.url.host != Uri.parse(BuildConfig.DESK_URL).host
        }
        webChromeClient = object : WebChromeClient() {
            override fun onPermissionRequest(request: PermissionRequest) {
                if (request.origin.host == Uri.parse(BuildConfig.DESK_URL).host) {
                    request.grant(request.resources.filter { it == PermissionRequest.RESOURCE_AUDIO_CAPTURE }.toTypedArray())
                } else request.deny()
            }
        }
        addJavascriptInterface(Bridge(), "ZyroApp")
    }

    private inner class Bridge {
        @JavascriptInterface
        fun config(): String = config ?: "{}"

        /** The page's state code: calling, ringing, talking, ended. */
        @JavascriptInterface
        fun state(s: String) {
            runOnUiThread {
                when (s) {
                    "calling", "ringing" -> if (phase != CallPhase.Talking) phase = CallPhase.Calling
                    "talking" -> {
                        phase = CallPhase.Talking
                        if (talkingSince == null) talkingSince = SystemClock.elapsedRealtime()
                    }
                    "ended" -> {
                        phase = CallPhase.Ended
                        // stay a moment, longer when the call never connected (its reason is on screen)
                        lifecycleScope.launch {
                            delay(if (talkingSince == null) 3500 else 1500)
                            finish()
                        }
                    }
                }
            }
        }

        /** The page's own line for the state (also why a call failed). */
        @JavascriptInterface
        fun status(text: String) {
            runOnUiThread { status = text }
        }
    }

    fun toggleMute() {
        val w = web ?: return
        w.evaluateJavascript("document.getElementById('mute').click()", null)
        muted = !muted
    }

    fun toggleSpeaker() {
        routeSpeaker(!speaker)
    }

    private fun routeSpeaker(on: Boolean) {
        val am = getSystemService(AudioManager::class.java)
        if (Build.VERSION.SDK_INT >= 31) {
            if (on) {
                am.availableCommunicationDevices.firstOrNull { it.type == AudioDeviceInfo.TYPE_BUILTIN_SPEAKER }
                    ?.let { am.setCommunicationDevice(it) }
            } else am.clearCommunicationDevice()
        } else {
            @Suppress("DEPRECATION")
            am.isSpeakerphoneOn = on
        }
        speaker = on
    }

    fun hangUp() {
        val w = web
        if (w == null) {
            end(null)
            return
        }
        w.evaluateJavascript("window.zyroHangup && window.zyroHangup()", null)
        lifecycleScope.launch {
            delay(800)
            finish()
        }
    }

    fun decline() = end(null)

    private fun end(message: String?) {
        uid?.let { CallNotifications.cancel(this, it) }
        if (message == null) {
            finish()
            return
        }
        phase = CallPhase.Ended
        status = message
        lifecycleScope.launch {
            delay(2500)
            finish()
        }
    }

    override fun onDestroy() {
        web?.let {
            it.evaluateJavascript("window.zyroHangup && window.zyroHangup()", null)
            it.destroy()
            if (speaker) routeSpeaker(false)
            getSystemService(AudioManager::class.java).mode = oldAudioMode
        }
        web = null
        super.onDestroy()
    }
}

/** Calls from the app's call buttons: the Desk phone for members allowed it, else the phone's dialer. */
object Phone {
    fun dial(context: Context, number: String, title: String) {
        val company = SessionStore.company()
        if (company?.can("phone") == true) {
            context.startActivity(CallActivity.outgoing(context, company.slug, number, title.ifBlank { number }))
        } else {
            context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$number")))
        }
    }
}

/** The ringing notification of a WhatsApp call (full screen when the phone is locked). */
object CallNotifications {
    fun cancel(context: Context, uid: String) = NotificationManagerCompat.from(context).cancel("call", uid.hashCode())
}
