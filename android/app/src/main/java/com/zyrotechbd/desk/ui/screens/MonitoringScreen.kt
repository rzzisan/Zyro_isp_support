package com.zyrotechbd.desk.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ConfirmationNumber
import androidx.compose.material.icons.filled.NetworkPing
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.SwapVert
import androidx.compose.material.icons.filled.Sync
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.MonitorRow
import com.zyrotechbd.desk.data.network.ServerCount
import com.zyrotechbd.desk.ui.components.Chip
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.DeskTextField
import com.zyrotechbd.desk.ui.components.EmptyText
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.RefreshBox
import com.zyrotechbd.desk.ui.components.SmallAction
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.navigation.Routes
import com.zyrotechbd.desk.ui.strings.AppStrings
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.InputLine
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.PrimarySoft
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import com.zyrotechbd.desk.ui.theme.Surface
import java.time.OffsetDateTime
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

fun stateColor(row: MonitorRow): Color = when (row.state) {
    "online" -> Good
    "blocked" -> Warn
    else -> Danger
}

fun monitorStateLabel(state: String, s: AppStrings): String = when (state) {
    "online" -> s.online
    "blocked" -> s.blocked
    else -> s.offlineLabel
}

/** "2 দিন 04:12:09" since [iso], at [nowMs]; the list ticks it every second like the panel. */
fun offlineFor(iso: String?, nowMs: Long, s: AppStrings): String? {
    val since = runCatching { OffsetDateTime.parse(iso).toInstant().toEpochMilli() }.getOrNull() ?: return null
    val t = maxOf(0L, (nowMs - since) / 1000)
    val d = t / 86400
    return (if (d > 0) "$d ${s.mDays} " else "") + "%02d:%02d:%02d".format(t % 86400 / 3600, t % 3600 / 60, t % 60)
}

