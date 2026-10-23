package com.zyrotechbd.desk.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Chat
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.call.Phone
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.FieldJob
import com.zyrotechbd.desk.data.network.FinishRequest
import com.zyrotechbd.desk.data.network.LineState
import com.zyrotechbd.desk.data.network.MyJobDetail
import com.zyrotechbd.desk.data.network.PauseRequest
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.DeskTextField
import com.zyrotechbd.desk.ui.components.Field
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.components.InfoRow
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.Pill
import com.zyrotechbd.desk.ui.components.PrimaryButton
import com.zyrotechbd.desk.ui.components.ScreenBody
import com.zyrotechbd.desk.ui.components.SecondaryButton
import com.zyrotechbd.desk.ui.components.SectionTitle
import com.zyrotechbd.desk.ui.components.SmallAction
import com.zyrotechbd.desk.ui.components.Toggle
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.navigation.Routes
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import com.zyrotechbd.desk.ui.theme.Surface
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * One of my tickets: the job (start, countdown, pause, finish), the customer's MikroTik line every minute while the job is
 * open (finishing needs it online), call / WhatsApp / customer page, and the line box (MikroTik, ONU, bill).
 */
@Composable
fun JobDetailScreen(navController: NavHostController, company: String, ticketId: Long) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var detail by remember { mutableStateOf<MyJobDetail?>(null) }
    var job by remember { mutableStateOf<FieldJob?>(null) }
    var loadedAt by remember { mutableLongStateOf(System.currentTimeMillis()) }
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    var line by remember { mutableStateOf<LineState?>(null) }
    var lineBusy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<Pair<String, Boolean>?>(null) }   // text, ok
    var busy by remember { mutableStateOf(false) }
    var showPause by remember { mutableStateOf(false) }
    var showFinish by remember { mutableStateOf(false) }

    fun setJob(j: FieldJob?) {
        job = j
        loadedAt = System.currentTimeMillis()
    }

    fun load(fresh: Boolean = false) {
        scope.launch {
            try {
                val d = ApiClient.service.myJob(company, ticketId, if (fresh) 1 else null)
                detail = d
                setJob(d.ticket.job)
                error = null
            } catch (e: Exception) {
                error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            }
        }
    }

    suspend fun checkLine(fresh: Boolean) {
        lineBusy = true
        line = runCatching { ApiClient.service.jobLine(company, ticketId, if (fresh) 1 else null) }.getOrElse { line }
        lineBusy = false
    }

    fun step(call: suspend () -> FieldJob) {
        scope.launch {
            busy = true
            try {
                setJob(call())
                notice = null
            } catch (e: Exception) {
                notice = ApiClient.errorMessage(e, s.loadFailed, s.offline) to false
            } finally {
                busy = false
            }
        }
    }

    LaunchedEffect(ticketId) { load() }
    LaunchedEffect(Unit) {
        while (true) {
            delay(1_000)
            now = System.currentTimeMillis()
        }
    }
    // the line every minute while the ticket is open and the work is not done (the server asks the router at most once a minute)
    val watching = detail?.ticket?.open == true && job?.status != "done" && detail?.ticket?.headerId != null
    LaunchedEffect(watching) {
        while (watching) {
            checkLine(false)
            delay(60_000)
        }
    }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
        ScreenBody {
            val d = detail
            if (d == null) {
                if (error != null) MessageBox(error!!, onRetry = { load() }, retryLabel = s.retry) else LoadingBox()
                return@ScreenBody
            }
            val t = d.ticket
            val j = job
            notice?.let { (text, ok) ->
                MessageBox(text, color = if (ok) Good else Danger)
                Spacer(Modifier.height(10.dp))
            }

            // the ticket
            DeskCard {
                Column(Modifier.padding(16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("#${t.complainId}", fontSize = 14.sp, fontWeight = FontWeight.SemiBold, color = PrimaryStrong)
                        Spacer(Modifier.weight(1f))
                        t.priority?.takeIf { it.isNotBlank() }?.let {
                            Pill(it, if (it.lowercase() == "high") Danger else Muted)
                            Spacer(Modifier.width(6.dp))
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
                    InfoRow(s.assignedLabel, t.person)
                    InfoRow(s.openedLabel, Format.dateTime(t.openedAt))
                    InfoRow(s.durationLabel, t.duration)
                }
            }
            Spacer(Modifier.height(12.dp))

            // the work: countdown and the buttons
            DeskCard {
                Column(Modifier.padding(16.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    val target = (j?.targetMinutes ?: d.targetMinutes) * 60L
                    if (j == null) {
                        Text("${s.targetLabel}: ${d.targetMinutes} ${s.minutesShort}", fontSize = 14.sp, color = Muted)
                        Spacer(Modifier.height(10.dp))
                        if (t.open) PrimaryButton(s.startWork, loading = busy) { step { ApiClient.service.startJob(company, ticketId).job } }
                    } else {
                        val worked = workedNow(j, loadedAt, now)
                        val left = target - worked
                        val color = when {
                            j.status == "done" -> Good
                            j.status == "paused" -> Muted
                            left < 0 -> Danger
                            else -> PrimaryStrong
                        }
                        Text(
                            if (j.status == "done") jobClock(worked) else if (left < 0) "+${jobClock(left)}" else jobClock(left),
                            fontSize = 44.sp, fontWeight = FontWeight.Bold, color = color, fontFamily = FontFamily.Monospace,
                        )
                        Text(
                            when {
                                j.status == "done" -> "${s.jobsDone} · ${s.workedLabel}"
                                j.status == "paused" -> s.pausedLabel + (j.pauseReason?.let { ": $it" } ?: "")
                                left < 0 -> s.overdueLabel
                                else -> s.timeLeft
                            },
                            fontSize = 13.sp, color = color,
                        )
                        Spacer(Modifier.height(8.dp))
                        InfoRow(s.workedLabel, jobClock(worked))
                        InfoRow(s.targetLabel, "${j.targetMinutes} ${s.minutesShort}")
                        InfoRow(s.startedByLabel, listOfNotNull(j.startedBy, Format.dateTime(j.startedAt).ifEmpty { null }).joinToString(" · "))
                        if (t.open && j.status != "done") {
                            Spacer(Modifier.height(10.dp))
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                if (j.status == "running") {
                                    SecondaryButton(s.pauseWork, Modifier.weight(1f)) { showPause = true }
                                } else {
                                    SecondaryButton(s.resumeWork, Modifier.weight(1f)) {
                                        step { ApiClient.service.resumeJob(company, ticketId).job }
                                    }
                                }
                                val ready = !j.lineCheck || line?.online == true || d.canForce
                                PrimaryButton(s.finishWork, enabled = ready && !busy, modifier = Modifier.weight(1f), color = Good) {
                                    showFinish = true
                                }
                            }
                            if (j.lineCheck && line?.online != true) {
                                Spacer(Modifier.height(6.dp))
                                Text(s.finishNeedsOnline, fontSize = 12.sp, color = Warn)
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(12.dp))

            // MikroTik now
            if (t.headerId != null) {
                SectionTitle(s.mikrotikLine) {
                    IconButton(onClick = { scope.launch { checkLine(true) } }, enabled = !lineBusy) {
                        Icon(Icons.Filled.Refresh, contentDescription = s.checkNow, tint = PrimaryStrong)
                    }
                }
                DeskCard {
                    Column(Modifier.padding(16.dp)) {
                        val l = line
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            val (label, c) = when {
                                l == null -> "…" to Muted
                                l.online == true -> s.lineOnline to Good
                                l.online == false -> s.lineOffline to Danger
                                else -> s.lineUnknown to Warn
                            }
                            Box(Modifier.size(12.dp).background(c, CircleShape))
                            Spacer(Modifier.width(10.dp))
                            Text(label, fontSize = 18.sp, fontWeight = FontWeight.Bold, color = c)
                            Spacer(Modifier.weight(1f))
                            l?.checkedAt?.let { Text("${s.lineChecked} ${Format.time(it)}", fontSize = 12.sp, color = Muted) }
                        }
                        l?.uptime?.takeIf { l?.online == true }?.let { InfoRow("Uptime", it) }
                        l?.address?.takeIf { l?.online == true }?.let { InfoRow("IP", it) }
                        l?.error?.let { Text(it, fontSize = 12.sp, color = Warn) }
                        Spacer(Modifier.height(4.dp))
                        Text(if (j?.lineCheck == false || (j == null && !d.lineCheck)) s.lineNoCheck else s.lineEveryMinute,
                            fontSize = 12.sp, color = Muted)
                    }
                }
                Spacer(Modifier.height(12.dp))
            }

            // the customer: call through the hotline when allowed, WhatsApp in the app, the customer page
            SectionTitle(s.customerLabel)
            DeskCard {
                Column(Modifier.padding(16.dp)) {
                    val c = d.customer
                    Text(c?.name ?: t.customerName ?: "", fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = Ink)
                    Text(listOfNotNull((c?.customerId ?: t.customerId)?.let { "ID $it" }, c?.username).joinToString(" · "),
                        fontSize = 13.sp, color = Muted)
                    Spacer(Modifier.height(6.dp))
                    InfoRow(s.mobile, c?.mobile ?: t.mobile)
                    InfoRow(s.area, c?.place()?.ifEmpty { null } ?: listOfNotNull(t.zone, t.subzone).joinToString(" / "))
                    InfoRow(s.address, c?.address)
                    c?.`package`?.let { InfoRow(s.packageLabel, it) }
                    c?.due?.takeIf { it > 0 }?.let { InfoRow(s.due, Format.tk(it), valueColor = Danger) }
                    Spacer(Modifier.height(8.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        val mobile = (c?.mobile ?: t.mobile)?.filter { it.isDigit() || it == '+' }.orEmpty()
                        SmallAction(s.call, Icons.Filled.Call, Modifier.weight(1f), enabled = mobile.isNotEmpty()) {
                            Phone.dial(context, mobile, "${c?.name ?: t.customerName ?: ""} (ID ${c?.customerId ?: t.customerId ?: ""})")
                        }
                        // only the customer's WhatsApp chat in the app, and only while the work runs (the server sends chat_id then)
                        if (c?.chatId != null) {
                            SmallAction(s.titleChat, Icons.Filled.Chat, Modifier.weight(1f), color = Color(0xFF128C7E)) {
                                navController.navigate(Routes.chat(c.chatId!!))
                            }
                        }
                        if (t.headerId != null) {
                            SmallAction(s.openCustomer, Icons.Filled.Person, Modifier.weight(1f)) {
                                navController.navigate(Routes.customerDetail(t.headerId!!))
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(12.dp))

            SectionTitle(s.liveStatus)
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

            if (showPause) {
                ReasonDialog(s.pauseWork, s.pauseReasonLabel, onDismiss = { showPause = false }) { reason ->
                    try {
                        setJob(ApiClient.service.pauseJob(company, ticketId, PauseRequest(reason)).job)
                        showPause = false
                        null
                    } catch (e: Exception) {
                        ApiClient.errorMessage(e, s.loadFailed, s.offline)
                    }
                }
            }
            if (showFinish) {
                FinishDialog(
                    needReason = j?.lineCheck == true && line?.online != true,
                    smsReady = d.smsSolved.ready, smsOn = d.smsSolved.on,
                    onDismiss = { showFinish = false },
                ) { remark, sms, force ->
                    try {
                        val res = ApiClient.service.finishJob(company, ticketId, FinishRequest(remark.ifBlank { null }, sms, force))
                        showFinish = false
                        notice = listOfNotNull(s.jobFinished, res.sms).joinToString(" · ") to true
                        load(true)
                        null
                    } catch (e: Exception) {
                        scope.launch { checkLine(false) }
                        ApiClient.errorMessage(e, s.loadFailed, s.offline)
                    }
                }
            }
        }
    }
}

/** One required text (why the work pauses); [onSave] returns an error to show, or null when done. */
@Composable
private fun ReasonDialog(title: String, label: String, onDismiss: () -> Unit, onSave: suspend (String) -> String?) {
    val s = LocalStrings.current
    val scope = rememberCoroutineScope()
    var text by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = { if (!saving) onDismiss() },
        containerColor = Surface,
        title = { Text(title, fontSize = 17.sp, fontWeight = FontWeight.SemiBold) },
        text = {
            Column {
                Field(label) { DeskTextField(text, { text = it.take(300) }, modifier = Modifier.fillMaxWidth(), singleLine = false) }
                error?.let { Text(it, color = Danger, fontSize = 13.sp) }
            }
        },
        confirmButton = {
            TextButton(enabled = !saving && text.isNotBlank(), onClick = {
                scope.launch {
                    saving = true
                    error = onSave(text.trim())
                    saving = false
                }
            }) { Text(if (saving) "…" else s.save) }
        },
        dismissButton = { TextButton(enabled = !saving, onClick = onDismiss) { Text(s.cancel) } },
    )
}

/** Remark, the Desk's "solved" SMS, and (line offline, member may) the reason to finish anyway. */
@Composable
private fun FinishDialog(needReason: Boolean, smsReady: Boolean, smsOn: Boolean, onDismiss: () -> Unit,
                         onSave: suspend (String, Boolean, String?) -> String?) {
    val s = LocalStrings.current
    val scope = rememberCoroutineScope()
    var remark by remember { mutableStateOf("") }
    var force by remember { mutableStateOf("") }
    var sms by remember { mutableStateOf(smsOn) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = { if (!saving) onDismiss() },
        containerColor = Surface,
        title = { Text(s.finishWork, fontSize = 17.sp, fontWeight = FontWeight.SemiBold) },
        text = {
            Column {
                if (needReason) {
                    Field(s.forceReasonLabel) { DeskTextField(force, { force = it.take(500) }, modifier = Modifier.fillMaxWidth(), singleLine = false) }
                    Spacer(Modifier.height(10.dp))
                }
                Field(s.solveRemark) { DeskTextField(remark, { remark = it.take(500) }, modifier = Modifier.fillMaxWidth(), singleLine = false) }
                Spacer(Modifier.height(10.dp))
                Toggle(s.smsSolved, sms, enabled = smsReady, hint = if (smsReady) null else s.smsOff) { sms = it }
                error?.let {
                    Spacer(Modifier.height(8.dp))
                    Text(it, color = Danger, fontSize = 13.sp)
                }
            }
        },
        confirmButton = {
            TextButton(enabled = !saving && (!needReason || force.isNotBlank()), onClick = {
                scope.launch {
                    saving = true
                    error = onSave(remark.trim(), sms && smsReady, force.trim().ifBlank { null })
                    saving = false
                }
            }) { Text(if (saving) "…" else s.finishWork) }
        },
        dismissButton = { TextButton(enabled = !saving, onClick = onDismiss) { Text(s.cancel) } },
    )
}
