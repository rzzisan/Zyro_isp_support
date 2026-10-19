package com.zyrotechbd.desk.ui.screens

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.PersonAdd
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.AssignTicketRequest
import com.zyrotechbd.desk.data.network.Choice
import com.zyrotechbd.desk.data.network.SolveRequest
import com.zyrotechbd.desk.data.network.TicketDetail
import com.zyrotechbd.desk.data.network.TicketOptions
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.DeskTextField
import com.zyrotechbd.desk.ui.components.Field
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.components.InfoRow
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.Pill
import com.zyrotechbd.desk.ui.components.Picker
import com.zyrotechbd.desk.ui.components.PrimaryButton
import com.zyrotechbd.desk.ui.components.ScreenBody
import com.zyrotechbd.desk.ui.components.SectionTitle
import com.zyrotechbd.desk.ui.components.SmallAction
import com.zyrotechbd.desk.ui.components.Toggle
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.navigation.Routes
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import com.zyrotechbd.desk.ui.theme.Surface
import kotlinx.coroutines.launch

/** One ticket like the panel's details: the ticket, the customer's line now, and assign / solve. */
@Composable
fun TicketDetailScreen(navController: NavHostController, company: String, ticketId: Long) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var detail by remember { mutableStateOf<TicketDetail?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var refreshing by remember { mutableStateOf(false) }
    var options by remember { mutableStateOf<TicketOptions?>(null) }
    var notice by remember { mutableStateOf<Pair<String, Boolean>?>(null) }   // text, ok
    var showAssign by remember { mutableStateOf(false) }
    var showSolve by remember { mutableStateOf(false) }

    fun load(fresh: Boolean) {
        scope.launch {
            refreshing = true
            try {
                detail = ApiClient.service.ticket(company, ticketId, if (fresh) 1 else null)
                error = null
            } catch (e: Exception) {
                error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            } finally {
                refreshing = false
            }
        }
    }

    LaunchedEffect(ticketId) {
        load(false)
        options = runCatching { ApiClient.service.ticketOptions(company) }.getOrNull()
    }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
        ScreenBody {
            val d = detail
            if (d == null) {
                if (error != null) MessageBox(error!!, onRetry = { load(false) }, retryLabel = s.retry) else LoadingBox()
                return@ScreenBody
            }
            val t = d.ticket
            notice?.let { (text, ok) ->
                MessageBox(text, color = if (ok) Good else Danger)
                Spacer(Modifier.height(10.dp))
            }

            DeskCard {
                Column(Modifier.padding(16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("#${t.complainId}", fontSize = 14.sp, fontWeight = FontWeight.SemiBold, color = PrimaryStrong)
                        Spacer(Modifier.weight(1f))
                        t.priority?.takeIf { it.isNotBlank() }?.let {
                            Pill(it, if (it.lowercase() == "high") Danger else Muted)
                            Spacer(Modifier.padding(start = 6.dp))
                        }
                        Pill(stateLabel(t.state, s), stateColor(t.state))
                    }
                    Spacer(Modifier.height(4.dp))
                    Text(t.category ?: "", fontSize = 18.sp, fontWeight = FontWeight.Bold, color = Ink)
                    t.description?.takeIf { it.isNotBlank() }?.let {
                        Spacer(Modifier.height(6.dp))
                        Text(it, fontSize = 14.sp, color = Ink)
                    }
                    Spacer(Modifier.height(10.dp))
                    InfoRow(if (t.open) s.assignedLabel else s.solvedByLabel, t.person)
                    InfoRow(s.openedLabel, Format.dateTime(t.openedAt))
                    InfoRow(s.solvedAtLabel, t.solvedAt?.let { Format.dateTime(it) })
                    InfoRow(s.durationLabel, t.duration)
                    InfoRow(s.createdByLabel, t.createdBy)
                    InfoRow(s.complainNoLabel, t.complainNumber?.takeIf { it != t.mobile })
                    if (t.open) {
                        Spacer(Modifier.height(12.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            SmallAction(s.assignTicket, Icons.Filled.PersonAdd, Modifier.weight(1f), enabled = options != null) { showAssign = true }
                            SmallAction(s.solveTicket, Icons.Filled.CheckCircle, Modifier.weight(1f), color = Good) { showSolve = true }
                        }
                    }
                }
            }
            Spacer(Modifier.height(12.dp))

            SectionTitle(s.customerLabel)
            DeskCard {
                Column(Modifier.padding(16.dp)) {
                    Text(t.customerName ?: "", fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = Ink)
                    Text(listOfNotNull(t.customerId?.let { "ID $it" }, t.username).joinToString(" · "), fontSize = 13.sp, color = Muted)
                    Spacer(Modifier.height(6.dp))
                    InfoRow(s.mobile, t.mobile)
                    InfoRow(s.area, listOfNotNull(t.zone, t.subzone).filter { it.isNotBlank() }.joinToString(" / "))
                    Spacer(Modifier.height(8.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        val mobile = t.mobile?.filter { it.isDigit() || it == '+' }.orEmpty()
                        SmallAction(s.call, Icons.Filled.Call, Modifier.weight(1f), enabled = mobile.isNotEmpty()) {
                            context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$mobile")))
                        }
                        val company0 = SessionStore.company()
                        val canOpen = t.headerId != null && (company0?.can("customers") == true || company0?.can("bills") == true)
                        if (canOpen) {
                            SmallAction(s.openCustomer, Icons.Filled.Person, Modifier.weight(1f)) {
                                navController.navigate(Routes.customerDetail(t.headerId!!))
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(12.dp))

            SectionTitle(s.liveStatus) {
                IconButton(onClick = { load(true) }, enabled = !refreshing) {
                    Icon(Icons.Filled.Refresh, contentDescription = s.refresh, tint = PrimaryStrong)
                }
            }
            DeskCard {
                Column(Modifier.padding(16.dp)) {
                    when {
                        d.live != null -> LiveBox(d.live)
                        d.liveError != null -> MessageBox(d.liveError, color = Warn)
                        else -> Text(s.routerNoAnswer, fontSize = 13.sp, color = Muted)
                    }
                }
            }
            Spacer(Modifier.height(24.dp))

            val opts = options
            if (showAssign && opts != null) {
                AssignDialog(opts, onDismiss = { showAssign = false }) { employees, dept ->
                    try {
                        ApiClient.service.assignTicket(company, ticketId, AssignTicketRequest(employees, dept))
                        showAssign = false
                        notice = s.ticketAssigned to true
                        load(false)
                        null
                    } catch (e: Exception) {
                        ApiClient.errorMessage(e, s.loadFailed, s.offline)
                    }
                }
            }
            if (showSolve) {
                SolveDialog(opts, onDismiss = { showSolve = false }) { remark, sms ->
                    try {
                        val res = ApiClient.service.solveTicket(company, ticketId, SolveRequest(remark.ifBlank { null }, sms))
                        showSolve = false
                        notice = listOfNotNull(s.ticketSolved, res.sms).joinToString(" · ") to true
                        load(true)
                        null
                    } catch (e: Exception) {
                        ApiClient.errorMessage(e, s.loadFailed, s.offline)
                    }
                }
            }
        }
    }
}

/** Department (optional) and billing employees; [onSave] returns an error to show, or null when done. */
@Composable
private fun AssignDialog(options: TicketOptions, onDismiss: () -> Unit, onSave: suspend (List<String>, String?) -> String?) {
    val s = LocalStrings.current
    val scope = rememberCoroutineScope()
    val picked = remember { mutableStateListOf<String>() }
    var dept by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = { if (!saving) onDismiss() },
        containerColor = Surface,
        title = { Text(s.assignTicket, fontSize = 17.sp, fontWeight = FontWeight.SemiBold) },
        text = {
            Column {
                if (options.departments.isNotEmpty()) {
                    Field(s.departmentLabel) {
                        Picker(listOf(Choice("", s.noDepartment)) + options.departments, dept ?: "") { dept = it.ifEmpty { null } }
                    }
                    Spacer(Modifier.height(10.dp))
                }
                Text(s.employeesLabel, fontSize = 12.sp, color = Muted, fontWeight = FontWeight.SemiBold)
                LazyColumn(Modifier.heightIn(max = 320.dp)) {
                    items(options.employees, key = { it.id }) { o ->
                        Row(Modifier.fillMaxWidth().clickable { if (o.id in picked) picked.remove(o.id) else picked.add(o.id) },
                            verticalAlignment = Alignment.CenterVertically) {
                            Checkbox(checked = o.id in picked, onCheckedChange = null,
                                colors = CheckboxDefaults.colors(checkedColor = Primary), modifier = Modifier.padding(8.dp))
                            Text(o.name, fontSize = 15.sp, color = Ink)
                        }
                    }
                }
                error?.let { Text(it, color = Danger, fontSize = 13.sp) }
            }
        },
        confirmButton = {
            TextButton(enabled = !saving, onClick = {
                if (picked.isEmpty()) {
                    error = s.pickEmployees
                    return@TextButton
                }
                scope.launch {
                    saving = true
                    error = onSave(picked.toList(), dept)
                    saving = false
                }
            }) { Text(if (saving) "…" else s.save) }
        },
        dismissButton = { TextButton(enabled = !saving, onClick = onDismiss) { Text(s.cancel) } },
    )
}

/** Solve note and the Desk's "solved" SMS; billing checks the line is online before it agrees. */
@Composable
private fun SolveDialog(options: TicketOptions?, onDismiss: () -> Unit, onSave: suspend (String, Boolean) -> String?) {
    val s = LocalStrings.current
    val scope = rememberCoroutineScope()
    var remark by remember { mutableStateOf("") }
    val ready = options?.smsSolved?.ready == true
    var sms by remember { mutableStateOf(options?.smsSolved?.on == true) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = { if (!saving) onDismiss() },
        containerColor = Surface,
        title = { Text(s.solveTicket, fontSize = 17.sp, fontWeight = FontWeight.SemiBold) },
        text = {
            Column {
                Text(s.solveHint, fontSize = 12.sp, color = Muted)
                Spacer(Modifier.height(10.dp))
                Field(s.solveRemark) {
                    DeskTextField(remark, { remark = it.take(500) }, modifier = Modifier.fillMaxWidth(), singleLine = false)
                }
                Spacer(Modifier.height(10.dp))
                Toggle(s.smsSolved, sms, enabled = ready, hint = if (ready) null else s.smsOff) { sms = it }
                error?.let {
                    Spacer(Modifier.height(8.dp))
                    Text(it, color = Danger, fontSize = 13.sp)
                }
            }
        },
        confirmButton = {
            TextButton(enabled = !saving, onClick = {
                scope.launch {
                    saving = true
                    error = onSave(remark.trim(), sms && ready)
                    saving = false
                }
            }) { Text(if (saving) "…" else s.solveTicket) }
        },
        dismissButton = { TextButton(enabled = !saving, onClick = onDismiss) { Text(s.cancel) } },
    )
}
