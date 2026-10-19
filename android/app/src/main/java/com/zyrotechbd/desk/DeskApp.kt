package com.zyrotechbd.desk

import android.app.Application
import com.zyrotechbd.desk.core.AppContext
import com.zyrotechbd.desk.push.Push

class DeskApp : Application() {
    override fun onCreate() {
        super.onCreate()
        AppContext.init(this)
        Push.init(this, "WhatsApp মেসেজ")
    }
}
