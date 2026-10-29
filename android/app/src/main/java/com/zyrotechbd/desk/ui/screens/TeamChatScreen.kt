package com.zyrotechbd.desk.ui.screens

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.media.MediaPlayer
import android.media.MediaRecorder
import android.net.Uri
import android.os.Build
import android.os.SystemClock
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Groups
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.Mic
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.NotificationsOff
import androidx.compose.material.icons.filled.Photo
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Stop
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
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
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.core.Badges
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.media.MediaCache
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.MuteRequest
import com.zyrotechbd.desk.data.network.TeamChatInfo
import com.zyrotechbd.desk.data.network.TeamChatRow
import com.zyrotechbd.desk.data.network.TeamMember
import com.zyrotechbd.desk.data.network.TeamMessage
import com.zyrotechbd.desk.data.network.TeamTextRequest
import com.zyrotechbd.desk.push.Push
import com.zyrotechbd.desk.ui.components.CustomerAvatar
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.EmptyText
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.navigation.Routes
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
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.ByteArrayOutputStream
import java.io.File

/**
 * টিম চ্যাট: the member's chats with other Desk users (one to one, and the walkie-talkie groups), latest first, and
 * a "new chat" button to write to anyone in the company. Refreshes on a push and every few seconds while open.
 */
@Composable
fun TeamChatsScreen(navController: NavHostController, company: String) {
    val s = LocalStrings.current
    val rows = remember { mutableStateListOf<TeamChatRow>() }
    var members by remember { mutableStateOf<List<TeamMember>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var picking by remember { mutableStateOf(false) }

    LaunchedEffect(reload) {
        while (true) {
            try {
                val res = ApiClient.service.teamChats(company)
                rows.clear()
                rows.addAll(res.chats)
                members = res.members
                Badges.teamUnread.value = res.unread
                error = null
            } catch (e: Exception) {
                error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            } finally {
                loading = false
            }
            withTimeoutOrNull(15_000) { Push.teamEvents.first() }
        }
    }

    Box(Modifier.fillMaxSize()) {
        LazyColumn(contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 12.dp, bottom = 96.dp),
            modifier = Modifier.fillMaxSize().clipToBounds()) {
            error?.let { e -> item { MessageBox(e, onRetry = { reload++ }, retryLabel = s.retry); Spacer(Modifier.height(8.dp)) } }
            if (rows.isNotEmpty()) item {
                DeskCard {
                    rows.forEachIndexed { i, c ->
                        if (i > 0) HorizontalDivider(color = Line)
                        TeamChatRowView(c, s) { navController.navigate(Routes.teamChat(c.ref)) }
                    }
                }
            }
            if (loading) item { LoadingBox() }
            if (!loading && rows.isEmpty() && error == null) item { EmptyText(s.teamNoChats) }
        }
        ExtendedFloatingActionButton(onClick = { picking = true }, containerColor = Primary, contentColor = Surface,
            icon = { Icon(Icons.Filled.Edit, null) }, text = { Text(s.teamNewChat) },
            modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp))
    }

    if (picking) {
        AlertDialog(onDismissRequest = { picking = false }, containerColor = Surface,
            title = { Text(s.teamPickMember, fontSize = 17.sp) },
            text = {
                LazyColumn(Modifier.heightIn(max = 420.dp)) {
                    if (members.isEmpty()) item { Text(s.teamNoMembers, color = Muted, fontSize = 14.sp) }
                    itemsIndexed(members, key = { _, m -> m.id }) { _, m ->
                        Row(Modifier.fillMaxWidth().clickable {
                            picking = false
                            navController.navigate(Routes.teamChat("u${m.id}"))
                        }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                            CustomerAvatar(m.name, "u${m.id}", size = 34.dp)
                            Spacer(Modifier.width(10.dp))
                            Text(m.name, fontSize = 15.sp, color = Ink, modifier = Modifier.weight(1f))
                            m.role?.let { Text(it.replaceFirstChar { c -> c.uppercase() }, fontSize = 12.sp, color = Muted) }
                        }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { picking = false }) { Text(s.close, color = Muted) } })
    }
}

