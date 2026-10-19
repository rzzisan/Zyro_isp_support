package com.zyrotechbd.desk

import android.app.Application
import com.zyrotechbd.desk.core.AppContext

class DeskApp : Application() {
    override fun onCreate() {
        super.onCreate()
        AppContext.init(this)
    }
}