/**
 * Online client monitoring, like the panel's page: every customer whose line is on, online / offline / blocked from the
 * MikroTik copy (every 2 minutes), per-server counts, and per customer a re-check, traffic, ping, ticket or the customer page.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun MonitoringScreen(navController: NavHostController, company: String) {
    val s = LocalStrings.current
    val scope = rememberCoroutineScope()
    var state by rememberSaveable { mutableStateOf("") }
    var server by rememberSaveable { mutableStateOf("") }
    var query by rememberSaveable { mutableStateOf("") }
    val rows = remember { mutableStateListOf<MonitorRow>() }
    var servers by remember { mutableStateOf<List<ServerCount>>(emptyList()) }
    var updatedAt by remember { mutableStateOf<String?>(null) }
    var nextPage by remember { mutableStateOf<Int?>(null) }
    var loading by remember { mutableStateOf(false) }
    var syncing by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var note by remember { mutableStateOf<String?>(null) }
    var picked by remember { mutableStateOf<MonitorRow?>(null) }
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    val listState = rememberLazyListState()

    suspend fun firstPage(quiet: Boolean = false) {
        if (!quiet) loading = true
        try {
            val res = ApiClient.service.monitoring(company, state.ifEmpty { null }, server.ifEmpty { null },
                query.trim().ifEmpty { null }, 1)
            rows.clear()
            rows.addAll(res.data)
            nextPage = res.nextPage
            res.servers?.let { servers = it }
            updatedAt = res.updatedAt
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
        } finally {
            loading = false
        }
    }

    LaunchedEffect(state, server, query) {
        if (query.isNotBlank()) delay(350)
        firstPage()
        listState.scrollToItem(0)
    }
    // the copy from MikroTik changes every 2 minutes: refresh the first page while it is all that is shown
    LaunchedEffect(Unit) {
        while (true) {
            delay(60_000)
            if (!loading && rows.size <= 50) firstPage(quiet = true)
        }
    }
    LaunchedEffect(Unit) {
        while (true) {
            delay(1_000)
            now = System.currentTimeMillis()
        }
    }

    // next page in the screen's scope, so setting [loading] does not cancel it
    fun loadMore() {
        val page = nextPage ?: return
        if (loading) return
        loading = true
        val key = Triple(state, server, query)
        scope.launch {
            try {
                val res = ApiClient.service.monitoring(company, state.ifEmpty { null }, server.ifEmpty { null },
                    query.trim().ifEmpty { null }, page)
                if (key != Triple(state, server, query)) return@launch
                rows.addAll(res.data.filter { r -> rows.none { it.headerId == r.headerId } })
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
            DeskTextField(query, { query = it }, placeholder = s.mSearch, modifier = Modifier.fillMaxWidth(), clearable = true)
            Spacer(Modifier.height(10.dp))
            // one card per server (all first), like the panel's: online / total
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(servers) { c -> ServerCard(c, if (c.server.isEmpty()) s.fAll else c.server, server == c.server) { server = c.server } }
            }
            Spacer(Modifier.height(8.dp))
            val current = servers.firstOrNull { it.server == server } ?: servers.firstOrNull()
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                val offline = current?.let { it.total - it.online - it.blocked }
                fun n(v: Int?) = v?.let { " · " + Format.amount(it.toDouble()) } ?: ""
                items(listOf("" to s.fAll, "online" to s.online + n(current?.online), "offline" to s.offlineLabel + n(offline),
                    "blocked" to s.blocked + n(current?.blocked))) { (key, label) ->
                    Chip(label, state == key) { state = key }
                }
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(listOfNotNull(updatedAt?.let { "${s.mUpdated} ${Format.dateTime(it)}" }, note).joinToString(" · "),
                    fontSize = 12.sp, color = Muted, modifier = Modifier.weight(1f), maxLines = 2, overflow = TextOverflow.Ellipsis)
                IconButton(enabled = !syncing, onClick = {
                    scope.launch {
                        syncing = true
                        note = try {
                            val res = ApiClient.service.monitorSync(company)
                            res.routers.joinToString(" · ") { "${it.router}: ${if (it.ok) "${it.online ?: 0} ${s.online}" else "✕"}" }
                                .ifEmpty { s.mSynced }
                        } catch (e: Exception) {
                            ApiClient.errorMessage(e, s.loadFailed, s.offline)
                        }
                        firstPage(quiet = true)
                        syncing = false
                    }
                }) {
                    if (syncing) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp, color = Primary)
                    else Icon(Icons.Filled.Sync, contentDescription = s.mSync, tint = PrimaryStrong)
                }
            }
            error?.let {
                MessageBox(it, onRetry = { scope.launch { firstPage() } }, retryLabel = s.retry)
                Spacer(Modifier.height(8.dp))
            }
        }
        RefreshBox({ firstPage(quiet = true) }) {
            LazyColumn(state = listState, contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 24.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxSize()) {
                items(rows, key = { it.headerId }) { r -> MonitorRowView(r, now, s) { picked = r } }
                if (loading) item { LoadingBox() }
                if (!loading && rows.isEmpty() && error == null) item { EmptyText(s.mNobody) }
            }
        }
    }

    picked?.let { r ->
        ModalBottomSheet(onDismissRequest = { picked = null }, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
            containerColor = Surface) {
            MonitorActions(navController, company, r, now) { picked = null }
        }
    }
}

@Composable
private fun ServerCard(c: ServerCount, label: String, selected: Boolean, onClick: () -> Unit) {
    Column(
        Modifier.clip(RoundedCornerShape(10.dp)).background(if (selected) PrimarySoft else Surface)
            .border(1.dp, if (selected) Primary else InputLine, RoundedCornerShape(10.dp))
            .clickable { onClick() }.padding(horizontal = 12.dp, vertical = 8.dp)
    ) {
        Text(label, fontSize = 12.sp, color = if (selected) PrimaryStrong else Muted, fontWeight = FontWeight.Medium)
        Row(verticalAlignment = Alignment.Bottom) {
            Text(Format.amount(c.online.toDouble()), fontSize = 18.sp, fontWeight = FontWeight.Bold, color = Good)
            Text(" / ${Format.amount(c.total.toDouble())}", fontSize = 13.sp, color = Muted)
        }
    }
}

@Composable
private fun MonitorRowView(r: MonitorRow, now: Long, s: AppStrings, onClick: () -> Unit) {
    DeskCard(Modifier.clickable { onClick() }) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(10.dp).clip(CircleShape).background(stateColor(r)))
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(r.name ?: "", fontSize = 15.sp, fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(listOfNotNull("ID " + (r.customerId ?: ""), r.username).joinToString(" · "), fontSize = 12.sp, color = Muted,
                    maxLines = 1, overflow = TextOverflow.Ellipsis)
                val place = listOfNotNull(r.place().ifEmpty { null }, r.server).joinToString(" · ")
                if (place.isNotEmpty()) Text(place, fontSize = 11.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            Spacer(Modifier.width(8.dp))
            Column(horizontalAlignment = Alignment.End) {
                Text(monitorStateLabel(r.state, s), fontSize = 13.sp, fontWeight = FontWeight.Bold, color = stateColor(r))
                if (r.state == "offline") {
                    Text(offlineFor(r.seenAt, now, s) ?: s.mNeverSeen, fontSize = 12.sp, color = Danger, fontWeight = FontWeight.Medium)
                } else {
                    r.ip?.let { Text(it, fontSize = 12.sp, color = Ink) }
                    r.uptime?.let { Text(it, fontSize = 11.sp, color = Muted) }
                }
            }
        }
    }
}

/** The panel's row buttons for one customer: re-check, traffic, ping (online), ticket, customer page. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun MonitorActions(navController: NavHostController, company: String, r: MonitorRow, now: Long, close: () -> Unit) {
    val s = LocalStrings.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var result by remember { mutableStateOf<Pair<String, Color>?>(null) }

    fun run(what: String) {
        scope.launch {
            busy = true
            result = s.mChecking to Muted
            result = try {
                val m = ApiClient.service.monitorCheck(company, r.headerId, what).result
                checkMessage(what, m, r, s)
            } catch (e: Exception) {
                ApiClient.errorMessage(e, s.loadFailed, s.offline) to Danger
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp).navigationBarsPadding()) {
        Text(r.name ?: "", fontSize = 17.sp, fontWeight = FontWeight.SemiBold, color = Ink)
        Text(listOfNotNull("ID " + (r.customerId ?: ""), r.username, r.mobile).joinToString(" · "), fontSize = 13.sp, color = Muted)
        Spacer(Modifier.height(8.dp))
        val detail = when (r.state) {
            "offline" -> listOfNotNull(offlineFor(r.seenAt, now, s)?.let { "${s.offlineLabel} $it" },
                r.seenAt?.let { "${s.mLastOnline} ${Format.dateTime(it)}" }).joinToString(" · ").ifEmpty { s.mNeverSeen }
            "blocked" -> "${s.mBlockedHint} · ${r.profile ?: ""} · IP ${r.ip ?: ""}"
            else -> listOfNotNull(r.uptime, r.ip?.let { "IP $it" }, r.mac?.let { "MAC $it" }).joinToString(" · ")
        }
        Text("${monitorStateLabel(r.state, s)} · $detail", fontSize = 14.sp, color = stateColor(r), fontWeight = FontWeight.Medium)
        Spacer(Modifier.height(12.dp))
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            SmallAction(s.mRecheck, Icons.Filled.Refresh, enabled = !busy) { run("recheck") }
            if (r.state == "online") {
                SmallAction(s.mTraffic, Icons.Filled.SwapVert, enabled = !busy) { run("traffic") }
                SmallAction(s.mPing, Icons.Filled.NetworkPing, enabled = !busy) { run("ping") }
            }
            if (SessionStore.company()?.can("tickets") == true) {
                SmallAction(s.mTicket, Icons.Filled.ConfirmationNumber, color = Warn) {
                    close()
                    navController.navigate(Routes.newTicket(r.headerId))
                }
            }
            if (SessionStore.company()?.can("customers") == true) {
                SmallAction(s.mDetails, Icons.Filled.Person) {
                    close()
                    navController.navigate(Routes.customerDetail(r.headerId))
                }
            }
        }
        result?.let { (text, color) ->
            Spacer(Modifier.height(12.dp))
            MessageBox(text, color = color)
        }
        Spacer(Modifier.height(20.dp))
    }
}

private fun speed(v: Any?): String {
    val bps = (v as? Number)?.toLong() ?: 0L
    return if (bps >= 1_000_000) "%.2f Mbps".format(bps / 1_000_000.0) else "${bps / 1000} Kbps"
}

/** What the router said, in the panel's words, and its colour. */
private fun checkMessage(what: String, m: Map<String, Any?>, r: MonitorRow, s: AppStrings): Pair<String, Color> {
    fun str(k: String) = m[k]?.toString()?.takeIf { it.isNotBlank() && it != "null" }
    return when (what) {
        "traffic" -> "${s.mDown} ${speed(m["download_bps"])} · ${s.mUp} ${speed(m["upload_bps"])}" to Good
        "ping" -> {
            val got = (m["received"] as? Number)?.toInt() ?: 0
            val sent = (m["sent"] as? Number)?.toInt() ?: 0
            val text = "${s.mPing} ${r.ip ?: ""}: $got/$sent ${s.mReceived}" + (str("avg_rtt")?.let { " · ${s.mAvg} $it" } ?: "")
            if (got > 0) text to Good else "$text\n${s.mPingNoReply}" to Warn
        }
        else -> when (m["online"]) {
            null -> s.mNoAnswer to Warn
            true -> "${s.online} · ${str("uptime") ?: ""} · ${str("router") ?: ""}\nIP ${str("address") ?: ""} · MAC ${str("caller_id") ?: ""}" to Good
            else -> if (m["blocked"] == true) "${s.blocked} (${str("profile") ?: ""}) · ${str("router") ?: ""}\n${s.mBlockedHint}" to Warn
            else "${s.offlineLabel} (${str("router") ?: ""})" to Danger
        }
    }
}