@Composable
private fun TeamChatRowView(c: TeamChatRow, s: AppStrings, onClick: () -> Unit) {
    val hot = c.unread > 0
    Row(Modifier.fillMaxWidth().clickable { onClick() }.padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically) {
        if (c.kind == "group") {
            Box(Modifier.size(42.dp).background(PrimarySoft, CircleShape), contentAlignment = Alignment.Center) {
                Icon(Icons.Filled.Groups, null, tint = PrimaryStrong, modifier = Modifier.size(24.dp))
            }
        } else CustomerAvatar(c.title, c.ref, size = 42.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(c.title, fontSize = 15.sp, fontWeight = if (hot) FontWeight.SemiBold else FontWeight.Medium, color = Ink,
                    maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                Spacer(Modifier.width(8.dp))
                Text(Format.shortTime(c.last?.at, s.yesterday), fontSize = 12.sp,
                    color = if (hot) PrimaryStrong else Muted, fontWeight = if (hot) FontWeight.SemiBold else FontWeight.Normal)
            }
            Spacer(Modifier.height(2.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                val last = c.last
                val preview = when {
                    last == null -> if (c.kind == "group") s.teamMembers(c.members) else ""
                    last.mine -> "${s.teamMe}: ${last.text}"
                    c.kind == "group" && last.sender != null -> "${last.sender}: ${last.text}"
                    else -> last.text
                }
                Text(preview.replace('\n', ' '), fontSize = 13.sp, color = if (hot) Ink else Muted, maxLines = 1,
                    overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                if (c.muted) Icon(Icons.Filled.NotificationsOff, null, tint = Muted, modifier = Modifier.padding(start = 4.dp).size(15.dp))
                if (hot) Box(Modifier.padding(start = 6.dp).heightIn(min = 20.dp).clip(RoundedCornerShape(10.dp)).background(Primary)
                    .padding(horizontal = 6.dp, vertical = 1.dp), contentAlignment = Alignment.Center) {
                    Text(if (c.unread > 99) "99+" else c.unread.toString(), fontSize = 11.sp, color = Surface, fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

private data class Photo(val uri: Uri, val mime: String)

/** One team chat: messages, text / photo / hold-to-record voice, delete own (15 min) or any as owner, mute. */
@Composable
fun TeamChatScreen(company: String, ref: String) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var chat by remember { mutableStateOf<TeamChatInfo?>(null) }
    val messages = remember { mutableStateListOf<TeamMessage>() }
    var hasOlder by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var text by rememberSaveable { mutableStateOf("") }
    var photo by remember { mutableStateOf<Photo?>(null) }
    var sending by remember { mutableStateOf(false) }
    val listState = rememberLazyListState()
    val player = remember { TeamPlayer() }
    val recorder = remember { VoiceRecorder(context) }
    DisposableEffect(Unit) { onDispose { player.stop(); recorder.stop(false) } }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) photo = Photo(uri, context.contentResolver.getType(uri) ?: "image/jpeg")
    }
    val askMic = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        if (!ok) notice = s.teamMicNeeded
    }

    // while this chat is on screen its pushes show no notification; they refresh it at once instead
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    DisposableEffect(ref, lifecycle) {
        val obs = LifecycleEventObserver { _, e ->
            if (e == Lifecycle.Event.ON_RESUME) Push.visibleTeamChat = ref
            if (e == Lifecycle.Event.ON_PAUSE) Push.visibleTeamChat = null
        }
        lifecycle.addObserver(obs)
        if (lifecycle.currentState.isAtLeast(Lifecycle.State.RESUMED)) Push.visibleTeamChat = ref
        onDispose {
            lifecycle.removeObserver(obs)
            if (Push.visibleTeamChat == ref) Push.visibleTeamChat = null
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

    /** The latest 50 merged into what is shown (new ones added; deleted / seen changes replace the old copy). */
    fun merge(latest: List<TeamMessage>): Int {
        val byId = messages.withIndex().associate { (i, m) -> m.id to i }
        var added = 0
        latest.forEach { m ->
            val i = byId[m.id]
            if (i == null) { messages.add(m); added++ } else if (messages[i] != m) messages[i] = m
        }
        if (added > 0) messages.sortBy { it.id }
        return added
    }

    LaunchedEffect(ref, reload) {
        var first = messages.isEmpty()
        while (true) {
            try {
                val d = ApiClient.service.teamChat(company, ref)
                chat = d.chat
                if (first) hasOlder = d.hasOlder
                val follow = first || atBottom
                val added = merge(d.messages)
                if (added > 0) {
                    if (follow) listState.scrollToItem(messages.size)
                    else unseen += added
                }
                first = false
                error = null
            } catch (e: Exception) {
                error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            }
            withTimeoutOrNull(5_000) { Push.teamEvents.first { it == ref } }
        }
    }

    fun loadOlder() {
        val oldest = messages.firstOrNull()?.id ?: return
        scope.launch {
            runCatching { ApiClient.service.teamChat(company, ref, before = oldest) }.onSuccess { d ->
                hasOlder = d.hasOlder
                messages.addAll(0, d.messages.filter { m -> messages.none { it.id == m.id } })
            }
        }
    }

    fun upload(kind: String, file: MultipartBody.Part, caption: String?, seconds: Int?) {
        sending = true
        notice = null
        scope.launch {
            try {
                val plain = "text/plain".toMediaTypeOrNull()
                val sent = ApiClient.service.teamSendFile(company, ref, file, kind.toRequestBody(plain),
                    caption?.toRequestBody(plain), seconds?.toString()?.toRequestBody(plain)).message
                merge(listOf(sent))
                listState.scrollToItem(messages.size)
                if (kind == "image") { photo = null; text = "" }
            } catch (e: Exception) {
                notice = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            } finally {
                sending = false
            }
        }
    }

    fun send() {
        val p = photo
        if (p != null) {
            scope.launch {
                val bytes = withContext(Dispatchers.IO) { runCatching { shrink(context, p.uri) }.getOrNull() }
                if (bytes == null) { notice = s.mediaFailed; return@launch }
                upload("image", MultipartBody.Part.createFormData("file", "photo.jpg", bytes.toRequestBody("image/jpeg".toMediaTypeOrNull())),
                    text.trim().takeIf { it.isNotEmpty() }, null)
            }
            return
        }
        val body = text.trim()
        if (body.isEmpty()) return
        sending = true
        notice = null
        scope.launch {
            try {
                merge(listOf(ApiClient.service.teamSend(company, ref, TeamTextRequest(body)).message))
                listState.scrollToItem(messages.size)
                text = ""
            } catch (e: Exception) {
                notice = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            } finally {
                sending = false
            }
        }
    }

    fun sendVoice(file: File, seconds: Int) = upload("voice",
        MultipartBody.Part.createFormData("file", file.name, file.asRequestBody("audio/mp4".toMediaTypeOrNull())), null, seconds)

    val c = chat
    if (c == null) {
        Column(Modifier.fillMaxSize().padding(16.dp)) {
            if (error != null) MessageBox(error!!, onRetry = { reload++ }, retryLabel = s.retry) else LoadingBox()
        }
        return
    }

    Column(Modifier.fillMaxSize()) {
        TeamChatHeader(c, s) { muted ->
            scope.launch {
                runCatching { ApiClient.service.teamMute(company, ref, MuteRequest(muted)) }
                    .onSuccess { chat = c.copy(muted = it.muted) }
                    .onFailure { notice = ApiClient.errorMessage(it as? Exception ?: Exception(it), s.loadFailed, s.offline) }
            }
        }
        HorizontalDivider(color = Line)
        Box(Modifier.weight(1f).fillMaxWidth()) {
            LazyColumn(state = listState, modifier = Modifier.fillMaxSize().background(Background),
                contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                item {
                    if (hasOlder) Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                        TextButton(onClick = { loadOlder() }) { Text(s.teamOlder, color = PrimaryStrong) }
                    }
                }
                itemsIndexed(messages, key = { _, m -> m.id }) { i, m ->
                    if (i == 0 || !Format.sameDay(messages[i - 1].at, m.at)) TeamDayHeading(Format.dayLabel(m.at, s.today, s.yesterday))
                    TeamBubble(m, group = c.kind == "group", company = company, s = s, player = player, onDelete = {
                        scope.launch {
                            try {
                                ApiClient.service.teamDelete(company, ref, m.id)
                                val i2 = messages.indexOfFirst { it.id == m.id }
                                if (i2 >= 0) messages[i2] = m.copy(deleted = true, text = null, media = null, canDelete = false)
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
                    .clickable { scope.launch { listState.animateScrollToItem(messages.size) } }
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
                MessageBox(it)
                Spacer(Modifier.height(6.dp))
            }
            TeamComposer(text, { text = it }, photo, { photo = null }, sending, recorder, s,
                onPhoto = { picker.launch("image/*") },
                onSend = { send() },
                onMicDenied = { askMic.launch(Manifest.permission.RECORD_AUDIO) },
                onVoice = { f, secs -> sendVoice(f, secs) },
                onNotice = { notice = it })
        }
    }
}

@Composable
private fun TeamChatHeader(c: TeamChatInfo, s: AppStrings, onMute: (Boolean) -> Unit) {
    var menu by remember { mutableStateOf(false) }
    Row(Modifier.fillMaxWidth().background(Surface).padding(start = 16.dp, end = 4.dp, top = 10.dp, bottom = 10.dp),
        verticalAlignment = Alignment.CenterVertically) {
        if (c.kind == "group") {
            Box(Modifier.size(40.dp).background(PrimarySoft, CircleShape), contentAlignment = Alignment.Center) {
                Icon(Icons.Filled.Groups, null, tint = PrimaryStrong, modifier = Modifier.size(22.dp))
            }
        } else CustomerAvatar(c.title, c.ref, size = 40.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(c.title, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(if (c.kind == "group") c.members.joinToString(", ") else s.teamPrivate, fontSize = 12.sp, color = Muted,
                maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        if (c.muted) Icon(Icons.Filled.NotificationsOff, s.teamMutedLabel, tint = Muted, modifier = Modifier.size(18.dp))
        Box {
            IconButton(onClick = { menu = true }) { Icon(Icons.Filled.MoreVert, null, tint = Ink) }
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }, modifier = Modifier.background(Surface)) {
                DropdownMenuItem(text = { Text(if (c.muted) s.teamUnmute else s.teamMute) }, onClick = { menu = false; onMute(!c.muted) })
            }
        }
    }
}

@Composable
private fun TeamDayHeading(label: String) {
    Box(Modifier.fillMaxWidth().padding(vertical = 6.dp), contentAlignment = Alignment.Center) {
        Text(label, fontSize = 12.sp, color = Muted, fontWeight = FontWeight.Medium,
            modifier = Modifier.clip(RoundedCornerShape(8.dp)).background(Surface).border(1.dp, Line, RoundedCornerShape(8.dp))
                .padding(horizontal = 10.dp, vertical = 3.dp))
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun TeamBubble(m: TeamMessage, group: Boolean, company: String, s: AppStrings, player: TeamPlayer, onDelete: () -> Unit) {
    val context = LocalContext.current
    val clipboard = LocalClipboardManager.current
    val text = m.text?.takeIf { it.isNotBlank() }
    var menu by remember { mutableStateOf(false) }
    var confirm by remember { mutableStateOf(false) }
    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (m.mine) Arrangement.End else Arrangement.Start) {
        Box {
            Column(Modifier.widthIn(max = 300.dp).clip(RoundedCornerShape(12.dp))
                .background(if (m.mine) PrimarySoft else Surface)
                .border(1.dp, if (m.mine) PrimarySoft else Line, RoundedCornerShape(12.dp))
                .combinedClickable(onClick = {}, onLongClick = { if (!m.deleted && (text != null || m.canDelete)) menu = true })
                .padding(horizontal = 12.dp, vertical = 8.dp)) {
                if (!m.mine && group) Text(m.sender, fontSize = 11.sp, color = PrimaryStrong, fontWeight = FontWeight.SemiBold)
                if (m.deleted) {
                    Text("🚫 ${s.teamDeleted}", fontSize = 14.sp, color = Muted)
                } else {
                    m.media?.let { media ->
                        if (media.gone) Text((if (media.type == "voice") "🎤 " else "📷 ") + s.teamFileGone, fontSize = 14.sp, color = Muted)
                        else if (media.type == "voice") VoicePart(m, media.seconds, company, s, player)
                        else PhotoPart(m, company, s)
                    }
                    text?.let { Text(it, fontSize = 15.sp, color = Ink, lineHeight = 20.sp) }
                }
                Row(Modifier.align(Alignment.End), verticalAlignment = Alignment.CenterVertically) {
                    Text(Format.time(m.at), fontSize = 10.sp, color = Muted)
                    if (m.mine && !group && !m.deleted) {
                        Text(if (m.seen) " ✓✓" else " ✓", fontSize = 11.sp, color = if (m.seen) Color(0xFF2563EB) else Muted,
                            fontWeight = if (m.seen) FontWeight.SemiBold else FontWeight.Normal)
                    }
                }
            }
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }, modifier = Modifier.background(Surface)) {
                if (text != null) DropdownMenuItem(text = { Text(s.copyText) }, onClick = {
                    menu = false
                    clipboard.setText(AnnotatedString(text))
                    Toast.makeText(context, s.copied, Toast.LENGTH_SHORT).show()
                })
                if (m.canDelete) DropdownMenuItem(text = { Text(s.deleteMessage, color = Danger) }, onClick = { menu = false; confirm = true })
            }
        }
    }
    if (confirm) {
        AlertDialog(onDismissRequest = { confirm = false }, containerColor = Surface,
            text = { Text(s.teamDeleteAsk, fontSize = 15.sp, color = Ink) },
            confirmButton = { TextButton(onClick = { confirm = false; onDelete() }) { Text(s.deleteMessage, color = Danger) } },
            dismissButton = { TextButton(onClick = { confirm = false }) { Text(s.cancel) } })
    }
}

@Composable
private fun PhotoPart(m: TeamMessage, company: String, s: AppStrings) {
    val context = LocalContext.current
    var bitmap by remember(m.id) { mutableStateOf<Bitmap?>(null) }
    var failed by remember(m.id) { mutableStateOf(false) }
    var big by remember { mutableStateOf(false) }
    LaunchedEffect(m.id) { bitmap = runCatching { MediaCache.teamImage(context, company, m.id) }.getOrNull(); failed = bitmap == null }
    val b = bitmap
    when {
        b != null -> Image(b.asImageBitmap(), s.imageLabel, contentScale = ContentScale.FillWidth,
            modifier = Modifier.padding(vertical = 4.dp).fillMaxWidth().clip(RoundedCornerShape(8.dp)).clickable { big = true })
        failed -> Text(s.mediaFailed, fontSize = 12.sp, color = Danger)
        else -> Box(Modifier.padding(vertical = 4.dp).size(200.dp, 140.dp).clip(RoundedCornerShape(8.dp)).background(Background),
            contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Primary, strokeWidth = 2.dp, modifier = Modifier.size(22.dp)) }
    }
    if (big && b != null) {
        androidx.compose.ui.window.Dialog(onDismissRequest = { big = false },
            properties = androidx.compose.ui.window.DialogProperties(usePlatformDefaultWidth = false)) {
            Box(Modifier.fillMaxSize().background(Color.Black).clickable { big = false }, contentAlignment = Alignment.Center) {
                Image(b.asImageBitmap(), s.imageLabel, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxWidth())
                Icon(Icons.Filled.Close, s.close, tint = Color.White, modifier = Modifier.align(Alignment.TopEnd).padding(20.dp).size(28.dp))
            }
        }
    }
}

@Composable
private fun VoicePart(m: TeamMessage, seconds: Int?, company: String, s: AppStrings, player: TeamPlayer) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var failed by remember(m.id) { mutableStateOf(false) }
    val playing = player.playingId == m.id
    Row(Modifier.padding(vertical = 4.dp).clip(RoundedCornerShape(8.dp)).background(Background)
        .clickable {
            if (playing) player.stop()
            else scope.launch {
                runCatching { player.play(m.id, MediaCache.teamFile(context, company, m.id, "audio").path) }.onFailure { failed = true }
            }
        }.padding(horizontal = 10.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(if (playing) Icons.Filled.Stop else Icons.Filled.PlayArrow, null, tint = PrimaryStrong)
        Spacer(Modifier.width(6.dp))
        Text("🎤 ${s.voiceLabel}" + (seconds?.let { " · " + clock(it) } ?: ""), fontSize = 14.sp, color = Ink)
    }
    if (failed) Text(s.mediaFailed, fontSize = 12.sp, color = Danger)
}


/** Text box, photo, and a mic to hold while talking (released = sent, under a second = a hint instead). */
@Composable
private fun TeamComposer(text: String, onText: (String) -> Unit, photo: Photo?, onRemovePhoto: () -> Unit, sending: Boolean,
                         recorder: VoiceRecorder, s: AppStrings, onPhoto: () -> Unit, onSend: () -> Unit, onMicDenied: () -> Unit,
                         onVoice: (File, Int) -> Unit, onNotice: (String) -> Unit) {
    val context = LocalContext.current
    var recording by remember { mutableStateOf(false) }
    var secs by remember { mutableIntStateOf(0) }
    LaunchedEffect(recording) {
        secs = 0
        while (recording) {
            delay(250)
            secs = recorder.seconds()
            if (secs >= VoiceRecorder.MAX_SECONDS) {   // the limit: send what there is
                recording = false
                recorder.stop(true)?.let { (f, n) -> onVoice(f, n) }
            }
        }
    }
    Column {
        photo?.let { p ->
            Row(Modifier.fillMaxWidth().padding(bottom = 6.dp).clip(RoundedCornerShape(8.dp)).background(PrimarySoft)
                .padding(horizontal = 10.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                val thumb by produceThumb(context, p.uri)
                thumb?.let { Image(it.asImageBitmap(), null, contentScale = ContentScale.Crop, modifier = Modifier.size(44.dp).clip(RoundedCornerShape(6.dp))) }
                Spacer(Modifier.width(8.dp))
                Text(s.teamPhotoCaption, fontSize = 13.sp, color = Muted, modifier = Modifier.weight(1f))
                Icon(Icons.Filled.Close, s.removeFile, tint = Muted, modifier = Modifier.size(18.dp).clickable { onRemovePhoto() })
            }
        }
        Row(verticalAlignment = Alignment.Bottom) {
            if (recording) {
                Row(Modifier.weight(1f).heightIn(min = 44.dp).clip(RoundedCornerShape(22.dp)).background(Color(0xFFFEE2E2))
                    .padding(horizontal = 14.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(10.dp).background(Danger, CircleShape))
                    Spacer(Modifier.width(8.dp))
                    Text(clock(secs), fontSize = 15.sp, color = Danger, fontWeight = FontWeight.SemiBold)
                    Spacer(Modifier.width(8.dp))
                    Text(s.teamRecording, fontSize = 13.sp, color = Danger, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            } else {
                IconButton(onClick = onPhoto, enabled = !sending) { Icon(Icons.Filled.Photo, s.imageLabel, tint = Muted) }
                Box(Modifier.weight(1f).heightIn(min = 44.dp).clip(RoundedCornerShape(8.dp)).border(1.dp, InputLine, RoundedCornerShape(8.dp))
                    .padding(horizontal = 12.dp, vertical = 10.dp)) {
                    BasicTextField(value = text, onValueChange = onText, maxLines = 6,
                        textStyle = TextStyle(color = Ink, fontSize = 15.sp), modifier = Modifier.fillMaxWidth(), cursorBrush = SolidColor(Primary))
                    if (text.isEmpty()) Text(s.teamHint, color = Muted, fontSize = 15.sp)
                }
            }
            Spacer(Modifier.width(8.dp))
            val canSend = !sending && (text.isNotBlank() || photo != null)
            if (canSend || sending) {
                Box(Modifier.size(44.dp).clip(CircleShape).background(if (canSend) Primary else InputLine)
                    .clickable(enabled = canSend) { onSend() }, contentAlignment = Alignment.Center) {
                    if (sending) CircularProgressIndicator(color = Surface, strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
                    else Icon(Icons.AutoMirrored.Filled.Send, s.sendBtn, tint = Surface, modifier = Modifier.size(20.dp))
                }
            } else {
                // hold to talk, like the walkie-talkie
                Box(Modifier.size(if (recording) 56.dp else 44.dp).clip(CircleShape).background(if (recording) Danger else Primary)
                    .pointerInput(Unit) {
                        detectTapGestures(onPress = {
                            if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
                                onMicDenied()
                                return@detectTapGestures
                            }
                            if (!recorder.start()) {
                                onNotice(s.teamMicFailed)
                                return@detectTapGestures
                            }
                            recording = true
                            tryAwaitRelease()
                            if (!recording) return@detectTapGestures   // the time limit already sent it
                            recording = false
                            val done = recorder.stop(true)
                            if (done == null) Toast.makeText(context, s.teamHoldToRecord, Toast.LENGTH_SHORT).show()
                            else onVoice(done.first, done.second)
                        })
                    }, contentAlignment = Alignment.Center) {
                    Icon(Icons.Filled.Mic, s.teamHoldToRecord, tint = Surface, modifier = Modifier.size(if (recording) 28.dp else 22.dp))
                }
            }
        }
    }
}

@Composable
private fun produceThumb(context: Context, uri: Uri) = androidx.compose.runtime.produceState<Bitmap?>(null, uri) {
    value = withContext(Dispatchers.IO) {
        runCatching {
            context.contentResolver.openInputStream(uri)!!.use { BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = 8 }) }
        }.getOrNull()
    }
}

/** A picked photo as a JPEG at most 1600 px on its long side (phones take 4-12 MB pictures; the server keeps 1600 px). */
private fun shrink(context: Context, uri: Uri): ByteArray {
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    context.contentResolver.openInputStream(uri)!!.use { BitmapFactory.decodeStream(it, null, bounds) }
    var sample = 1
    while (maxOf(bounds.outWidth, bounds.outHeight) / (sample * 2) >= 1600) sample *= 2
    var bmp = context.contentResolver.openInputStream(uri)!!.use {
        BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = sample })
    } ?: error("not an image")
    val scale = 1600f / maxOf(bmp.width, bmp.height)
    if (scale < 1f) bmp = Bitmap.createScaledBitmap(bmp, (bmp.width * scale).toInt(), (bmp.height * scale).toInt(), true)
    bmp = rotateByExif(context, uri, bmp)
    return ByteArrayOutputStream().use { out -> bmp.compress(Bitmap.CompressFormat.JPEG, 85, out); out.toByteArray() }
}

/** Camera photos are often stored sideways with an EXIF turn; the server drops EXIF, so turn them here. */
private fun rotateByExif(context: Context, uri: Uri, bmp: Bitmap): Bitmap {
    val degrees = runCatching {
        context.contentResolver.openInputStream(uri)!!.use {
            when (android.media.ExifInterface(it).getAttributeInt(
                android.media.ExifInterface.TAG_ORIENTATION, android.media.ExifInterface.ORIENTATION_NORMAL)) {
                android.media.ExifInterface.ORIENTATION_ROTATE_90 -> 90f
                android.media.ExifInterface.ORIENTATION_ROTATE_180 -> 180f
                android.media.ExifInterface.ORIENTATION_ROTATE_270 -> 270f
                else -> 0f
            }
        }
    }.getOrDefault(0f)
    if (degrees == 0f) return bmp
    val matrix = android.graphics.Matrix().apply { postRotate(degrees) }
    return Bitmap.createBitmap(bmp, 0, 0, bmp.width, bmp.height, matrix, true)
}

/** Records one voice message as AAC in MP4 (plays everywhere, about 0.5 MB a minute). */
class VoiceRecorder(private val context: Context) {
    companion object {
        const val MAX_SECONDS = 120
    }

    private var rec: MediaRecorder? = null
    private var file: File? = null
    private var started = 0L

    fun start(): Boolean {
        stop(false)
        val f = File(context.cacheDir, "voice-${System.currentTimeMillis()}.m4a")
        return try {
            val r = if (Build.VERSION.SDK_INT >= 31) MediaRecorder(context) else @Suppress("DEPRECATION") MediaRecorder()
            rec = r
            r.setAudioSource(MediaRecorder.AudioSource.MIC)
            r.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4)
            r.setAudioEncoder(MediaRecorder.AudioEncoder.AAC)
            r.setAudioChannels(1)
            r.setAudioSamplingRate(44_100)
            r.setAudioEncodingBitRate(64_000)
            r.setOutputFile(f.path)
            r.prepare()
            r.start()
            file = f
            started = SystemClock.elapsedRealtime()
            true
        } catch (e: Exception) {
            rec?.runCatching { release() }
            rec = null
            f.delete()
            false
        }
    }

    fun seconds(): Int = if (rec == null) 0 else ((SystemClock.elapsedRealtime() - started) / 1000).toInt()

    /** Stops; with keep, the file and its length when it is at least a second long, else null (and the file is gone). */
    fun stop(keep: Boolean): Pair<File, Int>? {
        val r = rec ?: return null
        val f = file
        val ms = SystemClock.elapsedRealtime() - started
        rec = null
        file = null
        val ok = runCatching { r.stop() }.isSuccess   // throws when nothing was recorded yet
        r.runCatching { release() }
        if (!keep || !ok || f == null || ms < 1000 || f.length() == 0L) {
            f?.delete()
            return null
        }
        return f to ((ms + 500) / 1000).toInt().coerceAtLeast(1)
    }
}

/** Plays one voice message at a time. */
private class TeamPlayer {
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
