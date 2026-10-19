package com.zyrotechbd.desk.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.NotificationsOff
import androidx.compose.material.icons.filled.PauseCircle
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.Chat
import com.zyrotechbd.desk.ui.components.Chip
import com.zyrotechbd.desk.ui.components.CustomerAvatar
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.DeskTextField
import com.zyrotechbd.desk.ui.components.EmptyText
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.Pill
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.navigation.Routes
import com.zyrotechbd.desk.ui.strings.AppStrings
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Line
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import kotlinx.coroutines.delay

/** The panel's boxes, in its order. */
private fun boxes(s: AppStrings) = listOf("all" to s.boxAll, "waiting" to s.boxWaiting, "mine" to s.boxMine,
    "paused" to s.boxPaused, "staff" to s.boxStaff, "muted" to s.boxMuted)

/** WhatsApp chats, newest first, with the panel's boxes and search; refreshes itself while open. */
@Composable
fun InboxScreen(navController: NavHostController, company: String) {
    val s = LocalStrings.current
    var box by rememberSaveable { mutableStateOf("all") }
    var query by rememberSaveable { mutableStateOf("") }
    val rows = remember { mutableStateListOf<Chat>() }
    var waiting by remember { mutableIntStateOf(0) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var reload by remember { mutableIntStateOf(0) }

    LaunchedEffect(box, query, reload) {
        if (query.isNotBlank()) delay(350)
        loading = rows.isEmpty()
        while (true) {
            try {
                val res = ApiClient.service.chats(company, box.takeIf { it != "all" }, query.trim().ifBlank { null })
                rows.clear()
                rows.addAll(res.data)
                waiting = res.waitingCount
                error = null
            } catch (e: Exception) {
                error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            } finally {
                loading = false
            }
            delay(15_000)  // new messages show up without pulling; push comes later with Firebase
        }
    }

    Column(Modifier.fillMaxSize()) {
        Column(Modifier.padding(start = 16.dp, end = 16.dp, top = 12.dp)) {
            DeskTextField(value = query, onValueChange = { query = it }, placeholder = s.searchChats, modifier = Modifier.fillMaxWidth())
            Spacer(Modifier.height(8.dp))
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(boxes(s)) { (key, label) ->
                    Chip(if (key == "waiting" && waiting > 0) "$label · $waiting" else label, box == key) {
                        if (box != key) {
                            rows.clear()
                            box = key
                        }
                    }
                }
            }
            Spacer(Modifier.height(8.dp))
            if (error != null) {
                MessageBox(error!!, onRetry = { reload++ }, retryLabel = s.retry)
                Spacer(Modifier.height(8.dp))
            }
        }
        LazyColumn(contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 24.dp), modifier = Modifier.fillMaxSize()) {
            if (rows.isNotEmpty()) item {
                DeskCard {
                    rows.forEachIndexed { i, c ->
                        if (i > 0) HorizontalDivider(color = Line)
                        ChatRow(c, s) { navController.navigate(Routes.chat(c.id)) }
                    }
                }
            }
            if (loading) item { LoadingBox() }
            if (!loading && rows.isEmpty() && error == null) item { EmptyText(s.noChats) }
        }
    }
}

@Composable
private fun ChatRow(c: Chat, s: AppStrings, onClick: () -> Unit) {
    val name = c.name?.takeIf { it.isNotBlank() } ?: s.noName
    Row(Modifier.fillMaxWidth().clickable { onClick() }.padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically) {
        CustomerAvatar(name, c.number, size = 42.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically) {
                    Text(name, fontSize = 15.sp, fontWeight = if (c.waiting) FontWeight.SemiBold else FontWeight.Medium, color = Ink,
                        maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                    if (c.customerId != null) {
                        Spacer(Modifier.width(6.dp))
                        Text("ID ${c.customerId}", fontSize = 12.sp, color = Muted, maxLines = 1)
                    }
                }
                Spacer(Modifier.width(8.dp))
                Text(Format.shortTime(c.lastMessageAt, s.yesterday), fontSize = 12.sp,
                    color = if (c.waiting) PrimaryStrong else Muted, fontWeight = if (c.waiting) FontWeight.SemiBold else FontWeight.Normal)
            }
            Spacer(Modifier.height(2.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(preview(c, s), fontSize = 13.sp, color = if (c.waiting) Ink else Muted, maxLines = 1,
                    overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                if (c.notifyMuted) Icon(Icons.Filled.NotificationsOff, null, tint = Muted, modifier = Modifier.padding(start = 4.dp).size(15.dp))
                if (c.botPaused) Icon(Icons.Filled.PauseCircle, null, tint = Warn, modifier = Modifier.padding(start = 4.dp).size(15.dp))
                if (c.waiting) Box(Modifier.padding(start = 6.dp).size(9.dp).background(Primary, CircleShape))
            }
            if (c.staff || c.assigned != null) {
                Spacer(Modifier.height(4.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (c.staff) Pill(s.staffLabel, PrimaryStrong)
                    c.assigned?.let { Pill(it.name, Muted) }
                }
            }
        }
    }
}

/** "বট: ..." / "কাস্টমার: ..." line under the name; media without a caption shows its kind. */
private fun preview(c: Chat, s: AppStrings): String {
    val m = c.lastMessage ?: return c.number
    val body = m.body?.takeIf { it.isNotBlank() }?.replace('\n', ' ') ?: when (m.type) {
        "image" -> s.imageLabel
        "audio", "voice" -> s.voiceLabel
        "video" -> s.videoLabel
        "document" -> s.fileLabel
        else -> ""
    }
    return when {
        m.direction == "in" -> body
        m.sender == "bot" -> "${s.senderBot}: $body"
        else -> "↩ $body"
    }
}
