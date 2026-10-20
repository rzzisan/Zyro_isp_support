package com.zyrotechbd.desk.ui.screens

import android.widget.Toast
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.Customer
import com.zyrotechbd.desk.data.network.LineRequest
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.InputLine
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Surface
import kotlinx.coroutines.launch

/** Whether this member may turn lines on/off (the panel's "কাস্টমার লিস্টে লাইন চালু/বন্ধ" permission) for [c]. */
fun canSwitchLine(c: Customer): Boolean = SessionStore.company()?.can("line_switch") == true && c.isLeft != true

/**
 * The panel list's M.Status switch: on = the line is on in billing. A tap asks first, then billing enables or disables the
 * line (with the member's own billing login); [onChanged] gets the customer as billing has it now.
 */
@Composable
fun LineSwitch(company: String, c: Customer, modifier: Modifier = Modifier, onChanged: (Customer) -> Unit) {
    var asking by remember { mutableStateOf(false) }
    Switch(
        checked = c.disabled != true,
        onCheckedChange = { asking = true },
        modifier = modifier,
        colors = SwitchDefaults.colors(checkedTrackColor = Good, uncheckedTrackColor = Color(0xFFFEE2E2),
            uncheckedThumbColor = Danger, uncheckedBorderColor = InputLine),
    )
    if (asking) LineSwitchDialog(company, c, onDismiss = { asking = false }) {
        asking = false
        onChanged(it)
    }
}

@Composable
fun LineSwitchDialog(company: String, c: Customer, onDismiss: () -> Unit, onDone: (Customer) -> Unit) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val turnOn = c.disabled == true
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        containerColor = Surface,
        title = {
            Text("${c.name ?: ""} (ID ${c.customerId ?: ""}): ${if (turnOn) s.lineOnAsk else s.lineOffAsk}",
                fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = Ink)
        },
        text = {
            Column {
                val due = c.due ?: 0.0
                Text(if (turnOn) s.lineOnHint + (if (due > 0) " ${s.due} ${Format.tk(due)}।" else "") else s.lineOffHint,
                    fontSize = 14.sp, color = Muted)
                error?.let {
                    Spacer(Modifier.height(8.dp))
                    Text(it, fontSize = 13.sp, color = Danger)
                }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                scope.launch {
                    busy = true
                    error = null
                    try {
                        val res = ApiClient.service.switchLine(company, c.headerId, LineRequest(turnOn))
                        Toast.makeText(context, "${c.name ?: ""}: ${if (turnOn) s.lineTurnedOn else s.lineTurnedOff}", Toast.LENGTH_SHORT).show()
                        onDone(res.customer)
                    } catch (e: Exception) {
                        error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
                    } finally {
                        busy = false
                    }
                }
            }) {
                Text(if (busy) "…" else if (turnOn) s.turnOn else s.turnOff, color = if (turnOn) Good else Danger,
                    fontWeight = FontWeight.SemiBold)
            }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss) { Text(s.cancel, color = Muted) } },
    )
}
