package com.zyrotechbd.desk.ui.screens

import android.content.Intent
import android.media.MediaPlayer
import android.net.Uri
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
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Pause
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.PhoneCallback
import androidx.compose.material.icons.filled.PhoneInTalk
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.SmartToy
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Slider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.media.MediaCache
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.CallLine
import com.zyrotechbd.desk.data.network.CallRow
import com.zyrotechbd.desk.ui.components.Chip
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.DeskTextField
import com.zyrotechbd.desk.ui.components.EmptyText
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.Pill
import com.zyrotechbd.desk.ui.components.RefreshBox
import com.zyrotechbd.desk.ui.components.ScreenBody
import com.zyrotechbd.desk.ui.components.SectionTitle
import com.zyrotechbd.desk.ui.components.SmallAction
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.navigation.Routes
import com.zyrotechbd.desk.ui.strings.AppStrings
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Background
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Line
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.PrimarySoft
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private val BotBlue = Color(0xFF2563EB)

/** "1:05" for [seconds]. */
fun clock(seconds: Int): String = if (seconds >= 3600) "%d:%02d:%02d".format(seconds / 3600, seconds % 3600 / 60, seconds % 60)
else "%d:%02d".format(seconds / 60, seconds % 60)

/**
 * The hotline call log, like the panel's "কল লগ": every call the company's SIP extension saw ringing, newest first;
 * the ones the voice bot answered open to the recording and what each side said.
 */
@Composable
fun CallsScreen(navController: NavHostController, company: String) {
    val s = LocalStrings.current
    val scope = rememberCoroutineScope()
    var filter by rememberSaveable { mutableStateOf("") }    // "", bot, agent, callback
    var query by rememberSaveable { mutableStateOf("") }
    val rows = remember { mutableStateListOf<CallRow>() }
    var nextPage by remember { mutableStateOf<Int?>(null) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val listState = rememberLazyListState()

    suspend fun page(n: Int) = ApiClient.service.calls(company, filter.takeIf { it == "bot" || it == "agent" },
        if (filter == "callback") 1 else null, query.trim().ifEmpty { null }, n)

    suspend fun firstPage(quiet: Boolean = false) {
        if (!quiet) loading = true
        try {
            val res = page(1)
            rows.clear()
            rows.addAll(res.data)
            nextPage = res.nextPage
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
        } finally {
            loading = false
        }
    }

    LaunchedEffect(filter, query) {
        if (query.isNotBlank()) delay(350)
        firstPage()
        listState.scrollToItem(0)
    }
    // new calls ring in all day: refresh the first page while it is all that is shown, like the panel's 15 s poll
    LaunchedEffect(Unit) {
        while (true) {
            delay(15_000)
            if (!loading && rows.size <= 30) firstPage(quiet = true)
        }
    }

    fun loadMore() {
        val n = nextPage ?: return
        if (loading) return
        loading = true
        val key = filter to query
        scope.launch {
            try {
                val res = page(n)
                if (key != (filter to query)) return@launch
                rows.addAll(res.data.filter { r -> rows.none { it.id == r.id } })
                nextPage = res.nextPage
            } catch (e: Exception) {
                error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            } finally {
                loading = false
            }
        }
    }
    val nearEnd by remember {
        derivedStateOf {
            val last = listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0
            last >= listState.layoutInfo.totalItemsCount - 5
        }
    }
    LaunchedEffect(nearEnd, nextPage) { if (nearEnd) loadMore() }

    Column(Modifier.fillMaxSize()) {
        Column(Modifier.padding(start = 16.dp, end = 16.dp, top = 12.dp)) {
            DeskTextField(query, { query = it }, placeholder = s.cSearch, modifier = Modifier.fillMaxWidth(), clearable = true)
            Spacer(Modifier.height(10.dp))
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(listOf("" to s.fAll, "bot" to s.cBot, "agent" to s.cAgent, "callback" to s.cCallback)) { (key, label) ->
                    Chip(label, filter == key) { filter = key }
                }
            }
            Spacer(Modifier.height(10.dp))
            error?.let {
                MessageBox(it, onRetry = { scope.launch { firstPage() } }, retryLabel = s.retry)
                Spacer(Modifier.height(8.dp))
            }
        }
        RefreshBox({ firstPage(quiet = true) }) {
            LazyColumn(state = listState, contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 24.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxSize()) {
                items(rows, key = { it.id }) { c -> CallRowView(c, s) { navController.navigate(Routes.call(c.id)) } }
                if (loading) item { LoadingBox() }
                if (!loading && rows.isEmpty() && error == null) item { EmptyText(s.cNoCalls) }
            }
        }
    }
}

