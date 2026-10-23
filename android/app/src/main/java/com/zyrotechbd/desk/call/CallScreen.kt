package com.zyrotechbd.desk.call

import android.os.SystemClock
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.CallEnd
import androidx.compose.material.icons.filled.ConfirmationNumber
import androidx.compose.material.icons.filled.Mic
import androidx.compose.material.icons.filled.MicOff
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.VolumeUp
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.network.CallerLine
import com.zyrotechbd.desk.data.network.Ticket
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.InfoRow
import com.zyrotechbd.desk.ui.components.Pill
import com.zyrotechbd.desk.ui.components.PrimaryButton
import com.zyrotechbd.desk.ui.components.nameInitial
import com.zyrotechbd.desk.ui.screens.LiveBox
import com.zyrotechbd.desk.ui.screens.TicketForm
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Background
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Line
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import com.zyrotechbd.desk.ui.theme.Subtle
import com.zyrotechbd.desk.ui.theme.Surface
import com.zyrotechbd.desk.ui.components.Warn
import kotlinx.coroutines.delay

private val WhatsAppGreen = Color(0xFF25D366)

/** The Desk phone's screen: the call on top, who the caller is below (lines, MikroTik/ONU now, open tickets), controls. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CallScreen(a: CallActivity) {
    var ticketFor by remember { mutableStateOf<Long?>(null) }
    val line = a.info?.lines?.getOrNull(a.selected)

    Box(Modifier.fillMaxSize().background(Background)) {
        Column(Modifier.fillMaxSize()) {
            Header(a, line)
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 14.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp)) {
                CallerDetails(a, line) { ticketFor = it }
                Spacer(Modifier.height(4.dp))
            }
            Controls(a)
        }
        // the call itself: the softphone page, out of sight
        a.web?.let { w -> AndroidView({ w }, Modifier.size(1.dp).alpha(0f)) }
    }

    ticketFor?.let { headerId ->
        ModalBottomSheet(onDismissRequest = { ticketFor = null },
            sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Background) {
            Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp)
                .navigationBarsPadding()) {
                Text("নতুন টিকিট", fontSize = 18.sp, fontWeight = FontWeight.Bold, color = Ink)
                Spacer(Modifier.height(10.dp))
                TicketForm(a.company, headerId, onDone = {
                    ticketFor = null
                    a.loadInfo()
                })
                Spacer(Modifier.height(24.dp))
            }
        }
    }
}

@Composable
private fun Header(a: CallActivity, line: CallerLine?) {
    val c = line?.customer
    val name = c?.name?.takeIf { it.isNotBlank() } ?: a.title.ifBlank { a.number }
    val waiting = a.phase == CallPhase.Ringing || a.phase == CallPhase.Connecting || a.phase == CallPhase.Calling
    val pulse = rememberInfiniteTransition(label = "pulse")
    val ring by pulse.animateFloat(1f, 1.35f, infiniteRepeatable(tween(1100, easing = LinearEasing), RepeatMode.Restart), label = "ring")
    val ringAlpha by pulse.animateFloat(0.55f, 0f, infiniteRepeatable(tween(1100, easing = LinearEasing), RepeatMode.Restart), label = "fade")

    Column(
        Modifier.fillMaxWidth()
            .background(Brush.verticalGradient(listOf(PrimaryStrong, Primary)))
            .statusBarsPadding().padding(start = 20.dp, end = 20.dp, top = 12.dp, bottom = 20.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(8.dp).background(if (a.whatsapp) WhatsAppGreen else Color.White, CircleShape))
            Spacer(Modifier.width(6.dp))
            Text(if (a.whatsapp) "WhatsApp কল" else "হটলাইন থেকে কল", color = Color.White.copy(alpha = 0.9f), fontSize = 13.sp)
        }
        Spacer(Modifier.height(16.dp))
        Box(contentAlignment = Alignment.Center, modifier = Modifier.size(104.dp)) {
            if (waiting) {
                Box(Modifier.size(84.dp).scale(ring).alpha(ringAlpha).background(Color.White, CircleShape))
            }
            Box(Modifier.size(84.dp).background(Color.White.copy(alpha = 0.18f), CircleShape)
                .border(2.dp, Color.White.copy(alpha = 0.5f), CircleShape), contentAlignment = Alignment.Center) {
                Text(nameInitial(name), color = Color.White, fontSize = 34.sp, fontWeight = FontWeight.Bold)
            }
        }
        Spacer(Modifier.height(12.dp))
        Text(name, color = Color.White, fontSize = 24.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
            maxLines = 2, overflow = TextOverflow.Ellipsis)
        val sub = listOfNotNull(c?.customerId?.let { "ID $it" }, a.number.takeIf { it.isNotBlank() }).joinToString("  ·  ")
        if (sub.isNotEmpty()) Text(sub, color = Color.White.copy(alpha = 0.85f), fontSize = 14.sp)
        Spacer(Modifier.height(14.dp))
        AnimatedContent(a.phase, label = "phase") { phase ->
            if (phase == CallPhase.Talking) Timer(a.talkingSince)
            else Text(phaseText(a, phase), color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.Medium,
                textAlign = TextAlign.Center)
        }
    }
}

private fun phaseText(a: CallActivity, phase: CallPhase): String = when (phase) {
    CallPhase.Ringing -> "কল আসছে…"
    CallPhase.Connecting -> "সংযোগ হচ্ছে…"
    CallPhase.Calling -> a.status.ifBlank { if (a.whatsapp) "কল ধরা হচ্ছে…" else "কল যাচ্ছে…" }
    CallPhase.Talking -> ""
    CallPhase.Ended -> a.status.ifBlank { "কল শেষ" }
}

@Composable
private fun Timer(since: Long?) {
    var now by remember { mutableLongStateOf(SystemClock.elapsedRealtime()) }
    LaunchedEffect(since) {
        while (true) {
            now = SystemClock.elapsedRealtime()
            delay(500)
        }
    }
    val s = ((now - (since ?: now)) / 1000).coerceAtLeast(0)
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(8.dp).background(Color(0xFF4ADE80), CircleShape))
        Spacer(Modifier.width(8.dp))
        Text("%02d:%02d".format(s / 60, s % 60), color = Color.White, fontSize = 30.sp, fontWeight = FontWeight.Light,
            letterSpacing = 1.sp)
    }
}

@Composable
private fun CallerDetails(a: CallActivity, line: CallerLine?, onNewTicket: (Long) -> Unit) {
    val info = a.info
    if (info == null) {
        DeskCard {
            Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                if (a.infoError == null && a.number.isNotBlank()) {
                    CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp, color = Primary)
                    Spacer(Modifier.width(10.dp))
                    Text("কাস্টমার খোঁজা হচ্ছে…", fontSize = 14.sp, color = Muted)
                } else {
                    Text(a.infoError ?: "নম্বর নেই", fontSize = 14.sp, color = Muted, modifier = Modifier.weight(1f))
                    if (a.infoError != null) RetryIcon { a.loadInfo() }
                }
            }
        }
        return
    }
    if (line == null) {
        DeskCard {
            Column(Modifier.padding(16.dp)) {
                Text("এই নম্বরে কোনো কাস্টমার পাওয়া যায়নি", fontSize = 15.sp, fontWeight = FontWeight.SemiBold, color = Ink)
                Text("নতুন কেউ হতে পারেন, বা অন্য নম্বর থেকে কল করছেন। ID বা নাম জিজ্ঞেস করুন।", fontSize = 13.sp, color = Muted)
            }
        }
        return
    }

    if (info.lines.size > 1) {
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            info.lines.forEachIndexed { i, l ->
                val on = i == a.selected
                Text("ID ${l.customer.customerId ?: l.customer.headerId}" + (l.customer.name?.let { " · $it" } ?: ""),
                    fontSize = 13.sp, fontWeight = FontWeight.Medium, color = if (on) Color.White else Ink, maxLines = 1,
                    modifier = Modifier.background(if (on) Primary else Surface, RoundedCornerShape(50))
                        .border(1.dp, if (on) Primary else Line, RoundedCornerShape(50))
                        .clickable { a.select(i) }.padding(horizontal = 14.dp, vertical = 8.dp))
            }
        }
    }

    CustomerCard(line)
    LiveCard(a)
    TicketsCard(line.tickets)
    if (info.can.ticket) {
        PrimaryButton("নতুন টিকিট খুলুন", modifier = Modifier.fillMaxWidth()) { onNewTicket(line.customer.headerId) }
    }
}

@Composable
private fun CustomerCard(line: CallerLine) {
    val s = LocalStrings.current
    val c = line.customer
    DeskCard {
        Column(Modifier.padding(16.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Pill(if (c.online) s.online else s.offlineLabel, if (c.online) Good else Danger)
                if (c.isLeft == true) Pill("LEFT", Muted)
                else if (c.disabled == true) Pill(s.lineOff, Danger)
                c.status?.takeIf { it.isNotBlank() }?.let { Pill(it, PrimaryStrong) }
            }
            Spacer(Modifier.height(10.dp))
            val due = c.due ?: 0.0
            Row(Modifier.fillMaxWidth()) {
                Stat("মাসিক বিল", Format.tk(c.monthlyBill), Ink, Modifier.weight(1f))
                Stat("বকেয়া", Format.tk(due), if (due > 0) Danger else Good, Modifier.weight(1f))
                Stat("বিল তারিখ", c.billDay?.toString() ?: "—", Ink, Modifier.weight(1f))
            }
            Spacer(Modifier.height(10.dp))
            HorizontalDivider(color = Line)
            Spacer(Modifier.height(6.dp))
            InfoRow("এলাকা", c.place())
            InfoRow("প্যাকেজ", c.`package`)
            InfoRow("PPPoE", c.username)
            InfoRow("শেষ পেমেন্ট", c.lastPaymentDate?.let { Format.date(it) })
            InfoRow("ঠিকানা", c.address)
        }
    }
}

@Composable
private fun Stat(label: String, value: String, color: Color, modifier: Modifier) {
    Column(modifier.padding(end = 6.dp).background(Subtle, RoundedCornerShape(10.dp)).padding(10.dp)) {
        Text(label, fontSize = 11.sp, color = Muted)
        Text(value, fontSize = 16.sp, fontWeight = FontWeight.Bold, color = color, maxLines = 1)
    }
}

@Composable
private fun LiveCard(a: CallActivity) {
    DeskCard {
        Column(Modifier.padding(horizontal = 16.dp, vertical = 12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("লাইভ অবস্থা", fontSize = 15.sp, fontWeight = FontWeight.Bold, color = Ink, modifier = Modifier.weight(1f))
                if (a.liveLoading) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp, color = Primary)
                else RetryIcon { a.loadLive(true) }
            }
            val live = a.live
            when {
                live != null -> LiveBox(live)
                a.liveError != null -> Text(a.liveError!!, fontSize = 13.sp, color = Muted)
                else -> Text("MikroTik আর ONU দেখা হচ্ছে…", fontSize = 13.sp, color = Muted)
            }
        }
    }
}

@Composable
private fun TicketsCard(tickets: List<Ticket>) {
    DeskCard {
        Column(Modifier.padding(horizontal = 16.dp, vertical = 12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.ConfirmationNumber, null, tint = if (tickets.isEmpty()) Muted else Warn, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(6.dp))
                Text(if (tickets.isEmpty()) "খোলা টিকিট নেই" else "খোলা টিকিট (${tickets.size})",
                    fontSize = 15.sp, fontWeight = FontWeight.Bold, color = Ink)
            }
            tickets.forEach { t ->
                Spacer(Modifier.height(8.dp))
                Column(Modifier.fillMaxWidth().background(Subtle, RoundedCornerShape(10.dp)).padding(12.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("#${t.complainId ?: t.id ?: ""}  ${t.category ?: ""}", fontSize = 14.sp, fontWeight = FontWeight.SemiBold,
                            color = Ink, modifier = Modifier.weight(1f))
                        Pill(if (t.state == "processing") "কাজ চলছে" else "অপেক্ষায়", if (t.state == "processing") Primary else Warn)
                    }
                    val meta = listOfNotNull(t.openedAt?.let { Format.dateTime(it) }, t.assignedTo?.takeIf { it.isNotBlank() }?.let { "কর্মী: $it" })
                    if (meta.isNotEmpty()) Text(meta.joinToString("  ·  "), fontSize = 12.sp, color = Muted)
                    t.note?.takeIf { it.isNotBlank() }?.let {
                        Spacer(Modifier.height(4.dp))
                        Text(it, fontSize = 13.sp, color = Ink, maxLines = 4, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
        }
    }
}

@Composable
private fun RetryIcon(onClick: () -> Unit) {
    IconButton(onClick = onClick, modifier = Modifier.size(32.dp)) {
        Icon(Icons.Filled.Refresh, "রিফ্রেশ", tint = PrimaryStrong, modifier = Modifier.size(20.dp))
    }
}

@Composable
private fun Controls(a: CallActivity) {
    Column(Modifier.fillMaxWidth().background(Surface)) {
        HorizontalDivider(color = Line)
        Row(Modifier.fillMaxWidth().navigationBarsPadding().padding(vertical = 14.dp),
            horizontalArrangement = Arrangement.SpaceEvenly, verticalAlignment = Alignment.CenterVertically) {
            if (a.phase == CallPhase.Ringing) {
                RoundButton(Icons.Filled.CallEnd, "কাটুন", Danger, Color.White, 64) { a.decline() }
                val pulse = rememberInfiniteTransition(label = "answer")
                val grow by pulse.animateFloat(1f, 1.08f, infiniteRepeatable(tween(600), RepeatMode.Reverse), label = "grow")
                Box(Modifier.scale(grow)) { RoundButton(Icons.Filled.Call, "ধরুন", Good, Color.White, 64) { a.answer() } }
            } else {
                val inCall = a.web != null && a.phase != CallPhase.Ended
                RoundButton(if (a.muted) Icons.Filled.MicOff else Icons.Filled.Mic, if (a.muted) "মিউট আছে" else "মিউট",
                    if (a.muted) Ink else Subtle, if (a.muted) Color.White else Ink, 56, enabled = inCall) { a.toggleMute() }
                RoundButton(Icons.Filled.CallEnd, "কেটে দিন", Danger, Color.White, 64) { a.hangUp() }
                RoundButton(Icons.Filled.VolumeUp, "স্পিকার", if (a.speaker) Ink else Subtle, if (a.speaker) Color.White else Ink, 56,
                    enabled = inCall) { a.toggleSpeaker() }
            }
        }
    }
}

@Composable
private fun RoundButton(icon: ImageVector, label: String, bg: Color, fg: Color, size: Int, enabled: Boolean = true,
                        onClick: () -> Unit) {
    Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.alpha(if (enabled) 1f else 0.4f)) {
        Box(Modifier.size(size.dp).background(bg, CircleShape).clickable(enabled = enabled, onClick = onClick),
            contentAlignment = Alignment.Center) {
            Icon(icon, label, tint = fg, modifier = Modifier.size((size * 0.42f).dp))
        }
        Spacer(Modifier.height(6.dp))
        Text(label, fontSize = 12.sp, color = Muted)
    }
}
