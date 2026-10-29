package com.zyrotechbd.desk.push

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.util.Log
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import com.google.firebase.messaging.FirebaseMessaging
import com.zyrotechbd.desk.BuildConfig
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.PushTokenRequest
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch

/** A chat a tapped notification asks to open: company slug and chat id. */
data class OpenChat(val company: String, val chatId: Long)

/** A team chat a tapped notification asks to open: company slug and chat ref ("g12", "u7"). */
data class OpenTeamChat(val company: String, val ref: String)

/**
 * Phone push for new WhatsApp messages (Firebase Cloud Messaging). The Firebase settings come in at build time
 * (BuildConfig.FCM_*); an APK built without them simply has no push.
 */
object Push {
    const val CHANNEL = "chats"
    const val CALL_CHANNEL = "calls"
    const val TEAM_CHANNEL = "team"
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    /** Set when a notification is tapped; the app shell opens the chat and clears it. */
    val open = MutableStateFlow<OpenChat?>(null)

    /** A chat that just got a message (from a push), so open lists and chats refresh at once instead of waiting. */
    val events = MutableSharedFlow<Long>(extraBufferCapacity = 16)

    /** A WhatsApp call (uid) that stopped ringing: someone took it, the bot answered or the caller hung up. */
    val callTaken = MutableSharedFlow<String>(extraBufferCapacity = 8)

    /** Set when a team chat notification is tapped; the app shell opens it and clears it. */
    val openTeam = MutableStateFlow<OpenTeamChat?>(null)

    /** A team chat (ref) that just got a message, so the open list, chat and tab badge refresh at once. */
    val teamEvents = MutableSharedFlow<String>(extraBufferCapacity = 16)

    /** The team chat on screen right now (app in front): its messages need no notification. */
    @Volatile var visibleTeamChat: String? = null

    /** The chat on screen right now (app in front), whose own messages need no notification. */
    @Volatile var visibleChat: Long? = null

    val enabled: Boolean get() = BuildConfig.FCM_APP_ID.isNotEmpty()

    fun init(context: Context, channelName: String) {
        if (!enabled) return
        if (FirebaseApp.getApps(context).isEmpty()) {
            FirebaseApp.initializeApp(context, FirebaseOptions.Builder()
                .setApplicationId(BuildConfig.FCM_APP_ID)
                .setApiKey(BuildConfig.FCM_API_KEY)
                .setProjectId(BuildConfig.FCM_PROJECT_ID)
                .setGcmSenderId(BuildConfig.FCM_SENDER_ID)
                .build())
        }
        val nm = context.getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel(CHANNEL, channelName, NotificationManager.IMPORTANCE_HIGH))
        nm.createNotificationChannel(NotificationChannel(TEAM_CHANNEL, "টিম চ্যাট", NotificationManager.IMPORTANCE_HIGH))
        // WhatsApp calls ring with the phone's ringtone
        nm.createNotificationChannel(NotificationChannel(CALL_CHANNEL, "WhatsApp কল", NotificationManager.IMPORTANCE_HIGH).apply {
            setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE),
                AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
            enableVibration(true)
        })
    }

    /** Tell the Desk this phone's token (after sign-in, on each start, and when Firebase rotates it). */
    fun register(token: String? = null) {
        if (!enabled || SessionStore.token() == null) return
        if (token != null) {
            send(token)
            return
        }
        runCatching { FirebaseMessaging.getInstance().token.addOnSuccessListener { send(it) } }
            .onFailure { Log.w("Push", "no FCM token", it) }
    }

    private fun send(token: String) {
        scope.launch {
            runCatching { ApiClient.service.pushToken(PushTokenRequest(token)) }
                .onFailure { Log.w("Push", "push token not saved", it) }
        }
    }
}
