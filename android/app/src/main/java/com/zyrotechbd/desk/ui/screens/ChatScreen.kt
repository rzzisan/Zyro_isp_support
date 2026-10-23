package com.zyrotechbd.desk.ui.screens

import android.content.Context
import com.zyrotechbd.desk.call.Phone
import android.widget.Toast
import android.content.Intent
import android.graphics.Bitmap
import android.media.MediaPlayer
import android.net.Uri
import android.provider.OpenableColumns
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.ime
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.ArrowDropDown
import androidx.compose.material.icons.filled.AttachFile
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Stop
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.graphics.Color
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.push.Push
import com.zyrotechbd.desk.ui.navigation.Routes
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.withTimeoutOrNull
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.media.MediaCache
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.AssignRequest
import com.zyrotechbd.desk.data.network.BotRequest
import com.zyrotechbd.desk.data.network.Chat
import com.zyrotechbd.desk.data.network.ChatMessage
import com.zyrotechbd.desk.data.network.Member
import com.zyrotechbd.desk.data.network.MuteRequest
import com.zyrotechbd.desk.data.network.ReplyRequest
import com.zyrotechbd.desk.ui.components.Chip
import com.zyrotechbd.desk.ui.components.CustomerAvatar
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.Pill
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.strings.AppStrings
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Background
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.InputLine
import com.zyrotechbd.desk.ui.theme.Line
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.PrimarySoft
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import com.zyrotechbd.desk.ui.theme.Surface
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody

/** The panel's "bot stays quiet after my reply" choices, in minutes. */
private val PAUSES = listOf(0, 30, 60, 120, 360, 1440)

private fun pauseLabel(minutes: Int, s: AppStrings) = when {
    minutes == 0 -> s.noPause
    minutes < 60 -> if (s.noPause == "No pause") "$minutes min" else "${bn(minutes)} মিনিট"
    else -> if (s.noPause == "No pause") "${minutes / 60} h" else "${bn(minutes / 60)} ঘণ্টা"
}

private fun bn(n: Int) = n.toString().map { if (it.isDigit()) '০' + (it - '0') else it }.joinToString("")

private data class Attachment(val uri: Uri, val name: String, val mime: String)

