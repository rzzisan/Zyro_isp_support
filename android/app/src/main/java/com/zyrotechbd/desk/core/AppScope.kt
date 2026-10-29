package com.zyrotechbd.desk.core

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob

/** Work that must finish even when the screen that started it closes (telling the server a call ended). */
object AppScope : CoroutineScope by CoroutineScope(SupervisorJob() + Dispatchers.IO)