@Composable
private fun CallIcon(c: CallRow) {
    val (icon, tint) = when {
        c.ringing -> Icons.Filled.PhoneInTalk to Primary
        c.botAnswered -> Icons.Filled.SmartToy to BotBlue
        else -> Icons.Filled.Call to Muted
    }
    Box(Modifier.size(38.dp).clip(CircleShape).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp))
    }
}

private fun answeredBy(c: CallRow, s: AppStrings) = when {
    c.botAnswered -> s.cWhoBot
    c.ringing -> s.cRinging
    else -> s.cAgent
}

@Composable
private fun CallRowView(c: CallRow, s: AppStrings, onClick: () -> Unit) {
    DeskCard(Modifier.clickable { onClick() }) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.Top) {
            CallIcon(c)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(c.customer?.name ?: c.caller ?: s.cUnknown, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, color = Ink,
                    maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(listOfNotNull(c.customer?.let { c.caller }, c.customer?.customerId?.let { "ID $it" }).joinToString(" · ")
                    .ifEmpty { s.cUnknown }, fontSize = 12.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                c.callbackReason?.let {
                    Spacer(Modifier.height(4.dp))
                    Text("${s.cCallbackLabel}: $it", fontSize = 12.sp, color = Warn, maxLines = 2, overflow = TextOverflow.Ellipsis)
                }
            }
            Spacer(Modifier.width(8.dp))
            Column(horizontalAlignment = Alignment.End) {
                Text(Format.shortTime(c.startedAt, s.yesterday), fontSize = 12.sp, color = Muted)
                Spacer(Modifier.height(4.dp))
                Pill(answeredBy(c, s) + (c.botSeconds?.takeIf { it > 0 }?.let { " · ${clock(it)}" } ?: ""),
                    if (c.botAnswered) BotBlue else if (c.ringing) Primary else Muted)
                c.flag?.let {
                    Spacer(Modifier.height(4.dp))
                    Pill(it, Danger)
                }
            }
        }
    }
}