/** One WhatsApp chat: messages, reply (text or a file) with the bot kept quiet a while, and the panel's chat actions. */
@Composable
fun ChatScreen(company: String, chatId: Long, navController: NavHostController? = null) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var chat by remember { mutableStateOf<Chat?>(null) }
    val messages = remember { mutableStateListOf<ChatMessage>() }
    var members by remember { mutableStateOf<List<Member>>(emptyList()) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var text by rememberSaveable { mutableStateOf("") }
    var pause by rememberSaveable { mutableIntStateOf(30) }
    var attachment by remember { mutableStateOf<Attachment?>(null) }
    var sending by remember { mutableStateOf(false) }
    val listState = rememberLazyListState()
    val player = remember { ChatPlayer() }
    DisposableEffect(Unit) { onDispose { player.stop() } }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) attachment = describe(context, uri)
    }

    // while this chat is on screen its pushes show no notification; they refresh it at once instead
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    DisposableEffect(chatId, lifecycle) {
        val obs = LifecycleEventObserver { _, e ->
            if (e == Lifecycle.Event.ON_RESUME) Push.visibleChat = chatId
            if (e == Lifecycle.Event.ON_PAUSE) Push.visibleChat = null
        }
        lifecycle.addObserver(obs)
        if (lifecycle.currentState.isAtLeast(Lifecycle.State.RESUMED)) Push.visibleChat = chatId
        onDispose {
            lifecycle.removeObserver(obs)
            if (Push.visibleChat == chatId) Push.visibleChat = null
        }
    }
    var unseen by remember { mutableIntStateOf(0) }
    val atBottom by remember {
        derivedStateOf {
            val last = listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: -1
            last >= listState.layoutInfo.totalItemsCount - 2
        }
    }
    LaunchedEffect(atBottom) { if (atBottom) unseen = 0 }
    // the keyboard shrinks the list: keep the latest message in view while replying
    val keyboardOpen = WindowInsets.ime.getBottom(LocalDensity.current) > 0
    LaunchedEffect(keyboardOpen) {
        if (keyboardOpen && messages.isNotEmpty()) listState.scrollToItem(messages.size - 1)
    }

    LaunchedEffect(chatId, reload) {
        var first = messages.isEmpty()
        while (true) {
            try {
                val d = ApiClient.service.chat(company, chatId)
                val known = messages.map { it.id }.toSet()
                val added = d.messages.count { it.id !in known }
                val changed = added > 0 || d.messages.size != messages.size ||
                    d.messages.zip(messages).any { (a, b) -> a.status != b.status }
                chat = d.chat
                members = d.members
                if (changed) {
                    val follow = first || atBottom
                    messages.clear()
                    messages.addAll(d.messages)
                    if (messages.isNotEmpty() && follow) listState.scrollToItem(messages.size - 1)
                    else if (added > 0 && !first) unseen += added
                }
                first = false
                error = null
            } catch (e: Exception) {
                error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            }
            withTimeoutOrNull(8_000) { Push.events.first { it == chatId } }
        }
    }

    fun act(call: suspend () -> Chat) {
        scope.launch {
            try {
                chat = call()
            } catch (e: Exception) {
                notice = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            }
        }
    }

    fun send() {
        val c = chat ?: return
        sending = true
        notice = null
        scope.launch {
            try {
                val a = attachment
                val sent = if (a != null) {
                    val bytes = context.contentResolver.openInputStream(a.uri)!!.use { it.readBytes() }
                    val part = MultipartBody.Part.createFormData("file", a.name, bytes.toRequestBody(a.mime.toMediaTypeOrNull()))
                    val plain = "text/plain".toMediaTypeOrNull()
                    ApiClient.service.replyFile(company, c.id, part, text.trim().takeIf { it.isNotEmpty() }?.toRequestBody(plain),
                        pause.toString().toRequestBody(plain)).message
                } else {
                    ApiClient.service.reply(company, c.id, ReplyRequest(text.trim(), pause)).message
                }
                messages.add(sent)
                listState.scrollToItem(messages.size - 1)
                text = ""
                attachment = null
                if (sent.status == "test") notice = s.sentTestMode
                reload++
            } catch (e: Exception) {
                notice = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            } finally {
                sending = false
            }
        }
    }

    val c = chat
    if (c == null) {
        Column(Modifier.fillMaxSize().padding(16.dp)) {
            if (error != null) MessageBox(error!!, onRetry = { reload++ }, retryLabel = s.retry) else LoadingBox()
        }
        return
    }

    Column(Modifier.fillMaxSize()) {
        ChatHeader(c, members, s, navController, onTake = { act { ApiClient.service.assignChat(company, c.id, AssignRequest("me")).chat } },
            onAssign = { id -> act { ApiClient.service.assignChat(company, c.id, AssignRequest(id)).chat } },
            onBot = { paused -> act { ApiClient.service.chatBot(company, c.id, BotRequest(paused)).chat } },
            onMute = { muted -> act { ApiClient.service.muteChat(company, c.id, MuteRequest(muted)).chat } })
        HorizontalDivider(color = Line)
        Box(Modifier.weight(1f).fillMaxWidth()) {
            LazyColumn(state = listState, modifier = Modifier.fillMaxSize().background(Background),
                contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                itemsIndexed(messages, key = { _, m -> m.id }) { i, m ->
                    if (i == 0 || !Format.sameDay(messages[i - 1].at, m.at)) DayHeading(Format.dayLabel(m.at, s.today, s.yesterday))
                    Bubble(m, company, s, player, canDelete = c.canDelete && m.direction != "in",
                        onDelete = {
                            scope.launch {
                                try {
                                    ApiClient.service.deleteMessage(company, c.id, m.id)
                                    messages.removeAll { it.id == m.id }
                                    Toast.makeText(context, s.messageDeleted, Toast.LENGTH_SHORT).show()
                                } catch (e: Exception) {
                                    notice = ApiClient.errorMessage(e, s.loadFailed, s.offline)
                                }
                            }
                        })
                }
            }
            if (!atBottom && messages.isNotEmpty()) {
                Row(Modifier.align(Alignment.BottomEnd).padding(12.dp).clip(RoundedCornerShape(20.dp)).background(Surface)
                    .border(1.dp, Line, RoundedCornerShape(20.dp))
                    .clickable { scope.launch { listState.animateScrollToItem(messages.size - 1) } }
                    .padding(horizontal = 12.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                    if (unseen > 0) {
                        Text("${s.newMessagesBtn} · $unseen", fontSize = 13.sp, color = PrimaryStrong, fontWeight = FontWeight.SemiBold)
                        Spacer(Modifier.width(4.dp))
                    }
                    Icon(Icons.Filled.KeyboardArrowDown, null, tint = PrimaryStrong, modifier = Modifier.size(20.dp))
                }
            }
        }
        HorizontalDivider(color = Line)
        Column(Modifier.fillMaxWidth().background(Surface).padding(horizontal = 12.dp, vertical = 8.dp)) {
            listOfNotNull(error, notice).forEach {
                MessageBox(it, color = if (it == s.sentTestMode) Warn else Danger)
                Spacer(Modifier.height(6.dp))
            }
            if (!c.sending && notice == null) {
                Text(s.testMode, fontSize = 12.sp, color = Warn, modifier = Modifier.padding(bottom = 6.dp))
            }
            if (!c.windowOpen) Text(s.windowClosed, fontSize = 13.sp, color = Warn) else Composer(
                text, { text = it }, attachment, { attachment = null }, sending, pause, { pause = it }, s,
                onAttach = { picker.launch("*/*") }, onSend = { send() })
        }
    }
}

