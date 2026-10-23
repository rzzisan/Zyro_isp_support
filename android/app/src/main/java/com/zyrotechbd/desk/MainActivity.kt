package com.zyrotechbd.desk

import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.view.Window
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.core.view.WindowCompat
import com.zyrotechbd.desk.core.AppContext
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.location.FieldTracker
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.LoginRequest
import com.zyrotechbd.desk.push.OpenChat
import com.zyrotechbd.desk.push.Push
import com.zyrotechbd.desk.ui.MainAppShell
import com.zyrotechbd.desk.ui.screens.LoginScreen
import com.zyrotechbd.desk.ui.strings.BengaliStrings
import com.zyrotechbd.desk.ui.strings.EnglishStrings
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Background
import com.zyrotechbd.desk.ui.theme.DeskTheme
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private const val LANG_PREFS = "lang_store"
private const val LANG_KEY = "lang_bn"

class MainActivity : ComponentActivity() {
    companion object {
        const val EXTRA_COMPANY = "push_company"
        const val EXTRA_CHAT = "push_chat"
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        openFromPush(intent)
    }

    /** A tapped chat notification: the app shell opens that chat (switching company if needed). */
    private fun openFromPush(intent: Intent?) {
        val chat = intent?.getLongExtra(EXTRA_CHAT, 0L) ?: 0L
        if (chat > 0) Push.open.value = OpenChat(intent?.getStringExtra(EXTRA_COMPANY).orEmpty(), chat)
        intent?.removeExtra(EXTRA_CHAT)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        requestWindowFeature(Window.FEATURE_NO_TITLE)
        super.onCreate(savedInstanceState)
        WindowCompat.setDecorFitsSystemWindows(window, false)
        openFromPush(intent)
        setContent {
            DeskTheme {
                AppRoot()
            }
        }
    }
}

@Composable
private fun AppRoot() {
    val context = LocalContext.current
    val prefs = remember { context.getSharedPreferences(LANG_PREFS, Context.MODE_PRIVATE) }
    var isBengali by remember { mutableStateOf(prefs.getBoolean(LANG_KEY, true)) }
    val onToggleLang: () -> Unit = {
        isBengali = !isBengali
        prefs.edit().putBoolean(LANG_KEY, isBengali).apply()
    }

    CompositionLocalProvider(LocalStrings provides if (isBengali) BengaliStrings else EnglishStrings) {
        Surface(modifier = Modifier.fillMaxSize(), color = Background) {
            AuthRoot(onToggleLang = onToggleLang)
        }
    }
}

@Composable
private fun AuthRoot(onToggleLang: () -> Unit) {
    val s = LocalStrings.current
    val scope = rememberCoroutineScope()
    var signedIn by remember { mutableStateOf(SessionStore.token() != null && SessionStore.company() != null) }
    var email by remember { mutableStateOf(SessionStore.userEmail()) }
    var password by remember { mutableStateOf("") }
    var isLoading by remember { mutableStateOf(false) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    var privacy by remember { mutableStateOf(false) }

    // a token the server no longer accepts (signed out from the panel, member removed): back to the login screen
    DisposableEffect(Unit) {
        ApiClient.onUnauthorized = {
            SessionStore.clear()
            FieldTracker.stop(AppContext.app)
            scope.launch { signedIn = false }
        }
        onDispose { ApiClient.onUnauthorized = null }
    }

    if (!signedIn) {
        LoginScreen(
            email = email,
            password = password,
            isLoading = isLoading,
            errorMessage = errorMessage,
            onEmailChange = { email = it.trim() },
            onPasswordChange = { password = it },
            onToggleLang = onToggleLang,
            privacyAccepted = privacy,
            onPrivacyChange = { privacy = it },
            onLogin = {
                scope.launch {
                    isLoading = true
                    errorMessage = null
                    try {
                        val device = "${Build.MANUFACTURER} ${Build.MODEL}".trim().take(100)
                        val res = ApiClient.service.login(LoginRequest(email, password, device, privacy))
                        withContext(Dispatchers.IO) {
                            SessionStore.save(res.token, res.user.name, res.user.email, res.companies)
                        }
                        password = ""
                        signedIn = SessionStore.company() != null
                        if (!signedIn) errorMessage = s.noCompany
                    } catch (e: Exception) {
                        errorMessage = ApiClient.errorMessage(e, s.loginFailed, s.offline)
                    } finally {
                        isLoading = false
                    }
                }
            }
        )
    } else {
        MainAppShell(
            onLogout = {
                scope.launch {
                    runCatching { ApiClient.service.logout() }
                    SessionStore.clear()
                    FieldTracker.stop(AppContext.app)
                    signedIn = false
                }
            },
            onToggleLang = onToggleLang
        )
    }
}
