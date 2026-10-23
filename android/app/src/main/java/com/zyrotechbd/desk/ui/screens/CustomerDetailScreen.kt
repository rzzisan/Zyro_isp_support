package com.zyrotechbd.desk.ui.screens

import android.content.Intent
import com.zyrotechbd.desk.call.Phone
import android.net.Uri
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Chat
import androidx.compose.material.icons.filled.ConfirmationNumber
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.CustomerDetail
import com.zyrotechbd.desk.data.network.LiveInfo
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.CustomerAvatar
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.EmptyText
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.components.InfoRow
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.Pill
import com.zyrotechbd.desk.ui.components.PrimaryButton
import com.zyrotechbd.desk.ui.components.ScreenBody
import com.zyrotechbd.desk.ui.components.SectionTitle
import com.zyrotechbd.desk.ui.components.SmallAction
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.navigation.Routes
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Line
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.runtime.saveable.rememberSaveable
import com.zyrotechbd.desk.ui.components.Chip

@Composable
fun CustomerDetailScreen(navController: NavHostController, company: String, headerId: Long) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var detail by remember { mutableStateOf<CustomerDetail?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var live by remember { mutableStateOf<LiveInfo?>(null) }
    var liveError by remember { mutableStateOf<String?>(null) }
    var liveLoading by remember { mutableStateOf(false) }
    var refreshing by remember { mutableStateOf(false) }
    var tab by rememberSaveable { mutableStateOf("service") }

    fun loadLive(fresh: Boolean) {
        scope.launch {
            liveLoading = true
            liveError = null
            try {
                live = ApiClient.service.customerLive(company, headerId, if (fresh) 1 else null)
            } catch (e: Exception) {
                liveError = ApiClient.errorMessage(e, s.liveFailed, s.offline)
            } finally {
                liveLoading = false
            }
        }
    }

    fun load() {
        scope.launch {
            error = null
            try {
                detail = ApiClient.service.customer(company, headerId)
            } catch (e: Exception) {
                error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            }
        }
    }

    // like the panel's customer page: billing's details and history are re-read in the background, then shown
    fun refreshFromBilling() {
        scope.launch {
            refreshing = runCatching { ApiClient.service.refreshCustomer(company, headerId).running }.getOrDefault(false)
            var tries = 0
            while (refreshing && tries++ < 40) {
                delay(3_000)
                refreshing = runCatching { ApiClient.service.customerRefreshing(company, headerId).running }.getOrDefault(false)
                if (!refreshing) runCatching { ApiClient.service.customer(company, headerId) }.onSuccess { detail = it }
            }
            refreshing = false
        }
    }

    LaunchedEffect(headerId) {
        load()
        loadLive(false)
        refreshFromBilling()
    }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
        ScreenBody {
            if (error != null) {
                MessageBox(error!!, onRetry = { load() }, retryLabel = s.retry)
                return@ScreenBody
            }
            val d = detail ?: run { LoadingBox(); return@ScreenBody }
            val c = d.customer

            DeskCard {
                Column(Modifier.padding(16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        CustomerAvatar(c.name ?: "?", c.customerId ?: c.headerId.toString(), size = 52.dp)
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(c.name ?: "", fontSize = 18.sp, fontWeight = FontWeight.Bold, color = Ink)
                            Text("ID ${c.customerId ?: ""}" + (c.username?.let { " · $it" } ?: ""), fontSize = 13.sp, color = Muted)
                            Spacer(Modifier.height(4.dp))
                            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                Pill(if (c.online) s.online else s.offlineLabel, if (c.online) Good else Muted)
                                if (c.isLeft == true) Pill("LEFT", Muted)
                                else if (c.disabled == true) Pill(s.lineOff, Danger)
                                c.status?.takeIf { it.isNotBlank() }?.let { Pill(it, PrimaryStrong) }
                            }
                        }
                    }
                    Spacer(Modifier.height(12.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        val mobile = c.mobile?.filter { it.isDigit() || it == '+' }.orEmpty()
                        SmallAction(s.call, Icons.Filled.Call, Modifier.weight(1f), enabled = mobile.isNotEmpty()) {
                            Phone.dial(context, mobile, "${c.name ?: ""} (ID ${c.customerId ?: ""})")
                        }
                        val inApp = c.chatId != null && (SessionStore.company()?.can("inbox") == true || SessionStore.company()?.can("field_jobs") == true)
                        val fieldOnly = SessionStore.company()?.fieldOnly == true
                        SmallAction("WhatsApp", Icons.Filled.Chat, Modifier.weight(1f), color = Color(0xFF128C7E),
                            enabled = inApp || (!fieldOnly && mobile.isNotEmpty())) {
                            if (inApp) {
                                navController.navigate(Routes.chat(c.chatId!!))
                                return@SmallAction
                            }
                            val digits = mobile.filter { it.isDigit() }
                            val wa = if (digits.startsWith("0")) "88$digits" else digits
                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("https://wa.me/$wa")))
                        }
                    }
                    if (d.can.line) {
                        Spacer(Modifier.height(8.dp))
                        HorizontalDivider(color = Line)
                        Row(Modifier.fillMaxWidth().padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text(if (c.disabled == true) s.lineOff else s.lineOnLabel, fontSize = 14.sp, fontWeight = FontWeight.SemiBold,
                                color = if (c.disabled == true) Danger else Good, modifier = Modifier.weight(1f))
                            LineSwitch(company, c) { updated ->
                                detail = d.copy(customer = c.copy(disabled = updated.disabled, status = updated.status))
                                load()
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(12.dp))

            val due = c.due ?: 0.0
            DeskCard {
                Column(Modifier.padding(16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(s.due, fontSize = 12.sp, color = Muted)
                            Text(Format.tk(due), fontSize = 24.sp, fontWeight = FontWeight.ExtraBold, color = if (due > 0) Danger else Good)
                        }
                        Column(horizontalAlignment = Alignment.End) {
                            Text(s.monthlyBill, fontSize = 12.sp, color = Muted)
                            Text(Format.tk(c.monthlyBill), fontSize = 16.sp, fontWeight = FontWeight.Bold, color = Ink)
                        }
                    }
                    if (d.can.bill) {
                        Spacer(Modifier.height(12.dp))
                        PrimaryButton(s.collectBill) { navController.navigate(Routes.billForm(c.headerId)) }
                    }
                    if (SessionStore.company()?.can("tickets") == true && c.isLeft != true) {
                        Spacer(Modifier.height(8.dp))
                        SmallAction(s.newTicket, Icons.Filled.ConfirmationNumber, Modifier.fillMaxWidth()) {
                            navController.navigate(Routes.newTicket(c.headerId))
                        }
                    }
                }
            }
            Spacer(Modifier.height(12.dp))

            DeskCard {
                Column(Modifier.padding(16.dp)) {
                    InfoRow(s.mobile, c.mobile)
                    InfoRow(s.packageLabel, c.`package`)
                    InfoRow(s.area, c.place())
                    InfoRow(s.address, c.address)
                    InfoRow(s.lastPayment, c.lastPaymentDate?.let { Format.date(it) })
                    InfoRow(s.billDay, c.billDay?.toString())
                    InfoRow(s.joined, c.joinedOn?.let { Format.date(it) })
                    InfoRow(s.server, c.server)
                }
            }
            Spacer(Modifier.height(12.dp))

            SectionTitle(s.liveStatus) {
                IconButton(onClick = { loadLive(true) }, enabled = !liveLoading) {
                    Icon(Icons.Filled.Refresh, contentDescription = s.refresh, tint = PrimaryStrong)
                }
            }
            DeskCard {
                Column(Modifier.padding(16.dp)) {
                    when {
                        liveLoading && live == null -> LoadingBox()
                        liveError != null -> MessageBox(liveError!!, color = Warn)
                        live != null -> LiveBox(live!!)
                    }
                }
            }
            Spacer(Modifier.height(12.dp))

            if (d.tickets.isNotEmpty()) {
                SectionTitle(s.openTickets)
                val canTickets = SessionStore.company()?.can("tickets") == true
                d.tickets.forEach { t ->
                    DeskCard(if (canTickets && t.id != null) Modifier.clickable { navController.navigate(Routes.ticket(t.id)) } else Modifier) {
                        Column(Modifier.padding(14.dp)) {
                            Text("#${t.complainId ?: ""} · ${t.category ?: ""}", fontWeight = FontWeight.SemiBold, color = Ink, fontSize = 14.sp)
                            Text(listOfNotNull(Format.dateTime(t.openedAt), t.assignedTo).joinToString(" · "), fontSize = 12.sp, color = Muted)
                            t.note?.takeIf { it.isNotBlank() }?.let { Text(it, fontSize = 13.sp, color = Ink) }
                        }
                    }
                    Spacer(Modifier.height(8.dp))
                }
                Spacer(Modifier.height(4.dp))
            }

            // billing's customer details, as the panel's tabs
            val tabs = listOf("service" to s.tService, "network" to s.tNetwork, "personal" to s.tPersonal, "payments" to s.tPayments,
                "complains" to s.tComplains, "line" to s.tLineLog)
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(tabs) { (key, label) -> Chip(label, tab == key) { tab = key } }
            }
            Spacer(Modifier.height(10.dp))
            when (tab) {
                "payments" -> PaymentsCard(d)
                "complains" -> DeskCard {
                    Column(Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) {
                        if (d.history.isEmpty()) EmptyText(s.noTickets)
                        val canTickets = SessionStore.company()?.can("tickets") == true
                        d.history.forEachIndexed { i, t ->
                            if (i > 0) HorizontalDivider(color = Line)
                            Row(Modifier.fillMaxWidth()
                                .then(if (canTickets && t.id != null) Modifier.clickable { navController.navigate(Routes.ticket(t.id)) } else Modifier)
                                .padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text("#${t.complainId ?: ""} · ${t.category ?: ""}", fontSize = 14.sp, fontWeight = FontWeight.SemiBold, color = Ink)
                                    Text(listOfNotNull(Format.dateTime(t.openedAt).ifEmpty { null }, t.solvedBy?.takeIf { it.isNotBlank() })
                                        .joinToString(" · "), fontSize = 12.sp, color = Muted)
                                }
                                t.state?.let { Pill(stateLabel(it, s), stateColor(it)) }
                            }
                        }
                    }
                }
                "line" -> DeskCard {
                    Column(Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) {
                        if (d.lineLogs.isEmpty()) EmptyText(s.noRecords)
                        d.lineLogs.forEachIndexed { i, l ->
                            if (i > 0) HorizontalDivider(color = Line)
                            Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(Format.dateTime(l.at), fontSize = 13.sp, color = Ink)
                                    Text(listOfNotNull(l.by, l.remarks?.takeIf { it.isNotBlank() }).joinToString(" · "), fontSize = 12.sp, color = Muted)
                                }
                                val on = l.action == "enable"
                                Pill(if (on) s.lineOnAction else s.lineOffAction, if (on) Good else Danger)
                            }
                        }
                    }
                }
                else -> {
                    d.sections.filter { it.tab == tab }.forEach { sec ->
                        DeskCard {
                            Column(Modifier.padding(16.dp)) {
                                Text(sec.title, fontSize = 14.sp, fontWeight = FontWeight.Bold, color = Ink)
                                Spacer(Modifier.height(6.dp))
                                sec.rows.forEach { r ->
                                    if (r.map && r.value != null) {
                                        Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
                                            Text(r.label, fontSize = 13.sp, color = Muted, modifier = Modifier.width(118.dp))
                                            Text("${r.value} · ${s.openMap}", fontSize = 13.sp, color = PrimaryStrong,
                                                modifier = Modifier.weight(1f).clickable {
                                                    context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("geo:0,0?q=${Uri.encode(r.value)}")))
                                                })
                                        }
                                    } else InfoRow(r.label, r.value ?: "—")
                                }
                            }
                        }
                        Spacer(Modifier.height(10.dp))
                    }
                }
            }
            Text(when {
                refreshing -> s.fetching
                d.detailsFetchedAt != null -> "${s.fetchedFrom}: ${Format.dateTime(d.detailsFetchedAt)}"
                else -> s.notFetched
            }, fontSize = 12.sp, color = Muted, modifier = Modifier.padding(top = 6.dp))
            Spacer(Modifier.height(24.dp))
            }
    }
}