/** Reply box: an optional file, the message, send, and how long the bot keeps quiet afterwards. */
@Composable
private fun Composer(text: String, onText: (String) -> Unit, attachment: Attachment?, onRemove: () -> Unit, sending: Boolean,
                     pause: Int, onPause: (Int) -> Unit, s: AppStrings, onAttach: () -> Unit, onSend: () -> Unit) {
    Column {
        attachment?.let { a ->
            Row(Modifier.fillMaxWidth().padding(bottom = 6.dp).clip(RoundedCornerShape(8.dp)).background(PrimarySoft)
                .padding(horizontal = 10.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.Description, null, tint = PrimaryStrong, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(6.dp))
                Text(a.name, fontSize = 13.sp, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                Icon(Icons.Filled.Close, s.removeFile, tint = Muted, modifier = Modifier.size(18.dp).clickable { onRemove() })
            }
        }
        Row(verticalAlignment = Alignment.Bottom) {
            IconButton(onClick = onAttach) { Icon(Icons.Filled.AttachFile, s.attachFile, tint = Muted) }
            Box(Modifier.weight(1f).heightIn(min = 44.dp).clip(RoundedCornerShape(8.dp)).border(1.dp, InputLine, RoundedCornerShape(8.dp))
                .padding(horizontal = 12.dp, vertical = 10.dp)) {
                androidx.compose.foundation.text.BasicTextField(value = text, onValueChange = onText, maxLines = 6,
                    textStyle = androidx.compose.ui.text.TextStyle(color = Ink, fontSize = 15.sp), modifier = Modifier.fillMaxWidth(),
                    cursorBrush = androidx.compose.ui.graphics.SolidColor(Primary))
                if (text.isEmpty()) Text(s.replyHint, color = Muted, fontSize = 15.sp)
            }
            Spacer(Modifier.width(8.dp))
            val canSend = !sending && (text.isNotBlank() || attachment != null)
            Box(Modifier.size(44.dp).clip(CircleShape).background(if (canSend) Primary else InputLine)
                .clickable(enabled = canSend) { onSend() }, contentAlignment = Alignment.Center) {
                if (sending) CircularProgressIndicator(color = Surface, strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
                else Icon(Icons.AutoMirrored.Filled.Send, s.sendBtn, tint = Surface, modifier = Modifier.size(20.dp))
            }
        }
        Spacer(Modifier.height(4.dp))
        var pick by remember { mutableStateOf(false) }
        Box(Modifier.padding(start = 48.dp)) {
            Row(Modifier.clip(RoundedCornerShape(6.dp)).clickable { pick = true }.padding(vertical = 4.dp, horizontal = 2.dp),
                verticalAlignment = Alignment.CenterVertically) {
                Text("${s.botQuietFor}: ", fontSize = 12.sp, color = Muted)
                Text(pauseLabel(pause, s), fontSize = 12.sp, color = PrimaryStrong, fontWeight = FontWeight.SemiBold)
                Icon(Icons.Filled.ArrowDropDown, null, tint = PrimaryStrong, modifier = Modifier.size(18.dp))
            }
            DropdownMenu(expanded = pick, onDismissRequest = { pick = false }, modifier = Modifier.background(Surface)) {
                PAUSES.forEach { m ->
                    DropdownMenuItem(text = { Text(pauseLabel(m, s), fontWeight = if (m == pause) FontWeight.SemiBold else FontWeight.Normal,
                        color = if (m == pause) PrimaryStrong else Ink) }, onClick = { onPause(m); pick = false })
                }
            }
        }
    }
}

@Composable
private fun ChatHeader(c: Chat, members: List<Member>, s: AppStrings, navController: NavHostController?, onTake: () -> Unit, onAssign: (Long?) -> Unit,
                       onBot: (Boolean) -> Unit, onMute: (Boolean) -> Unit) {
    var menu by remember { mutableStateOf(false) }
    var picking by remember { mutableStateOf(false) }
    val name = c.name?.takeIf { it.isNotBlank() } ?: s.noName
    Row(Modifier.fillMaxWidth().background(Surface).padding(start = 16.dp, end = 4.dp, top = 10.dp, bottom = 10.dp),
        verticalAlignment = Alignment.CenterVertically) {
        CustomerAvatar(name, c.number, size = 40.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(name, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(listOfNotNull(c.number, c.customerId?.let { "ID $it" }).joinToString(" · "), fontSize = 12.sp, color = Muted)
            if (c.assigned != null || c.botPaused || c.notifyMuted) {
                Spacer(Modifier.height(4.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    c.assigned?.let { Pill("${s.assignedTo}: ${it.name}", PrimaryStrong) }
                    if (c.botPaused) Pill(s.botPausedLabel + (c.botPausedUntil?.let { " · " + Format.time(it) } ?: ""), Warn)
                    if (c.notifyMuted) Pill(s.boxMuted, Muted)
                }
            }
        }
        val context = LocalContext.current
        val digits = c.number.filter { it.isDigit() }
        if (digits.length >= 10) {
            IconButton(onClick = { Phone.dial(context, digits, c.name ?: digits) }) {
                Icon(Icons.Filled.Call, s.call, tint = PrimaryStrong)
            }
        }
        val co = SessionStore.company()
        val headerId = c.headerId
        if (navController != null && headerId != null && (co?.can("customers") == true || co?.can("bills") == true || co?.can("field_jobs") == true)) {
            IconButton(onClick = { navController.navigate(Routes.customerDetail(headerId)) }) {
                Icon(Icons.Filled.Person, s.openCustomer, tint = PrimaryStrong)
            }
        }
        Box {
            IconButton(onClick = { menu = true }) { Icon(Icons.Filled.MoreVert, s.chatActions, tint = Ink) }
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }, modifier = Modifier.background(Surface)) {
                DropdownMenuItem(text = { Text(s.takeChat) }, onClick = { menu = false; onTake() })
                if (navController != null && headerId != null && co?.can("tickets") == true) {
                    DropdownMenuItem(text = { Text(s.newTicket) }, onClick = { menu = false; navController.navigate(Routes.newTicket(headerId)) })
                }
                DropdownMenuItem(text = { Text(s.assignChat) }, onClick = { menu = false; picking = true })
                DropdownMenuItem(text = { Text(if (c.botPaused) s.resumeBot else s.pauseBot) },
                    onClick = { menu = false; onBot(!c.botPaused) })
                DropdownMenuItem(text = { Text(if (c.notifyMuted) s.unmuteChat else s.muteChat) },
                    onClick = { menu = false; onMute(!c.notifyMuted) })
            }
        }
    }
    if (picking) {
        AlertDialog(onDismissRequest = { picking = false }, containerColor = Surface,
            title = { Text(s.assignChat, fontSize = 17.sp) },
            text = {
                Column {
                    (listOf<Member?>(null) + members).forEach { m ->
                        val current = c.assigned?.id == m?.id
                        Text(m?.name ?: s.assignNobody, fontSize = 15.sp, color = if (current) PrimaryStrong else Ink,
                            fontWeight = if (current) FontWeight.SemiBold else FontWeight.Normal,
                            modifier = Modifier.fillMaxWidth().clickable { picking = false; onAssign(m?.id) }.padding(vertical = 10.dp))
                    }
                }
            },
            confirmButton = { TextButton(onClick = { picking = false }) { Text(s.close, color = Muted) } })
    }
}

/** "আজ" / "গতকাল" / a date between days, like WhatsApp. */
@Composable
private fun DayHeading(label: String) {
    Box(Modifier.fillMaxWidth().padding(vertical = 6.dp), contentAlignment = Alignment.Center) {
        Text(label, fontSize = 12.sp, color = Muted, fontWeight = FontWeight.Medium,
            modifier = Modifier.clip(RoundedCornerShape(8.dp)).background(Surface).border(1.dp, Line, RoundedCornerShape(8.dp))
                .padding(horizontal = 10.dp, vertical = 3.dp))
    }
}

/** WhatsApp's ticks for our messages: sent, delivered, read (blue); failed in red. */
@Composable
private fun Ticks(status: String?) {
    when (status) {
        "sent" -> Text(" ✓", fontSize = 11.sp, color = Muted)
        "delivered" -> Text(" ✓✓", fontSize = 11.sp, color = Muted)
        "read" -> Text(" ✓✓", fontSize = 11.sp, color = Color(0xFF2563EB), fontWeight = FontWeight.SemiBold)
        "failed" -> Text(" ✕", fontSize = 11.sp, color = Danger, fontWeight = FontWeight.Bold)
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun Bubble(m: ChatMessage, company: String, s: AppStrings, player: ChatPlayer, canDelete: Boolean = false,
    onDelete: () -> Unit = {}) {
    val mine = m.direction != "in"
    val context = LocalContext.current
    val clipboard = LocalClipboardManager.current
    val text = m.body?.takeIf { it.isNotBlank() }
    var menu by remember { mutableStateOf(false) }
    var confirm by remember { mutableStateOf(false) }
    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start) {
        Box {
            Column(Modifier.widthIn(max = 300.dp).clip(RoundedCornerShape(12.dp))
                .background(if (mine) PrimarySoft else Surface)
                .border(1.dp, if (mine) PrimarySoft else Line, RoundedCornerShape(12.dp))
                .combinedClickable(onClick = {}, onLongClick = {
                    // the owner gets copy + delete; everyone else long-presses to copy, as before
                    if (canDelete) menu = true
                    else text?.let {
                        clipboard.setText(AnnotatedString(it))
                        Toast.makeText(context, s.copied, Toast.LENGTH_SHORT).show()
                    }
                })
                .padding(horizontal = 12.dp, vertical = 8.dp)) {
                if (mine) {
                    Text(if (m.sender == "bot") s.senderBot else (m.user ?: ""), fontSize = 11.sp, color = PrimaryStrong,
                        fontWeight = FontWeight.SemiBold)
                }
                if (m.hasMedia) MediaPart(m, company, s, player)
                m.body?.takeIf { it.isNotBlank() && !(m.type == "document" && m.hasMedia) }?.let {
                    Text(it, fontSize = 15.sp, color = Ink, lineHeight = 20.sp)
                }
                Row(Modifier.align(Alignment.End), verticalAlignment = Alignment.CenterVertically) {
                    if (m.status == "test") Text("TEST  ", fontSize = 10.sp, color = Warn, fontWeight = FontWeight.SemiBold)
                    Text(Format.time(m.at), fontSize = 10.sp, color = Muted)
                    if (mine) Ticks(m.status)
                }
            }
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }, modifier = Modifier.background(Surface)) {
                if (text != null) DropdownMenuItem(text = { Text(s.copyText) }, onClick = {
                    menu = false
                    clipboard.setText(AnnotatedString(text))
                    Toast.makeText(context, s.copied, Toast.LENGTH_SHORT).show()
                })
                DropdownMenuItem(text = { Text(s.deleteMessage, color = Danger) }, onClick = { menu = false; confirm = true })
            }
        }
    }
    if (confirm) {
        AlertDialog(onDismissRequest = { confirm = false }, containerColor = Surface,
            text = { Text(s.deleteMessageAsk, fontSize = 15.sp, color = Ink) },
            confirmButton = { TextButton(onClick = { confirm = false; onDelete() }) { Text(s.deleteMessage, color = Danger) } },
            dismissButton = { TextButton(onClick = { confirm = false }) { Text(s.cancel) } })
    }
}

@Composable
private fun MediaPart(m: ChatMessage, company: String, s: AppStrings, player: ChatPlayer) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var failed by remember { mutableStateOf(false) }
    val mime = m.mediaMime ?: ""
    when {
        mime.startsWith("image/") || m.type == "image" || m.type == "sticker" -> {
            var bitmap by remember(m.id) { mutableStateOf<Bitmap?>(null) }
            LaunchedEffect(m.id) { bitmap = runCatching { MediaCache.image(context, company, m.id) }.getOrNull(); failed = bitmap == null }
            val b = bitmap
            if (b != null) {
                Image(b.asImageBitmap(), s.imageLabel, contentScale = ContentScale.FillWidth,
                    modifier = Modifier.padding(vertical = 4.dp).fillMaxWidth().clip(RoundedCornerShape(8.dp))
                        .clickable { scope.launch { open(context, company, m) } })
            } else if (!failed) {
                Box(Modifier.padding(vertical = 4.dp).size(200.dp, 140.dp).clip(RoundedCornerShape(8.dp)).background(Background),
                    contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Primary, strokeWidth = 2.dp, modifier = Modifier.size(22.dp)) }
            }
        }
        mime.startsWith("audio/") || m.type == "audio" || m.type == "voice" -> {
            val playing = player.playingId == m.id
            Row(Modifier.padding(vertical = 4.dp).clip(RoundedCornerShape(8.dp)).background(Background)
                .clickable {
                    if (playing) player.stop()
                    else scope.launch {
                        runCatching { player.play(m.id, MediaCache.file(context, company, m.id, MediaCache.extensionFor(mime)).path) }
                            .onFailure { failed = true }
                    }
                }.padding(horizontal = 10.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(if (playing) Icons.Filled.Stop else Icons.Filled.PlayArrow, null, tint = PrimaryStrong)
                Spacer(Modifier.width(6.dp))
                Text("${s.voiceLabel} · ${if (playing) s.stopLabel else s.playLabel}", fontSize = 14.sp, color = Ink)
            }
        }
        else -> {
            Row(Modifier.padding(vertical = 4.dp).clip(RoundedCornerShape(8.dp)).background(Background)
                .clickable { scope.launch { if (!open(context, company, m)) failed = true } }
                .padding(horizontal = 10.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.Description, null, tint = PrimaryStrong)
                Spacer(Modifier.width(6.dp))
                Text(m.body?.takeIf { m.type == "document" } ?: if (m.type == "video") s.videoLabel else s.fileLabel,
                    fontSize = 14.sp, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                Spacer(Modifier.width(6.dp))
                Text(s.openFile, fontSize = 13.sp, color = PrimaryStrong, fontWeight = FontWeight.SemiBold)
            }
        }
    }
    if (failed) Text(s.mediaFailed, fontSize = 12.sp, color = Danger)
}

/** Opens a downloaded file in another app (gallery, PDF reader, video player). */
private suspend fun open(context: Context, company: String, m: ChatMessage): Boolean = runCatching {
    val file = MediaCache.file(context, company, m.id, MediaCache.extensionFor(m.mediaMime))
    val uri = FileProvider.getUriForFile(context, context.packageName + ".files", file)
    context.startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(uri, m.mediaMime ?: "*/*")
        .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK))
}.isSuccess

private fun describe(context: Context, uri: Uri): Attachment {
    var name = "file"
    context.contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use {
        if (it.moveToFirst()) name = it.getString(0) ?: name
    }
    return Attachment(uri, name, context.contentResolver.getType(uri) ?: "application/octet-stream")
}

/** Plays one voice message at a time. */
private class ChatPlayer {
    var playingId by mutableStateOf<Long?>(null)
        private set
    private var player: MediaPlayer? = null

    fun play(id: Long, path: String) {
        stop()
        player = MediaPlayer().apply {
            setDataSource(path)
            setOnCompletionListener { stop() }
            prepare()
            start()
        }
        playingId = id
    }

    fun stop() {
        player?.runCatching { stop(); release() }
        player = null
        playingId = null
    }
}
