package com.zyrotechbd.desk.push

import android.Manifest
import android.app.Notification
import android.app.PendingIntent
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import com.zyrotechbd.desk.MainActivity
import com.zyrotechbd.desk.R
import com.zyrotechbd.desk.call.CallActivity
import com.zyrotechbd.desk.call.CallNotifications
import com.zyrotechbd.desk.ui.theme.Primary
import androidx.compose.ui.graphics.toArgb

/** Draws the Desk's data-only pushes (one notification per chat, replaced by its next message; a tap opens it) and rings WhatsApp calls. */
class DeskMessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) = Push.register(token)

    override fun onMessageReceived(message: RemoteMessage) {
        val d = message.data
        when (d["type"]) {
            "call" -> return if (NotifySettings.calls) incomingCall(d) else Unit   // turned off on this phone: no ringing
            "call_taken" -> {
                val uid = d["uid"] ?: return
                Push.callTaken.tryEmit(uid)
                CallNotifications.cancel(this, uid)
                return
            }
            "chat" -> Unit
            else -> return
        }
        val chatId = d["chat_id"]?.toLongOrNull() ?: return
        Push.events.tryEmit(chatId)
        if (Push.visibleChat == chatId) return  // already reading it
        if (!NotifySettings.chats) return  // turned off on this phone (lists still refresh)
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return
        val intent = Intent(this, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            .putExtra(MainActivity.EXTRA_COMPANY, d["company"].orEmpty())
            .putExtra(MainActivity.EXTRA_CHAT, chatId)
        val tap = PendingIntent.getActivity(this, chatId.toInt(), intent,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        val n = NotificationCompat.Builder(this, Push.CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_desk)
            .setColor(Primary.toArgb())
            .setContentTitle(d["title"].orEmpty())
            .setContentText(d["body"].orEmpty())
            .setStyle(NotificationCompat.BigTextStyle().bigText(d["body"].orEmpty()))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setAutoCancel(true)
            .setContentIntent(tap)
            .build()
        NotificationManagerCompat.from(this).notify("chat", chatId.toInt(), n)
    }

    /** A WhatsApp or hotline call ringing for staff: rings like a phone call, full screen when locked; stops when someone takes it. */
    private fun incomingCall(d: Map<String, String>) {
        val uid = d["uid"] ?: return
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return
        val company = d["company"].orEmpty()
        val title = d["name"]?.takeIf { it.isNotBlank() } ?: d["caller"].orEmpty()
        val flags = PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        val number = d["caller"].orEmpty()
        val channel = d["channel"] ?: "whatsapp"
        val ring = PendingIntent.getActivity(this, uid.hashCode(), CallActivity.incoming(this, company, uid, title, number, false, channel), flags)
        val take = PendingIntent.getActivity(this, uid.hashCode() + 1, CallActivity.incoming(this, company, uid, title, number, true, channel), flags)
        val n = NotificationCompat.Builder(this, Push.CALL_CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_desk)
            .setColor(Primary.toArgb())
            .setContentTitle((if (channel == "hotline") "হটলাইনে কল: " else "WhatsApp কল: ") + title)
            .setContentText("ধরতে চাপ দিন")
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setOngoing(true)
            .setTimeoutAfter(45_000)
            .setContentIntent(ring)
            .setFullScreenIntent(ring, true)
            .addAction(0, "ধরুন", take)
            .build()
        n.flags = n.flags or Notification.FLAG_INSISTENT
        NotificationManagerCompat.from(this).notify("call", uid.hashCode(), n)
    }
}
