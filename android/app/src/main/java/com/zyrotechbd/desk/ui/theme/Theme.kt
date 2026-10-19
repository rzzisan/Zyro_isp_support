package com.zyrotechbd.desk.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val DeskColors = lightColorScheme(
    primary = Primary,
    onPrimary = OnPrimary,
    primaryContainer = PrimarySoft,
    onPrimaryContainer = PrimaryStrong,
    secondary = Primary,
    onSecondary = OnPrimary,
    secondaryContainer = PrimarySoft,
    onSecondaryContainer = PrimaryStrong,
    tertiary = PrimaryStrong,
    onTertiary = OnPrimary,
    background = Background,
    onBackground = Ink,
    surface = Surface,
    onSurface = Ink,
    surfaceVariant = Subtle,
    onSurfaceVariant = Muted,
    surfaceTint = Color.Transparent,
    outline = InputLine,
    outlineVariant = Line,
    error = Color(0xFFDC2626),
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
