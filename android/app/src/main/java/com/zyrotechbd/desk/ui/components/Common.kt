package com.zyrotechbd.desk.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.PrimaryLight
import com.zyrotechbd.desk.ui.theme.PrimaryStrong

val Danger = Color(0xFFB24734)
val Good = Color(0xFF1AA57B)
val Warn = Color(0xFFB45309)

@Composable
fun LoadingBox(modifier: Modifier = Modifier) {
    Box(modifier = modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) {
        CircularProgressIndicator(color = Primary, strokeWidth = 2.5.dp, modifier = Modifier.size(28.dp))
    }
}

@Composable
fun MessageBox(text: String, color: Color = Danger, onRetry: (() -> Unit)? = null, retryLabel: String = "") {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .background(color.copy(alpha = 0.10f))
            .border(1.dp, color.copy(alpha = 0.25f), RoundedCornerShape(12.dp))
            .padding(12.dp)
    ) {
        Text(text, color = color, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
        if (onRetry != null) {
            Spacer(Modifier.height(6.dp))
            Text(retryLabel, color = PrimaryStrong, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clickable { onRetry() })
        }
    }
}

@Composable
fun EmptyText(text: String) {
    Text(text, color = Muted, fontSize = 13.sp, textAlign = TextAlign.Center,
        modifier = Modifier.fillMaxWidth().padding(24.dp))
}

/** A big number on the dashboard. */
@Composable
fun StatCard(label: String, value: String, icon: ImageVector, modifier: Modifier = Modifier, sub: String? = null,
             tint: Color = Primary, onClick: (() -> Unit)? = null) {
    CatvCard(modifier = modifier.then(if (onClick != null) Modifier.clickable { onClick() } else Modifier)) {
        Column(Modifier.padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(
                    modifier = Modifier.size(30.dp).clip(RoundedCornerShape(8.dp)).background(tint.copy(alpha = 0.12f)),
                    contentAlignment = Alignment.Center
                ) {
                    Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(17.dp))
                }
                Spacer(Modifier.width(8.dp))
                Text(label, fontSize = 12.sp, color = Muted, fontWeight = FontWeight.SemiBold, maxLines = 1,
                    overflow = TextOverflow.Ellipsis)
            }
            Spacer(Modifier.height(8.dp))
            Text(value, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, color = Ink, maxLines = 1)
            if (sub != null) Text(sub, fontSize = 11.sp, color = Muted, maxLines = 2)
        }
    }
}

/** A filled gradient button like the CATV login button. */
@Composable
fun PrimaryButton(text: String, enabled: Boolean = true, loading: Boolean = false, modifier: Modifier = Modifier,
                  color: Color? = null, onClick: () -> Unit) {
    val brush = if (!enabled) Brush.horizontalGradient(listOf(Color(0xFFD9D6CF), Color(0xFFD9D6CF)))
    else if (color != null) Brush.horizontalGradient(listOf(color, color))
    else Brush.horizontalGradient(listOf(Primary, PrimaryLight))
    Box(
        modifier = modifier
            .fillMaxWidth()
            .height(50.dp)
            .clip(RoundedCornerShape(14.dp))
            .background(brush)
            .clickable(enabled = enabled && !loading) { onClick() },
        contentAlignment = Alignment.Center
    ) {
        if (loading) CircularProgressIndicator(color = Color.White, strokeWidth = 2.5.dp, modifier = Modifier.size(22.dp))
        else Text(text, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 15.sp)
    }
}

/** A small outlined action (call, WhatsApp, print). */
@Composable
fun SmallAction(text: String, icon: ImageVector, modifier: Modifier = Modifier, color: Color = PrimaryStrong,
                enabled: Boolean = true, onClick: () -> Unit) {
    Row(
        modifier = modifier
            .clip(RoundedCornerShape(10.dp))
            .border(1.dp, color.copy(alpha = if (enabled) 0.35f else 0.12f), RoundedCornerShape(10.dp))
            .clickable(enabled = enabled) { onClick() }
            .padding(horizontal = 10.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.Center
    ) {
        Icon(icon, contentDescription = null, tint = color.copy(alpha = if (enabled) 1f else 0.4f), modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = color.copy(alpha = if (enabled) 1f else 0.4f), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** A label/value line inside a card. */
@Composable
fun InfoRow(label: String, value: String?, valueColor: Color = Ink, bold: Boolean = false) {
    if (value.isNullOrBlank()) return
    Row(modifier = Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
        Text(label, fontSize = 13.sp, color = Muted, modifier = Modifier.width(118.dp))
        Text(value, fontSize = 13.sp, color = valueColor, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal,
            modifier = Modifier.weight(1f))
    }
}

@Composable
fun Chip(text: String, selected: Boolean, onClick: () -> Unit) {
    Box(
        modifier = Modifier
            .clip(RoundedCornerShape(999.dp))
            .background(if (selected) Primary else Color.White)
            .border(1.dp, if (selected) Primary else Color(0xFFE0DAD0), RoundedCornerShape(999.dp))
            .clickable { onClick() }
            .padding(horizontal = 12.dp, vertical = 6.dp)
    ) {
        Text(text, fontSize = 12.sp, color = if (selected) Color.White else Ink, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
fun Pill(text: String, color: Color) {
    Box(
        modifier = Modifier.background(color, RoundedCornerShape(999.dp)).padding(horizontal = 8.dp, vertical = 2.dp)
    ) {
        Text(text, color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
fun SectionTitle(text: String, modifier: Modifier = Modifier, trailing: @Composable RowScope.() -> Unit = {}) {
    Row(modifier = modifier.fillMaxWidth().padding(top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(text, fontSize = 14.sp, fontWeight = FontWeight.Bold, color = Ink, modifier = Modifier.weight(1f))
        trailing()
    }
}

