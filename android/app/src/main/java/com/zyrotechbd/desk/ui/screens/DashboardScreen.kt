package com.zyrotechbd.desk.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Chat
import androidx.compose.material.icons.filled.ConfirmationNumber
import androidx.compose.material.icons.filled.HourglassTop
import androidx.compose.material.icons.filled.Payments
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Print
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Wifi
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.cache.AppCache
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.Dashboard
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.PrimaryButton
import com.zyrotechbd.desk.ui.components.RefreshBox
import com.zyrotechbd.desk.ui.components.SectionTitle
import com.zyrotechbd.desk.ui.components.SmallAction
import com.zyrotechbd.desk.ui.components.StatCard
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.navigation.Routes
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import kotlinx.coroutines.launch

@Composable
fun DashboardScreen(navController: NavHostController, company: String) {
    val s = LocalStrings.current
    val scope = rememberCoroutineScope()
    val cacheKey = AppCache.DASHBOARD_STATS + ":" + company
    var data by remember { mutableStateOf(AppCache.get<Dashboard>(cacheKey, AppCache.TTL_DASHBOARD)) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val info = SessionStore.company()

    suspend fun fetch() {
            loading = true
            error = null
            try {
                val d = ApiClient.service.dashboard(company)
                AppCache.put(cacheKey, d)
                data = d
                d.inbox?.let { com.zyrotechbd.desk.core.Badges.waitingChats.value = it.waiting }
                d.tickets?.let { com.zyrotechbd.desk.core.Badges.openTickets.value = it.open }
            } catch (e: Exception) {
                error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            } finally {
                loading = false
            }
    }
    fun load() { scope.launch { fetch() } }
    LaunchedEffect(company) { load() }

    RefreshBox({ fetch() }) {
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
        Text(s.hello(SessionStore.userName()), fontSize = 18.sp, fontWeight = FontWeight.Bold, color = Ink)
        Text(s.todayAt(Format.date(Format.today())), fontSize = 13.sp, color = Muted)
        Spacer(Modifier.height(14.dp))

        if (info?.can("bills") == true) {
            PrimaryButton(s.navCollect) { navController.navigate(Routes.Collect) }
            Spacer(Modifier.height(10.dp))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.fillMaxWidth()) {
            if (info?.can("customers") == true || info?.can("bills") == true) {
                SmallAction(s.findCustomer, Icons.Filled.Search, modifier = Modifier.weight(1f)) { navController.navigate(Routes.Customers) }
            }
            if (info?.can("tickets") == true) {
                SmallAction(s.newTicket, Icons.Filled.ConfirmationNumber, modifier = Modifier.weight(1f)) {
                    navController.navigate(Routes.newTicket())
                }
            } else {
                SmallAction(s.navPrinters, Icons.Filled.Print, modifier = Modifier.weight(1f)) { navController.navigate(Routes.Printers) }
            }
        }
        Spacer(Modifier.height(16.dp))

        if (error != null) {
            MessageBox(error!!, onRetry = { load() }, retryLabel = s.retry)
            Spacer(Modifier.height(10.dp))
        }
        val d = data
        if (d == null) {
            if (loading) LoadingBox()
        } else {
            DashboardStats(d, navController)
        }
    }
    }
}

/** Today's numbers, only the parts the member's menus allow. */
@Composable
private fun DashboardStats(d: Dashboard, navController: NavHostController) {
    val s = LocalStrings.current
    Column {
        d.bills?.let { b ->
            SectionTitle(s.todayBills)
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                StatCard(s.myCollection, Format.tk(b.minePaid), Icons.Filled.Person, Modifier.weight(1f),
                    sub = s.billCount(b.mineCount)) { navController.navigate(Routes.Bills) }
                StatCard(s.totalCollection, Format.tk(b.paid), Icons.Filled.Payments, Modifier.weight(1f),
                    sub = s.billCount(b.count)) { navController.navigate(Routes.Bills) }
            }
            if (b.pending > 0) {
                Spacer(Modifier.height(10.dp))
                StatCard(s.approvePending, Format.tk(b.pending), Icons.Filled.HourglassTop, tint = Warn,
                    sub = s.approvePendingHint)
            }
            Spacer(Modifier.height(12.dp))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            d.tickets?.let { t ->
                StatCard(s.openTickets, t.open.toString(), Icons.Filled.ConfirmationNumber, Modifier.weight(1f),
                    sub = s.ticketsToday(t.openedToday, t.solvedToday)) { navController.navigate(Routes.Tickets) }
            }
            d.network?.let { n ->
                StatCard(s.onlineNow, n.online.toString(), Icons.Filled.Wifi, Modifier.weight(1f),
                    sub = s.activeCustomers(n.activeCustomers),
                    onClick = if (SessionStore.company()?.can("monitoring") == true) ({ navController.navigate(Routes.Monitoring) }) else null)
            }
        }
        d.inbox?.let { i ->
            Spacer(Modifier.height(10.dp))
            StatCard(s.waitingChats, i.waiting.toString(), Icons.Filled.Chat, sub = s.waitingChatsHint) {
                navController.navigate(Routes.Inbox)
            }
        }
    }
}