/** MikroTik session and ONU now, the way the panel's live box shows them. */
@Composable
fun LiveBox(info: LiveInfo) {
    val s = LocalStrings.current
    val mk = info.mikrotik
    fun str(m: Map<String, Any?>?, k: String): String? = m?.get(k)?.toString()?.takeIf { it.isNotBlank() && it != "null" }

    Text("MikroTik", fontWeight = FontWeight.Bold, fontSize = 13.sp, color = Ink)
    if (mk == null) {
        Text(s.routerNoAnswer, fontSize = 13.sp, color = Muted)
    } else {
        val online = mk["online"] == true
        val blocked = mk["blocked"] == true
        InfoRow(s.status, if (online) s.online else if (blocked) s.blocked else s.offlineLabel,
            valueColor = if (online) Good else Danger, bold = true)
        InfoRow("Uptime", str(mk, "uptime"))
        InfoRow("IP", str(mk, "address"))
        InfoRow("Profile", str(mk, "profile"))
        InfoRow("Router", str(mk, "router"))
    }
    if (!(mk?.get("online") == true)) {
        InfoRow(s.lastSeen, str(info.lastSeen, "seen_at")?.let { Format.dateTime(it.replace(" ", "T")).ifEmpty { it } })
    }
    InfoRow("MAC", info.mac)
    Spacer(Modifier.height(8.dp))
    Text("ONU", fontWeight = FontWeight.Bold, fontSize = 13.sp, color = Ink)
    val onu = info.onu
    if (onu == null) {
        Text(s.onuNotFound, fontSize = 13.sp, color = Muted)
    } else {
        val rx = str(onu, "OpticalPower")
        val rxValue = rx?.replace(Regex("[^0-9.\\-]"), "")?.toDoubleOrNull()
        InfoRow(s.status, str(onu, "OnuStatus"))
        InfoRow("Rx", rx, valueColor = if (rxValue != null && rxValue < -27) Danger else Ink, bold = true)
        InfoRow("Tx", str(onu, "TxPower"))
        InfoRow("OLT", listOfNotNull(str(onu, "OLTName"), str(onu, "OLTPort")).joinToString(" · "))
        InfoRow(s.distance, str(onu, "Distance"))
        InfoRow(s.lastDown, listOfNotNull(str(onu, "LastDeregisterTime"), str(onu, "DeregisterReason")).joinToString(" · "))
    }
    info.paymentStatus?.takeIf { it.isNotBlank() }?.let {
        Spacer(Modifier.height(6.dp))
        InfoRow(s.billingState, it)
    }
}

@Composable
private fun PaymentsCard(d: CustomerDetail) {
    val s = LocalStrings.current
    DeskCard {
        Column(Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) {
            if (d.payments.isEmpty()) EmptyText(s.noPayments)
            d.payments.forEachIndexed { i, p ->
                if (i > 0) HorizontalDivider(color = Line)
                Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(Format.date(p.date) + (p.billMonth?.let { " · $it" } ?: ""), fontSize = 13.sp, color = Ink)
                        Text(listOfNotNull(p.method, p.receivedBy).joinToString(" · "), fontSize = 11.sp, color = Muted)
                    }
                    Column(horizontalAlignment = Alignment.End) {
                        Text(Format.tk(p.paid), fontSize = 14.sp, fontWeight = FontWeight.Bold, color = Good)
                        if (p.discount > 0) Text(s.discountShort(Format.amount(p.discount)), fontSize = 11.sp, color = Muted)
                    }
                }
            }
        }
    }
}
