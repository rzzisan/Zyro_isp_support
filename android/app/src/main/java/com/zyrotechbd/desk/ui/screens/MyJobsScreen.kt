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
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshContainer
import androidx.compose.material3.pulltorefresh.rememberPullToRefreshState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.FieldJob
import com.zyrotechbd.desk.data.network.MyJobRow
import com.zyrotechbd.desk.push.Push
import com.zyrotechbd.desk.ui.components.Chip
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.EmptyText
import com.zyrotechbd.desk.ui.components.Good
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
import com.zyrotechbd.desk.ui.theme.Surface
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.withTimeoutOrNull

/** Which tab a ticket of mine sits in: not started, started (running or paused), or done / solved. */
private fun bucket(r: MyJobRow): String = when {
    !r.open || r.job?.status == "done" -> "done"
    r.job != null -> "running"
    else -> "new"
}

/** Seconds worked on a job now: the server's count plus the time since it answered while it runs. */
fun workedNow(job: FieldJob, loadedAt: Long, now: Long): Long =
    job.workedSeconds + if (job.status == "running") (now - loadedAt) / 1000 else 0

/** "1:05:09" / "12:04". */
fun jobClock(seconds: Long): String {
    val s = kotlin.math.abs(seconds)
    val h = s / 3600
    val m = (s % 3600) / 60
    val sec = s % 60
    return if (h > 0) "%d:%02d:%02d".format(h, m, sec) else "%02d:%02d".format(m, sec)
}

@OptIn(ExperimentalMaterial3Api::class)
/** A field technician's tickets: new, running (with the countdown) and done in the last day. */
@Composable
fun MyJobsScreen(navController: NavHostController, company: String) {
    val s = LocalStrings.current
    var tab by rememberSaveable { mutableStateOf("new") }
    val rows = remember { mutableStateListOf<MyJobRow>() }
    var linked by remember { mutableStateOf(true) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var loadedAt by remember { mutableLongStateOf(System.currentTimeMillis()) }
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }

    suspend fun fetch() {
        loading = true
        try {
            val res = ApiClient.service.myJobs(company)
            rows.clear()
            rows.addAll(res.data)
            linked = res.linked
            loadedAt = System.currentTimeMillis()
            error = null
        } catch (e: Exception) {
            error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
        } finally {
            loading = false
        }
    }

    // on open, every minute, and right after a push (a new ticket assigned)
    LaunchedEffect(reload) {
        while (true) {
            fetch()
            withTimeoutOrNull(60_000) { Push.events.first() }
        }
    }
    LaunchedEffect(Unit) {
        while (true) {
            delay(1_000)
            now = System.currentTimeMillis()
        }
    }
    // the first visit lands on running work if there is any
    var picked by rememberSaveable { mutableStateOf(false) }
    LaunchedEffect(rows.size) {
        if (!picked && rows.isNotEmpty()) {
            picked = true
            if (rows.any { bucket(it) == "running" }) tab = "running"
        }
    }
    val pull = rememberPullToRefreshState()
    if (pull.isRefreshing) LaunchedEffect(Unit) {
        fetch()
        pull.endRefresh()
    }

    val counts = rows.groupingBy { bucket(it) }.eachCount()
    val shown = rows.filter { bucket(it) == tab }
    Column(Modifier.fillMaxSize()) {
        Column(Modifier.padding(start = 16.dp, end = 16.dp, top = 12.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("new" to s.jobsNew, "running" to s.jobsRunning, "done" to s.jobsDone).forEach { (key, label) ->
                    Chip("$label · ${counts[key] ?: 0}", tab == key) { tab = key }
                }
            }
            Spacer(Modifier.height(8.dp))
            if (linked) FieldLocationCard(company)
            if (error != null) {
                MessageBox(error!!, onRetry = { reload++ }, retryLabel = s.retry)
                Spacer(Modifier.height(8.dp))
            }
            if (!linked) {
                MessageBox(s.jobsNotLinked, color = Warn)
                Spacer(Modifier.height(8.dp))
            }
        }
        Box(Modifier.fillMaxSize().clipToBounds().nestedScroll(pull.nestedScrollConnection)) {
            LazyColumn(contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 24.dp), modifier = Modifier.fillMaxSize()) {
                if (shown.isNotEmpty()) item {
                    DeskCard {
                        shown.forEachIndexed { i, r ->
                            if (i > 0) HorizontalDivider(color = Line)
                            JobRowView(r, s, loadedAt, now) { navController.navigate(Routes.job(r.id)) }
                        }
                    }
                }
                if (loading && rows.isEmpty()) item { LoadingBox() }
                if (!loading && shown.isEmpty() && error == null && linked) item { EmptyText(s.jobsEmpty) }
            }
            PullToRefreshContainer(pull, Modifier.align(Alignment.TopCenter), containerColor = Surface, contentColor = Primary)
        }
    }
}

@Composable
private fun JobRowView(r: MyJobRow, s: AppStrings, loadedAt: Long, now: Long, onClick: () -> Unit) {
    Column(Modifier.fillMaxWidth().clickable { onClick() }.padding(horizontal = 14.dp, vertical = 12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            r.online?.let {
                Box(Modifier.size(9.dp).background(if (it) Good else Danger, CircleShape))
                Spacer(Modifier.width(8.dp))
            }
            Text("#${r.complainId}", fontSize = 13.sp, fontWeight = FontWeight.SemiBold, color = PrimaryStrong)
            Spacer(Modifier.width(8.dp))
            Text(r.category ?: "", fontSize = 14.sp, fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1,
                overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Spacer(Modifier.width(8.dp))
            val job = r.job
            when {
                job != null && job.status != "done" && r.open -> {
                    val left = job.targetMinutes * 60L - workedNow(job, loadedAt, now)
                    val color = if (job.status == "paused") Muted else if (left < 0) Danger else Good
                    Pill(if (job.status == "paused") s.pausedLabel else if (left < 0) "${s.overdueLabel} ${jobClock(left)}" else jobClock(left), color)
                }
                else -> Pill(stateLabel(r.state, s), stateColor(r.state))
            }
        }
        Spacer(Modifier.height(2.dp))
        Text(listOfNotNull(r.customerName, r.customerId?.let { "ID $it" }).joinToString(" · "), fontSize = 13.sp, color = Ink,
            maxLines = 1, overflow = TextOverflow.Ellipsis)
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(listOfNotNull(r.zone, r.subzone).filter { it.isNotBlank() }.joinToString(" / "),
                fontSize = 12.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Spacer(Modifier.width(8.dp))
            Text(listOfNotNull(Format.shortTime(r.openedAt, s.yesterday).ifEmpty { null }, r.duration).joinToString(" · "),
                fontSize = 12.sp, color = if (r.open && r.priority?.lowercase() == "high") Danger else Muted, maxLines = 1)
        }
    }
}