/** One call: who called (and their customer page), the recording with a seek bar, and the conversation. */
@Composable
fun CallDetailScreen(navController: NavHostController, company: String, callId: Long) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var call by remember { mutableStateOf<CallRow?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun load() {
        try {
            call = ApiClient.service.call(company, callId).call
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
        }
    }
    LaunchedEffect(callId) { load() }

    ScreenBody {
        val c = call
        if (c == null) {
            Column(Modifier.padding(16.dp)) {
                error?.let { MessageBox(it, onRetry = { scope.launch { load() } }, retryLabel = s.retry) } ?: LoadingBox()
            }
            return@ScreenBody
        }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            DeskCard {
                Column(Modifier.padding(14.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        CallIcon(c)
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(c.caller ?: s.cUnknown, fontSize = 17.sp, fontWeight = FontWeight.SemiBold, color = Ink)
                            Text(Format.dateTime(c.startedAt), fontSize = 12.sp, color = Muted)
                        }
                        Pill(answeredBy(c, s) + (c.botSeconds?.takeIf { it > 0 }?.let { " · ${clock(it)}" } ?: ""),
                            if (c.botAnswered) BotBlue else Muted)
                    }
                    c.flag?.let {
                        Spacer(Modifier.height(8.dp))
                        Pill(it, Danger)
                    }
                    c.callbackReason?.let {
                        Spacer(Modifier.height(8.dp))
                        Text("${s.cCallbackLabel}: $it", fontSize = 14.sp, color = Warn, fontWeight = FontWeight.Medium)
                    }
                    Spacer(Modifier.height(12.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        c.caller?.let { number ->
                            SmallAction(s.cDial, Icons.Filled.PhoneCallback) {
                                context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$number")))
                            }
                        }
                        val customer = c.customer
                        if (customer != null && SessionStore.company()?.can("customers") == true) {
                            SmallAction("${customer.name ?: ""} (ID ${customer.customerId ?: ""})", Icons.Filled.Person) {
                                navController.navigate(Routes.customerDetail(customer.headerId))
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(12.dp))
            if (c.botAnswered) {
                SectionTitle(s.cRecording)
                if (c.hasRecording) RecordingPlayer(company, c.id, c.botSeconds ?: 0)
                else Text(s.cNoRecording, fontSize = 13.sp, color = Muted)
                c.botError?.let {
                    Spacer(Modifier.height(6.dp))
                    Text(it, fontSize = 12.sp, color = Danger)
                }
                Spacer(Modifier.height(12.dp))
                SectionTitle(s.cTranscript)
                val lines = c.transcript.orEmpty()
                if (lines.isEmpty()) Text(s.cNoTranscript, fontSize = 13.sp, color = Muted)
                lines.forEach { TranscriptLine(it, s) }
            }
        }
    }
}

@Composable
private fun TranscriptLine(t: CallLine, s: AppStrings) {
    val bot = t.who == "bot"
    Box(Modifier.fillMaxWidth().padding(vertical = 3.dp), contentAlignment = if (bot) Alignment.CenterStart else Alignment.CenterEnd) {
        Column(Modifier.widthIn(max = 300.dp).clip(RoundedCornerShape(12.dp))
            .background(if (bot) BotBlue.copy(alpha = 0.10f) else PrimarySoft).padding(horizontal = 10.dp, vertical = 6.dp)) {
            Text("${if (bot) s.cWhoBot else s.cWhoCaller} · ${clock(t.at)}", fontSize = 11.sp, color = Muted)
            Text(t.text, fontSize = 14.sp, color = Ink)
        }
    }
}

/** The call's recording: fetched once into the cache, then play / pause and a seek bar. */
@Composable
private fun RecordingPlayer(company: String, callId: Long, seconds: Int) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var player by remember { mutableStateOf<MediaPlayer?>(null) }
    var preparing by remember { mutableStateOf(false) }
    var playing by remember { mutableStateOf(false) }
    var failed by remember { mutableStateOf(false) }
    var position by remember { mutableIntStateOf(0) }
    var duration by remember { mutableIntStateOf(seconds * 1000) }
    var dragging by remember { mutableStateOf<Float?>(null) }
    var drag by remember { mutableFloatStateOf(0f) }

    DisposableEffect(callId) { onDispose { player?.runCatching { stop(); release() }; player = null } }
    LaunchedEffect(playing) {
        while (playing) {
            player?.let { p -> runCatching { if (dragging == null) position = p.currentPosition } }
            delay(250)
        }
    }

    fun toggle() {
        val p = player
        if (p != null) {
            if (p.isPlaying) { p.pause(); playing = false } else { p.start(); playing = true }
            return
        }
        scope.launch {
            preparing = true
            failed = false
            try {
                val file = MediaCache.recording(context, company, callId)
                player = MediaPlayer().apply {
                    setDataSource(file.path)
                    setOnCompletionListener { playing = false; position = 0; seekTo(0) }
                    prepare()
                    if (this.duration > 0) duration = this.duration
                    seekTo(position)
                    start()
                }
                playing = true
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                failed = true
            } finally {
                preparing = false
            }
        }
    }

    DeskCard {
        Column(Modifier.padding(horizontal = 12.dp, vertical = 8.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(44.dp).clip(CircleShape).background(Primary).clickable(enabled = !preparing) { toggle() },
                    contentAlignment = Alignment.Center) {
                    if (preparing) CircularProgressIndicator(Modifier.size(20.dp), color = Color.White, strokeWidth = 2.dp)
                    else Icon(if (playing) Icons.Filled.Pause else Icons.Filled.PlayArrow, contentDescription = null, tint = Color.White)
                }
                Spacer(Modifier.width(8.dp))
                Column(Modifier.weight(1f)) {
                    Slider(
                        value = dragging ?: if (duration > 0) position.toFloat() / duration else 0f,
                        onValueChange = { dragging = it; drag = it },
                        onValueChangeFinished = {
                            val to = (drag * duration).toInt()
                            position = to
                            player?.seekTo(to)
                            dragging = null
                        },
                        colors = SliderDefaults.colors(thumbColor = PrimaryStrong, activeTrackColor = Primary, inactiveTrackColor = Line),
                    )
                    Row {
                        Text(clock(((dragging?.let { it * duration } ?: position.toFloat()) / 1000).toInt()), fontSize = 12.sp, color = Muted,
                            modifier = Modifier.weight(1f))
                        Text(clock(duration / 1000), fontSize = 12.sp, color = Muted)
                    }
                }
            }
            if (preparing) Text(s.cLoadingRec, fontSize = 12.sp, color = Muted)
            if (failed) Text(s.cPlayFailed, fontSize = 12.sp, color = Danger)
        }
    }
}
