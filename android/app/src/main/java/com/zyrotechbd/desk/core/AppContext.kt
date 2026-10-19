package com.zyrotechbd.desk.core

import android.app.Application

object AppContext {
    lateinit var app: Application
        private set

    fun init(application: Application) {
        app = application
    }
}
