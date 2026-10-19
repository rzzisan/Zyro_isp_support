package com.zyrotechbd.desk.ui.screens

import androidx.compose.ui.draw.clipToBounds
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.ExtendedFloatingActionButton
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
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.TicketCounts
import com.zyrotechbd.desk.data.network.TicketRow
import com.zyrotechbd.desk.ui.components.Chip
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.DeskTextField
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
import kotlinx.coroutines.delay
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.pulltorefresh.PullToRefreshContainer
import androidx.compose.material3.pulltorefresh.rememberPullToRefreshState
import androidx.compose.ui.input.nestedscroll.nestedScroll
import com.zyrotechbd.desk.core.Badges
import com.zyrotechbd.desk.ui.theme.Surface
import kotlinx.coroutines.launch

/** The panel's ticket tabs, in its order; open = pending + processing. */
private fun tabs(s: AppStrings, c: TicketCounts) = listOf(
    "open" to "${s.tabOpen} · ${c.open}", "pending" to "${s.tabPending} · ${c.pending}",
    "processing" to "${s.tabProcessing} · ${c.processing}", "solved" to s.tabSolved, "all" to s.statusAll)

fun stateLabel(state: String, s: AppStrings): String = when (state) {
    "pending" -> s.tabPending
    "processing" -> s.tabProcessing
    "solved" -> s.tabSolved
    "closed" -> s.stateClosed
    else -> state
}

fun stateColor(state: String): Color = when (state) {
    "pending" -> Warn
    "processing" -> PrimaryStrong
    "solved" -> Good
    else -> Muted
}

@OptIn(ExperimentalMaterial3Api::class)
/** Billing tickets, newest first, with the panel's tabs and search; "+" opens a new one. */
@Composable
fun TicketsScreen(navController: NavHostController, company: String) {
    val s = LocalStrings.current
    val scope = rememberCoroutineScope()
    var tab by rememberSaveable { mutableStateOf("open") }
    var query by rememberSaveable { mutableStateOf("") }
    val rows = remember { mutableStateListOf<TicketRow>() }
    var counts by remember { mutableStateOf(TicketCounts()) }
    var nextPage by remember { mutableStateOf<Int?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var reload by remember { mutableIntStateOf(0) }

    suspend fun fetch(page: Int) {
        loading = true
        try {
            val res = ApiClient.service.tickets(company, tab, query.trim().ifBlank { null }, page)
            if (page == 1) rows.clear()
            rows.addAll(res.data)
            counts = res.counts
            Badges.openTickets.value = res.counts.open
            nextPage = res.nextPage
            error = null
        } catch (e: Exception) {
            error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
        } finally {
            loading = false
        }
    }

    LaunchedEffect(tab, query, reload) {
        if (query.isNotBlank()) delay(350)
        fetch(1)
    }
    val pull = rememberPullToRefreshState()
    if (pull.isRefreshing) LaunchedEffect(Unit) {
        fetch(1)
        pull.endRefresh()
    }

    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            Column(Modifier.padding(start = 16.dp, end = 16.dp, top = 12.dp)) {
                DeskTextField(value = query, onValueChange = { query = it }, placeholder = s.searchTickets, modifier = Modifier.fillMaxWidth(), clearable = true)
                Spacer(Modifier.height(8.dp))
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(tabs(s, counts)) { (key, label) ->
                        Chip(label, tab == key) {
                            if (tab != key) {
                                rows.clear()
                                tab = key
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
            Box(Modifier.fillMaxSize().clipToBounds().nestedScroll(pull.nestedScrollConnection)) {
            LazyColumn(contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 88.dp), modifier = Modifier.fillMaxSize()) {
                if (rows.isNotEmpty()) item {
                    DeskCard {
                        rows.forEachIndexed { i, t ->
                            if (i > 0) HorizontalDivider(color = Line)
                            TicketRowView(t, s) { navController.navigate(Routes.ticket(t.id)) }
                        }
                    }
                }
                if (loading) item { LoadingBox() }
                else if (nextPage != null) item {
                    Text(s.loadMore, color = PrimaryStrong, fontWeight = FontWeight.SemiBold, fontSize = 14.sp,
                        modifier = Modifier.fillMaxWidth().clickable { scope.launch { fetch(nextPage!!) } }.padding(16.dp))
                }
                if (!loading && rows.isEmpty() && error == null) item { EmptyText(s.noTickets) }
            }
            PullToRefreshContainer(pull, Modifier.align(Alignment.TopCenter), containerColor = Surface, contentColor = Primary)
            }
        }
        ExtendedFloatingActionButton(
            onClick = { navController.navigate(Routes.newTicket()) },
            containerColor = Primary, contentColor = Color.White,
            icon = { Icon(Icons.Filled.Add, contentDescription = null) },
            text = { Text(s.newTicket, fontWeight = FontWeight.SemiBold) },
            modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp),
        )
    }
}

@Composable
private fun TicketRowView(t: TicketRow, s: AppStrings, onClick: () -> Unit) {
    Column(Modifier.fillMaxWidth().clickable { onClick() }.padding(horizontal = 14.dp, vertical = 12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("#${t.complainId}", fontSize = 13.sp, fontWeight = FontWeight.SemiBold, color = PrimaryStrong)
            Spacer(Modifier.width(8.dp))
            Text(t.category ?: "", fontSize = 14.sp, fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1,
                overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Spacer(Modifier.width(8.dp))
            Pill(stateLabel(t.state, s), stateColor(t.state))
        }
        Spacer(Modifier.height(2.dp))
        Text(listOfNotNull(t.customerName, t.customerId?.let { "ID $it" }).joinToString(" · "), fontSize = 13.sp, color = Ink,
            maxLines = 1, overflow = TextOverflow.Ellipsis)
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(listOfNotNull(t.person?.takeIf { it.isNotBlank() }, t.zone?.takeIf { it.isNotBlank() }).joinToString(" · "),
                fontSize = 12.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Spacer(Modifier.width(8.dp))
            Text(listOfNotNull(Format.shortTime(t.openedAt, s.yesterday).ifEmpty { null }, t.duration).joinToString(" · "),
                fontSize = 12.sp, color = if (t.open && t.priority?.lowercase() == "high") Danger else Muted, maxLines = 1)
        }
    }
}
