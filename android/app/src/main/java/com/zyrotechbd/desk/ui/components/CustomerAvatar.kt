package com.zyrotechbd.desk.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.ui.theme.AvatarPalette

fun avatarColor(seed: String): Color {
    var hash = 0
    for (ch in seed) hash = (hash * 31 + ch.code) % 997
    return AvatarPalette[Math.abs(hash) % AvatarPalette.size]
}

fun nameInitial(name: String): String {
    val t = name.trim()
    return if (t.isEmpty()) "গ" else t[0].toString()
}

@Composable
fun CustomerAvatar(name: String, seed: String, size: Dp = 44.dp) {
    val color = avatarColor(seed)
    val initial = nameInitial(name)
    Box(
        modifier = Modifier
            .size(size)
            .background(color = color.copy(alpha = 0.12f), shape = CircleShape),
        contentAlignment = Alignment.Center
    ) {
        Text(
            text = initial,
            color = color,
            fontWeight = FontWeight.SemiBold,
            fontSize = (size.value * 0.42f).sp
        )
    }
}
