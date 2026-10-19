package com.zyrotechbd.desk.ui.theme

import androidx.compose.ui.graphics.Color

// The Desk panel's look (Filament): teal primary on a light gray page, white cards with a thin gray border.
val Primary = Color(0xFF0F7C7B)
val PrimaryStrong = Color(0xFF0B5F5E)
val PrimarySoft = Color(0xFFE6F2F2)
val OnPrimary = Color(0xFFFFFFFF)

// Gray scale (Tailwind gray, as the panel uses)
val Background = Color(0xFFF9FAFB)   // gray-50, the page
val Surface = Color(0xFFFFFFFF)      // cards, top bar, inputs
val Line = Color(0xFFE5E7EB)         // gray-200, card and divider lines
val InputLine = Color(0xFFD1D5DB)    // gray-300, input borders
val Subtle = Color(0xFFF3F4F6)       // gray-100, hovered rows, unselected chips

// Text
val Ink = Color(0xFF111827)          // gray-900
val Muted = Color(0xFF6B7280)        // gray-500

// Status pill colors (the panel's success / warning / danger / info / gray badges)
val StatusDue = Color(0xFFDC2626)
val StatusPartial = Color(0xFFD97706)
val StatusPaid = Color(0xFF16A34A)
val StatusAdvance = Color(0xFF2563EB)
val StatusClosed = Color(0xFF6B7280)
val StatusActive = Color(0xFF16A34A)

// Avatar palette
val AvatarPalette = listOf(
    Color(0xFF0F7C7B),
    Color(0xFF2563EB),
    Color(0xFF7C3AED),
    Color(0xFFDB2777),
    Color(0xFFD97706),
)
