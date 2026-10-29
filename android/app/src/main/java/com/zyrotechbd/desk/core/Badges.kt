package com.zyrotechbd.desk.core

import kotlinx.coroutines.flow.MutableStateFlow

/** Counts on the bottom tabs (chats waiting for an answer, open tickets, unread team chat), kept fresh by whichever screen loads them. */
object Badges {
    val waitingChats = MutableStateFlow(0)
    val openTickets = MutableStateFlow(0)
    val teamUnread = MutableStateFlow(0)
}
