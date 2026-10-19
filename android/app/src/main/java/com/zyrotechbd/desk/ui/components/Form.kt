package com.zyrotechbd.desk.ui.components

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowDropDown
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.data.network.Choice
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.Surface

/** A form label over its input. */
@Composable
fun Field(label: String, modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    Column(modifier) {
        Text(label, fontSize = 12.sp, color = Muted, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(start = 6.dp, bottom = 4.dp))
        content()
    }
}

/** A select box: the chosen name, and a dropdown of the [options]. */
@Composable
fun Picker(options: List<Choice>, selected: String?, placeholder: String = "—", onPick: (String) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Box {
        DeskCard(Modifier.fillMaxWidth().clickable { open = true }) {
            Row(Modifier.padding(horizontal = 16.dp, vertical = 11.dp), verticalAlignment = Alignment.CenterVertically) {
                val name = options.firstOrNull { it.id == selected }?.name
                Text(name ?: placeholder, fontSize = 15.sp, color = if (name != null) Ink else Muted, modifier = Modifier.weight(1f))
                Icon(Icons.Filled.ArrowDropDown, contentDescription = null, tint = Muted)
            }
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            options.forEach { o ->
                DropdownMenuItem(text = { Text(o.name) }, onClick = { onPick(o.id); open = false })
            }
        }
    }
}

/** Several choices (e.g. employees): the chosen names in a box that opens a checkbox list. */
@Composable
fun MultiPicker(options: List<Choice>, selected: List<String>, title: String, placeholder: String,
                okLabel: String, cancelLabel: String, onPick: (List<String>) -> Unit) {
    var open by remember { mutableStateOf(false) }
    DeskCard(Modifier.fillMaxWidth().clickable { open = true }) {
        Row(Modifier.padding(horizontal = 16.dp, vertical = 11.dp), verticalAlignment = Alignment.CenterVertically) {
            val names = options.filter { it.id in selected }.joinToString(", ") { it.name }
            Text(names.ifEmpty { placeholder }, fontSize = 15.sp, color = if (names.isNotEmpty()) Ink else Muted,
                maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Icon(Icons.Filled.ArrowDropDown, contentDescription = null, tint = Muted)
        }
    }
    if (open) {
        val picked = remember { mutableStateListOf<String>().apply { addAll(selected) } }
        AlertDialog(
            onDismissRequest = { open = false },
            containerColor = Surface,
            title = { Text(title, fontSize = 17.sp, fontWeight = FontWeight.SemiBold) },
            text = {
                LazyColumn(Modifier.heightIn(max = 420.dp)) {
                    items(options, key = { it.id }) { o ->
                        Row(Modifier.fillMaxWidth().clickable { if (o.id in picked) picked.remove(o.id) else picked.add(o.id) },
                            verticalAlignment = Alignment.CenterVertically) {
                            Checkbox(checked = o.id in picked, onCheckedChange = null, colors = CheckboxDefaults.colors(checkedColor = Primary),
                                modifier = Modifier.padding(10.dp))
                            Text(o.name, fontSize = 15.sp, color = Ink)
                        }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { onPick(picked.toList()); open = false }) { Text(okLabel) } },
            dismissButton = { TextButton(onClick = { open = false }) { Text(cancelLabel) } },
        )
    }
}

/** An on/off row with an optional small note under the label. */
@Composable
fun Toggle(label: String, checked: Boolean, enabled: Boolean = true, hint: String? = null, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(label, fontSize = 14.sp, color = if (enabled) Ink else Muted)
            hint?.let { Text(it, fontSize = 11.sp, color = Muted) }
        }
        Switch(checked = checked && enabled, onCheckedChange = onChange, enabled = enabled,
            colors = SwitchDefaults.colors(checkedTrackColor = Primary))
    }
}
