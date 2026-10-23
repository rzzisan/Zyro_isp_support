package com.zyrotechbd.desk.push

import android.content.Context
import com.zyrotechbd.desk.core.AppContext

/** This phone's own choice (More tab): show new-message notifications, and ring for WhatsApp calls. On by default. */
object NotifySettings {
    private val prefs by lazy { AppContext.app.getSharedPreferences("notify", Context.MODE_PRIVATE) }

    var chats: Boolean
        get() = prefs.getBoolean("chats", true)
        set(v) = prefs.edit().putBoolean("chats", v).apply()

    var calls: Boolean
        get() = prefs.getBoolean("calls", true)
        set(v) = prefs.edit().putBoolean("calls", v).apply()
}
