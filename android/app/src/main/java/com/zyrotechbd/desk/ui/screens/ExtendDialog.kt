package com.zyrotechbd.desk.ui.screens

import android.app.DatePickerDialog
import android.widget.Toast
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Checkbox
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
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
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.Customer
import com.zyrotechbd.desk.data.network.ExtendRequest
import com.zyrotechbd.desk.data.network.Extension
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.DeskTextField
import com.zyrotechbd.desk.ui.components.SmallAction
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.Surface
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.ZoneId

/**
 * সময় বাড়ান (billing phase 4a): a date until which the line stays on although a bill is due, with a reason. The limits
 * (days, times a month) are checked by the server, which answers with the reason when a date is refused.
 */
@Composable
fun ExtendDialog(company: String, c: Customer, current: Extension?, onDismiss: () -> Unit, onDone: (Extension?) -> Unit) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val today = LocalDate.now(ZoneId.of("Asia/Dhaka"))
    var until by remember { mutableStateOf(today.plusDays(7)) }
    var reason by remember { mutableStateOf("") }
    var enableLine by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        containerColor = Surface,
        title = {
            Text("${c.name ?: ""} (ID ${c.customerId ?: ""}): ${s.extendTime}", fontSize = 16.sp,
                fontWeight = FontWeight.SemiBold, color = Ink)
        },
        text = {
            Column {
                current?.let {
                    Text("${s.extendedTill}: ${Format.date(it.until)}" + (it.reason?.let { r -> " ($r)" } ?: ""),
                        fontSize = 13.sp, color = Muted)
                    Spacer(Modifier.height(8.dp))
                }
                Text(s.extendUntil, fontSize = 13.sp, color = Muted)
                Spacer(Modifier.height(4.dp))
                SmallAction(Format.date(until), Icons.Filled.CalendarMonth, Modifier.fillMaxWidth()) {
                    DatePickerDialog(context, { _, y, m, d -> until = LocalDate.of(y, m + 1, d) },
                        until.year, until.monthValue - 1, until.dayOfMonth).apply {
                        datePicker.minDate = today.plusDays(1).atStartOfDay(ZoneId.of("Asia/Dhaka")).toInstant().toEpochMilli()
                    }.show()
                }
                Spacer(Modifier.height(8.dp))
                Text(s.extendReason, fontSize = 13.sp, color = Muted)
                Spacer(Modifier.height(4.dp))
                DeskTextField(reason, { reason = it.take(1000) }, s.extendReasonHint, singleLine = false)
                if (c.disabled == true) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Checkbox(checked = enableLine, onCheckedChange = { enableLine = it })
                        Text(s.extendEnableLine, fontSize = 13.sp, color = Ink)
                    }
                }
                Spacer(Modifier.height(6.dp))
                Text(s.extendRules, fontSize = 12.sp, color = Muted)
                error?.let {
                    Spacer(Modifier.height(8.dp))
                    Text(it, fontSize = 13.sp, color = Danger)
                }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && reason.isNotBlank(), onClick = {
                scope.launch {
                    busy = true
                    error = null
                    try {
                        val res = ApiClient.service.extendTime(company, c.headerId,
                            ExtendRequest(until.toString(), reason.trim(), enableLine && c.disabled == true))
                        Toast.makeText(context, res.message ?: s.extendGive, Toast.LENGTH_LONG).show()
                        onDone(res.extension)
                    } catch (e: Exception) {
                        error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
                    } finally {
                        busy = false
                    }
                }
            }) {
                Text(if (busy) "…" else s.extendGive, color = Primary, fontWeight = FontWeight.SemiBold)
            }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss) { Text(s.cancel, color = Muted) } },
    )
}
