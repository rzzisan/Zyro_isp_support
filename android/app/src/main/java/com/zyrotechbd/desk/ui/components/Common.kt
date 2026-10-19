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
import com.zyrotechbd.desk.ui.theme.InputLine
import com.zyrotechbd.desk.ui.theme.Line
import com.zyrotechbd.desk.ui.theme.PrimarySoft
import com.zyrotechbd.desk.ui.theme.Surface
import com.zyrotechbd.desk.ui.theme.PrimaryStrong

val Danger = Color(0xFFDC2626)
val Good = Color(0xFF16A34A)
val Warn = Color(0xFFD97706)

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
            .clip(RoundedCornerShape(8.dp))
            .background(color.copy(alpha = 0.08f))
            .border(1.dp, color.copy(alpha = 0.25f), RoundedCornerShape(8.dp))
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

/** A big number on the dashboard, laid out like the panel's stats widgets: label, value, then a short note. */
@Composable
fun StatCard(label: String, value: String, icon: ImageVector, modifier: Modifier = Modifier, sub: String? = null,
             tint: Color = Primary, onClick: (() -> Unit)? = null) {
    DeskCard(modifier = modifier.then(if (onClick != null) Modifier.clickable { onClick() } else Modifier)) {
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(label, fontSize = 13.sp, color = Muted, fontWeight = FontWeight.Medium, maxLines = 1,
                    overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(18.dp))
            }
            Spacer(Modifier.height(6.dp))
            Text(value, fontSize = 24.sp, fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1)
            if (sub != null) Text(sub, fontSize = 12.sp, color = if (tint == Primary) Muted else tint, maxLines = 2)
        }
    }
}

/** A flat filled button, like the panel's primary buttons. */
@Composable
fun PrimaryButton(text: String, enabled: Boolean = true, loading: Boolean = false, modifier: Modifier = Modifier,
                  color: Color? = null, onClick: () -> Unit) {
    val fill = if (!enabled) Color(0xFFD1D5DB) else color ?: Primary
    Box(
        modifier = modifier
            .fillMaxWidth()
            .height(46.dp)
            .clip(RoundedCornerShape(8.dp))
            .background(fill)
            .clickable(enabled = enabled && !loading) { onClick() },
        contentAlignment = Alignment.Center
    ) {
        if (loading) CircularProgressIndicator(color = Color.White, strokeWidth = 2.5.dp, modifier = Modifier.size(22.dp))
        else Text(text, color = Color.White, fontWeight = FontWeight.SemiBold, fontSize = 15.sp)
    }
}

/** An outlined button the size of [PrimaryButton], for the other choice beside it. */
@Composable
fun SecondaryButton(text: String, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Box(
        modifier = modifier
            .fillMaxWidth()
            .height(46.dp)
            .clip(RoundedCornerShape(8.dp))
            .background(Surface)
            .border(1.dp, InputLine, RoundedCornerShape(8.dp))
            .clickable { onClick() },
        contentAlignment = Alignment.Center
    ) {
        Text(text, color = Ink, fontWeight = FontWeight.SemiBold, fontSize = 15.sp)
    }
}

/** A small outlined action (call, WhatsApp, print). */
@Composable
fun SmallAction(text: String, icon: ImageVector, modifier: Modifier = Modifier, color: Color = PrimaryStrong,
                enabled: Boolean = true, onClick: () -> Unit) {
    Row(
        modifier = modifier
            .clip(RoundedCornerShape(8.dp))
            .background(Surface)
            .border(1.dp, if (enabled) InputLine else Line, RoundedCornerShape(8.dp))
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
fun Chip(text: String, selected: Boolean, icon: ImageVector? = null, trailing: ImageVector? = null, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .clip(RoundedCornerShape(8.dp))
            .background(if (selected) PrimarySoft else Surface)
            .border(1.dp, if (selected) Primary else InputLine, RoundedCornerShape(8.dp))
            .clickable { onClick() }
            .padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        val tint = if (selected) PrimaryStrong else Ink
        icon?.let {
            Icon(it, contentDescription = null, tint = tint, modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(6.dp))
        }
        Text(text, fontSize = 13.sp, color = tint, fontWeight = FontWeight.Medium)
        trailing?.let {
            Spacer(Modifier.width(4.dp))
            Icon(it, contentDescription = null, tint = tint, modifier = Modifier.size(14.dp))
        }
    }
}

@Composable
fun Pill(text: String, color: Color) {
    Box(
        modifier = Modifier
            .background(color.copy(alpha = 0.10f), RoundedCornerShape(6.dp))
            .border(1.dp, color.copy(alpha = 0.25f), RoundedCornerShape(6.dp))
            .padding(horizontal = 7.dp, vertical = 1.dp)
    ) {
        Text(text, color = color, fontSize = 11.sp, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
fun SectionTitle(text: String, modifier: Modifier = Modifier, trailing: @Composable RowScope.() -> Unit = {}) {
    Row(modifier = modifier.fillMaxWidth().padding(top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(text, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, color = Ink, modifier = Modifier.weight(1f))
        trailing()
    }
}


/**
 * A screen's content that may stop early (`return@ScreenBody` while loading or on an error). An early return straight
 * out of an inline layout lambda such as Column's corrupts Compose's slot table on the next recomposition; out of
 * this non-inline lambda it is safe.
 */
@Composable
fun ScreenBody(content: @Composable () -> Unit) {
    content()
}
