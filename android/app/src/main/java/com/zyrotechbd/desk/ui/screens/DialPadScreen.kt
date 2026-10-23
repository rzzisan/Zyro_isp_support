package com.zyrotechbd.desk.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.ExperimentalFoundationApi
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
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Backspace
import androidx.compose.material.icons.filled.Call
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.call.Phone
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.OnPrimary
import com.zyrotechbd.desk.ui.theme.StatusPaid
import com.zyrotechbd.desk.ui.theme.Subtle

/** Type any number and call it through the hotline (members with the phone menu). */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun DialPadScreen() {
    val s = LocalStrings.current
    val context = LocalContext.current
    var number by rememberSaveable { mutableStateOf("") }
    val keys = listOf(listOf("1", "2", "3"), listOf("4", "5", "6"), listOf("7", "8", "9"), listOf("+", "0", "#"))

    Column(Modifier.fillMaxSize().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Spacer(Modifier.height(16.dp))
        Text(number.ifEmpty { s.dialHint }, fontSize = if (number.isEmpty()) 20.sp else 32.sp, fontWeight = FontWeight.SemiBold,
            color = if (number.isEmpty()) Muted else Ink, textAlign = TextAlign.Center, maxLines = 1, modifier = Modifier.fillMaxWidth())
        Text(s.dialViaHotline, fontSize = 13.sp, color = Muted)
        Spacer(Modifier.weight(1f))
        keys.forEach { row ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
                row.forEach { k ->
                    Box(
                        Modifier.size(72.dp).clip(CircleShape).background(Subtle).clickable { if (number.length < 20) number += k },
                        contentAlignment = Alignment.Center,
                    ) { Text(k, fontSize = 28.sp, color = Ink) }
                }
            }
            Spacer(Modifier.height(14.dp))
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly, verticalAlignment = Alignment.CenterVertically) {
            Spacer(Modifier.size(72.dp))
            Box(
                Modifier.size(72.dp).clip(CircleShape).background(StatusPaid.copy(alpha = if (number.length >= 3) 1f else 0.4f))
                    .clickable(enabled = number.length >= 3) { Phone.dial(context, number, number) },
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Filled.Call, contentDescription = s.call, tint = OnPrimary, modifier = Modifier.size(32.dp)) }
            Box(
                Modifier.size(72.dp).clip(CircleShape)
                    .combinedClickable(onClick = { number = number.dropLast(1) }, onLongClick = { number = "" }),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.AutoMirrored.Filled.Backspace, contentDescription = s.erase, tint = Muted, modifier = Modifier.size(28.dp)) }
        }
        Spacer(Modifier.height(8.dp))
    }
}
