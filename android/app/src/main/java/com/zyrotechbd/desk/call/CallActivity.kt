package com.zyrotechbd.desk.call

import android.Manifest
import android.annotation.SuppressLint
import android.app.KeyguardManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.media.AudioManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
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
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.CallEnd
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.zyrotechbd.desk.BuildConfig
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.push.Push
import com.zyrotechbd.desk.ui.theme.Background
import com.zyrotechbd.desk.ui.theme.DeskTheme
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.StatusDue
import com.zyrotechbd.desk.ui.theme.StatusPaid
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONObject

/**
 * Desk phone: a WebView of the panel's softphone page (panel/public/softphone/phone.html, JsSIP over wss into Asterisk),
 * fed its login through window.ZyroApp. Opened ringing by a WhatsApp call push (ধরুন takes it, if nobody else did)
 * or straight away to call a customer through the hotline extension.
 */
class CallActivity : ComponentActivity() {
    companion object {
        private const val EXTRA_COMPANY = "company"
        private const val EXTRA_TARGET = "target"
        private const val EXTRA_TITLE = "title"
        private const val EXTRA_UID = "uid"
        private const val EXTRA_ANSWER = "answer"
        private const val RING_SECONDS = 45_000L

        /** A ringing WhatsApp call; answer = the notification's ধরুন was tapped. */
        fun incoming(context: Context, company: String, uid: String, title: String, answer: Boolean): Intent =
            Intent(context, CallActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra(EXTRA_COMPANY, company).putExtra(EXTRA_UID, uid)
                .putExtra(EXTRA_TARGET, "take-$uid").putExtra(EXTRA_TITLE, title).putExtra(EXTRA_ANSWER, answer)

        fun outgoing(context: Context, company: String, number: String, title: String): Intent =
            Intent(context, CallActivity::class.java)
                .putExtra(EXTRA_COMPANY, company).putExtra(EXTRA_TARGET, number).putExtra(EXTRA_TITLE, title)
                .putExtra(EXTRA_ANSWER, true)
    }

    private var ringing by mutableStateOf(false)
    private var status by mutableStateOf("")
    private var web by mutableStateOf<WebView?>(null)
    private var config: String? = null
    private var oldAudioMode = AudioManager.MODE_NORMAL

    private val mic = registerForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        if (ok) connect() else end("মাইক্রোফোনের অনুমতি ছাড়া কল করা যায় না")
    }

    private val company get() = intent.getStringExtra(EXTRA_COMPANY).orEmpty()
    private val target get() = intent.getStringExtra(EXTRA_TARGET).orEmpty()
    private val title get() = intent.getStringExtra(EXTRA_TITLE).orEmpty()
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
        setContent { DeskTheme { Screen() } }

        // the call was taken elsewhere (or by the bot) or the caller gave up: stop ringing here
        lifecycleScope.launch {
            Push.callTaken.collect { if (it == uid && web == null) end(null) }
        }
        start(intent)
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
            ringing = true
            lifecycleScope.launch {
                delay(RING_SECONDS)
                if (web == null) end(null)
            }
        }
    }

    private fun answer() {
        ringing = false
        uid?.let { CallNotifications.cancel(this, it) }
        status = "সংযোগ হচ্ছে…"
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) connect()
        else mic.launch(Manifest.permission.RECORD_AUDIO)
    }

    /** Fetch this member's softphone login, then load the page that dials. */
    private fun connect() {
        lifecycleScope.launch {
            val cfg = runCatching { ApiClient.service.softphone(company) }.getOrElse {
                end("ফোন চালু করা যায়নি: ${it.message ?: ""}")
                return@launch
            }
            if (!target.startsWith("take-") && !cfg.canCallOut) {
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
            status = ""
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

        @JavascriptInterface
        fun state(s: String) {
            if (s == "ended") lifecycleScope.launch {
                delay(1500)
                finish()
            }
        }
    }

    private fun hangUp() {
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

    private fun end(message: String?) {
        uid?.let { CallNotifications.cancel(this, it) }
        if (message == null) {
            finish()
            return
        }
        ringing = false
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
            getSystemService(AudioManager::class.java).mode = oldAudioMode
        }
        web = null
        super.onDestroy()
    }

    @Composable
    private fun Screen() {
        val w = web
        if (w != null) {
            AndroidView({ w }, Modifier.fillMaxSize())
            return
        }
        Column(
            Modifier.fillMaxSize().background(Background).padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.SpaceBetween,
        ) {
            Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.padding(top = 48.dp)) {
                Text(title, fontSize = 26.sp, fontWeight = FontWeight.Bold, color = Ink, textAlign = TextAlign.Center)
                Spacer(Modifier.height(6.dp))
                Text(if (target.startsWith("take-")) "WhatsApp কল" else "হটলাইন থেকে কল", fontSize = 15.sp, color = Muted)
                Spacer(Modifier.height(24.dp))
                Text(if (ringing) "কল আসছে…" else status, fontSize = 17.sp, color = Ink, textAlign = TextAlign.Center)
            }
            if (ringing) {
                Row(horizontalArrangement = Arrangement.spacedBy(64.dp), modifier = Modifier.padding(bottom = 32.dp)) {
                    RoundButton(Icons.Filled.CallEnd, "কাটুন", StatusDue) { end(null) }
                    RoundButton(Icons.Filled.Call, "ধরুন", StatusPaid) { answer() }
                }
            } else {
                RoundButton(Icons.Filled.CallEnd, "কেটে দিন", StatusDue) { end(null) }
            }
        }
    }

    @Composable
    private fun RoundButton(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, color: Color, onClick: () -> Unit) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(72.dp).background(color, CircleShape), contentAlignment = Alignment.Center) {
                IconButton(onClick = onClick, modifier = Modifier.size(72.dp)) {
                    Icon(icon, label, tint = Color.White, modifier = Modifier.size(32.dp))
                }
            }
            Spacer(Modifier.height(6.dp))
            Text(label, fontSize = 13.sp, color = Muted)
        }
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
