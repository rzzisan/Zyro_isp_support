package com.zyrotechbd.desk.ui.components

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.call.Phone
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Line
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.PrimaryStrong

/** A number to call and what it is (the customer's mobile, the contact number given with the ticket). */
data class CallNumber(val label: String, val number: String)

/** The numbers worth calling: dialable ones only, the same number (by its last 10 digits) once. */
fun callNumbers(vararg numbers: Pair<String, String?>): List<CallNumber> =
    numbers.mapNotNull { (label, raw) -> raw?.filter { it.isDigit() || it == '+' }?.takeIf { it.length >= 5 }?.let { CallNumber(label, it) } }
        .distinctBy { it.number.filter(Char::isDigit).takeLast(10) }

/** "Call": one number calls it, more ask which (the customer's own or the contact number of the ticket). */
@Composable
fun CallAction(numbers: List<CallNumber>, title: String, modifier: Modifier = Modifier) {
    val s = LocalStrings.current
    val context = LocalContext.current
    var pick by remember { mutableStateOf(false) }
    SmallAction(s.call, Icons.Filled.Call, modifier, enabled = numbers.isNotEmpty()) {
        if (numbers.size == 1) Phone.dial(context, numbers[0].number, title) else pick = true
    }
    if (pick) {
        AlertDialog(
            onDismissRequest = { pick = false },
            title = { Text(s.chooseNumber) },
            text = {
                Column {
                    numbers.forEachIndexed { i, n ->
                        if (i > 0) HorizontalDivider(color = Line)
                        Row(
                            Modifier.fillMaxWidth().clickable { pick = false; Phone.dial(context, n.number, title) }.padding(vertical = 12.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Filled.Call, contentDescription = null, tint = PrimaryStrong, modifier = Modifier.size(20.dp))
                            Spacer(Modifier.width(12.dp))
                            Column {
                                Text(n.number, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = Ink)
                                Text(n.label, fontSize = 12.sp, color = Muted)
                            }
                        }
                    }
                }
            },
            confirmButton = {},
            dismissButton = { TextButton(onClick = { pick = false }) { Text(s.cancel) } },
        )
    }
}
