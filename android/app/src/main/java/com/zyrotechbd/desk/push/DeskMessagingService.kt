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
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.walkie.Walkie
import com.zyrotechbd.desk.walkie.WalkieCallActivity
import com.zyrotechbd.desk.walkie.WalkieService
import androidx.compose.ui.graphics.toArgb

/** Draws the Desk's data-only pushes (one notification per chat or team chat, replaced by its next message; a tap opens it) and rings WhatsApp calls. */
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
            "walkie_on" -> return walkieOn(d)
            "walkie_off" -> return WalkieService.stop(this)
            "walkie_call" -> return walkieCall(d)
            "walkie_call_end" -> {
                val id = d["call_id"]?.toLongOrNull() ?: return
                NotificationManagerCompat.from(this).cancel("walkie_call", WalkieCallActivity.notifyTag(id))
                WalkieCallActivity.ended.tryEmit(id)
                return
            }
            "team_chat" -> return teamChat(d)
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

    /** A team chat message from another Desk user: one notification per chat, replaced by its next message. */
    private fun teamChat(d: Map<String, String>) {
        val ref = d["chat"] ?: return
        Push.teamEvents.tryEmit(ref)
        val company = d["company"].orEmpty()
        if (Push.visibleTeamChat == ref && SessionStore.company()?.slug == company) return  // already reading it
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return
        val intent = Intent(this, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            .putExtra(MainActivity.EXTRA_COMPANY, company)
            .putExtra(MainActivity.EXTRA_TEAM_CHAT, ref)
        val id = ("$company/$ref").hashCode()
        val tap = PendingIntent.getActivity(this, id, intent, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        val n = NotificationCompat.Builder(this, Push.TEAM_CHANNEL)
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
        NotificationManagerCompat.from(this).notify("team", id, n)
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
        val reminder = d["kind"] == "reminder"
        val note = d["note"].orEmpty()
        val ring = PendingIntent.getActivity(this, uid.hashCode(),
            CallActivity.incoming(this, company, uid, title, number, false, channel, reminder, note), flags)
        val take = PendingIntent.getActivity(this, uid.hashCode() + 1,
            CallActivity.incoming(this, company, uid, title, number, true, channel, reminder, note), flags)
        val n = NotificationCompat.Builder(this, Push.CALL_CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_desk)
            .setColor(Primary.toArgb())
            .setContentTitle((if (reminder) "রিমাইন্ডার কলে কথা চান: " else if (channel == "hotline") "হটলাইনে কল: " else "WhatsApp কল: ") + title)
            .setContentText(note.ifBlank { "ধরতে চাপ দিন" })
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

    /** The company's daily on time: listen (a push may start the service from the background). */
    private fun walkieOn(d: Map<String, String>) {
        val company = d["company"].orEmpty()
        if (company.isEmpty() || WalkieService.running || SessionStore.company()?.slug != company) return
        if (Walkie.offRecently()) return
        WalkieService.start(this, company, foreground = false)
    }

    /** A group member calls this phone privately: rings like a call, full screen when locked. */
    private fun walkieCall(d: Map<String, String>) {
        val id = d["call_id"]?.toLongOrNull() ?: return
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return
        val company = d["company"].orEmpty()
        val name = d["name"].orEmpty()
        val flags = PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        val ring = PendingIntent.getActivity(this, 5000 + id.toInt() * 2, WalkieCallActivity.incoming(this, company, id, name, false), flags)
        val take = PendingIntent.getActivity(this, 5001 + id.toInt() * 2, WalkieCallActivity.incoming(this, company, id, name, true), flags)
        val n = NotificationCompat.Builder(this, Push.CALL_CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_desk)
            .setColor(Primary.toArgb())
            .setContentTitle("ওয়াকি-টকি কল: $name")
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
        NotificationManagerCompat.from(this).notify("walkie_call", WalkieCallActivity.notifyTag(id), n)
    }
}
