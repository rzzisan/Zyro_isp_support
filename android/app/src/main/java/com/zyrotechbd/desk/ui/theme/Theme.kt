package com.zyrotechbd.desk.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val DeskColors = lightColorScheme(
    primary = Primary,
    onPrimary = OnPrimary,
    primaryContainer = Color(0xFFD0F0EF),
    onPrimaryContainer = PrimaryStrong,
    secondary = Accent,
    onSecondary = OnPrimary,
    secondaryContainer = Color(0xFFFFE5DC),
    onSecondaryContainer = AccentStrong,
    tertiary = PrimaryLight,
    onTertiary = OnPrimary,
    background = Background,
    onBackground = Ink,
    surface = Surface,
    onSurface = Ink,
    surfaceVariant = SurfaceAlt,
    onSurfaceVariant = Muted,
    outline = Color(0xFFD9D1C6),
    error = Color(0xFFB24734),
    onError = OnPrimary,
)

@Composable
fun DeskTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = DeskColors,
        typography = Typography,
        content = content
    )
}
